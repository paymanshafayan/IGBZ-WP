<?php
/**
 * Phase 52 — the rebuilt 13-step product registration.
 *
 * Covers: start validation + client-token idempotency, tenant isolation, the
 * honest agent seam (AI stages refuse with `agent_not_configured` when no agent
 * is registered), the full manual flow (the human-in-the-loop fallback), the
 * voice transcription branch, product-creation idempotency (a crash between the
 * product write and the status flip), failure/resume from the exact checkpoint,
 * the failure/compensation path (drafts deleted, live products never touched),
 * and the human approval/rejection gates.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Instagram\Contracts\IntakeAgentInterface;
use IGBZ\Suite\Modules\Instagram\Services\ProductRegistrationService;
use IGBZ\Suite\Modules\Instagram\Services\WooProductFactory;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

/**
 * In-memory engine for the registration table: the service issues real
 * SELECT/INSERT/UPDATE statements, so the rows live in state and the service's
 * SQL runs against them (the house pattern from ZernioInboxDb).
 */
final class ProductRegDb extends wpdb {

	/** @var array<int,array<string,mixed>> id => registration row */
	public array $rows = [];

	/** @var array<int,array<string,mixed>> id => ig_content row */
	public array $content = [];

	private int $auto_id = 0;

	private int $auto_content_id = 0;

	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->last_write = [ 'table' => $table, 'data' => $data, 'formats' => $format, 'guessed' => null === $format ];
		$this->writes[]    = $this->last_write;
		$this->queries[]   = 'INSERT INTO ' . $table;

		if ( str_ends_with( $table, 'ig_product_registrations' ) ) {
			$id         = (int) ( $data['id'] ?? 0 );
			$id         = $id > 0 ? $id : ( ++$this->auto_id );
			$data['id'] = $id;
			$this->rows[ $id ] = $data;
			$this->insert_id   = $id;

			return 1;
		}

		if ( str_ends_with( $table, 'ig_content' ) ) {
			$id              = (int) ( $data['id'] ?? 0 );
			$id              = $id > 0 ? $id : ( ++$this->auto_content_id );
			$data['id']      = $id;
			$this->content[ $id ] = $data;
			$this->insert_id   = $id;

			return 1;
		}

		return false;
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->last_write = [ 'table' => $table, 'data' => $data, 'formats' => $format, 'guessed' => null === $format ];
		$this->writes[]    = $this->last_write;
		$this->queries[]   = 'UPDATE ' . $table;

		$id = (int) ( $where['id'] ?? 0 );
		if ( $id <= 0 || ! isset( $this->rows[ $id ] ) ) {
			return 0;
		}

		if ( isset( $where['tenant_id'] ) && (string) $this->rows[ $id ]['tenant_id'] !== (string) $where['tenant_id'] ) {
			return 0; // tenant guard: a foreign tenant's row is never written
		}

		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );

		return 1;
	}

	public function get_row( string $sql, $output = null ) {
		$rows = $this->select_rows( $sql );

		return $rows ? $rows[0] : null;
	}

	public function get_results( string $sql, $output = null ) {
		return $this->select_rows( $sql );
	}

	/**
	 * A mini WHERE evaluator: enough for the service's equality-only queries
	 * (`id = '5' AND tenant_id = '1'` and friends) — never real SQL execution.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function select_rows( string $sql ): array {
		$this->queries[] = $sql;

		preg_match_all( "/(\w+) = '([^']*)'/", $sql, $m, PREG_SET_ORDER );
		$conds = [];
		foreach ( $m as $c ) {
			$conds[ $c[1] ] = $c[2];
		}

		$out = [];
		foreach ( $this->rows as $row ) {
			$ok = true;
			foreach ( $conds as $col => $val ) {
				if ( ! array_key_exists( $col, $row ) || (string) $row[ $col ] !== (string) $val ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				$out[] = $row;
			}
		}

		return $out;
	}
}

/** A scripted agent: real interface, deterministic task ids, call recording. */
final class ScriptedIntakeAgent implements IntakeAgentInterface {

	/** @var array<int,array<int,mixed>> */
	public array $calls = [];

