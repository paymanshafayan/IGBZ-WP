<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Modules\Instagram\Contracts\IntakeAgentInterface;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 52 — the rebuilt 13-step product registration (ADR-0004 §6).
 *
 * Row-state, not a call stack. Every REST call and every agent webhook moves one
 * registration row one checkpoint forward, so a request that dies, an app that is
 * closed, or a task that lands twenty minutes later all resume exactly where they
 * stopped. Thirteen checkpoints:
 *
 *   uploaded ─► grading ─► graded ─► processing ─► ready_to_edit ─► edited
 *                 └► rejected
 *        ─► describing ─► writing ─► product_created ─► awaiting_kind
 *                 └► transcribing (voice input only)
 *        ─► composing ─► awaiting_approval ─► approved
 *                                              └► rejected
 *
 * Two honesty rules shape the machine:
 *
 *  1. AI stages (grading, image, transcription, writing, composing) run through the
 *     `IntakeAgentInterface` seam. When no agent is registered the stage is refused
 *     with `agent_not_configured` and the row stays where it is — the flow never
 *     pretends an AI step happened. The `manual_*` methods are the honest
 *     human-in-the-loop fallback: the operator supplies the result directly.
 *  2. The commerce step creates a DRAFT WooCommerce product and nothing more. A
 *     registration cannot make anything public on its own: publishing waits on the
 *     human approval checkpoint (and, for the actual post, on phase 53).
 *
 * Any step can land in `failed`; `failed_from` records where it stopped so `retry()`
 * resumes from that exact checkpoint. `compensate()` is the failure/compensation
 * path: it deletes the draft product (drafts only) so a dead registration does not
 * leave catalogue litter.
 */
final class ProductRegistrationService {

	// The thirteen checkpoints.
	public const STATUS_UPLOADED        = 'uploaded';
	public const STATUS_GRADING         = 'grading';
	public const STATUS_GRADED          = 'graded';
	public const STATUS_PROCESSING      = 'processing';
	public const STATUS_READY_TO_EDIT   = 'ready_to_edit';
	public const STATUS_EDITED          = 'edited';
	public const STATUS_DESCRIBING      = 'describing';
	public const STATUS_TRANSCRIBING    = 'transcribing';
	public const STATUS_WRITING         = 'writing';
	public const STATUS_PRODUCT_CREATED = 'product_created';
	public const STATUS_AWAITING_KIND   = 'awaiting_kind';
	public const STATUS_COMPOSING       = 'composing';
	public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';

	// Terminal / error states.
	public const STATUS_APPROVED  = 'approved';
	public const STATUS_REJECTED  = 'rejected';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_ABANDONED = 'abandoned';

	public const INPUT_TEXT  = 'text';
	public const INPUT_VOICE = 'voice';

	public const KIND_IMAGE = 'image';
	public const KIND_VIDEO = 'video';

	public const STAGE_QUALITY  = 'quality';
	public const STAGE_IMAGE    = 'image';
	public const STAGE_TRANSCRIPT = 'transcript';
	public const STAGE_COPY     = 'copy';
	public const STAGE_VIDEO    = 'video';
	public const STAGE_POST     = 'post';

	/** @var array<string,string[]> action => allowed source statuses */
	private const GUARDS = [
		'start_grading'     => [ self::STATUS_UPLOADED ],
		'complete_grading'  => [ self::STATUS_GRADING ],
		'manual_grade'      => [ self::STATUS_UPLOADED, self::STATUS_GRADING ],
		'start_image'       => [ self::STATUS_GRADED ],
		'complete_image'    => [ self::STATUS_PROCESSING ],
		'manual_prepared_image' => [ self::STATUS_GRADED, self::STATUS_PROCESSING ],
		'mark_edited'       => [ self::STATUS_READY_TO_EDIT ],
		'start_describing'  => [ self::STATUS_EDITED ],
		'complete_transcription' => [ self::STATUS_TRANSCRIBING ],
		'manual_transcription'   => [ self::STATUS_TRANSCRIBING ],
		'start_writing'     => [ self::STATUS_DESCRIBING ],
		'complete_writing'  => [ self::STATUS_WRITING ],
		'manual_copy'       => [ self::STATUS_DESCRIBING, self::STATUS_WRITING ],
		'create_product'    => [ self::STATUS_WRITING ],
		'await_kind'        => [ self::STATUS_PRODUCT_CREATED ],
		'choose_kind'       => [ self::STATUS_AWAITING_KIND ],
		'complete_compose'  => [ self::STATUS_COMPOSING ],
		// The no-agent world: choose_kind refuses, so the operator picks the kind and
		// writes the caption in one manual step from awaiting_kind.
		'manual_composed'   => [ self::STATUS_COMPOSING, self::STATUS_AWAITING_KIND ],
		'approve'           => [ self::STATUS_AWAITING_APPROVAL ],
		'reject'            => [ self::STATUS_AWAITING_APPROVAL ],
		'retry'             => [ self::STATUS_FAILED ],
		'compensate'        => [ self::STATUS_FAILED, self::STATUS_REJECTED, self::STATUS_ABANDONED ],
	];

