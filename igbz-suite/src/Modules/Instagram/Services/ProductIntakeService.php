<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Modules\Instagram\Contracts\IntakeAgentInterface;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The state machine behind "register a product from your phone".
 *
 * The shopkeeper never opens wp-admin. They photograph an item, answer a couple of questions and
 * the plugin does the rest: grade the photo, clean it up, write the listing, create the
 * WooCommerce product, mint a code, wire up the comment-to-DM funnel, build the Instagram post and
 * hand the purchase link to ManyChat. Thirteen steps, most of them waiting on an asynchronous
 * Manus task, spread over minutes and several REST round-trips from the app.
 *
 * That is why every step is a row state rather than a call stack. Each REST call and each webhook
 * moves one intake row from one status to the next, so a request that dies, an app that is closed
 * or a task that comes back twenty minutes later all resume from exactly where they stopped. The
 * cron sweep in ContentScheduler is the safety net for tasks whose webhook never arrived.
 *
 *   uploaded ─► grading ─► rejected            (photo not good enough, seller retries)
 *                       └► graded ─► processing ─► ready_to_edit ─► edited
 *                                                                     │
 *                             transcribing ◄──────── describing ◄─────┘
 *                                    └──► writing ─► product_created
 *                                                          │
 *                                        awaiting_kind ◄───┘
 *                                              ├─ video ─► producing_video ─► video_review ─┐
 *                                              └─ image ────────────────────────────────────┤
 *                                                                                composing ─┘
 *                                                                                     │
 *                                                                    published ◄─ scheduled
 *
 * Anything can land in `failed`; nothing leaves it except an explicit retry from the app.
 */
final class ProductIntakeService {

	// The photo half.
	public const STATUS_UPLOADED      = 'uploaded';
	public const STATUS_GRADING       = 'grading';
	public const STATUS_REJECTED      = 'rejected';
	public const STATUS_GRADED        = 'graded';
	public const STATUS_PROCESSING    = 'processing';
	public const STATUS_READY_TO_EDIT = 'ready_to_edit';
	public const STATUS_EDITED        = 'edited';

	// The description half.
	public const STATUS_DESCRIBING   = 'describing';
	public const STATUS_TRANSCRIBING = 'transcribing';
	public const STATUS_WRITING      = 'writing';

	// The commerce half.
	public const STATUS_PRODUCT_CREATED = 'product_created';

	// The Instagram half.
	public const STATUS_AWAITING_KIND   = 'awaiting_kind';
	public const STATUS_PRODUCING_VIDEO = 'producing_video';
	public const STATUS_VIDEO_REVIEW    = 'video_review';
	public const STATUS_COMPOSING       = 'composing';
	public const STATUS_SCHEDULED       = 'scheduled';
	public const STATUS_PUBLISHED       = 'published';

	public const STATUS_FAILED = 'failed';

	/** Which Manus task a row is currently waiting on, so one webhook can route to the right handler. */
	public const STAGE_QUALITY    = 'quality';
	public const STAGE_IMAGE      = 'image';
	public const STAGE_TRANSCRIPT = 'transcript';
	public const STAGE_COPY       = 'copy';
	public const STAGE_VIDEO      = 'video';
	public const STAGE_POST       = 'post';

	public const INPUT_TEXT  = 'text';
	public const INPUT_VOICE = 'voice';

	public const KIND_IMAGE = 'image';
	public const KIND_VIDEO = 'video';

	/** Statuses from which a Manus task is outstanding; used by the cron sweep. */
	private const AWAITING_TASK = [
		self::STATUS_GRADING,
		self::STATUS_PROCESSING,
		self::STATUS_TRANSCRIBING,
		self::STATUS_WRITING,
		self::STATUS_PRODUCING_VIDEO,
		self::STATUS_COMPOSING,
	];

	public function __construct(
		private Db $db,
		private IntakeAgentInterface $manus,
		private SkuGenerator $skus,
		private Logger $logger
	) {}

	// ------------------------------------------------------------------ CRUD

	/** @return array<string,mixed>|null */
	public function get( int $id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_intake' ) . ' WHERE id = %d AND tenant_id = %d', $id, igbz()->tenancy()->id() );
	}