	public function account( int $account_id ): ?array {
		$this->calls[] = [ 'account', $account_id ];

		return [ 'platform' => 'instagram', 'platform_account_id' => 'zernio-acc-' . $account_id, 'username' => 'shop' ];
	}

	public function accounts( int $tenant_id = 0, bool $active_only = true ): array {
		return [];
	}

	public function parse_json_block( string $text ): array {
		$decoded = json_decode( trim( $text ), true );

		return is_array( $decoded ) ? $decoded : [];
	}

	public function grade_photo( array $account, string $image_url, string $hint = '' ): string {
		$this->calls[] = [ 'grade', $image_url ];

		return 'task-grade';
	}

	public function prepare_product_image( array $account, string $image_url, array $brief = [] ): string {
		$this->calls[] = [ 'image', $image_url ];

		return 'task-image';
	}

	public function transcribe_audio( array $account, string $audio_url, string $language = '' ): string {
		$this->calls[] = [ 'transcribe', $audio_url ];

		return 'task-transcribe';
	}

	public function write_product_copy( array $account, array $brief, string $image_url = '' ): string {
		$this->calls[] = [ 'copy', $image_url ];

		return 'task-copy';
	}

	public function produce_product_video( array $account, array $brief, string $image_url = '' ): string {
		$this->calls[] = [ 'video', $image_url ];

		return 'task-video';
	}

	public function finish_product_post( array $account, array $brief, string $image_url = '' ): string {
		$this->calls[] = [ 'post', $image_url ];

		return 'task-post';
	}
}

/** The recording Woo factory: every draft it "creates" and deletes is asserted. */
final class RecordingWooFactory implements WooProductFactory {

	/** @var array<int,array<string,mixed>> */
	public array $created = [];

	/** @var int[] */
	public array $deleted = [];

	public bool $fail_creation = false;

	public int $next_id = 100;

	public function create_draft( array $copy ): int {
		if ( $this->fail_creation ) {
			return 0;
		}
		$this->next_id += 10;
		$this->created[] = [ 'id' => $this->next_id, 'copy' => $copy ];

		return $this->next_id;
	}

	public function delete_draft( int $product_id ): bool {
		$this->deleted[] = $product_id;

		return true;
	}

	public function is_available(): bool {
		return true;
	}
}

final class ProductRegistrationTest extends TestCase {

	/** @var ProductRegDb */
	private $db;

	/** @var RecordingWooFactory */
	private $factory;

	/** @var ScriptedIntakeAgent */
	private $agent;

	/** @var Logger */
	private $logger;

	/** @var Db */
	private $raw;

	public function run(): void {
		$this->start_validates_input_and_is_idempotent_on_the_client_token();
		$this->registrations_are_tenant_isolated();
		$this->ai_stages_refuse_honestly_without_an_agent();
		$this->full_manual_flow_reaches_human_approval();
		$this->voice_flow_passes_through_transcription();
		$this->product_creation_is_idempotent_after_a_crash();
		$this->failed_step_retries_from_its_exact_checkpoint();
		$this->compensation_deletes_draft_products_but_never_live_ones();
		$this->approval_and_rejection_only_from_awaiting_approval();
		$this->invalid_transitions_are_refused();
		$this->agent_driven_flow_records_stage_tasks();
		$this->woocommerce_unavailable_fails_the_commerce_step_cleanly();
	}

	private function fresh(): void {
		$this->db      = new ProductRegDb();
		$GLOBALS['wpdb'] = $this->db;
		$this->factory = new RecordingWooFactory();
		$this->agent   = new ScriptedIntakeAgent();
		$this->logger  = new Logger( igbz_test_reset_settings() );
		$this->raw     = new Db();
	}

	private function service( bool $with_agent = false, ?WooProductFactory $products = null ): ProductRegistrationService {
		return new ProductRegistrationService(
			$this->raw,
			$this->logger,
			null !== $products ? $products : $this->factory,
			$with_agent ? $this->agent : null
		);
	}

	/** @return array<string,mixed> */
	private function start_text( ProductRegistrationService $svc, int $tenant = 1, string $token = 'tok-a' ): array {
		return $svc->start(
			$tenant,
			[
				'client_token' => $token,
				'input_type'   => 'text',
				'image_url'    => 'https://cdn.example.com/photo-1.jpg',
				'account_id'   => 7,
			]
		);
	}