	public function __construct(
		private Db $db,
		private Logger $logger,
		private WooProductFactory $products,
		private ?IntakeAgentInterface $agent = null
	) {}

	// ------------------------------------------------------------- lifecycle

	/**
	 * Start a registration. Idempotent on (tenant, client_token): a retried start
	 * from the app returns the existing row instead of creating a second one.
	 *
	 * @param array<string,mixed> $data image_url | voice_url, input_type, account_id
	 */
	public function start( int $tenant_id, array $data ): array {
		$token = substr( trim( (string) ( $data['client_token'] ?? '' ) ), 0, 64 );
		if ( '' === $token ) {
			$token = 'reg-' . Crypto::token( 12 );
		}

		$existing = $this->db->row(
			"SELECT id FROM " . $this->db->table( 'ig_product_registrations' ) . " WHERE tenant_id = %d AND client_token = %s LIMIT 1",
			$tenant_id,
			$token
		);
		if ( null !== $existing ) {
			return [ 'ok' => true, 'id' => (int) $existing['id'], 'status' => 'duplicate', 'error' => '' ];
		}

		$input_type = (string) ( $data['input_type'] ?? self::INPUT_TEXT );
		if ( ! in_array( $input_type, [ self::INPUT_TEXT, self::INPUT_VOICE ], true ) ) {
			$input_type = self::INPUT_TEXT;
		}
		$image_url = trim( (string) ( $data['image_url'] ?? '' ) );
		$voice_url = trim( (string) ( $data['voice_url'] ?? '' ) );

		if ( self::INPUT_VOICE === $input_type ) {
			if ( '' === $voice_url ) {
				return [ 'ok' => false, 'id' => 0, 'status' => '', 'error' => 'voice_url_required' ];
			}
		} elseif ( '' === $image_url ) {
			return [ 'ok' => false, 'id' => 0, 'status' => '', 'error' => 'image_url_required' ];
		}

		$now = current_time( 'mysql', true );
		$id  = $this->db->insert(
			'ig_product_registrations',
			[
				'tenant_id'          => $tenant_id,
				'account_id'         => max( 0, (int) ( $data['account_id'] ?? 0 ) ),
				'input_type'         => $input_type,
				'client_token'       => $token,
				'status'             => self::STATUS_UPLOADED,
				'stage'              => '',
				'stage_task'         => '',
				'image_url'          => $image_url,
				'image_prepared_url' => '',
				'voice_url'          => $voice_url,
				'transcription'      => '',
				'copy_json'          => '',
				'kind'               => '',
				'product_id'         => 0,
				'content_id'         => 0,
				'public_code'        => '',
				'approved_by'        => 0,
				'approved_at'        => null,
				'failed_from'        => '',
				'error'              => '',
				'attempts'           => 0,
				'created_at'         => $now,
				'updated_at'         => $now,
			]
		);

		$this->logger->info( 'product_reg', 'Registration started', [ 'tenant' => $tenant_id, 'id' => (int) $id ] );

		return [ 'ok' => true, 'id' => (int) $id, 'status' => self::STATUS_UPLOADED, 'error' => '' ];
	}

