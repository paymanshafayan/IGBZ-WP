<?php
/**
 * Phase 59 — publishing, campaign blasts and policy changes ride the phase-57
 * approval queue: the three content categories batch under one approval, a sales
 * post needs its own justified request, a blast is capped in recipients and stops
 * at the first failure, a policy change is a closed key list that compensates
 * when its write does not stick — and every execution leaves a provable outcome
 * in the row's metadata.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Instagram\Services\ContentPublishService;
use IGBZ\Suite\Modules\Instagram\Vip\VipMessageService;
use IGBZ\Suite\Modules\Pado\Services\ApprovalRequestService;
use IGBZ\Suite\Modules\Pado\Services\ContentOperationsService;
use IGBZ\Suite\Support\Db;

require_once __DIR__ . '/SensitiveOpsTest.php';

/** The little world the publishing/messaging/policy doubles serve. */
final class ContentOpsWorld {
	/** @var array<int,string> content id => status */
	public static array $contents = [];
	/** @var array<int,string> content id => 'now'|'scheduled' */
	public static array $published = [];
	/** When true, the store is not connected: publish_now refuses everything. */
	public static bool $not_connected = false;
	/** @var array<int,int> active member user ids */
	public static array $members = [];
	/** @var array<int,array{user:int,body:string}> */
	public static array $sent = [];
	/** @var array<int,int> user ids whose message send must fail */
	public static array $send_fail_users = [];
	/** @var array<string,mixed> policy key => value */
	public static array $policy = [];
	/** When true, the first policy write silently does not stick. */
	public static bool $policy_lag = false;

	public static function reset(): void {
		self::$contents      = [];
		self::$published     = [];
		self::$not_connected = false;
		self::$members       = [];
		self::$sent          = [];
		self::$send_fail_users = [];
		self::$policy        = [];
		self::$policy_lag    = false;
	}
}

/** Parent constructor deliberately bypassed: the stubs serve the world, not the wire. */
final class ContentOpsPublisherStub extends ContentPublishService {
	public function __construct() {}
}

/** @return array<string,mixed> */
final class ContentOpsMessagesStub extends VipMessageService {
	public function __construct() {}
}

/** The service with its environment seams pointed at the test world. */
final class ContentOpsServiceSpy extends ContentOperationsService {
	public function __construct( Db $db, $logger, ApprovalRequestService $approvals, $settings ) {
		parent::__construct( $db, $logger, $approvals, new ContentOpsPublisherStub(), new ContentOpsMessagesStub(), $settings );
	}

	protected function publish_now( int $tenant_id, int $content_id ): array {
		if ( ContentOpsWorld::$not_connected ) {
			return [ 'ok' => false, 'id' => $content_id, 'status' => '', 'error' => 'not_connected' ];
		}
		if ( ! isset( ContentOpsWorld::$contents[ $content_id ] ) ) {
			return [ 'ok' => false, 'id' => $content_id, 'status' => '', 'error' => 'not_found' ];
		}
		$status = ContentOpsWorld::$contents[ $content_id ];
		if ( ! in_array( $status, [ 'draft', 'scheduled', 'failed' ], true ) ) {
			return [ 'ok' => false, 'id' => $content_id, 'status' => $status, 'error' => 'invalid_state_for_publish' ];
		}
		ContentOpsWorld::$contents[ $content_id ]  = 'publishing';
		ContentOpsWorld::$published[ $content_id ] = 'now';
		return [ 'ok' => true, 'id' => $content_id, 'status' => 'publishing', 'error' => '' ];
	}

	protected function schedule( int $tenant_id, int $content_id, string $when_iso ): array {
		if ( ! isset( ContentOpsWorld::$contents[ $content_id ] ) ) {
			return [ 'ok' => false, 'id' => $content_id, 'status' => '', 'error' => 'not_found' ];
		}
		ContentOpsWorld::$contents[ $content_id ]  = 'scheduled';
		ContentOpsWorld::$published[ $content_id ] = 'scheduled';
		return [ 'ok' => true, 'id' => $content_id, 'status' => 'scheduled', 'error' => '' ];
	}

	protected function load_recipients( int $tenant_id, int $cap ): array {
		return array_slice( ContentOpsWorld::$members, 0, $cap );
	}

	protected function thread_for_user( int $user_id, int $tenant_id, string $subject ): int {
		return 1000 + $user_id;
	}

	protected function send_message( int $thread_id, int $sender_id, string $body ): int {
		$user = $thread_id - 1000;
		if ( in_array( $user, ContentOpsWorld::$send_fail_users, true ) ) {
			return 0;
		}
		ContentOpsWorld::$sent[] = [ 'user' => $user, 'body' => $body ];
		return 500 + count( ContentOpsWorld::$sent );
	}