	/** Drive a registration to `writing` through the manual path. @return int the id */
	private function to_writing( ProductRegistrationService $svc, string $token = 'tok-a' ): int {
		$id  = $this->start_text( $svc, 1, $token )['id'];
		$svc->manual_grade( 1, $id, true );
		$svc->manual_prepared_image( 1, $id, 'https://cdn.example.com/prepared.jpg' );
		$svc->mark_edited( 1, $id );
		$svc->start_describing( 1, $id );
		$svc->manual_copy( 1, $id, [ 'title' => 'کفش کتانی', 'description' => 'خیلی راحت', 'price' => '450000' ] );

		return $id;
	}

	private function start_validates_input_and_is_idempotent_on_the_client_token(): void {
		$this->fresh();
		$svc = $this->service();

		// No image and no voice note: refused before any row exists.
		$r = $svc->start( 1, [ 'client_token' => 'tok-x', 'input_type' => 'text' ] );
		$this->assert_false( (bool) $r['ok'], 'A start without media must be refused' );
		$this->assert_same( 'image_url_required', $r['error'], 'the error code must match' );

		// A voice start without a voice note is also refused.
		$r = $svc->start( 1, [ 'client_token' => 'tok-y', 'input_type' => 'voice' ] );
		$this->assert_false( (bool) $r['ok'], 'A voice start without a voice_url must be refused' );
		$this->assert_same( 'voice_url_required', $r['error'], 'the error code must match' );
		$this->assert_same( 0, count( $this->db->rows ), 'Refused starts must not write rows' );

		$first = $this->start_text( $svc, 1, 'tok-a' );
		$again = $this->start_text( $svc, 1, 'tok-a' );

		$this->assert_true( (bool) $first['ok'], 'A valid start must succeed' );
		$this->assert_same( 'uploaded', $first['status'], 'the stored value must match' );
		$this->assert_true( (bool) $again['ok'], 'A retried start must succeed (idempotent), not error' );
		$this->assert_same( 'duplicate', $again['status'], 'A retried start is reported as duplicate' );
		$this->assert_same( $first['id'], $again['id'], 'The duplicate returns the SAME registration id' );

		$rows = $this->raw->results( 'SELECT id FROM ' . $this->raw->table( 'ig_product_registrations' ) . " WHERE client_token = 'tok-a'" );
		$this->assert_same( 1, count( $rows ), 'Exactly one row must exist for the client token' );
	}

	private function registrations_are_tenant_isolated(): void {
		$this->fresh();
		$svc   = $this->service();
		$start = $this->start_text( $svc, 1, 'tok-t' );

		$other = $svc->get( 2, $start['id'] );
		$this->assert_true( null === $other, 'Another tenant must not see this registration' );

		$foreign = $svc->mark_edited( 2, $start['id'] );
		$this->assert_false( (bool) $foreign['ok'], 'Another tenant must not be able to advance this registration' );
		$this->assert_same( 'not_found', $foreign['error'], 'the stored value must match' );

		// A direct UPDATE with a foreign tenant id writes nothing.
		$affected = $this->raw->update( 'ig_product_registrations', [ 'status' => 'approved' ], [ 'id' => $start['id'], 'tenant_id' => 2 ] );
		$this->assert_same( 0, $affected, 'A foreign-tenant update must affect zero rows' );
	}

	private function ai_stages_refuse_honestly_without_an_agent(): void {
		$this->fresh();
		$svc   = $this->service( false );
		$start = $this->start_text( $svc );
		$id    = $start['id'];

		$r = $svc->start_grading( 1, $id );
		$this->assert_false( (bool) $r['ok'], 'Grading must be refused without an agent' );
		$this->assert_same( 'agent_not_configured', $r['error'], 'The refusal reason must be explicit, not a silent success' );
		$this->assert_same( 'uploaded', $r['status'], 'The row must NOT move to grading when the agent is missing' );

		// The manual fallback is the honest path forward.
		$r = $svc->manual_grade( 1, $id, true );
		$this->assert_true( (bool) $r['ok'], 'the step must report ok' );
		$this->assert_same( 'graded', $r['status'], 'the checkpoint status must match' );

		$r = $svc->start_image( 1, $id );
		$this->assert_false( (bool) $r['ok'], 'Image prep must be refused without an agent' );
		$this->assert_same( 'agent_not_configured', $r['error'], 'the error code must match' );
	}