	/** @return array<string,mixed>|null */
	public function get( int $tenant_id, int $id ): ?array {
		return $this->db->row(
			"SELECT * FROM " . $this->db->table( 'ig_product_registrations' ) . ' WHERE id = %d AND tenant_id = %d LIMIT 1',
			$id,
			$tenant_id
		);
	}

	// ------------------------------------------------------------- AI stages

	/** Kick off photo grading. Needs an agent; refuses honestly without one. */
	public function start_grading( int $tenant_id, int $id ): array {
		$row = $this->guard( 'start_grading', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}
		if ( null === $this->agent ) {
			return $this->refuse( $tenant_id, $id, 'agent_not_configured' );
		}

		$account = $this->agent->account( (int) $row['account_id'] );
		$task    = $this->agent->grade_photo( $account ?? [], (string) $row['image_url'] );
		if ( '' === $task ) {
			return $this->fail( $tenant_id, $id, 'agent_refused_grading' );
		}

		return $this->move( $tenant_id, $id, self::STATUS_GRADING, [ 'stage' => self::STAGE_QUALITY, 'stage_task' => $task ] );
	}

	/**
	 * Consume the grading verdict.
	 *
	 * @param array<string,mixed> $result {pass:bool, reason?:string}
	 */
	public function complete_grading( int $tenant_id, int $id, array $result ): array {
		$row = $this->guard( 'complete_grading', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}

		$pass = ! empty( $result['pass'] );
		if ( $pass ) {
			return $this->move( $tenant_id, $id, self::STATUS_GRADED, [ 'stage' => '', 'stage_task' => '' ] );
		}

		$reason = substr( (string) ( $result['reason'] ?? 'photo_rejected' ), 0, 500 );
		return $this->move( $tenant_id, $id, self::STATUS_REJECTED, [ 'stage' => '', 'stage_task' => '', 'error' => $reason ] );
	}

	/** Human fallback: the operator says the photo is good (or not). */
	public function manual_grade( int $tenant_id, int $id, bool $pass, string $reason = '' ): array {
		$row = $this->guard( 'manual_grade', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}

		return $pass
			? $this->move( $tenant_id, $id, self::STATUS_GRADED, [ 'stage' => '', 'stage_task' => '' ] )
			: $this->move( $tenant_id, $id, self::STATUS_REJECTED, [ 'stage' => '', 'stage_task' => '', 'error' => substr( $reason, 0, 500 ) ] );
	}

	/** Kick off commercial image preparation. */
	public function start_image( int $tenant_id, int $id ): array {
		$row = $this->guard( 'start_image', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}
		if ( null === $this->agent ) {
			return $this->refuse( $tenant_id, $id, 'agent_not_configured' );
		}

		$task = $this->agent->prepare_product_image( $this->agent->account( (int) $row['account_id'] ) ?? [], (string) $row['image_url'], $this->copy( $row ) );
		if ( '' === $task ) {
			return $this->fail( $tenant_id, $id, 'agent_refused_image' );
		}

		return $this->move( $tenant_id, $id, self::STATUS_PROCESSING, [ 'stage' => self::STAGE_IMAGE, 'stage_task' => $task ] );
	}

	/** Consume the prepared image url. */
	public function complete_image( int $tenant_id, int $id, string $url ): array {
		$row = $this->guard( 'complete_image', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}
		if ( '' === trim( $url ) ) {
			return $this->fail( $tenant_id, $id, 'no_prepared_image' );
		}

		return $this->move( $tenant_id, $id, self::STATUS_READY_TO_EDIT, [ 'image_prepared_url' => $url, 'stage' => '', 'stage_task' => '' ] );
	}

	/** Human fallback: the operator supplies the prepared image (or keeps the original). */
	public function manual_prepared_image( int $tenant_id, int $id, string $url ): array {
		$row = $this->guard( 'manual_prepared_image', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}
		$url  = trim( $url );
		$url  = '' !== $url ? $url : (string) $row['image_url'];
		if ( '' === $url ) {
			return $this->fail( $tenant_id, $id, 'no_image_available' );
		}

		return $this->move( $tenant_id, $id, self::STATUS_READY_TO_EDIT, [ 'image_prepared_url' => $url, 'stage' => '', 'stage_task' => '' ] );
	}

