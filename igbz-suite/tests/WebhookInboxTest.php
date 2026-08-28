<?php
/**
 * Phase 29 — the durable webhook inbox and the shared payment state machine.
 *
 * Capture is fast and deduplicated at the database level, signatures gate dispatch, unknown
 * verdicts retry with backoff instead of being guessed, and the state machine only ever applies
 * legal transitions with an atomic, race-safe write.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\MultiTenant\MultiTenantModule;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentStateMachine;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Jobs\JobQueue;
use IGBZ\Suite\Support\Jobs\QueueRunner;
use IGBZ\Suite\Support\Webhooks\WebhookInbox;

/** In-memory engine for the inbox (and the payments rows the machine acts on). */
final class InboxDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'webhook_events' => [],
		'payments'       => [],
	];

	private int $next_id = 1;

	/** When true, payments updates report zero affected rows — a forced lost race. */
	public bool $force_lost_race = false;

	public function seed( string $table, array $row ): int {
		$id                      = (int) ( $row['id'] ?? $this->next_id++ );
		$row['id']               = $id;
		$this->tables[ $table ][ $id ] = $row;
		return $id;
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$short = str_contains( $table, 'webhook_events' ) ? 'webhook_events' : ( str_contains( $table, 'payments' ) ? 'payments' : '' );
		if ( 'webhook_events' === $short ) {
			// The UNIQUE (source,event_key) guard, enforced exactly like the database would.
			foreach ( $this->tables['webhook_events'] as $row ) {
				if ( (string) $row['source'] === (string) $data['source']
					&& (string) $row['event_key'] === (string) $data['event_key'] ) {
					$this->last_error = 'Duplicate entry';
					return 0;
				}
			}
		}
		if ( '' !== $short ) {
			if ( 'webhook_events' === $short ) {
				$data += [
					'tenant_id'        => 0,
					'status'           => 'received',
					'signature_status' => 'unchecked',
					'attempts'         => 0,
					'max_attempts'     => 5,
					'last_error'       => '',
					'processed_at'     => null,
				];
			}
			$id = $this->next_id++;
			$data['id'] = $id;
			$this->tables[ $short ][ $id ] = $data;
			$this->insert_id = $id;
			return 1;
		}
		return parent::insert( $table, $data, $format );
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'webhook_events' ) && preg_match( "/source = '([^']*)' AND event_key = '([^']*)'/", $sql, $m ) ) {
			foreach ( $this->tables['webhook_events'] as $row ) {
				if ( (string) $row['source'] === $m[1] && (string) $row['event_key'] === $m[2] ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'payments' ) && preg_match( "/WHERE id = '?(\d+)'?/", $sql, $m ) ) {
			return $this->tables['payments'][ (int) $m[1] ] ?? null;
		}
		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'GROUP BY status' ) ) {
			$tallies = [];
			foreach ( $this->tables['webhook_events'] as $row ) {
				$status             = (string) $row['status'];
				$tallies[ $status ] = ( $tallies[ $status ] ?? 0 ) + 1;
			}
			$out = [];
			foreach ( $tallies as $status => $total ) {
				$out[] = [ 'status' => $status, 'total' => $total ];
			}
			return $out;
		}

		if ( str_contains( $sql, 'webhook_events' ) ) {
			$out = [];
			foreach ( $this->tables['webhook_events'] as $row ) {
				if ( $this->matches( $sql, $row ) ) {
					$out[] = $row;
				}
			}
			usort( $out, static fn ( $a, $b ): int => strcmp( (string) $a['available_at'], (string) $b['available_at'] ) ?: ( (int) $a['id'] <=> (int) $b['id'] ) );
			if ( str_contains( $sql, 'ORDER BY updated_at DESC' ) ) {
				usort( $out, static fn ( $a, $b ): int => strcmp( (string) $b['updated_at'], (string) $a['updated_at'] ) ?: ( (int) $b['id'] <=> (int) $a['id'] ) );
			}
			if ( preg_match( "/LIMIT '?(\d+)'?/", $sql, $l ) ) {
				$out = array_slice( $out, 0, (int) $l[1] );
			}
			return $out;
		}
		return parent::get_results( $sql, $output );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$short = str_contains( $table, 'webhook_events' ) ? 'webhook_events' : ( str_contains( $table, 'payments' ) ? 'payments' : '' );
		if ( '' === $short ) {
			return parent::update( $table, $data, $where, $format, $where_format );
		}
		if ( 'payments' === $short && $this->force_lost_race ) {
			return 0;
		}
		$changed = 0;
		foreach ( $this->tables[ $short ] as $id => $row ) {
			$hit = true;
			foreach ( $where as $column => $value ) {
				if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
					$hit = false;
					break;
				}
			}
			if ( $hit ) {
				$this->tables[ $short ][ $id ] = array_merge( $row, $data );
				++$changed;
			}
		}
		return $changed;
	}

	/** @param array<string,mixed> $row */
	private function matches( string $sql, array $row ): bool {
		if ( preg_match_all( "/\b(status|source) = '([^']*)'/", $sql, $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $p ) {
				if ( (string) ( $row[ $p[1] ] ?? '' ) !== $p[2] ) {
					return false;
				}
			}
		}
		if ( preg_match_all( "/\b(available_at) <= '([^']*)'/", $sql, $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $p ) {
				if ( strcmp( (string) ( $row[ $p[1] ] ?? '' ), $p[2] ) > 0 ) {
					return false;
				}
			}
		}
		return true;
	}
}

