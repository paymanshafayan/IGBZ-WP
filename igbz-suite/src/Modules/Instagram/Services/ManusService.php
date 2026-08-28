<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Modules\Instagram\Contracts\ContentGeneratorInterface;
use IGBZ\Suite\Modules\Instagram\Contracts\IntakeAgentInterface;
use IGBZ\Suite\Modules\Instagram\Contracts\PublishResult;
use IGBZ\Suite\Modules\Instagram\Contracts\PublisherInterface;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Manus-powered Instagram content engine.
 *
 * This is the replacement for the Instagram Graph API integration of the nopCommerce original.
 * Manus performs the whole workflow as an autonomous agent:
 *   research -> design / reel production -> caption + hashtags -> schedule -> publish.
 * No asset is ever downloaded and re-uploaded manually.
 *
 * Every call is asynchronous: we store the returned task id on the content row and a cron worker
 * (ContentScheduler) reconciles the state, or the Manus webhook pushes it to us.
 */
final class ManusService implements ContentGeneratorInterface, PublisherInterface, IntakeAgentInterface {

	public const KIND_POST     = 'post';
	public const KIND_CAROUSEL = 'carousel';
	public const KIND_STORY    = 'story';
	public const KIND_REEL     = 'reel';

	public const STATUS_DRAFT      = 'draft';
	public const STATUS_GENERATING = 'generating';
	public const STATUS_READY      = 'ready';
	public const STATUS_SCHEDULED  = 'scheduled';
	public const STATUS_PUBLISHING = 'publishing';
	public const STATUS_PUBLISHED  = 'published';
	public const STATUS_FAILED     = 'failed';

	public function __construct(
		private Db $db,
		private ManusClient $client,
		private PromptBuilder $prompts,
		private Logger $logger,
		private AccountCredentials $credentials
	) {}

	/**
	 * The Manus client bound to one account's own key.
	 *
	 * @param array<string,mixed> $account
	 */
	public function client_for( array $account ): ManusClient {
		return $this->client->for_key( $this->credentials->key( $account, AccountCredentials::SERVICE_MANUS ) );
	}

	/** @param array<string,mixed> $account */
	public function account_is_configured( array $account ): bool {
		return $this->credentials->has_key( $account, AccountCredentials::SERVICE_MANUS );
	}

	public function credentials(): AccountCredentials {
		return $this->credentials;
	}

	public function id(): string {
		return 'manus';
	}

	public function title(): string {
		return __( 'Manus', 'igbz-suite' );
	}