	protected function get_policy( string $key ): mixed {
		return ContentOpsWorld::$policy[ $key ] ?? false;
	}

	protected function set_policy( string $key, mixed $value ): void {
		if ( ContentOpsWorld::$policy_lag ) {
			// The first write does not stick; the verify-on-re-read must catch it.
			ContentOpsWorld::$policy_lag = false;
			return;
		}
		ContentOpsWorld::$policy[ $key ] = $value;
	}
}

final class ContentOpsTest extends TestCase {

	private OpsQueueDb $db;
	private ApprovalRequestService $approvals;
	private ContentOpsServiceSpy $ops;

	public function run(): void {
		$this->a_batch_publishes_under_one_approval();
		$this->a_scheduled_publish_waits_for_its_moment();
		$this->a_sales_post_needs_a_reason_and_stays_single();
		$this->a_batch_stops_at_the_first_refusal();
		$this->a_repeated_batch_is_one_row();
		$this->a_tampered_payload_never_executes();
		$this->a_campaign_reaches_the_active_members();
		$this->a_campaign_stops_at_a_failing_member();
		$this->the_campaign_is_capped();
		$this->a_policy_change_applies_with_old_value_on_record();
		$this->a_policy_write_that_does_not_stick_is_compensated();
		$this->policy_keys_outside_the_list_are_refused();
	}

	// ---------------------------------------------------------------- publish

	private function a_batch_publishes_under_one_approval(): void {
		$this->fresh( [ 11 => 'draft', 12 => 'draft', 13 => 'draft' ] );

		$made = $this->ops->request_publish( 1, [ 11, 12, 13 ], 'viral', 7 );
		$this->assert_true( $made['ok'], 'the batch lands as one request' , 'the invariant holds' );
		$this->assert_same( 'draft', ContentOpsWorld::$contents[12], 'nothing published before approval' );

		$ok = $this->decide( $made['id'] );
		$this->assert_true( $ok, 'the batch executes' , 'the invariant holds' );
		$this->assert_same( 'publishing', ContentOpsWorld::$contents[11], 'row 11 is out' );
		$this->assert_same( 'publishing', ContentOpsWorld::$contents[13], 'row 13 is out' );
		$this->assert_same( 'executed', (string) $this->db->approvals[ $made['id'] ]['status'], 'the request is executed' );

		$meta = $this->outcome( $made['id'] );
		$this->assert_same( 3, (int) $meta['published'], 'the outcome proves all three went out' );
		$this->assert_same( 3, (int) $meta['requested'], 'the outcome records what was requested' );
		$this->assert_same( 'medium', (string) $this->db->approvals[ $made['id'] ]['impact'], 'content categories are medium impact' );
	}

	private function a_scheduled_publish_waits_for_its_moment(): void {
		$this->fresh( [ 14 => 'draft' ] );
		$when = gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 );

		$made = $this->ops->request_publish( 1, [ 14 ], 'trust', 7, '', $when );
		$ok   = $this->decide( $made['id'] );