/** Scripted inbox for the drain-job wiring scenario. */
final class InboxSpy {
	public int $calls = 0;
	public int $last_limit = 0;

	/** @var array<int,array{done:int,unknown:int,failed:int,dead:int}> */
	public array $script = [];

	/** @return array{done:int,unknown:int,failed:int,dead:int} */
	public function process_batch( int $limit = 20 ): array {
		++$this->calls;
		$this->last_limit = $limit;
		return $this->script ? (array) array_shift( $this->script ) : [ 'done' => 0, 'unknown' => 0, 'failed' => 0, 'dead' => 0 ];
	}
}

final class WebhookInboxTest extends TestCase {

	private InboxDb $wpdb;
	private WebhookInbox $inbox;

	public function run(): void {
		$this->receive_stores_and_dedupes_on_source_and_key();
		$this->signature_is_checked_timing_safe();
		$this->drain_dispatches_and_completes();
		$this->invalid_signature_is_never_dispatched();
		$this->unknown_is_retried_until_it_dead_letters();
		$this->a_throwing_handler_retries_then_dead_letters();
		$this->state_machine_only_allows_legal_atomic_transitions();
		$this->drain_job_continues_on_a_full_batch();
	}

	private function fresh(): void {
		igbz_test_reset_settings();
		$this->wpdb      = new InboxDb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->inbox     = new WebhookInbox( new Db(), igbz()->settings(), igbz()->get( 'logger' ) );
	}

	private function receive_stores_and_dedupes_on_source_and_key(): void {
		$this->fresh();

		$first = $this->inbox->receive( 'psp', 'EVT-1', '{"payment_id":7}' );
		$this->assert_same( 'stored', (string) $first['status'], 'a fresh event lands in the inbox' );

		$replay = $this->inbox->receive( 'psp', 'EVT-1', '{"payment_id":7}' );
		$this->assert_same( 'duplicate', (string) $replay['status'], 'a replayed delivery is detected...' );
		$this->assert_same( (int) $first['id'], (int) $replay['id'], '...and points at the original' );
		$this->assert_same( 1, count( $this->wpdb->tables['webhook_events'] ), 'exactly one copy is ever stored' );

		$other = $this->inbox->receive( 'psp', 'EVT-2', '{}' );
		$this->assert_same( 'stored', (string) $other['status'], 'a different event key is a different event' );

		$same_key_other_source = $this->inbox->receive( 'manychat', 'EVT-1', '{}' );
		$this->assert_same( 'stored', (string) $same_key_other_source['status'], 'deduplication is per source' );
	}

	private function signature_is_checked_timing_safe(): void {
		$this->fresh();

		igbz()->settings()->set( 'webhooks.psp.secret', 'sekrit' );

		$body = '{"payment_id":7,"status":"paid"}';
		$good = hash_hmac( 'sha256', $body, 'sekrit' );

		$this->assert_true( $this->inbox->verify_signature( 'psp', $body, $good ), 'a correct HMAC verifies' );
		$this->assert_true( ! $this->inbox->verify_signature( 'psp', $body . ' ', $good ), 'a tampered body fails' );
		$this->assert_true( ! $this->inbox->verify_signature( 'psp', $body, str_repeat( '0', 64 ) ), 'a wrong signature fails' );
		$this->assert_true( ! $this->inbox->verify_signature( 'psp', $body, '' ), 'a missing signature fails' );
		$this->assert_true( ! $this->inbox->verify_signature( 'other', $body, $good ), 'a source without a secret fails' );

		// The shared fallback secret applies to any source without its own.
		igbz()->settings()->set( 'webhooks.hmac_secret', 'shared' );
		$shared_sig = hash_hmac( 'sha256', $body, 'shared' );
		$this->assert_true( $this->inbox->verify_signature( 'other', $body, $shared_sig ), 'the shared fallback secret verifies' );
	}