	private function full_manual_flow_reaches_human_approval(): void {
		$this->fresh();
		$svc   = $this->service( false );
		$start = $this->start_text( $svc );
		$id    = $start['id'];

		$steps = [
			'graded'        => $svc->manual_grade( 1, $id, true ),
			'ready_to_edit' => $svc->manual_prepared_image( 1, $id, 'https://cdn.example.com/prepared.jpg' ),
			'edited'        => $svc->mark_edited( 1, $id ),
			'describing'    => $svc->start_describing( 1, $id ),
			'writing'       => $svc->manual_copy( 1, $id, [ 'title' => 'کفش کتانی', 'description' => 'خیلی راحت', 'price' => '450000' ] ),
		];
		foreach ( $steps as $expected => $r ) {
			$this->assert_true( (bool) $r['ok'], "Step to $expected must succeed" );
			$this->assert_same( $expected, $r['status'], "After the step the checkpoint must be $expected" );
		}

		// The commerce step creates the draft product and mints the public code.
		$r = $svc->create_product( 1, $id );
		$this->assert_true( (bool) $r['ok'], 'Product creation must succeed with a valid copy' );
		$this->assert_same( 'product_created', $r['status'], 'the checkpoint status must match' );
		$this->assert_same( 1, count( $this->factory->created ), 'Exactly one product must be created' );

		$row = $svc->get( 1, $id );
		$this->assert_same( 110, (int) $row['product_id'], 'The product id is recorded on the registration' );
		$this->assert_same( '110', (string) $row['public_code'], 'The public code is the product id' );

		// The kind checkpoint, then composing (manual, no agent), then the human gate.
		$this->assert_true( (bool) $svc->await_kind( 1, $id )['ok'], 'the kind checkpoint must be reachable after product creation' );
		$r = $svc->choose_kind( 1, $id, 'image' );
		$this->assert_false( (bool) $r['ok'], 'Composing must be refused without an agent' );
		$this->assert_same( 'awaiting_kind', $r['status'], 'The row stays at awaiting_kind when the agent is missing' );

		$r = $svc->manual_composed( 1, $id, 'کفش جدید — کد ترفند: 110' );
		$this->assert_true( (bool) $r['ok'], 'the step must report ok' );
		$this->assert_same( 'awaiting_approval', $r['status'], 'the checkpoint status must match' );

		$r = $svc->approve( 1, $id, 42 );
		$this->assert_true( (bool) $r['ok'], 'The human approval must succeed' );
		$this->assert_same( 'approved', $r['status'], 'the checkpoint status must match' );

		$row = $svc->get( 1, $id );
		$this->assert_same( 42, (int) $row['approved_by'], 'The approving user is recorded' );
		$this->assert_true( null !== $row['approved_at'] && '' !== (string) $row['approved_at'], 'The approval timestamp is recorded' );
		$this->assert_same( 'image', (string) $row['kind'], 'The manual compose defaults the kind to image' );

		// Approval materializes the publishable artifact: one draft content row.
		$this->assert_same( 1, count( $this->db->content ), 'Approval must create exactly one content row' );
		$content = $this->db->content[1];
		$this->assert_same( 1, (int) $content['tenant_id'], 'The content row is tenant-scoped' );
		$this->assert_same( 110, (int) $content['product_id'], 'The content row points at the draft product' );
		$this->assert_same( 'zernio', (string) $content['provider'], 'The provider is zernio for the phase-53 publisher' );
		$this->assert_same( 'draft', (string) $content['status'], 'The content row is a draft: nothing is published by approval' );
		$this->assert_same( 'کفش جدید — کد ترفند: 110', (string) $content['caption'], 'The composed caption lands on the content row' );
		$this->assert_same( (int) $row['content_id'], 1, 'The registration records its content row id' );

		// Terminal: nothing is re-creatable or re-approvable.
		$this->assert_false( (bool) $svc->create_product( 1, $id )['ok'], 'An approved registration must not create another product' );
		$this->assert_false( (bool) $svc->approve( 1, $id, 42 )['ok'], 'An approved registration cannot be approved twice' );
	}