	/**
	 * Whether *any* account on this install can reach Manus. Credentials are per account, so this
	 * is only a coarse health signal for the status screen -- use account_is_configured() before
	 * acting on a specific account.
	 */
	public function is_configured(): bool {
		$configured = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_accounts' ) . "
			 WHERE is_active = 1 AND manus_api_key IS NOT NULL AND manus_api_key <> ''"
		);
		return $configured > 0 || $this->credentials->trial_available();
	}

	public function supports( string $kind ): bool {
		return in_array( $kind, [ self::KIND_POST, self::KIND_CAROUSEL, self::KIND_STORY, self::KIND_REEL ], true );
	}

	/**
	 * The unbound client. It carries no key, so callers must bind one with for_key(); prefer
	 * client_for( $account ).
	 */
	public function client(): ManusClient {
		return $this->client;
	}

	// ------------------------------------------------------------- accounts

	/** @return array<string,mixed>|null */
	public function account( int $id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_accounts' ) . ' WHERE id = %d', $id );
	}

	/** @return array<int,array<string,mixed>> */
	public function accounts( int $tenant_id = 0, bool $active_only = true ): array {
		$sql = 'SELECT * FROM ' . $this->db->table( 'ig_accounts' ) . ' WHERE tenant_id = %d';
		if ( $active_only ) {
			$sql .= ' AND is_active = 1';
		}
		return $this->db->results( $sql . ' ORDER BY id', $tenant_id );
	}

	/**
	 * Every account on the install, ignoring tenancy. For site-wide health and cron sweeps only --
	 * anything user-facing must go through accounts() so it stays scoped to one tenant.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all_accounts( bool $active_only = true ): array {
		$sql = 'SELECT * FROM ' . $this->db->table( 'ig_accounts' );
		if ( $active_only ) {
			$sql .= ' WHERE is_active = 1';
		}
		return $this->db->results( $sql . ' ORDER BY id' );
	}

	/** @param array<string,mixed> $data */
	public function save_account( array $data, int $id = 0 ): int {
		$now     = current_time( 'mysql', true );
		$payload = [
			'tenant_id'        => (int) ( $data['tenant_id'] ?? 0 ),
			'username'         => ltrim( sanitize_text_field( (string) ( $data['username'] ?? '' ) ), '@' ),
			'display_name'     => sanitize_text_field( (string) ( $data['display_name'] ?? '' ) ),
			'manus_project_id' => sanitize_text_field( (string) ( $data['manus_project_id'] ?? '' ) ),
			'manychat_page_id' => sanitize_text_field( (string) ( $data['manychat_page_id'] ?? '' ) ),
			'timezone'         => sanitize_text_field( (string) ( $data['timezone'] ?? wp_timezone_string() ) ),
			'niche'            => sanitize_text_field( (string) ( $data['niche'] ?? '' ) ),
			'brand_voice'      => sanitize_textarea_field( (string) ( $data['brand_voice'] ?? '' ) ),
			'peak_hours'       => sanitize_text_field( (string) ( $data['peak_hours'] ?? '' ) ),
			'is_active'        => empty( $data['is_active'] ) ? 0 : 1,
			'updated_at'       => $now,
		];

		if ( isset( $data['credential_mode'] ) ) {
			$payload['credential_mode'] = AccountCredentials::MODE_TRIAL === $data['credential_mode']
				? AccountCredentials::MODE_TRIAL
				: AccountCredentials::MODE_OWN;
		}

		// A key is only written when a new value was actually typed. The edit form renders stored
		// keys as a mask, so an untouched field must leave the ciphertext alone.
		foreach ( [ 'manus_api_key', 'manychat_api_key' ] as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			$value = trim( (string) $data[ $field ] );
			if ( Crypto::MASK === $value ) {
				continue;
			}
			$payload[ $field ] = '' === $value ? null : $this->credentials->encrypt_key( $value );
		}

		if ( $id > 0 ) {
			$this->db->update( 'ig_accounts', $payload, [ 'id' => $id ] );
			$this->after_account_saved( $id, $payload );
			return $id;
		}

		$payload['created_at'] = $now;
		$new_id                = $this->db->insert( 'ig_accounts', $payload );
		if ( $new_id > 0 ) {
			$this->after_account_saved( $new_id, $payload );
		}
		return $new_id;
	}

	/**
	 * Give a freshly saved account the pieces it cannot work without: its own webhook tokens, and
	 * a trial clock when it is running on the shared key.
	 *
	 * @param array<string,mixed> $payload
	 */
	private function after_account_saved( int $id, array $payload ): void {
		$account = $this->account( $id );
		if ( ! $account ) {
			return;
		}

		$this->credentials->webhook_token( $account, AccountCredentials::SERVICE_MANUS );
		$this->credentials->webhook_token( $account, AccountCredentials::SERVICE_MANYCHAT );

		if ( AccountCredentials::MODE_TRIAL === $this->credentials->mode( $account ) ) {
			$this->credentials->start_trial( $id );
		}
	}

	public function delete_account( int $id ): bool {
		return $this->db->delete( 'ig_accounts', [ 'id' => $id ] ) > 0;
	}

	// -------------------------------------------------------------- content

	/** @return array<string,mixed>|null */
	public function content( int $id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_content' ) . ' WHERE id = %d AND tenant_id = %d', $id, igbz()->tenancy()->id() );
	}

	/**
	 * @param array{account_id?:int,tenant_id?:int,status?:string,limit?:int,offset?:int} $args
	 * @return array<int,array<string,mixed>>
	 */
	public function contents( array $args = [] ): array {
		$where  = [ '1=1' ];
		$params = [];
		foreach ( [ 'account_id', 'tenant_id' ] as $column ) {
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
			'SELECT * FROM ' . $this->db->table( 'ig_content' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d',
			...$params
		);
	}

	/** @param array<string,mixed> $data */
	public function save_content( array $data, int $id = 0 ): int {
		$now     = current_time( 'mysql', true );
		$payload = [
			'tenant_id'     => (int) ( $data['tenant_id'] ?? 0 ),
			'account_id'    => (int) ( $data['account_id'] ?? 0 ),
			'kind'          => $this->supports( (string) ( $data['kind'] ?? '' ) ) ? (string) $data['kind'] : self::KIND_POST,
			'title'         => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'brief'         => wp_json_encode( (array) ( $data['brief'] ?? [] ) ),
			'caption'       => (string) ( $data['caption'] ?? '' ),
			'hashtags'      => wp_json_encode( (array) ( $data['hashtags'] ?? [] ) ),
			'media'         => wp_json_encode( (array) ( $data['media'] ?? [] ) ),
			'product_id'    => (int) ( $data['product_id'] ?? 0 ),
			'funnel_id'     => (int) ( $data['funnel_id'] ?? 0 ),
			'provider'      => 'manus',
			'status'        => (string) ( $data['status'] ?? self::STATUS_DRAFT ),
			'scheduled_for' => ! empty( $data['scheduled_for'] ) ? (string) $data['scheduled_for'] : null,
			'updated_at'    => $now,
		];

		if ( $id > 0 ) {
			$this->db->update( 'ig_content', $payload, [ 'id' => $id ] );
			return $id;
		}
		$payload['created_at'] = $now;
		return $this->db->insert( 'ig_content', $payload );
	}

	public function delete_content( int $id ): bool {
		return $this->db->delete( 'ig_content', [ 'id' => $id ] ) > 0;
	}

	// ------------------------------------------------------- generator side

	public function research_trends( array $account, string $topic = '' ): string {
		return $this->dispatch(
			$this->prompts->trend_research( $account, $topic ),
			$account,
			sprintf( 'Trend research: @%s', (string) ( $account['username'] ?? '' ) )
		);
	}

	public function design_graphic( array $account, array $brief ): string {
		return $this->dispatch(
			$this->prompts->graphic_design( $account, $brief ),
			$account,
			sprintf( 'Design: %s', (string) ( $brief['subject'] ?? '' ) ),
			[]
		);
	}

	public function produce_reel( array $account, array $brief ): string {
		return $this->dispatch(
			$this->prompts->reel( $account, $brief ),
			$account,
			sprintf( 'Reel: %s', (string) ( $brief['subject'] ?? '' ) )
		);
	}

	public function write_caption( array $account, array $brief ): string {
		return $this->dispatch(
			$this->prompts->caption( $account, $brief ),
			$account,
			sprintf( 'Caption: %s', (string) ( $brief['subject'] ?? '' ) )
		);
	}

	// ------------------------------------------------ product registration

	/**
	 * Grade a product photograph. Step 3 of the registration flow.
	 *
	 * @param array<string,mixed> $account
	 */
	public function grade_photo( array $account, string $image_url, string $hint = '' ): string {
		return $this->dispatch(
			$this->prompts->photo_quality( $account, $hint ),
			$account,
			__( 'Product photo check', 'igbz-suite' ),
			[],
			[
				'attachments'              => [ $image_url ],
				'structured_output_schema' => $this->prompts->photo_quality_schema(),
				'hide_in_task_list'        => true,
			]
		);
	}

	/**
	 * Turn a shop photo into a commercial product image. Step 4.
	 *
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $brief
	 */
	public function prepare_product_image( array $account, string $image_url, array $brief = [] ): string {
		return $this->dispatch(
			$this->prompts->product_image( $account, $brief ),
			$account,
			sprintf( /* translators: %s: product name */ __( 'Product image: %s', 'igbz-suite' ), (string) ( $brief['product'] ?? '' ) ),
			[],
			[ 'attachments' => [ $image_url ] ]
		);
	}

	/**
	 * Write the listing copy, optionally translated. Step 7.
	 *
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $brief
	 */
	public function write_product_copy( array $account, array $brief, string $image_url = '' ): string {
		$languages = array_map( 'strval', (array) ( $brief['languages'] ?? [] ) );

		return $this->dispatch(
			$this->prompts->product_copy( $account, $brief ),
			$account,
			sprintf( /* translators: %s: product name */ __( 'Product listing: %s', 'igbz-suite' ), (string) ( $brief['product'] ?? '' ) ),
			[],
			[
				'attachments'              => '' !== $image_url ? [ $image_url ] : [],
				'structured_output_schema' => $this->prompts->product_copy_schema( $languages ),
			]
		);
	}

	/**
	 * Produce the product video. Step 10.
	 *
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $brief
	 */
	public function produce_product_video( array $account, array $brief, string $image_url = '' ): string {
		return $this->dispatch(
			$this->prompts->product_video( $account, $brief ),
			$account,
			sprintf( /* translators: %s: product code */ __( 'Product video: %s', 'igbz-suite' ), (string) ( $brief['code'] ?? '' ) ),
			[],
			[ 'attachments' => '' !== $image_url ? [ $image_url ] : [] ]
		);
	}

	/**
	 * Stamp the code onto the image and write the comment-to-DM caption. Step 11.
	 *
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $brief
	 */
	public function finish_product_post( array $account, array $brief, string $image_url = '' ): string {
		return $this->dispatch(
			$this->prompts->product_post( $account, $brief ),
			$account,
			sprintf( /* translators: %s: product code */ __( 'Instagram post: %s', 'igbz-suite' ), (string) ( $brief['code'] ?? '' ) ),
			[],
			[ 'attachments' => '' !== $image_url ? [ $image_url ] : [] ]
		);
	}

	/**
	 * Transcribe a voice note through Manus. The fallback speech-to-text path.
	 *
	 * @param array<string,mixed> $account
	 */
	public function transcribe_audio( array $account, string $audio_url, string $language = '' ): string {
		return $this->dispatch(
			$this->prompts->transcription( $account, $language ),
			$account,
			__( 'Voice note transcription', 'igbz-suite' ),
			[],
			[
				'attachments'              => [ $audio_url ],
				'structured_output_schema' => [
					'type'       => 'object',
					'properties' => [ 'text' => [ 'type' => 'string' ] ],
					'required'   => [ 'text' ],
				],
				'hide_in_task_list'        => true,
			]
		);
	}

	/**
	 * @param array<string,mixed> $account
	 * @param array<int,string>   $connectors
	 * @param array<string,mixed> $options    Extra task options: attachments, structured_output_schema, …
	 */
	private function dispatch( string $prompt, array $account, string $title, array $connectors = [], array $options = [] ): string {
		if ( ! $this->account_is_configured( $account ) ) {
			$reason = $this->credentials->trial_blocked_reason( $account );
			$this->logger->error(
				'manus',
				'Task creation skipped: no usable Manus key for this account',
				[ 'account_id' => (int) ( $account['id'] ?? 0 ), 'reason' => $reason ]
			);
			return '';
		}

		// Claim the trial task BEFORE calling Manus. The quota is a single request by default, so
		// checking first and counting afterwards would let two concurrent cron ticks spend the
		// same one. claim_trial_task() settles that in the database and returns false to the loser.
		if ( ! $this->credentials->claim_trial_task( $account ) ) {
			$this->logger->warning(
				'manus',
				'Task creation skipped: the free trial is used up',
				[ 'account_id' => (int) ( $account['id'] ?? 0 ) ]
			);
			return '';
		}

		// FX credit gate: only for accounts on their own keys, only when the FX module is
		// enabled, and never a queue — enough credit and the task goes out now, otherwise it is
		// refused on the spot so the tenant tops up instead of waiting.
		$meter  = igbz()->has( 'fx.meter' ) ? igbz()->get( 'fx.meter' ) : null;
		$fx_ref = '';
		if ( $meter && AccountCredentials::MODE_OWN === ( $account['credential_mode'] ?? AccountCredentials::MODE_OWN ) ) {
			$fx_ref = 'manus-task:' . bin2hex( random_bytes( 6 ) );
			$spend  = $meter->consume( (int) ( $account['tenant_id'] ?? 0 ), 'manus_task', $fx_ref );
			if ( ! $spend['ok'] ) {
				$this->logger->warning(
					'manus',
					'Task creation skipped: insufficient FX credit',
					[ 'account_id' => (int) ( $account['id'] ?? 0 ), 'error' => $spend['error'] ]
				);
				return '';
			}
		}

		$result = $this->client_for( $account )->create_task(
			$prompt,
			array_filter(
				array_merge(
					$options,
					[
						'project_id' => (string) ( $account['manus_project_id'] ?? '' ),
						'title'      => $title,
						'connectors' => $connectors,
					]
				),
				// array_filter drops empty attachment lists and empty schemas, which
				// create_task() would otherwise have to special-case one by one.
				static fn ( $value ): bool => ! ( is_array( $value ) && ! $value ) && '' !== $value
			)
		);

		if ( ! $result['ok'] ) {
			// Manus never took the job, so the tenant should not lose their one free request.
			$this->credentials->release_trial_task( $account );
			if ( '' !== $fx_ref && $meter ) {
				$meter->release( (int) ( $account['tenant_id'] ?? 0 ), 'manus_task', $fx_ref );
			}
			$this->logger->error( 'manus', 'Task creation failed', [ 'title' => $title, 'error' => $result['error'] ] );
			return '';
		}

		$this->logger->info( 'manus', 'Task created', [ 'task_id' => $result['task_id'], 'title' => $title ] );
		return $result['task_id'];
	}

	/**
	 * Kick off the full creative pipeline for one content row.
	 */
	public function generate( int $content_id ): bool {
		$content = $this->content( $content_id );
		if ( ! $content ) {
			return false;
		}
		$account = $this->account( (int) $content['account_id'] );
		if ( ! $account ) {
			return false;
		}

		$brief = json_decode( (string) $content['brief'], true );
		$brief = is_array( $brief ) ? $brief : [];
		$brief['subject'] = $brief['subject'] ?? (string) $content['title'];
		if ( (int) $content['product_id'] > 0 ) {
			$brief['product_url'] = get_permalink( (int) $content['product_id'] ) ?: '';
		}
		if ( (int) $content['funnel_id'] > 0 ) {
			$keyword = $this->db->scalar(
				'SELECT keyword FROM ' . $this->db->table( 'ig_funnels' ) . ' WHERE id = %d',
				(int) $content['funnel_id']
			);
			if ( $keyword ) {
				$brief['keyword'] = (string) $keyword;
			}
		}

		$task_id = match ( (string) $content['kind'] ) {
			self::KIND_REEL, self::KIND_STORY => $this->produce_reel( $account, $brief ),
			self::KIND_CAROUSEL               => $this->design_graphic( $account, $brief + [ 'slides' => (int) ( $brief['slides'] ?? 5 ) ] ),
			default                           => $this->design_graphic( $account, $brief ),
		};

		if ( '' === $task_id ) {
			$this->fail( $content_id, __( 'Manus rejected the generation task.', 'igbz-suite' ) );
			return false;
		}

		$this->db->update(
			'ig_content',
			[
				'provider_task_id' => $task_id,
				'provider_status'  => ManusClient::STATUS_RUNNING,
				'status'           => self::STATUS_GENERATING,
				'last_error'       => '',
				'updated_at'       => current_time( 'mysql', true ),
			],
			[ 'id' => $content_id ]
		);

		do_action( 'igbz_ig_content_generating', $content_id, $task_id );
		return true;
	}

	/**
	 * Poll a generating row and absorb the produced assets.
	 */
	public function sync_generation( int $content_id ): string {
		$content = $this->content( $content_id );
		if ( ! $content || '' === (string) $content['provider_task_id'] ) {
			return self::STATUS_FAILED;
		}

		$account = $this->account( (int) $content['account_id'] );
		if ( ! $account ) {
			return self::STATUS_FAILED;
		}

		$state = $this->client_for( $account )->task_state( (string) $content['provider_task_id'] );

		if ( ManusClient::STATUS_ERROR === $state['status'] ) {
			$this->fail( $content_id, __( 'The Manus task ended with an error.', 'igbz-suite' ) );
			return self::STATUS_FAILED;
		}
		if ( ManusClient::STATUS_STOPPED !== $state['status'] ) {
			return self::STATUS_GENERATING;
		}
		if ( 'ask' === $state['stop_reason'] ) {
			$this->fail( $content_id, __( 'Manus is waiting for a human answer on this task.', 'igbz-suite' ) );
			return self::STATUS_FAILED;
		}

		$this->absorb_result( $content_id, $state );
		return self::STATUS_READY;
	}

	/**
	 * @param array{status:string,stop_reason:string,attachments:array<int,array<string,mixed>>,text:string} $state
	 */
	public function absorb_result( int $content_id, array $state ): void {
		$content = $this->content( $content_id );
		if ( ! $content ) {
			return;
		}

		$media = [];
		foreach ( $state['attachments'] as $attachment ) {
			$name = strtolower( (string) $attachment['file_name'] );
			if ( str_ends_with( $name, '.json' ) ) {
				continue;
			}
			$media[] = [
				'url'  => (string) $attachment['url'],
				'name' => (string) $attachment['file_name'],
				'type' => str_ends_with( $name, '.mp4' ) ? 'video' : 'image',
			];
		}

		$parsed   = $this->parse_json_block( $state['text'] );
		$caption  = (string) ( $parsed['caption'] ?? $content['caption'] );
		$hashtags = (array) ( $parsed['hashtags'] ?? json_decode( (string) $content['hashtags'], true ) ?: [] );

		$this->db->update(
			'ig_content',
			[
				'media'           => wp_json_encode( $media ?: json_decode( (string) $content['media'], true ) ?: [] ),
				'caption'         => $caption,
				'hashtags'        => wp_json_encode( array_values( array_map( 'strval', $hashtags ) ) ),
				'provider_status' => ManusClient::STATUS_STOPPED,
				'status'          => self::STATUS_READY,
				'updated_at'      => current_time( 'mysql', true ),
			],
			[ 'id' => $content_id ]
		);

		do_action( 'igbz_ig_content_ready', $content_id, $media );
	}

	/** @return array<string,mixed> */
	public function parse_json_block( string $text ): array {
		if ( '' === $text ) {
			return [];
		}
		if ( preg_match( '/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $matches ) ) {
			$text = $matches[1];
		} elseif ( preg_match( '/\{.*\}/s', $text, $matches ) ) {
			$text = $matches[0];
		}
		$decoded = json_decode( $text, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	// ------------------------------------------------------- publisher side

	public function publish( array $content ): PublishResult {
		$account = $this->account( (int) $content['account_id'] );
		if ( ! $account ) {
			return PublishResult::failure( __( 'The Instagram account no longer exists.', 'igbz-suite' ) );
		}

		$task_id = $this->dispatch(
			$this->prompts->publish( $account, $content, 0 ),
			$account,
			sprintf( 'Publish %s: %s', (string) $content['kind'], (string) $content['title'] )
		);

		return '' === $task_id
			? PublishResult::failure( __( 'Manus rejected the publish task.', 'igbz-suite' ) )
			: PublishResult::queued( $task_id );
	}

	public function schedule( array $content, int $timestamp ): PublishResult {
		$account = $this->account( (int) $content['account_id'] );
		if ( ! $account ) {
			return PublishResult::failure( __( 'The Instagram account no longer exists.', 'igbz-suite' ) );
		}

		$task_id = $this->dispatch(
			$this->prompts->publish( $account, $content, $timestamp ),
			$account,
			sprintf( 'Schedule %s: %s', (string) $content['kind'], (string) $content['title'] )
		);

		return '' === $task_id
			? PublishResult::failure( __( 'Manus rejected the scheduling task.', 'igbz-suite' ) )
			: PublishResult::scheduled( $task_id );
	}

	public function mark_published( int $content_id, string $permalink ): void {
		$permalink = esc_url_raw( $permalink );

		// Derive the post's shortcode now, while the URL is in hand. This is the only moment the
		// system learns the identity of a public post: we never call the Graph API, so no media id
		// is ever handed to us, and a permalink that arrives later by hand goes through the same
		// path. Without it a funnel could only be scoped to a post by typing an opaque id from
		// memory. An unparseable URL stores '' -- unknown, never a wildcard.
		$this->db->update(
			'ig_content',
			[
				'status'       => self::STATUS_PUBLISHED,
				'permalink'    => $permalink,
				'ig_shortcode' => PostIdentity::from_permalink( $permalink ),
				'published_at' => current_time( 'mysql', true ),
				'updated_at'   => current_time( 'mysql', true ),
			],
			[ 'id' => $content_id ]
		);

		// The Graph API answered a publish call with the media id, so the post was either created
		// or it was not. Manus reports through a task instead, and a task can finish successfully
		// while failing to hand back the post URL. That leaves a row that says "published" with
		// nothing to link to, and no way to tell from the outside whether the post actually exists
		// or the task just stopped early. Surface it instead of storing the ambiguity silently --
		// the row stays published, because it very likely is, but somebody has to eyeball it.
		if ( '' === $permalink ) {
			$this->logger->warning(
				'manus',
				'Published without a permalink: the task returned no post URL, so the result is unverified',
				[ 'content_id' => $content_id ]
			);
			do_action( 'igbz_ig_content_published_unverified', $content_id );
		}

		do_action( 'igbz_ig_content_published', $content_id, $permalink );
	}

	/**
	 * Rows that were published but came back without a post URL.
	 *
	 * Derived rather than flagged on the row: a permalink can be filled in later (by hand, or by a
	 * retried confirmation), and a stored flag would then be a lie nobody clears.
	 */
	public function unverified_publish_count( int $account_id = 0 ): int {
		$sql  = 'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_content' ) . " WHERE status = %s AND permalink = ''";
		$args = [ self::STATUS_PUBLISHED ];

		if ( $account_id > 0 ) {
			$sql   .= ' AND account_id = %d';
			$args[] = $account_id;
		}

		return (int) $this->db->scalar( $sql, ...$args );
	}

	public function fail( int $content_id, string $error ): void {
		$this->db->query(
			'UPDATE ' . $this->db->table( 'ig_content' ) . '
			 SET status = %s, last_error = %s, retry_count = retry_count + 1, updated_at = %s
			 WHERE id = %d',
			self::STATUS_FAILED,
			mb_substr( $error, 0, 500 ),
			current_time( 'mysql', true ),
			$content_id
		);
		$this->logger->error( 'manus', 'Content failed', [ 'content_id' => $content_id, 'error' => $error ] );
		do_action( 'igbz_ig_content_failed', $content_id, $error );
	}

	/**
	 * @param array<string,mixed> $account
	 * @return array{status:string,messages:array<int,mixed>,attachments:array<int,array<string,mixed>>,output:array<string,mixed>}
	 */
	public function task_state( string $task_id, array $account = [] ): array {
		$state = $this->client_for( $account )->task_state( $task_id );
		return [
			'status'      => $state['status'],
			'messages'    => [],
			'attachments' => $state['attachments'],
			'output'      => $this->parse_json_block( $state['text'] ),
		];
	}
}