	/** The shopkeeper finished editing the image. */
	public function mark_edited( int $tenant_id, int $id ): array {
		$row = $this->guard( 'mark_edited', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}

		return $this->move( $tenant_id, $id, self::STATUS_EDITED, [] );
	}

	/**
	 * Begin the description. Voice input with no transcription yet goes through the
	 * transcribing step first; text input goes straight to describing.
	 */
	public function start_describing( int $tenant_id, int $id ): array {
		$row = $this->guard( 'start_describing', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}

		if ( self::INPUT_VOICE === (string) $row['input_type'] && '' === (string) $row['transcription'] ) {
			if ( null === $this->agent ) {
				// Move to transcribing so the operator knows what is missing, then the
				// manual_transcription fallback supplies the text.
				return $this->move( $tenant_id, $id, self::STATUS_TRANSCRIBING, [ 'stage' => self::STAGE_TRANSCRIPT, 'stage_task' => '' ] );
			}
			$task = $this->agent->transcribe_audio( $this->agent->account( (int) $row['account_id'] ) ?? [], (string) $row['voice_url'] );
			if ( '' === $task ) {
				return $this->fail( $tenant_id, $id, 'agent_refused_transcription' );
			}
			return $this->move( $tenant_id, $id, self::STATUS_TRANSCRIBING, [ 'stage' => self::STAGE_TRANSCRIPT, 'stage_task' => $task ] );
		}

		return $this->move( $tenant_id, $id, self::STATUS_DESCRIBING, [] );
	}

	/** Consume a transcription. */
	public function complete_transcription( int $tenant_id, int $id, string $text ): array {
		$row = $this->guard( 'complete_transcription', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}
		if ( '' === trim( $text ) ) {
			return $this->fail( $tenant_id, $id, 'empty_transcription' );
		}

		return $this->move( $tenant_id, $id, self::STATUS_DESCRIBING, [ 'transcription' => $text, 'stage' => '', 'stage_task' => '' ] );
	}

	/** Human fallback: the operator types what the voice note said. */
	public function manual_transcription( int $tenant_id, int $id, string $text ): array {
		$row = $this->guard( 'manual_transcription', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}
		if ( '' === trim( $text ) ) {
			return $this->fail( $tenant_id, $id, 'empty_transcription' );
		}

		return $this->move( $tenant_id, $id, self::STATUS_DESCRIBING, [ 'transcription' => $text, 'stage' => '', 'stage_task' => '' ] );
	}

	/** Kick off copy writing. */
	public function start_writing( int $tenant_id, int $id ): array {
		$row = $this->guard( 'start_writing', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}
		if ( null === $this->agent ) {
			return $this->refuse( $tenant_id, $id, 'agent_not_configured' );
		}

		$task = $this->agent->write_product_copy( $this->agent->account( (int) $row['account_id'] ) ?? [], $this->copy( $row ), (string) $row['image_prepared_url'] );
		if ( '' === $task ) {
			return $this->fail( $tenant_id, $id, 'agent_refused_copy' );
		}

		return $this->move( $tenant_id, $id, self::STATUS_WRITING, [ 'stage' => self::STAGE_COPY, 'stage_task' => $task ] );
	}

	/**
	 * Consume the finished listing copy.
	 *
	 * @param array<string,mixed> $copy {title, description, price, sku?}
	 */
	public function complete_writing( int $tenant_id, int $id, array $copy ): array {
		$row = $this->guard( 'complete_writing', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}

		$validation = $this->validate_copy( $copy );
		if ( null !== $validation ) {
			return $this->fail( $tenant_id, $id, $validation );
		}

		return $this->move( $tenant_id, $id, self::STATUS_WRITING, [ 'copy_json' => wp_json_encode( $copy ), 'stage' => '', 'stage_task' => '' ] );
	}

	/** Human fallback: the operator writes the listing by hand. */
	public function manual_copy( int $tenant_id, int $id, array $copy ): array {
		$row = $this->guard( 'manual_copy', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}

		$validation = $this->validate_copy( $copy );
		if ( null !== $validation ) {
			return $this->fail( $tenant_id, $id, $validation );
		}

		return $this->move( $tenant_id, $id, self::STATUS_WRITING, [ 'copy_json' => wp_json_encode( $copy ), 'stage' => '', 'stage_task' => '' ] );
	}