	private function voice_flow_passes_through_transcription(): void {
		$this->fresh();
		$svc = $this->service( false );
		$r   = $svc->start(
			1,
			[
				'client_token' => 'tok-voice',
				'input_type'   => 'voice',
				'voice_url'    => 'https://cdn.example.com/note-1.mp3',
			]
		);
		$this->assert_true( (bool) $r['ok'], 'the step must report ok' );
		$id = $r['id'];

		$svc->manual_grade( 1, $id, true );
		$svc->manual_prepared_image( 1, $id, 'https://cdn.example.com/voice-prepared.jpg' );
		$svc->mark_edited( 1, $id );

		// Voice input with no transcription yet must land on transcribing.
		$r = $svc->start_describing( 1, $id );
		$this->assert_true( (bool) $r['ok'], 'the step must report ok' );
		$this->assert_same( 'transcribing', $r['status'], 'Voice input goes through the transcribing checkpoint' );

		$r = $svc->manual_transcription( 1, $id, 'کفش جدید سایز 42' );
		$this->assert_true( (bool) $r['ok'], 'the step must report ok' );
		$this->assert_same( 'describing', $r['status'], 'The transcription lands on describing' );

		$row = $svc->get( 1, $id );
		$this->assert_same( 'کفش جدید سایز 42', (string) $row['transcription'], 'the stored transcription must match' );
	}

	private function product_creation_is_idempotent_after_a_crash(): void {
		$this->fresh();
		$svc = $this->service( false );
		$id  = $this->to_writing( $svc );

		// Simulate a crash: the product row was written but the status flip never happened.
		$this->raw->update( 'ig_product_registrations', [ 'product_id' => 777 ], [ 'id' => $id, 'tenant_id' => 1 ] );

		$r = $svc->create_product( 1, $id );
		$this->assert_true( (bool) $r['ok'], 'the step must report ok' );
		$this->assert_same( 'product_created', $r['status'], 'the checkpoint status must match' );
		$this->assert_same( 0, count( $this->factory->created ), 'A crash-recovered product creation must NOT create a second product' );

		$row = $svc->get( 1, $id );
		$this->assert_same( 777, (int) $row['product_id'], 'The original product id survives the crash recovery' );
	}

	private function failed_step_retries_from_its_exact_checkpoint(): void {
		$this->fresh();
		$svc = $this->service( false );
		$id  = $this->to_writing( $svc );

		// A copy without a price fails validation; the row lands in failed.
		$r = $svc->complete_writing( 1, $id, [ 'title' => 'بی قیمت' ] );
		$this->assert_false( (bool) $r['ok'], 'An invalid copy must fail the step' );
		$this->assert_same( 'failed', $r['status'], 'the checkpoint status must match' );
		$this->assert_same( 'copy_price_required', $r['error'], 'the error code must match' );

		$row = $svc->get( 1, $id );
		$this->assert_same( 'writing', (string) $row['failed_from'], 'failed_from must remember the exact checkpoint' );
		$this->assert_same( 1, (int) $row['attempts'], 'The attempt counter increments on failure' );

		// Retry resumes at the exact checkpoint, with the error cleared.
		$r = $svc->retry( 1, $id );
		$this->assert_true( (bool) $r['ok'], 'the step must report ok' );
		$this->assert_same( 'writing', $r['status'], 'Retry must resume at the failed checkpoint' );
		$row = $svc->get( 1, $id );
		$this->assert_same( '', (string) $row['error'], 'The error is cleared on resume' );
		$this->assert_same( 2, (int) $row['attempts'], 'the attempt counter must match' );
	}