		$this->assert_true( $ok, 'the scheduled publish executes' , 'the invariant holds' );
		$this->assert_same( 'scheduled', ContentOpsWorld::$contents[14], 'the row is scheduled, not fired' );
		$this->assert_same( 'scheduled', (string) ContentOpsWorld::$published[14], 'the schedule path was taken' );
	}

	private function a_sales_post_needs_a_reason_and_stays_single(): void {
		$this->fresh( [ 15 => 'draft', 16 => 'draft' ] );

		$no_reason = $this->ops->request_publish( 1, [ 15 ], 'campaign', 7 );
		$this->assert_false( $no_reason['ok'], 'a sales post without a reason is refused' , 'the invariant holds' );

		$too_many = $this->ops->request_publish( 1, [ 15, 16 ], 'campaign', 7, 'کمپین نوروز' );
		$this->assert_false( $too_many['ok'], 'a sales post is exactly one row' , 'the invariant holds' );

		$made = $this->ops->request_publish( 1, [ 15 ], 'campaign', 7, 'کمپین نوروز' );
		$this->assert_true( $made['ok'], 'the justified single sales post lands' , 'the invariant holds' );
		$this->assert_same( 'high', (string) $this->db->approvals[ $made['id'] ]['impact'], 'sales content is high impact' );
	}

	private function a_batch_stops_at_the_first_refusal(): void {
		$this->fresh( [ 21 => 'draft', 22 => 'draft' ] );
		ContentOpsWorld::$contents[23] = 'publishing'; // already out: invalid state for publish

		$made = $this->ops->request_publish( 1, [ 21, 22, 23 ], 'lifestyle', 7 );
		$ok   = $this->decide( $made['id'] );

		$this->assert_true( $ok, 'the decision completes either way — the fate lives in the status' , 'the invariant holds' );
		$this->assert_same( 'failed', (string) $this->db->approvals[ $made['id'] ]['status'], 'the stopped batch dies as failed' );
		$this->assert_same( 'publishing', ContentOpsWorld::$contents[21], 'what went out stays out — publishing is not compensable' );
		$this->assert_same( 'publishing', ContentOpsWorld::$contents[23], 'the refusing row never moved' );

		$meta = $this->outcome( $made['id'] );
		$this->assert_same( 2, (int) $meta['published'], 'the outcome proves how far the batch went' );
		$this->assert_same( 'invalid_state_for_publish', (string) $meta['error'], 'the refusal is on record' );
	}

	private function a_repeated_batch_is_one_row(): void {
		$this->fresh( [ 31 => 'draft' ] );
		$first = $this->ops->request_publish( 1, [ 31 ], 'viral', 7 );
		$again = $this->ops->request_publish( 1, [ 31 ], 'viral', 7 );
		$this->assert_true( $again['duplicate'], 'the same batch is one row, not two' , 'the invariant holds' );
		$this->assert_same( $first['id'], $again['id'], 'the invariant holds' );
	}

	private function a_tampered_payload_never_executes(): void {
		$this->fresh( [ 32 => 'draft' ] );
		$id = $this->ops->request_publish( 1, [ 32 ], 'viral', 7 )['id'];

		$payload = json_decode( (string) $this->db->approvals[ $id ]['payload'], true );
		$payload['content_ids'][] = 999;
		$this->db->approvals[ $id ]['payload'] = wp_json_encode( $payload );

		$ok = $this->decide( $id );
		$this->assert_true( $ok, 'the decision completes either way — the fate lives in the status' , 'the invariant holds' );
		$this->assert_same( 'failed', (string) $this->db->approvals[ $id ]['status'], 'the row dies as failed' );
		$this->assert_false( isset( ContentOpsWorld::$published[32] ), 'nothing was published' );
	}

	// -------------------------------------------------------------- campaign

	private function a_campaign_reaches_the_active_members(): void {
		$this->fresh();
		ContentOpsWorld::$members = [ 7, 8, 9 ];

		$made = $this->ops->request_campaign_send( 1, 'حراج هفته', 'سلام! ۲۰٪ تخفیف تا جمعه.', 7, 'کمپین تست' );
		$this->assert_true( $made['ok'], 'the blast lands' , 'the invariant holds' );
		$this->assert_same( 'high', (string) $this->db->approvals[ $made['id'] ]['impact'], 'customer-facing blasts are high impact' );

		$ok = $this->decide( $made['id'] );
		$this->assert_true( $ok, 'the blast executes' , 'the invariant holds' );
		$this->assert_same( 3, count( ContentOpsWorld::$sent ), 'every active member got one message' );
		$this->assert_same( 'سلام! ۲۰٪ تخفیف تا جمعه.', (string) ContentOpsWorld::$sent[0]['body'], 'the approved body is what was sent' );

		$meta = $this->outcome( $made['id'] );
		$this->assert_same( 3, (int) $meta['sent'], 'the outcome proves the count' );
		$this->assert_same( 3, (int) $meta['recipients'], 'the outcome records the audience size' );
	}

	private function a_campaign_stops_at_a_failing_member(): void {
		$this->fresh();
		ContentOpsWorld::$members       = [ 5, 6 ];
		ContentOpsWorld::$send_fail_users = [ 6 ];

		$id = $this->ops->request_campaign_send( 1, 'عنوان', 'متن', 7 )['id'];
		$ok = $this->decide( $id );

		$this->assert_true( $ok, 'the decision completes either way — the fate lives in the status' , 'the invariant holds' );
		$this->assert_same( 'failed', (string) $this->db->approvals[ $id ]['status'], 'the stopped blast dies as failed' );
		$this->assert_same( 1, count( ContentOpsWorld::$sent ), 'the messages already delivered cannot be unsent — and no more went out' );

		$meta = $this->outcome( $id );
		$this->assert_same( 1, (int) $meta['sent'], 'the outcome proves how far the blast went' );
		$this->assert_same( 'send_failed', (string) $meta['error'], 'the failure is on record' );
	}

	private function the_campaign_is_capped(): void {
		$this->fresh();
		ContentOpsWorld::$members = range( 1, 205 );

		$id = $this->ops->request_campaign_send( 1, 'عنوان', 'متن', 7 )['id'];
		$ok = $this->decide( $id );

		$this->assert_true( $ok, 'the blast executes' , 'the invariant holds' );
		$this->assert_same( 200, count( ContentOpsWorld::$sent ), 'the blast never exceeds the recipient cap' );

		$meta = $this->outcome( $id );
		$this->assert_same( 200, (int) $meta['recipients'], 'the outcome records the capped audience' );
	}

	// --------------------------------------------------------------- policy

	private function a_policy_change_applies_with_old_value_on_record(): void {
		$this->fresh();
		ContentOpsWorld::$policy['pado.ai.routing.routine'] = 'groq';

		$made = $this->ops->request_policy_change( 1, 'pado.ai.routing.routine', 'openrouter', 7, 'تغییر ارائه‌دهندهٔ امور اداری' );
		$this->assert_true( $made['ok'], 'the policy change lands' , 'the invariant holds' );
		$this->assert_same( 'critical', (string) $this->db->approvals[ $made['id'] ]['impact'], 'policy changes are critical impact' );

		$payload = json_decode( (string) $this->db->approvals[ $made['id'] ]['payload'], true );
		$this->assert_same( 'groq', (string) $payload['old_value'], 'the captured before-state is on record' );

		$ok = $this->decide( $made['id'] );
		$this->assert_true( $ok, 'the policy change executes' , 'the invariant holds' );
		$this->assert_same( 'openrouter', (string) ContentOpsWorld::$policy['pado.ai.routing.routine'], 'the new routing applies' );

		$meta = $this->outcome( $made['id'] );
		$this->assert_same( 'groq', (string) $meta['from'], 'the outcome proves what it changed from' );
		$this->assert_same( 'openrouter', (string) $meta['to'], 'the outcome proves what it changed to' );
	}

	private function a_policy_write_that_does_not_stick_is_compensated(): void {
		$this->fresh();
		ContentOpsWorld::$policy['pado.ai.routing.judgment'] = 'openrouter';

		$id = $this->ops->request_policy_change( 1, 'pado.ai.routing.judgment', 'groq', 7, 'تغییر ارائه‌دهندهٔ مدیریت') ['id'];
		ContentOpsWorld::$policy_lag = true; // the first write pretends and does not stick

		$ok = $this->decide( $id );
		$this->assert_true( $ok, 'the decision completes either way — the fate lives in the status' , 'the invariant holds' );
		$this->assert_same( 'failed', (string) $this->db->approvals[ $id ]['status'], 'the row dies as failed' );
		$this->assert_same( 'openrouter', (string) ContentOpsWorld::$policy['pado.ai.routing.judgment'], 'the routing stays — compensated back' );

		$meta = $this->outcome( $id );
		$this->assert_same( 'write_did_not_stick', (string) $meta['error'], 'the refusal is on record' );
	}

	private function policy_keys_outside_the_list_are_refused(): void {
		$this->fresh();
		$made = $this->ops->request_policy_change( 1, 'pado.ai.providers', 'https://evil.example', 7 );
		$this->assert_false( $made['ok'], 'keys outside the closed list never reach the queue' , 'the invariant holds' );
		$this->assert_same( 'policy_key_not_allowed', $made['error'], 'the invariant holds' );

		$bad_batch = $this->ops->request_publish( 1, array_map( static fn ( int $i ): int => 1000 + $i, range( 1, 51 ) ), 'viral', 7 );
		$this->assert_false( $bad_batch['ok'], 'a 51-row batch is refused up front' , 'the invariant holds' );
		$this->assert_same( 'batch_too_large', $bad_batch['error'], 'the invariant holds' );

		$bad_cat = $this->ops->request_publish( 1, [ 1 ], 'spam', 7 );
		$this->assert_false( $bad_cat['ok'], 'unknown categories are refused' , 'the invariant holds' );
	}

	// --------------------------------------------------------------- helpers

	/** @param array<int,string> $contents */
	private function fresh( array $contents = [] ): void {
		igbz_test_reset_settings();
		ContentOpsWorld::reset();
		foreach ( $contents as $id => $status ) {
			ContentOpsWorld::$contents[ $id ] = $status;
		}

		$this->db = new OpsQueueDb();
		$GLOBALS['wpdb'] = $this->db;

		$db     = new Db();
		$logger = igbz()->get( 'logger' );

		$this->approvals = new ApprovalRequestService( $db );
		$this->ops       = new ContentOpsServiceSpy( $db, $logger, $this->approvals, igbz()->settings() );
	}

	private function decide( int $id ): bool {
		return $this->approvals->decide( $id, ApprovalRequestService::STATUS_APPROVED, 9, '', fn ( array $r ): bool => $this->ops->run( $r ), null, true );
	}

	/** @return array<string,mixed> */
	private function outcome( int $id ): array {
		$meta = json_decode( (string) ( $this->db->approvals[ $id ]['metadata'] ?? '' ), true );
		return is_array( $meta ) ? (array) ( $meta['outcome'] ?? [] ) : [];
	}
}