	private function drain_dispatches_and_completes(): void {
		$this->fresh();

		$seen = [];
		$this->inbox->register_source( 'demo', function ( array $payload ) use ( &$seen ): string {
			$seen[] = (int) ( $payload['n'] ?? 0 );
			return 'done';
		} );

		$this->inbox->receive( 'demo', 'A', '{"n":1}' );
		$this->inbox->receive( 'demo', 'B', '{"n":2}' );

		$totals = $this->inbox->process_batch( 10 );
		$this->assert_same( 2, (int) $totals['done'], 'both events process' );
		$this->assert_same( [ 1, 2 ], $seen, 'payloads reach the handler decoded, in order' );

		$stats = $this->inbox->stats();
		$this->assert_same( 2, (int) $stats['done'], 'the tally agrees' );

		$again = $this->inbox->process_batch( 10 );
		$this->assert_same( 0, (int) $again['done'], 'a second drain finds nothing due' );
	}

	private function invalid_signature_is_never_dispatched(): void {
		$this->fresh();

		$called = 0;
		$this->inbox->register_source( 'demo', function () use ( &$called ): string {
			++$called;
			return 'done';
		} );

		$this->inbox->receive( 'demo', 'FORGED', '{}', 0, WebhookInbox::SIG_INVALID );

		$totals = $this->inbox->process_batch( 10 );
		$this->assert_same( 0, $called, 'an invalid delivery never reaches the handler' );
		$this->assert_same( 1, (int) $totals['dead'], 'it is dead-lettered instead' );

		$dead = $this->inbox->dead_letters();
		$this->assert_same( 'invalid signature', (string) $dead[0]['last_error'], 'with the reason recorded' );
	}

	private function unknown_is_retried_until_it_dead_letters(): void {
		$this->fresh();

		$this->inbox->register_source( 'demo', static fn (): string => 'unknown' );
		$this->inbox->receive( 'demo', 'MAYBE', '{}' );

		for ( $round = 1; $round <= 5; ++$round ) {
			$totals = $this->inbox->process_batch( 10 );
			if ( $round < 5 ) {
				$this->assert_same( 1, (int) $totals['unknown'], "round {$round}: still unknown, scheduled for retry" );
				$row = $this->wpdb->tables['webhook_events'][1];
				$this->assert_same( WebhookInbox::STATUS_RECEIVED, (string) $row['status'], "round {$round}: back in the pool" );
				$this->assert_true(
					strtotime( (string) $row['available_at'] . ' UTC' ) > time(),
					"round {$round}: the retry respects the backoff window"
				);
				// Time travel past the backoff for the next round.
				$this->wpdb->tables['webhook_events'][1]['available_at'] = gmdate( 'Y-m-d H:i:s', time() - 1 );
			} else {
				$this->assert_same( 1, (int) $totals['dead'], 'round 5: attempts exhausted — dead-lettered' );
			}
		}

		$row = $this->wpdb->tables['webhook_events'][1];
		$this->assert_same( 5, (int) $row['attempts'], 'every attempt is recorded' );
		$this->assert_same( 'unknown state after final attempt', (string) $row['last_error'], 'the dead letter says why' );
	}

	private function a_throwing_handler_retries_then_dead_letters(): void {
		$this->fresh();

		$this->inbox->register_source( 'demo', static function (): string {
			throw new RuntimeException( 'downstream blew up' );
		} );
		$this->inbox->receive( 'demo', 'BOOM', '{}' );

		$first = $this->inbox->process_batch( 10 );
		$this->assert_same( 1, (int) $first['failed'], 'a failure is a retry, not a loss' );
		$row = $this->wpdb->tables['webhook_events'][1];
		$this->assert_same( WebhookInbox::STATUS_RECEIVED, (string) $row['status'], 'the event is back in the pool' );
		$this->assert_same( 'downstream blew up', (string) $row['last_error'], 'the reason is kept' );

		$this->wpdb->tables['webhook_events'][1]['available_at'] = gmdate( 'Y-m-d H:i:s', time() - 1 );
		$this->wpdb->tables['webhook_events'][1]['attempts']     = 5; // final attempt
		$last = $this->inbox->process_batch( 10 );
		$this->assert_same( 1, (int) $last['dead'], 'the final failure dead-letters' );
		$this->assert_same( 'downstream blew up', (string) $this->wpdb->tables['webhook_events'][1]['last_error'], 'with the original reason' );
	}