	private function compensation_deletes_draft_products_but_never_live_ones(): void {
		$this->fresh();
		$svc = $this->service( false );
		$id  = $this->to_writing( $svc, 'tok-c1' );
		$svc->create_product( 1, $id );

		// The registration fails downstream and is compensated: the draft product goes.
		$this->raw->update( 'ig_product_registrations', [ 'status' => 'failed', 'failed_from' => 'composing' ], [ 'id' => $id, 'tenant_id' => 1 ] );
		$r = $svc->compensate( 1, $id );
		$this->assert_true( (bool) $r['ok'], 'the step must report ok' );
		$this->assert_same( 'abandoned', $r['status'], 'the checkpoint status must match' );
		$this->assert_same( [ 110 ], $this->factory->deleted, 'The draft product must be deleted during compensation' );

		$row = $svc->get( 1, $id );
		$this->assert_same( 0, (int) $row['product_id'], 'The registration no longer claims the product' );

		// A live product is never touched: the factory refuses and the row still abandons.
		$id2 = $this->to_writing( $svc, 'tok-c2' );
		$svc->create_product( 1, $id2 );
		$this->raw->update( 'ig_product_registrations', [ 'status' => 'failed', 'failed_from' => 'composing' ], [ 'id' => $id2, 'tenant_id' => 1 ] );

		$live_factory = new class implements WooProductFactory {
			public function create_draft( array $copy ): int {
				return 0;
			}

			// Simulates the real factory refusing anything that is not a draft.
			public function delete_draft( int $product_id ): bool {
				return false;
			}

			public function is_available(): bool {
				return true;
			}
		};
		$svc2 = new ProductRegistrationService( $this->raw, $this->logger, $live_factory, null );
		$r    = $svc2->compensate( 1, $id2 );
		$this->assert_true( (bool) $r['ok'], 'Compensation still completes when the product must be kept' );
		$this->assert_same( 'abandoned', $r['status'], 'the checkpoint status must match' );
		$this->assert_same( 120, (int) $svc2->get( 1, $id2 )['product_id'], 'The kept product id stays on the row for the operator to inspect' );
	}

	private function approval_and_rejection_only_from_awaiting_approval(): void {
		$this->fresh();
		$svc = $this->service( false );
		$id  = $this->start_text( $svc )['id'];

		// Approving too early is refused.
		$r = $svc->approve( 1, $id, 42 );
		$this->assert_false( (bool) $r['ok'], 'the step must be refused' );
		$this->assert_same( 'invalid_state_for_approve', $r['error'], 'the error code must match' );

		$svc->manual_grade( 1, $id, false, 'عکس نامناسب' );
		$row = $svc->get( 1, $id );
		$this->assert_same( 'rejected', (string) $row['status'], 'the stored value must match' );
		$this->assert_same( 'عکس نامناسب', (string) $row['error'], 'The rejection reason is recorded' );

		// A rejected registration without a product: compensation just abandons it.
		$r = $svc->compensate( 1, $id );
		$this->assert_true( (bool) $r['ok'], 'the step must report ok' );
		$this->assert_same( 'abandoned', $r['status'], 'the checkpoint status must match' );
		$this->assert_same( [], $this->factory->deleted, 'No product existed, so nothing is deleted' );
	}

	private function invalid_transitions_are_refused(): void {
		$this->fresh();
		$svc = $this->service( false );
		$id  = $this->start_text( $svc )['id'];

		// Skipping ahead: editing before the image exists.
		$r = $svc->mark_edited( 1, $id );
		$this->assert_false( (bool) $r['ok'], 'the step must be refused' );
		$this->assert_same( 'invalid_state_for_mark_edited', $r['error'], 'the error code must match' );

		// A bad media kind is refused without moving the row.
		$svc->manual_grade( 1, $id, true );
		$svc->manual_prepared_image( 1, $id, 'u' );
		$svc->mark_edited( 1, $id );
		$svc->start_describing( 1, $id );
		$svc->manual_copy( 1, $id, [ 'title' => 'تست', 'price' => '1' ] );
		$svc->create_product( 1, $id );
		$svc->await_kind( 1, $id );

		$r = $svc->choose_kind( 1, $id, 'gif' );
		$this->assert_false( (bool) $r['ok'], 'Unknown kinds must be refused' );
		$this->assert_same( 'bad_kind', $r['error'], 'the error code must match' );
		$this->assert_same( 'awaiting_kind', $svc->get( 1, $id )['status'], 'A bad kind does not move the row' );
	}