	// ------------------------------------------------------------- commerce

	/**
	 * The commerce step: create the DRAFT WooCommerce product and mint the public
	 * code. Idempotent — a row that already has a product_id is never re-created.
	 */
	public function create_product( int $tenant_id, int $id ): array {
		$row = $this->guard( 'create_product', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}

		// Idempotency: a crash after the product write but before the status flip
		// must not create a second product on retry.
		if ( (int) $row['product_id'] > 0 ) {
			return $this->move( $tenant_id, $id, self::STATUS_PRODUCT_CREATED, [] );
		}

		$copy = $this->copy( $row );
		$validation = $this->validate_copy( $copy );
		if ( null !== $validation ) {
			return $this->fail( $tenant_id, $id, $validation );
		}

		if ( ! $this->products->is_available() ) {
			return $this->fail( $tenant_id, $id, 'woocommerce_not_active' );
		}

		$product_id = $this->products->create_draft( $copy );
		if ( $product_id <= 0 ) {
			return $this->fail( $tenant_id, $id, 'product_creation_failed' );
		}

		$public_code = (string) $product_id; // the public code is the product id (see SkuGenerator)
		if ( function_exists( 'update_post_meta' ) ) {
			update_post_meta( $product_id, '_igbz_registration_id', $id );
			update_post_meta( $product_id, '_igbz_public_code', $public_code );
		}

		$this->logger->info( 'product_reg', 'Draft product created', [ 'tenant' => $tenant_id, 'id' => $id, 'product' => $product_id ] );

		return $this->move( $tenant_id, $id, self::STATUS_PRODUCT_CREATED, [ 'product_id' => $product_id, 'public_code' => $public_code ] );
	}

	/**
	 * The product exists; the machine now asks the shopkeeper which media kind the
	 * post should carry. This is a real checkpoint, so an app closed at this moment
	 * resumes here — with the product safely already created.
	 */
	public function await_kind( int $tenant_id, int $id ): array {
		$row = $this->guard( 'await_kind', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}

		return $this->move( $tenant_id, $id, self::STATUS_AWAITING_KIND, [] );
	}

	/**
	 * The shopkeeper chooses the media kind. Image goes straight to composing;
	 * video asks the agent to produce the reel first (still one composing step).
	 */
	public function choose_kind( int $tenant_id, int $id, string $kind ): array {
		$row = $this->guard( 'choose_kind', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}
		if ( ! in_array( $kind, [ self::KIND_IMAGE, self::KIND_VIDEO ], true ) ) {
			return [ 'ok' => false, 'id' => $id, 'status' => (string) $row['status'], 'error' => 'bad_kind' ];
		}

		$base = [ 'kind' => $kind ];

		if ( self::KIND_IMAGE === $kind ) {
			return $this->start_composing( $tenant_id, $row, $base, self::STAGE_POST );
		}

		return $this->start_composing( $tenant_id, $row, $base, self::STAGE_VIDEO );
	}

	/** Shared composing kick-off for image and video. */
	private function start_composing( int $tenant_id, array $row, array $base, string $stage ): array {
		$id = (int) $row['id'];
		if ( null === $this->agent ) {
			return $this->refuse( $tenant_id, $id, 'agent_not_configured' );
		}

		$account = $this->agent->account( (int) $row['account_id'] ) ?? [];
		$brief   = $this->copy( $row );
		$media   = (string) ( $row['image_prepared_url'] ?: $row['image_url'] );

		$task = self::STAGE_VIDEO === $stage
			? $this->agent->produce_product_video( $account, $brief, $media )
			: $this->agent->finish_product_post( $account, $brief, $media );

		if ( '' === $task ) {
			return $this->fail( $tenant_id, $id, 'agent_refused_composing' );
		}

		return $this->move( $tenant_id, $id, self::STATUS_COMPOSING, $base + [ 'stage' => $stage, 'stage_task' => $task ] );
	}