	/** @return array<string,mixed>|null */
	public function by_task( string $task_id ): ?array {
		if ( '' === $task_id ) {
			return null;
		}
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_intake' ) . ' WHERE provider_task_id = %s ORDER BY id DESC LIMIT 1',
			$task_id
		);
	}

	/** @return array<string,mixed>|null */
	public function by_product( int $product_id ): ?array {
		if ( $product_id <= 0 ) {
			return null;
		}
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_intake' ) . ' WHERE product_id = %d ORDER BY id DESC LIMIT 1',
			$product_id
		);
	}

	/**
	 * @param array{tenant_id?:int,account_id?:int,user_id?:int,status?:string,limit?:int,offset?:int} $args
	 * @return array<int,array<string,mixed>>
	 */
	public function all( array $args = [] ): array {
		$where  = [ '1=1' ];
		$params = [];

		foreach ( [ 'tenant_id', 'account_id', 'user_id' ] as $column ) {
			if ( isset( $args[ $column ] ) ) {
				$where[]  = $column . ' = %d';
				$params[] = (int) $args[ $column ];
			}
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}

		$params[] = (int) ( $args['limit'] ?? 50 );
		$params[] = (int) ( $args['offset'] ?? 0 );

		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_intake' ) . ' WHERE ' . implode( ' AND ', $where )
				. ' ORDER BY id DESC LIMIT %d OFFSET %d',
			...$params
		);
	}

	public function count( array $args = [] ): int {
		$where  = [ '1=1' ];
		$params = [];

		foreach ( [ 'tenant_id', 'account_id', 'user_id' ] as $column ) {
			if ( isset( $args[ $column ] ) ) {
				$where[]  = $column . ' = %d';
				$params[] = (int) $args[ $column ];
			}
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}

		return (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_intake' ) . ' WHERE ' . implode( ' AND ', $where ),
			...$params
		);
	}

	/** @param array<string,mixed> $data */
	public function update( int $id, array $data ): void {
		$data['updated_at'] = current_time( 'mysql', true );
		$this->db->update( 'ig_intake', $data, [ 'id' => $id ] );
	}

	public function delete( int $id ): bool {
		return $this->db->delete( 'ig_intake', [ 'id' => $id, 'tenant_id' => igbz()->tenancy()->id() ] ) > 0;
	}

	public function fail( int $id, string $error ): void {
		$this->db->query(
			'UPDATE ' . $this->db->table( 'ig_intake' ) . '
			 SET status = %s, last_error = %s, retry_count = retry_count + 1, updated_at = %s
			 WHERE id = %d',
			self::STATUS_FAILED,
			mb_substr( $error, 0, 500 ),
			current_time( 'mysql', true ),
			$id
		);

		$this->logger->error( 'intake', 'Product registration failed', [ 'intake_id' => $id, 'error' => $error ] );
		do_action( 'igbz_intake_failed', $id, $error );
	}

	// ------------------------------------------------------- step 2: upload

	/**
	 * Record a freshly shot photograph and start grading it.
	 *
	 * The code is minted here rather than at product-creation time so the app can show it
	 * immediately and so the funnel keyword is reserved before anything downstream depends on it.
	 * A UNIQUE index collision means another registration drew the same code between the check
	 * and the insert; drawing again is enough, and three tries makes that astronomically safe.
	 *
	 * @param array<string,mixed> $data
	 */
	public function create( array $data ): int {
		$now = current_time( 'mysql', true );

		$payload = [
			'tenant_id'            => (int) ( $data['tenant_id'] ?? 0 ),
			'account_id'           => (int) ( $data['account_id'] ?? 0 ),
			'user_id'              => (int) ( $data['user_id'] ?? get_current_user_id() ),
			'status'               => self::STATUS_UPLOADED,
			'source_attachment_id' => (int) ( $data['source_attachment_id'] ?? 0 ),
			'source_url'           => esc_url_raw( (string) ( $data['source_url'] ?? '' ) ),
			'attempt'              => max( 1, (int) ( $data['attempt'] ?? 1 ) ),
			'created_at'           => $now,
			'updated_at'           => $now,
		];

		for ( $try = 0; $try < 3; $try++ ) {
			$payload['sku'] = $this->skus->generate();

			$id = $this->db->insert( 'ig_intake', $payload );
			if ( $id > 0 ) {
				do_action( 'igbz_intake_created', $id, $payload );
				return $id;
			}
		}

		$this->logger->error( 'intake', 'Could not allocate a product code' );
		return 0;
	}

	// ------------------------------------------------------ step 3: grading

	/**
	 * Ask the assistant whether this photo is good enough to build a listing on.
	 *
	 * Returns false only when the task could not be started at all — a *rejected* photo is a
	 * successful grading, and the reasons live on the row for the app to show.
	 */
	public function start_grading( int $id, string $hint = '' ): bool {
		$row = $this->get( $id );
		if ( ! $row ) {
			return false;
		}

		$account = $this->account_for( $row );
		if ( ! $account ) {
			$this->fail( $id, __( 'This store has no Instagram account configured, so the assistant cannot be reached.', 'igbz-suite' ) );
			return false;
		}

		$url = (string) $row['source_url'];
		if ( '' === $url ) {
			$this->fail( $id, __( 'The uploaded photo has no reachable URL.', 'igbz-suite' ) );
			return false;
		}

		$task_id = $this->manus->grade_photo( $account, $url, $hint );
		if ( '' === $task_id ) {
			$this->fail( $id, __( 'The assistant did not accept the photo check.', 'igbz-suite' ) );
			return false;
		}

		$this->update(
			$id,
			[
				'status'           => self::STATUS_GRADING,
				'provider_task_id' => $task_id,
				'provider_stage'   => self::STAGE_QUALITY,
				'last_error'       => '',
			]
		);

		return true;
	}

	/**
	 * Absorb the photo verdict.
	 *
	 * A rejection is a normal outcome, not an error: the row goes to `rejected` carrying the
	 * reasons, the app shows them and the seller shoots again. Only an unreadable answer is
	 * treated as a failure, and even then the benefit of the doubt goes to the photo — a
	 * malfunctioning grader must not stop somebody from listing their stock.
	 *
	 * @param array<string,mixed> $output
	 */
	public function absorb_quality( int $id, array $output ): void {
		$row = $this->get( $id );
		if ( ! $row ) {
			return;
		}

		if ( ! $output ) {
			$this->logger->warning( 'intake', 'The photo check returned nothing readable; accepting the photo', [ 'intake_id' => $id ] );
			$output = [ 'verdict' => 'accept', 'score' => 0, 'reasons' => [] ];
		}

		$score   = (int) ( $output['score'] ?? 0 );
		$reasons = array_values( array_filter( array_map( 'strval', (array) ( $output['reasons'] ?? [] ) ) ) );
		$verdict = 'reject' === (string) ( $output['verdict'] ?? '' ) ? 'reject' : 'accept';

		// A score below the threshold is a rejection even if the model said "accept": the number
		// is the contract the setting exposes to the store owner, and it has to mean something.
		$threshold = igbz()->settings()->int( 'intake.quality_threshold', 60 );
		if ( $score > 0 && $score < $threshold ) {
			$verdict = 'reject';
		}

		if ( 'reject' === $verdict && ! $reasons ) {
			$reasons[] = __( 'The photo is not clear enough to build a product listing on. Shoot it again in better light, with the whole product in frame against a plain background.', 'igbz-suite' );
		}

		$this->update(
			$id,
			[
				'status'          => 'reject' === $verdict ? self::STATUS_REJECTED : self::STATUS_GRADED,
				'quality_score'   => $score,
				'quality_verdict' => $verdict,
				'quality_reasons' => wp_json_encode(
					[
						'reasons'                  => $reasons,
						'suggestion'               => (string) ( $output['suggestion'] ?? '' ),
						'background_removal_ready' => (bool) ( $output['background_removal_ready'] ?? true ),
						'video_ready'              => (bool) ( $output['video_ready'] ?? true ),
						'detected_product'         => (string) ( $output['detected_product'] ?? '' ),
					]
				),
				'provider_task_id' => '',
				'provider_stage'   => '',
			]
		);

		do_action( 'igbz_intake_graded', $id, $verdict, $reasons );

		if ( 'accept' === $verdict ) {
			$this->start_processing( $id );
		}
	}

	// --------------------------------------------------- step 4: processing

	/** Background removal, new background, relighting — the image the shop will actually sell with. */
	public function start_processing( int $id ): bool {
		$row = $this->get( $id );
		if ( ! $row ) {
			return false;
		}

		$account = $this->account_for( $row );
		if ( ! $account ) {
			$this->fail( $id, __( 'This store has no Instagram account configured, so the assistant cannot be reached.', 'igbz-suite' ) );
			return false;
		}

		$quality = $this->quality( $row );

		$task_id = $this->manus->prepare_product_image(
			$account,
			(string) $row['source_url'],
			[ 'product' => (string) ( $quality['detected_product'] ?? '' ) ]
		);

		if ( '' === $task_id ) {
			$this->fail( $id, __( 'The assistant did not accept the image preparation task.', 'igbz-suite' ) );
			return false;
		}

		$this->update(
			$id,
			[
				'status'           => self::STATUS_PROCESSING,
				'provider_task_id' => $task_id,
				'provider_stage'   => self::STAGE_IMAGE,
				'last_error'       => '',
			]
		);

		return true;
	}

	/**
	 * Store the cleaned-up image and hand it to the app's editor.
	 *
	 * Falls back to the original photo when the task produced nothing usable. That is a
	 * deliberate degradation rather than a failure: the seller's own photograph already passed
	 * the quality gate, so a listing built on it is worse-looking but perfectly correct, and
	 * blocking the whole registration on a cosmetic step would be the wrong trade.
	 *
	 * @param array<int,array<string,mixed>> $attachments
	 */
	public function absorb_image( int $id, array $attachments ): void {
		$row = $this->get( $id );
		if ( ! $row ) {
			return;
		}

		$image = '';
		foreach ( $attachments as $attachment ) {
			$name = strtolower( (string) ( $attachment['file_name'] ?? '' ) );
			if ( str_ends_with( $name, '.json' ) ) {
				continue;
			}
			if ( preg_match( '/\.(png|jpe?g|webp)$/', $name ) ) {
				$image = (string) ( $attachment['url'] ?? '' );
				break;
			}
		}

		if ( '' === $image ) {
			$this->logger->warning(
				'intake',
				'Image preparation produced no file; falling back to the original photo',
				[ 'intake_id' => $id ]
			);

			// The seller's own upload is already in the media library, so it is reused in place
			// rather than downloaded and stored a second time.
			$this->update(
				$id,
				[
					'status'              => self::STATUS_READY_TO_EDIT,
					'clean_url'           => (string) $row['source_url'],
					'clean_attachment_id' => (int) $row['source_attachment_id'],
					'provider_task_id'    => '',
					'provider_stage'      => '',
				]
			);

			do_action( 'igbz_intake_image_ready', $id, (string) $row['source_url'] );
			return;
		}

		// Pulled into the media library so the store owns the asset. A Manus attachment URL is
		// temporary, and a product image that expires is a broken shop.
		$stored = $this->sideload( $image, (int) $row['id'], 'clean' );

		$this->update(
			$id,
			[
				'status'              => self::STATUS_READY_TO_EDIT,
				'clean_url'           => $stored['url'],
				'clean_attachment_id' => $stored['attachment_id'],
				'provider_task_id'    => '',
				'provider_stage'      => '',
			]
		);

		do_action( 'igbz_intake_image_ready', $id, $stored['url'] );
	}

	// ------------------------------------------------------- step 5: editor

	/**
	 * Accept the version the seller saved in the app's editor.
	 *
	 * Optional by design: the editor is a convenience, and skipping it just means the prepared
	 * image is used as it is.
	 */
	public function save_edited_image( int $id, string $url, int $attachment_id = 0 ): bool {
		$row = $this->get( $id );
		if ( ! $row ) {
			return false;
		}

		$this->update(
			$id,
			[
				'status'               => self::STATUS_EDITED,
				'edited_url'           => esc_url_raw( $url ),
				'edited_attachment_id' => $attachment_id,
			]
		);

		do_action( 'igbz_intake_image_edited', $id, $url );
		return true;
	}

	/** Skip the editor and carry the prepared image forward unchanged. */
	public function skip_editor( int $id ): bool {
		$row = $this->get( $id );
		if ( ! $row ) {
			return false;
		}

		$this->update( $id, [ 'status' => self::STATUS_EDITED ] );
		return true;
	}

	/** The image that should be used from here on: edited if there is one, else the prepared one, else the original. */
	public function best_image( array $row ): string {
		foreach ( [ 'edited_url', 'clean_url', 'source_url' ] as $column ) {
			$url = (string) ( $row[ $column ] ?? '' );
			if ( '' !== $url ) {
				return $url;
			}
		}
		return '';
	}

	/** The attachment id matching best_image(), or 0 when only a remote URL is known. */
	public function best_attachment_id( array $row ): int {
		foreach ( [ 'edited_attachment_id', 'clean_attachment_id', 'source_attachment_id' ] as $column ) {
			$id = (int) ( $row[ $column ] ?? 0 );
			if ( $id > 0 ) {
				return $id;
			}
		}
		return 0;
	}

	// -------------------------------------------------- step 6: description

	/**
	 * Store what the seller typed, plus the commerce fields only they can supply.
	 *
	 * Price, stock and category come from the form and are never inferred. The assistant writes
	 * the words; the shopkeeper owns the numbers.
	 *
	 * @param array<string,mixed> $data
	 */
	public function save_description( int $id, array $data ): bool {
		$row = $this->get( $id );
		if ( ! $row ) {
			return false;
		}

		$categories = array_values( array_filter( array_map( 'absint', (array) ( $data['category_ids'] ?? [] ) ) ) );

		$this->update(
			$id,
			[
				'status'          => self::STATUS_DESCRIBING,
				'raw_description' => (string) ( $data['description'] ?? '' ),
				'input_mode'      => self::INPUT_VOICE === ( $data['input_mode'] ?? '' ) ? self::INPUT_VOICE : self::INPUT_TEXT,
				'price'           => (float) ( $data['price'] ?? 0 ),
				'sale_price'      => (float) ( $data['sale_price'] ?? 0 ),
				'stock'           => (int) ( $data['stock'] ?? 0 ),
				'category_ids'    => implode( ',', $categories ),
			]
		);

		return true;
	}

	/** Park the row while a voice note is being transcribed asynchronously. */
	public function await_transcript( int $id, string $task_id ): void {
		$this->update(
			$id,
			[
				'status'           => self::STATUS_TRANSCRIBING,
				'provider_task_id' => $task_id,
				'provider_stage'   => self::STAGE_TRANSCRIPT,
				'input_mode'       => self::INPUT_VOICE,
			]
		);
	}

	/**
	 * Fold a finished transcript into the description and carry on.
	 *
	 * Appended rather than replacing, because the seller may well have typed a few specifications
	 * and dictated the rest; throwing either away would lose information the listing needs.
	 */
	public function absorb_transcript( int $id, string $text ): void {
		$row = $this->get( $id );
		if ( ! $row ) {
			return;
		}

		$text = trim( $text );
		if ( '' === $text ) {
			$this->fail( $id, __( 'The voice note could not be transcribed. Please type the description instead.', 'igbz-suite' ) );
			return;
		}

		$existing = trim( (string) $row['raw_description'] );
		$merged   = '' === $existing ? $text : $existing . "\n" . $text;

		$this->update(
			$id,
			[
				'status'           => self::STATUS_DESCRIBING,
				'transcript'       => $text,
				'raw_description'  => $merged,
				'provider_task_id' => '',
				'provider_stage'   => '',
			]
		);

		do_action( 'igbz_intake_transcribed', $id, $text );
	}

	// --------------------------------------------------- step 7: the listing

	/** Ask the assistant to write the listing, translated when the store is multilingual. */
	public function start_writing( int $id, array $languages = [] ): bool {
		$row = $this->get( $id );
		if ( ! $row ) {
			return false;
		}

		$account = $this->account_for( $row );
		if ( ! $account ) {
			$this->fail( $id, __( 'This store has no Instagram account configured, so the assistant cannot be reached.', 'igbz-suite' ) );
			return false;
		}

		if ( '' === trim( (string) $row['raw_description'] ) ) {
			$this->fail( $id, __( 'There is no description to build the listing from.', 'igbz-suite' ) );
			return false;
		}

		$quality = $this->quality( $row );

		$task_id = $this->manus->write_product_copy(
			$account,
			[
				'description' => (string) $row['raw_description'],
				// No code here on purpose: this step runs before the product exists, so the
				// public code has not been minted yet. It is not needed either — the listing
				// text never quotes the code, only the caption and the overlay do.
				'category'    => $this->category_names( (string) $row['category_ids'] ),
				'product'     => (string) ( $quality['detected_product'] ?? '' ),
				'languages'   => $languages,
			],
			$this->best_image( $row )
		);

		if ( '' === $task_id ) {
			$this->fail( $id, __( 'The assistant did not accept the listing task.', 'igbz-suite' ) );
			return false;
		}

		$this->update(
			$id,
			[
				'status'           => self::STATUS_WRITING,
				'provider_task_id' => $task_id,
				'provider_stage'   => self::STAGE_COPY,
				'last_error'       => '',
			]
		);

		return true;
	}

	/**
	 * Store the written copy. Creating the product itself is the caller's job.
	 *
	 * @param array<string,mixed> $output
	 */
	public function absorb_copy( int $id, array $output ): void {
		$row = $this->get( $id );
		if ( ! $row ) {
			return;
		}

		if ( ! $output || '' === trim( (string) ( $output['title'] ?? '' ) ) ) {
			$this->fail( $id, __( 'The assistant did not return a usable listing.', 'igbz-suite' ) );
			return;
		}

		$translations = (array) ( $output['translations'] ?? [] );
		unset( $output['translations'] );

		$this->update(
			$id,
			[
				'copy_json'        => wp_json_encode( $output ),
				'translations'     => wp_json_encode( $translations ),
				'specs'            => wp_json_encode( (array) ( $output['specs'] ?? [] ) ),
				'provider_task_id' => '',
				'provider_stage'   => '',
			]
		);

		do_action( 'igbz_intake_copy_ready', $id, $output, $translations );
	}

	/**
	 * Record the shopper-facing code, which only exists once the product does.
	 *
	 * Kept separate from mark_product_created() because the publisher needs the code *before*
	 * it creates the funnel, and the funnel id is not known until after that.
	 */
	public function set_public_code( int $id, string $code ): void {
		$this->update( $id, [ 'public_code' => $code ] );
	}

	public function mark_product_created( int $id, int $product_id, int $funnel_id ): void {
		$this->update(
			$id,
			[
				'status'     => self::STATUS_PRODUCT_CREATED,
				'product_id' => $product_id,
				'funnel_id'  => $funnel_id,
			]
		);

		do_action( 'igbz_intake_product_created', $id, $product_id, $funnel_id );
	}

	// ---------------------------------------------- steps 9-10: the video

	/**
	 * Step 9: the assistant asks image or video; this records the answer.
	 *
	 * An empty $kind means "the question has been asked but not answered yet", which is what the
	 * hand-off at step 8 does — it moves the row into awaiting_kind without pre-empting a choice
	 * that belongs to the seller.
	 */
	public function choose_kind( int $id, string $kind ): bool {
		$row = $this->get( $id );
		if ( ! $row ) {
			return false;
		}

		$data = [ 'status' => self::STATUS_AWAITING_KIND ];

		if ( '' !== $kind ) {
			$data['post_kind'] = self::KIND_VIDEO === $kind ? self::KIND_VIDEO : self::KIND_IMAGE;
		}

		$this->update( $id, $data );

		return true;
	}

	/**
	 * Absorb the finished post: the code-stamped media, the caption and the hashtags.
	 *
	 * @param array{status:string,stop_reason:string,attachments:array<int,array<string,mixed>>,text:string} $state
	 * @return array<string,mixed> The composed post, for the publisher to queue.
	 */
	public function absorb_post( int $id, array $state ): array {
		$row = $this->get( $id );
		if ( ! $row ) {
			return [];
		}

		$parsed = $this->manus->parse_json_block( (string) ( $state['text'] ?? '' ) );

		$media = [];
		foreach ( (array) ( $state['attachments'] ?? [] ) as $attachment ) {
			$name = strtolower( (string) ( $attachment['file_name'] ?? '' ) );
			if ( str_ends_with( $name, '.json' ) || ! preg_match( '/\.(png|jpe?g|webp)$/', $name ) ) {
				continue;
			}

			// Pulled local for the same reason as the product image: a Manus URL expires and an
			// Instagram post that never got published would then have nothing to publish.
			$stored  = $this->sideload( (string) ( $attachment['url'] ?? '' ), $id, 'post' );
			$media[] = [ 'url' => $stored['url'], 'name' => (string) ( $attachment['file_name'] ?? '' ) ];
		}

		$caption = trim( (string) ( $parsed['caption'] ?? '' ) );
		if ( '' === $caption ) {
			// A post without a caption cannot ask anybody to comment the code, which is the entire
			// mechanism. Better an honest fallback than a silent dead end.
			$copy    = $this->copy( $row );
			$caption = sprintf(
				/* translators: 1: product name, 2: product code */
				__( "%1\$s\n\nComment %2\$s below and the purchase link is sent straight to your direct messages.", 'igbz-suite' ),
				(string) ( $copy['title'] ?? '' ),
				(string) $row['public_code']
			);

			$this->logger->warning( 'intake', 'The post task returned no caption; using a fallback', [ 'intake_id' => $id ] );
		}

		$composed = [
			'caption'       => $caption,
			'hashtags'      => array_values( array_map( 'strval', (array) ( $parsed['hashtags'] ?? [] ) ) ),
			'alt_text'      => (string) ( $parsed['alt_text'] ?? '' ),
			'first_comment' => (string) ( $parsed['first_comment'] ?? '' ),
			'media'         => $media,
		];

		$this->update( $id, [ 'provider_task_id' => '', 'provider_stage' => '' ] );

		do_action( 'igbz_intake_post_composed', $id, $composed );

		return $composed;
	}

	/** Step 10: produce the video from the seller's own brief. */
	public function start_video( int $id, string $prompt ): bool {
		$row = $this->get( $id );
		if ( ! $row ) {
			return false;
		}

		$account = $this->account_for( $row );
		if ( ! $account ) {
			$this->fail( $id, __( 'This store has no Instagram account configured, so the assistant cannot be reached.', 'igbz-suite' ) );
			return false;
		}

		$copy = $this->copy( $row );

		$task_id = $this->manus->produce_product_video(
			$account,
			[
				// The public code, not the SKU: this is what gets burned onto the video for
				// viewers to type into a comment.
				'code'    => (string) $row['public_code'],
				'title'   => (string) ( $copy['title'] ?? '' ),
				'summary' => (string) ( $copy['short_description'] ?? '' ),
				'prompt'  => $prompt,
			],
			$this->best_image( $row )
		);

		if ( '' === $task_id ) {
			$this->fail( $id, __( 'The assistant did not accept the video task.', 'igbz-suite' ) );
			return false;
		}

		$this->update(
			$id,
			[
				'status'           => self::STATUS_PRODUCING_VIDEO,
				'post_kind'        => self::KIND_VIDEO,
				'video_prompt'     => $prompt,
				'video_approved'   => 0,
				'provider_task_id' => $task_id,
				'provider_stage'   => self::STAGE_VIDEO,
				'last_error'       => '',
			]
		);

		return true;
	}

	/**
	 * A finished video, waiting for the seller to approve it.
	 *
	 * @param array<int,array<string,mixed>> $attachments
	 */
	public function absorb_video( int $id, array $attachments ): void {
		$video = '';
		foreach ( $attachments as $attachment ) {
			if ( str_ends_with( strtolower( (string) ( $attachment['file_name'] ?? '' ) ), '.mp4' ) ) {
				$video = (string) ( $attachment['url'] ?? '' );
				break;
			}
		}

		if ( '' === $video ) {
			$this->fail( $id, __( 'The video task finished without producing a video file.', 'igbz-suite' ) );
			return;
		}

		$this->update(
			$id,
			[
				'status'           => self::STATUS_VIDEO_REVIEW,
				'video_url'        => $video,
				'video_approved'   => 0,
				'provider_task_id' => '',
				'provider_stage'   => '',
			]
		);

		do_action( 'igbz_intake_video_ready', $id, $video );
	}

	/** The seller has seen the video and said yes. */
	public function approve_video( int $id ): bool {
		$row = $this->get( $id );
		if ( ! $row || self::STATUS_VIDEO_REVIEW !== (string) $row['status'] ) {
			return false;
		}

		$this->update( $id, [ 'video_approved' => 1 ] );
		return true;
	}

	// ------------------------------------------------ step 11: the post

	/** Stamp the code onto the media and write the comment-to-DM caption. */
	public function start_composing( int $id ): bool {
		$row = $this->get( $id );
		if ( ! $row ) {
			return false;
		}

		$account = $this->account_for( $row );
		if ( ! $account ) {
			$this->fail( $id, __( 'This store has no Instagram account configured, so the assistant cannot be reached.', 'igbz-suite' ) );
			return false;
		}

		$copy = $this->copy( $row );

		$task_id = $this->manus->finish_product_post(
			$account,
			[
				'code'    => (string) $row['public_code'],
				'title'   => (string) ( $copy['title'] ?? '' ),
				'summary' => (string) ( $copy['short_description'] ?? '' ),
				'price'   => $this->formatted_price( $row ),
			],
			$this->best_image( $row )
		);

		if ( '' === $task_id ) {
			$this->fail( $id, __( 'The assistant did not accept the post composition task.', 'igbz-suite' ) );
			return false;
		}

		$this->update(
			$id,
			[
				'status'           => self::STATUS_COMPOSING,
				'provider_task_id' => $task_id,
				'provider_stage'   => self::STAGE_POST,
				'last_error'       => '',
			]
		);

		return true;
	}

	public function mark_scheduled( int $id, int $content_id ): void {
		$this->update( $id, [ 'status' => self::STATUS_SCHEDULED, 'content_id' => $content_id ] );
		do_action( 'igbz_intake_scheduled', $id, $content_id );
	}

	public function mark_published( int $id ): void {
		$this->update( $id, [ 'status' => self::STATUS_PUBLISHED ] );
		do_action( 'igbz_intake_published', $id );
	}

	// ------------------------------------------------------------- helpers

	/** @return array<string,mixed> */
	public function quality( array $row ): array {
		$decoded = json_decode( (string) ( $row['quality_reasons'] ?? '' ), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/** @return array<string,mixed> */
	public function copy( array $row ): array {
		$decoded = json_decode( (string) ( $row['copy_json'] ?? '' ), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/** @return array<string,mixed> */
	public function translations( array $row ): array {
		$decoded = json_decode( (string) ( $row['translations'] ?? '' ), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/** @return array<int,int> */
	public function category_ids( array $row ): array {
		$raw = (string) ( $row['category_ids'] ?? '' );
		return '' === $raw ? [] : array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );
	}

	/**
	 * The Instagram account this intake belongs to.
	 *
	 * An intake does not have to name one — the app may not know or care which account will post
	 * it — so the tenant's first active account is the sensible default, and it is also the
	 * account whose API key the whole pipeline runs on.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>|null
	 */
	public function account_for( array $row ): ?array {
		$account_id = (int) ( $row['account_id'] ?? 0 );
		if ( $account_id > 0 ) {
			$account = $this->manus->account( $account_id );
			if ( $account ) {
				return $account;
			}
		}

		$accounts = $this->manus->accounts( (int) ( $row['tenant_id'] ?? 0 ), true );
		return $accounts ? $accounts[0] : null;
	}

	private function category_names( string $ids ): string {
		$names = [];
		foreach ( array_filter( array_map( 'absint', explode( ',', $ids ) ) ) as $term_id ) {
			$term = get_term( $term_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
			}
		}
		return implode( ', ', $names );
	}

	/** @param array<string,mixed> $row */
	private function formatted_price( array $row ): string {
		$price = (float) ( $row['sale_price'] ?? 0 ) > 0 ? (float) $row['sale_price'] : (float) ( $row['price'] ?? 0 );
		if ( $price <= 0 ) {
			return '';
		}
		return function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $price ) ) : (string) $price;
	}

	/**
	 * Copy a remote file into the media library.
	 *
	 * Manus attachment URLs are signed and expire. A product image that 404s two weeks after the
	 * listing went up is worse than no automation at all, so every asset the pipeline intends to
	 * keep is pulled local at the moment it is produced. On failure the remote URL is returned
	 * unchanged — a temporary image still beats a broken registration.
	 *
	 * @return array{url:string,attachment_id:int}
	 */
	public function sideload( string $url, int $intake_id, string $suffix = '' ): array {
		if ( '' === $url ) {
			return [ 'url' => '', 'attachment_id' => 0 ];
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Phase 10: intake assets are user-supplied URLs — the most SSRF-prone input we fetch.
		if ( ! \IGBZ\Suite\Support\UrlGuard::is_safe( (string) $url ) ) {
			$this->logger->log( \IGBZ\Suite\Support\Logger::WARNING, 'security', 'Intake asset download blocked by URL guard', [ 'intake_id' => $intake_id ] );
			return [ 'url' => '', 'attachment_id' => 0 ];
		}

		$temp = download_url( $url, 60 );
		if ( is_wp_error( $temp ) ) {
			$this->logger->warning(
				'intake',
				'Could not download a generated asset; keeping the remote URL',
				[ 'intake_id' => $intake_id, 'error' => $temp->get_error_message() ]
			);
			return [ 'url' => $url, 'attachment_id' => 0 ];
		}

		$name = sprintf( 'igbz-%d%s', $intake_id, '' !== $suffix ? '-' . $suffix : '' );
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$ext  = is_string( $path ) ? pathinfo( $path, PATHINFO_EXTENSION ) : '';
		$ext  = preg_match( '/^[a-z0-9]{2,5}$/i', (string) $ext ) ? strtolower( (string) $ext ) : 'jpg';

		$attachment_id = media_handle_sideload(
			[ 'name' => $name . '.' . $ext, 'tmp_name' => $temp ],
			0,
			null,
			[ 'post_title' => $name ]
		);

		if ( is_wp_error( $attachment_id ) ) {
			// media_handle_sideload deletes the temp file on success only.
			if ( file_exists( $temp ) ) {
				wp_delete_file( $temp );
			}
			$this->logger->warning(
				'intake',
				'Could not store a generated asset in the media library; keeping the remote URL',
				[ 'intake_id' => $intake_id, 'error' => $attachment_id->get_error_message() ]
			);
			return [ 'url' => $url, 'attachment_id' => 0 ];
		}

		return [
			'url'           => (string) wp_get_attachment_url( (int) $attachment_id ),
			'attachment_id' => (int) $attachment_id,
		];
	}

	// --------------------------------------------------------------- sweeps

	/**
	 * Rows whose Manus task has not reported back.
	 *
	 * The webhook is the fast path and this is the truth: a webhook that was never delivered,
	 * was rejected by a firewall or arrived while the site was down would otherwise strand a
	 * registration forever.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function awaiting_tasks( int $limit = 25 ): array {
		$placeholders = implode( ', ', array_fill( 0, count( self::AWAITING_TASK ), '%s' ) );

		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_intake' ) . "
			 WHERE status IN ({$placeholders}) AND provider_task_id <> %s AND retry_count < 3
			 ORDER BY updated_at LIMIT %d",
			...array_merge( self::AWAITING_TASK, [ '', $limit ] )
		);
	}

	/** @return array<string,int> */
	public function counts_by_status( int $tenant_id = 0 ): array {
		$sql    = 'SELECT status, COUNT(*) AS total FROM ' . $this->db->table( 'ig_intake' );
		$params = [];

		if ( $tenant_id > 0 ) {
			$sql     .= ' WHERE tenant_id = %d';
			$params[] = $tenant_id;
		}

		$out = [];
		foreach ( $this->db->results( $sql . ' GROUP BY status', ...$params ) as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $out;
	}
}