	private function agent_driven_flow_records_stage_tasks(): void {
		$this->fresh();
		$svc   = $this->service( true );
		$start = $this->start_text( $svc, 1, 'tok-agent' );
		$id    = $start['id'];

		$r = $svc->start_grading( 1, $id );
		$this->assert_true( (bool) $r['ok'], 'With an agent, grading starts' );
		$this->assert_same( 'grading', $r['status'], 'the checkpoint status must match' );
		$row = $svc->get( 1, $id );
		$this->assert_same( 'quality', (string) $row['stage'], 'the agent stage must match' );
		$this->assert_same( 'task-grade', (string) $row['stage_task'], 'The agent task id is recorded for webhook matching' );

		$svc->complete_grading( 1, $id, [ 'pass' => true ] );
		$svc->start_image( 1, $id );
		$svc->complete_image( 1, $id, 'https://cdn.example.com/agent-prepared.jpg' );
		$svc->mark_edited( 1, $id );
		$svc->start_describing( 1, $id );
		$svc->start_writing( 1, $id );

		$row = $svc->get( 1, $id );
		$this->assert_same( 'copy', (string) $row['stage'], 'the agent stage must match' );
		$this->assert_same( 'task-copy', (string) $row['stage_task'], 'the agent task id must match' );

		$svc->complete_writing( 1, $id, [ 'title' => 'محصول هوشمند', 'price' => '99000', 'description' => 'خوب' ] );
		$svc->create_product( 1, $id );
		$svc->await_kind( 1, $id );
		$svc->choose_kind( 1, $id, 'video' );

		$row = $svc->get( 1, $id );
		$this->assert_same( 'composing', (string) $row['status'], 'the stored value must match' );
		$this->assert_same( 'video', (string) $row['kind'], 'The chosen kind is recorded' );
		$this->assert_same( 'video', (string) $row['stage'], 'Video kind uses the video stage' );
		$this->assert_same( 'task-video', (string) $row['stage_task'], 'the agent task id must match' );

		$svc->complete_compose( 1, $id, [ 'caption' => 'ویدیوی محصول جدید' ] );
		$row = $svc->get( 1, $id );
		$this->assert_same( 'awaiting_approval', (string) $row['status'], 'Composing lands on the human approval gate' );
		$this->assert_same( 'video', (string) $row['kind'], 'the media kind must match' );

		// The agent saw real account context for every AI call.
		$this->assert_true( in_array( [ 'account', 7 ], $this->agent->calls, true ), 'The agent must be given the account context' );
		$this->assert_true( in_array( [ 'video', 'https://cdn.example.com/agent-prepared.jpg' ], $this->agent->calls, true ), 'The video task must carry the prepared media' );
	}

	private function woocommerce_unavailable_fails_the_commerce_step_cleanly(): void {
		$this->fresh();
		$unavailable = new class implements WooProductFactory {
			public function create_draft( array $copy ): int {
				return 0;
			}

			public function delete_draft( int $product_id ): bool {
				return false;
			}

			public function is_available(): bool {
				return false;
			}
		};
		$svc = $this->service( false, $unavailable );
		$id  = $this->start_text( $svc )['id'];

		$svc->manual_grade( 1, $id, true );
		$svc->manual_prepared_image( 1, $id, 'u' );
		$svc->mark_edited( 1, $id );
		$svc->start_describing( 1, $id );
		$svc->manual_copy( 1, $id, [ 'title' => 'تست', 'price' => '1' ] );

		$r = $svc->create_product( 1, $id );
		$this->assert_false( (bool) $r['ok'], 'Without WooCommerce the commerce step must fail, not fatal' );
		$this->assert_same( 'woocommerce_not_active', $r['error'], 'the error code must match' );
		$this->assert_same( 'failed', $r['status'], 'the checkpoint status must match' );
		$this->assert_same( 'writing', (string) $svc->get( 1, $id )['failed_from'], 'The failure remembers it stopped at the commerce step' );
	}
}