	/**
	 * Consume the composed post (caption + stamped media). Lands on the human
	 * approval checkpoint — nothing publishes from here on its own.
	 *
	 * @param array<string,mixed> $post {caption, media_url?, hashtags?}
	 */
	public function complete_compose( int $tenant_id, int $id, array $post ): array {
		$row = $this->guard( 'complete_compose', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}
		if ( '' === trim( (string) ( $post['caption'] ?? '' ) ) ) {
			return $this->fail( $tenant_id, $id, 'no_caption' );
		}

		$copy = $this->copy( $row );
		$copy['post'] = $post;
		return $this->move( $tenant_id, $id, self::STATUS_AWAITING_APPROVAL, [ 'copy_json' => wp_json_encode( $copy ), 'stage' => '', 'stage_task' => '' ] );
	}

	/** Human fallback: the operator writes the caption by hand. */
	public function manual_composed( int $tenant_id, int $id, string $caption, string $media_url = '' ): array {
		$row = $this->guard( 'manual_composed', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}
		if ( '' === trim( $caption ) ) {
			return $this->fail( $tenant_id, $id, 'no_caption' );
		}

		$media = '' !== trim( $media_url ) ? trim( $media_url ) : (string) ( $row['image_prepared_url'] ?: $row['image_url'] );
		$copy  = $this->copy( $row );
		$copy['post'] = [ 'caption' => trim( $caption ), 'media_url' => $media ];

		// When the kind was never chosen (agent missing), the manual path defaults to
		// image: a video cannot honestly be produced without the agent.
		$kind = (string) $row['kind'];
		$kind = in_array( $kind, [ self::KIND_IMAGE, self::KIND_VIDEO ], true ) ? $kind : self::KIND_IMAGE;

		return $this->move( $tenant_id, $id, self::STATUS_AWAITING_APPROVAL, [ 'kind' => $kind, 'copy_json' => wp_json_encode( $copy ), 'stage' => '', 'stage_task' => '' ] );
	}

	// ------------------------------------------------------------- the gates

	/**
	 * The human approval: the only path out of awaiting_approval to a live result.
	 *
	 * Approval materializes the publishable artifact: a DRAFT ig_content row the
	 * phase-53 publisher will consume. A draft posts nowhere — the row is the
	 * bridge between "a human said yes" and "the network sees it".
	 */
	public function approve( int $tenant_id, int $id, int $user_id ): array {
		$row = $this->guard( 'approve', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}

		// Idempotent within the one legal call: a registration can only be approved
		// from awaiting_approval, after which it is terminal — but the guard is the
		// real promise, and a stored content_id is the crash-recovery backstop.
		$write = [ 'approved_by' => max( 0, $user_id ), 'approved_at' => current_time( 'mysql', true ), 'error' => '' ];

		$content_id = (int) $row['content_id'];
		if ( $content_id <= 0 ) {
			$content_id = $this->create_content_row( $tenant_id, $row );
			if ( $content_id > 0 ) {
				$write['content_id'] = $content_id;
			}
		}

		$this->logger->info( 'product_reg', 'Registration approved by a human', [ 'tenant' => $tenant_id, 'id' => $id, 'user' => $user_id, 'content' => $content_id ] );

		return $this->move( $tenant_id, $id, self::STATUS_APPROVED, $write );
	}