	private function state_machine_only_allows_legal_atomic_transitions(): void {
		$this->fresh();

		$this->assert_true( PaymentStateMachine::can( 'pending', 'paid' ), 'pending may become paid' );
		$this->assert_true( PaymentStateMachine::can( 'pending', 'unknown' ), 'pending may rest in unknown' );
		$this->assert_true( ! PaymentStateMachine::can( 'failed', 'paid' ), 'failed can never become paid' );
		$this->assert_true( ! PaymentStateMachine::can( 'paid', 'failed' ), 'paid is terminal' );
		$this->assert_true( ! PaymentStateMachine::can( 'bogus', 'paid' ), 'unknown origins are illegal' );

		$machine = PaymentStateMachine::make( new Db() );

		$this->wpdb->seed( 'payments', [ 'status' => 'pending', 'gateway' => 'zarinpal' ] );
		$this->wpdb->seed( 'payments', [ 'status' => 'paid', 'gateway' => 'zarinpal' ] );

		$won = $machine->advance( 1, 'paid', [ 'reference_id' => 'REF-9' ] );
		$this->assert_true( (bool) $won['ok'], 'a legal transition applies' );
		$this->assert_same( 'pending', (string) $won['from'], 'reporting where it came from' );
		$this->assert_same( 'paid', (string) $this->wpdb->tables['payments'][1]['status'], 'the row moved' );
		$this->assert_same( 'REF-9', (string) $this->wpdb->tables['payments'][1]['reference_id'], 'extra columns land with the transition' );

		$illegal = $machine->advance( 2, 'failed' );
		$this->assert_true( ! (bool) $illegal['ok'], 'a terminal state refuses further hops' );
		$this->assert_same( 'illegal_transition', (string) $illegal['reason'], 'with the exact reason' );
		$this->assert_same( 'paid', (string) $this->wpdb->tables['payments'][2]['status'], 'and nothing was written' );

		$missing = $machine->advance( 999, 'paid' );
		$this->assert_same( 'not_found', (string) $missing['reason'], 'a missing payment is reported' );

		// A racing callback that loses the conditional write reports the race instead of corrupting.
		$this->wpdb->seed( 'payments', [ 'status' => 'pending', 'gateway' => 'zarinpal' ] );
		$this->wpdb->force_lost_race = true;
		$raced = $machine->advance( 3, 'paid' );
		$this->assert_true( ! (bool) $raced['ok'], 'the loser of the race reports honestly' );
		$this->assert_same( 'lost_race', (string) $raced['reason'], 'as a lost race, not a silent drop' );
		$this->wpdb->force_lost_race = false;
	}

	private function drain_job_continues_on_a_full_batch(): void {
		igbz_test_reset_settings();
		$GLOBALS['wpdb'] = new JobQueueDb();
		$queue           = new JobQueue( new Db(), igbz()->get( 'logger' ) );
		$runner          = new QueueRunner( $queue, igbz()->get( 'logger' ) );
		igbz()->bind( 'jobs', fn () => $queue );

		$spy    = new InboxSpy();
		$script = [
			[ 'done' => 20, 'unknown' => 0, 'failed' => 0, 'dead' => 0 ], // Full batch — more may wait.
			[ 'done' => 4, 'unknown' => 0, 'failed' => 0, 'dead' => 0 ],  // Partial — the drain ends.
		];
		foreach ( $script as $totals ) {
			$spy->script[] = $totals;
		}
		igbz()->bind( 'webhooks.inbox', static fn () => $spy );

		( new MultiTenantModule() )->register_queue_handlers( $queue );

		( new MultiTenantModule() )->webhook_tick();
		$runner->run();

		$this->assert_same( 2, $spy->calls, 'the full batch re-queued the next round' );
		$this->assert_same( 20, $spy->last_limit, 'the batch size reaches the inbox' );
		$this->assert_same( 0, count( array_filter( $GLOBALS['wpdb']->tables['jobs'], static fn ( array $j ) => 'pending' === $j['status'] ) ), 'the partial second round ends the drain' );
	}
}