	/**
	 * Build the draft ig_content row from the registration. Returns the content id,
	 * or 0 when the write fails (the approval itself still stands; the publisher
	 * phase re-checks for missing rows).
	 */
	private function create_content_row( int $tenant_id, array $row ): int {
		$copy = $this->copy( $row );
		$post = is_array( $copy['post'] ?? null ) ? $copy['post'] : [];

		$media_url = (string) ( $post['media_url'] ?? '' );
		$media_url = '' !== $media_url ? $media_url : (string) ( $row['image_prepared_url'] ?: $row['image_url'] );

		$now = current_time( 'mysql', true );

		$content_id = $this->db->insert(
			'ig_content',
			[
				'tenant_id'      => $tenant_id,
				'account_id'     => (int) $row['account_id'],
				'kind'           => (string) ( $row['kind'] ?: self::KIND_IMAGE ),
				'title'          => substr( (string) ( $copy['title'] ?? '' ), 0, 191 ),
				'brief'          => (string) ( $copy['description'] ?? '' ),
				'caption'        => (string) ( $post['caption'] ?? '' ),
				'hashtags'       => '' !== (string) ( $post['hashtags'] ?? '' ) ? (string) $post['hashtags'] : null,
				'media'          => wp_json_encode( [ 'url' => $media_url ] ),
				'product_id'     => (int) $row['product_id'],
				'funnel_id'      => 0,
				'provider'       => 'zernio',
				'status'         => 'draft',
				'created_at'     => $now,
				'updated_at'     => $now,
			]
		);

		if ( $content_id > 0 ) {
			$this->logger->info( 'product_reg', 'Draft content row created for the publisher', [ 'tenant' => $tenant_id, 'content' => $content_id, 'registration' => (int) $row['id'] ] );
		} else {
			$this->logger->error( 'product_reg', 'Content row write failed during approval', [ 'tenant' => $tenant_id, 'registration' => (int) $row['id'] ] );
		}

		return $content_id;
	}

	/** The human refuses; the registration is terminal unless compensated. */
	public function reject( int $tenant_id, int $id, int $user_id, string $reason = '' ): array {
		$row = $this->guard( 'reject', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}

		$this->logger->info( 'product_reg', 'Registration rejected by a human', [ 'tenant' => $tenant_id, 'id' => $id, 'user' => $user_id ] );

		return $this->move( $tenant_id, $id, self::STATUS_REJECTED, [ 'error' => substr( $reason, 0, 500 ) ] );
	}

	/** Resume from the exact checkpoint the row failed at. */
	public function retry( int $tenant_id, int $id ): array {
		$row = $this->guard( 'retry', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}

		$from = (string) $row['failed_from'];
		// Only stable checkpoints are resumable; anything else means the failure
		// happened before real progress and the honest place to resume is the start.
		$stable = [ self::STATUS_UPLOADED, self::STATUS_GRADED, self::STATUS_READY_TO_EDIT, self::STATUS_EDITED, self::STATUS_DESCRIBING, self::STATUS_TRANSCRIBING, self::STATUS_WRITING, self::STATUS_PRODUCT_CREATED, self::STATUS_AWAITING_KIND, self::STATUS_COMPOSING ];
		if ( ! in_array( $from, $stable, true ) ) {
			$from = self::STATUS_UPLOADED;
		}

		$this->logger->info( 'product_reg', 'Registration retried from checkpoint', [ 'tenant' => $tenant_id, 'id' => $id, 'from' => $from ] );

		return $this->move( $tenant_id, $id, $from, [ 'error' => '', 'attempts' => (int) $row['attempts'] + 1 ] );
	}

	/**
	 * The failure/compensation path: delete the draft product (drafts only) so a
	 * dead registration leaves no catalogue litter, then mark it abandoned.
	 */
	public function compensate( int $tenant_id, int $id ): array {
		$row = $this->guard( 'compensate', $tenant_id, $id );
		if ( isset( $row['ok'] ) && ! $row['ok'] ) {
			return $row;
		}

		$product_id = (int) $row['product_id'];
		$removed    = false;
		if ( $product_id > 0 ) {
			$removed = $this->products->delete_draft( $product_id );
			$this->logger->warning( 'product_reg', 'Compensation: draft product ' . ( $removed ? 'deleted' : 'kept (not a draft or missing)' ), [ 'tenant' => $tenant_id, 'id' => $id, 'product' => $product_id ] );
		}

		// A product that could not be deleted stays referenced on the row, so the
		// operator can find it. Only a confirmed deletion clears the claim.
		$write = [ 'error' => $removed ? 'compensated' : 'compensated_product_kept' ];
		if ( $removed ) {
			$write['product_id']  = 0;
			$write['public_code'] = '';
		}

		return $this->move( $tenant_id, $id, self::STATUS_ABANDONED, $write );
	}

	// ------------------------------------------------------------- plumbing

	/**
	 * Fetch the row and check the action's guard. Returns the row, or an error
	 * result when the row is missing, in another tenant's scope, or the action is
	 * not legal from the current checkpoint.
	 *
	 * @return array<string,mixed>
	 */
	private function guard( string $action, int $tenant_id, int $id ): array {
		$row = $this->db->row(
			"SELECT * FROM " . $this->db->table( 'ig_product_registrations' ) . ' WHERE id = %d AND tenant_id = %d LIMIT 1',
			$id,
			$tenant_id
		);
		if ( null === $row ) {
			return [ 'ok' => false, 'id' => $id, 'status' => '', 'error' => 'not_found' ];
		}

		$status = (string) $row['status'];
		if ( ! isset( self::GUARDS[ $action ] ) || ! in_array( $status, self::GUARDS[ $action ], true ) ) {
			return [ 'ok' => false, 'id' => $id, 'status' => $status, 'error' => 'invalid_state_for_' . $action ];
		}

		return $row;
	}

	/**
	 * Persist a checkpoint move.
	 *
	 * @param array<string,mixed> $fields
	 */
	private function move( int $tenant_id, int $id, string $status, array $fields ): array {
		$now   = current_time( 'mysql', true );
		$write = $fields + [ 'status' => $status, 'updated_at' => $now ];
		unset( $write['id'], $write['tenant_id'] );

		$this->db->update( 'ig_product_registrations', $write, [ 'id' => $id, 'tenant_id' => $tenant_id ] );

		return [ 'ok' => true, 'id' => $id, 'status' => $status, 'error' => '' ];
	}

	/**
	 * Land the row in failed, remembering where it stopped.
	 *
	 * Returns ok=false on purpose: the STEP failed. The status field reports where
	 * the row ended up (failed), so callers can distinguish "step failed and the row
	 * is resumable" from "the step is simply not legal here".
	 */
	private function fail( int $tenant_id, int $id, string $error ): array {
		$row = $this->db->row(
			"SELECT status FROM " . $this->db->table( 'ig_product_registrations' ) . ' WHERE id = %d AND tenant_id = %d LIMIT 1',
			$id,
			$tenant_id
		);
		$from = null !== $row ? (string) $row['status'] : '';

		$this->logger->error( 'product_reg', 'Registration failed', [ 'tenant' => $tenant_id, 'id' => $id, 'from' => $from, 'error' => $error ] );

		$this->move( $tenant_id, $id, self::STATUS_FAILED, [ 'failed_from' => $from, 'error' => substr( $error, 0, 500 ), 'attempts' => $this->next_attempts( $tenant_id, $id ) ] );

		return [ 'ok' => false, 'id' => $id, 'status' => self::STATUS_FAILED, 'error' => $error ];
	}

	/** A refused-but-not-failed stage (no agent): the row simply does not advance. */
	private function refuse( int $tenant_id, int $id, string $error ): array {
		$row = $this->db->row(
			"SELECT status FROM " . $this->db->table( 'ig_product_registrations' ) . ' WHERE id = %d AND tenant_id = %d LIMIT 1',
			$id,
			$tenant_id
		);
		$status = null !== $row ? (string) $row['status'] : '';

		return [ 'ok' => false, 'id' => $id, 'status' => $status, 'error' => $error ];
	}

	private function next_attempts( int $tenant_id, int $id ): int {
		$row = $this->db->row(
			"SELECT attempts FROM " . $this->db->table( 'ig_product_registrations' ) . ' WHERE id = %d AND tenant_id = %d LIMIT 1',
			$id,
			$tenant_id
		);

		return ( null !== $row ? (int) $row['attempts'] : 0 ) + 1;
	}

	/** @return array<string,mixed> */
	private function copy( array $row ): array {
		$decoded = json_decode( (string) $row['copy_json'], true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Listing copy must carry a title and a price before a product may exist.
	 *
	 * @return string|null null when valid, otherwise the error code
	 */
	private function validate_copy( array $copy ): ?string {
		if ( '' === trim( (string) ( $copy['title'] ?? '' ) ) ) {
			return 'copy_title_required';
		}
		if ( ! isset( $copy['price'] ) || '' === (string) $copy['price'] ) {
			return 'copy_price_required';
		}

		return null;
	}
}
