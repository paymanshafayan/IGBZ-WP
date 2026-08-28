<?php
/**
 * Phase 23 — the durable job queue core. Everything phases 24-27 migrate onto this queue stands
 * on these guarantees: leased claims survive worker crashes, retries back off with jitter,
 * poison jobs end up dead-lettered instead of looping, idempotency keys dedupe re-enqueues, and
 * every job carries its tenant and trace id into the handler.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Jobs\Envelope;
use IGBZ\Suite\Support\Jobs\JobContext;
use IGBZ\Suite\Support\Jobs\JobQueue;
use IGBZ\Suite\Support\Logger;

/**
 * Persisting wpdb double for the jobs table. Supports equality, <=, IN and IS NOT NULL in WHERE,
 * one-column ORDER BY and LIMIT — exactly the shapes JobQueue issues.
 */
final class JobQueueDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [ 'jobs' => [] ];

	private int $next_id = 500;

	private function short( string $table ): string {
		return str_ends_with( $table, 'igbz_jobs' ) ? 'jobs' : '';
	}

	/** @return array<int,array<string,mixed>> */
	private function rows_for( string $sql ): array {
		if ( ! str_contains( $sql, 'igbz_jobs' ) ) {
			return [];
		}

		$out = [];
		foreach ( $this->tables['jobs'] as $row ) {
			if ( $this->matches( $sql, $row ) ) {
				$out[] = $row;
			}
		}

		if ( preg_match( '/ORDER BY (\w+)\s*(ASC|DESC)?/i', $sql, $m ) ) {
			$column = $m[1];
			$desc   = strtoupper( $m[2] ?? 'ASC' ) === 'DESC';
			usort( $out, static function ( array $a, array $b ) use ( $column, $desc ): int {
				$cmp = strcmp( (string) ( $a[ $column ] ?? '' ), (string) ( $b[ $column ] ?? '' ) )
					?: ( (int) $a['id'] <=> (int) $b['id'] );
				return $desc ? -$cmp : $cmp;
			} );
		}
		if ( preg_match( "/LIMIT '?(\d+)'?/", $sql, $m ) ) {
			$out = array_slice( $out, 0, (int) $m[1] );
		}
		return $out;
	}

	/** @param array<string,mixed> $row */
	private function matches( string $sql, array $row ): bool {
		if ( preg_match_all( "/\b([a-z_]+) = '([^']*)'/", $sql, $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $p ) {
				if ( (string) ( $row[ $p[1] ] ?? '' ) !== $p[2] ) {
					return false;
				}
			}
		}
		if ( preg_match_all( "/\b([a-z_]+) <= '([^']*)'/", $sql, $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $p ) {
				$value = $row[ $p[1] ] ?? null;
				if ( null === $value || strcmp( (string) $value, $p[2] ) > 0 ) {
					return false;
				}
			}
		}
		if ( preg_match_all( "/\b([a-z_]+) IN \(([^)]*)\)/", $sql, $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $p ) {
				$values = array_map(
					static fn ( string $v ): string => trim( $v, " '" ),
					explode( ',', $p[2] )
				);
				if ( ! in_array( (string) ( $row[ $p[1] ] ?? '' ), $values, true ) ) {
					return false;
				}
			}
		}
		if ( preg_match_all( '/\b([a-z_]+) IS NOT NULL/', $sql, $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $p ) {
				if ( null === ( $row[ $p[1] ] ?? null ) ) {
					return false;
				}
			}
		}
		return true;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;
		$rows            = $this->rows_for( $sql );
		return $rows[0] ?? null;
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;
		return $this->rows_for( $sql );
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		if ( 'jobs' !== $this->short( $table ) ) {
			return parent::insert( $table, $data, $format );
		}
		$id                          = $this->next_id++;
		$this->insert_id             = $id;
		$data['id']                  = $id;
		$this->tables['jobs'][ $id ] = $data;
		return 1;
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		if ( 'jobs' !== $this->short( $table ) ) {
			return parent::update( $table, $data, $where, $format, $where_format );
		}
		$changed = 0;
		foreach ( $this->tables['jobs'] as $id => $row ) {
			foreach ( $where as $column => $value ) {
				if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
					continue 2;
				}
			}
			$this->tables['jobs'][ $id ] = array_merge( $row, $data );
			++$changed;
		}
		return $changed;
	}
}

final class JobQueueTest extends TestCase {

	private JobQueueDb $wpdb;
	private JobQueue $queue;

	public function run(): void {
		$this->envelope_is_versioned_and_self_describing();
		$this->enqueue_stamps_tenant_trace_and_envelope();
		$this->enqueue_is_idempotent_per_queue_and_key();
		$this->claim_leases_only_due_jobs_once();
		$this->successful_run_completes_and_hands_context_to_the_handler();
		$this->failure_schedules_a_jittered_exponential_retry();
		$this->final_failure_dead_letters_instead_of_looping();
		$this->unrunnable_jobs_are_isolated_not_guessed();
		$this->expired_leases_come_back_or_die_by_attempts();
		$this->cancel_stops_pending_jobs_but_not_running_ones();
		$this->retry_delay_is_bounded_and_capped();
	}

	private function fresh(): void {
		igbz_test_reset_settings();
		$this->wpdb      = new JobQueueDb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->queue     = new JobQueue( new Db(), new Logger( igbz()->settings() ) );
	}

	/** @return array<string,mixed> */
	private function job( int $id ): array {
		return $this->wpdb->tables['jobs'][ $id ];
	}

	private function envelope_is_versioned_and_self_describing(): void {
		$json = Envelope::wrap( 'cache.rebuild', [ 'tenant' => 3 ], 'trace-abc', [ 'group' => 'g1' ] );
		$open = Envelope::open( $json );

		$this->assert_same( Envelope::VERSION, $open['v'] ?? 0, 'envelope carries its schema version' );
		$this->assert_same( 'cache.rebuild', $open['job_type'] ?? '', 'envelope names the job type' );
		$this->assert_same( 3, (int) ( $open['payload']['tenant'] ?? 0 ), 'payload survives the round trip' );
		$this->assert_same( 'trace-abc', $open['trace_id'] ?? '', 'trace id survives the round trip' );
		$this->assert_same( 'g1', (string) ( $open['meta']['group'] ?? '' ), 'group context survives the round trip' );

		$this->assert_same( null, Envelope::open( 'not json at all' ), 'garbage parses to nothing' );
		$this->assert_same( null, Envelope::open( '{"job_type":"x"}' ), 'a version-less envelope is rejected' );

		$future = json_decode( $json, true );
		$future['v'] = 99;
		$this->assert_same( 99, Envelope::open( (string) wp_json_encode( $future ) )['v'] ?? 0, 'future versions still parse — the dispatcher decides' );
	}

	private function enqueue_stamps_tenant_trace_and_envelope(): void {
		$this->fresh();

		$id = $this->queue->enqueue( 'feed.sync', [ 'account' => 12 ], [ 'tenant_id' => 4, 'trace_id' => 'trace-9' ] );
		$this->assert_true( $id > 0, 'enqueue returns the job id' );

		$row = $this->job( $id );
		$this->assert_same( 'pending', (string) $row['status'], 'jobs start pending' );
		$this->assert_same( 4, (int) $row['tenant_id'], 'the tenant is stamped on enqueue' );
		$this->assert_same( 0, (int) $row['attempts'], 'no attempts before any claim' );

		$open = Envelope::open( (string) $row['envelope'] );
		$this->assert_same( 'trace-9', $open['trace_id'] ?? '', 'the given trace id travels inside the envelope' );
		$this->assert_same( 12, (int) ( $open['payload']['account'] ?? 0 ), 'payload is inside the envelope' );
	}

	private function enqueue_is_idempotent_per_queue_and_key(): void {
		$this->fresh();

		$first  = $this->queue->enqueue( 'order.export', [ 'n' => 1 ], [ 'idempotency_key' => 'key-A', 'tenant_id' => 1 ] );
		$second = $this->queue->enqueue( 'order.export', [ 'n' => 1 ], [ 'idempotency_key' => 'key-A', 'tenant_id' => 1 ] );
		$this->assert_same( $first, $second, 're-enqueue with the same key returns the same job' );
		$this->assert_same( 1, count( $this->wpdb->tables['jobs'] ), 'no duplicate row for the same key' );

		$third = $this->queue->enqueue( 'order.export', [ 'n' => 1 ], [ 'idempotency_key' => 'key-B', 'tenant_id' => 1 ] );
		$this->assert_not_same( $first, $third, 'a different key is a different job' );
		$this->assert_same( 2, count( $this->wpdb->tables['jobs'] ), 'two rows for two keys' );

		// A finished job releases its key: the same operation may legitimately run again later.
		$this->queue->complete( $first );
		$fourth = $this->queue->enqueue( 'order.export', [ 'n' => 1 ], [ 'idempotency_key' => 'key-A', 'tenant_id' => 1 ] );
		$this->assert_not_same( $first, $fourth, 'a done job releases its idempotency key' );
	}

	private function claim_leases_only_due_jobs_once(): void {
		$this->fresh();

		$due    = $this->queue->enqueue( 'a', [], [ 'tenant_id' => 1 ] );
		$future = $this->queue->enqueue( 'b', [], [ 'tenant_id' => 1, 'delay_seconds' => 3600 ] );

		$claimed = $this->queue->claim( 10 );
		$this->assert_same( 1, count( $claimed ), 'only the due job is claimed' );
		$this->assert_same( $due, (int) $claimed[0]['id'], 'the due job is the one claimed' );

		$row = $this->job( $due );
		$this->assert_same( 'claimed', (string) $row['status'], 'claim moves the job to claimed' );
		$this->assert_same( 1, (int) $row['attempts'], 'claim counts as the first attempt' );
		$this->assert_true( null !== $row['claim_expires_at'], 'the claim carries a lease expiry' );
		$this->assert_true( $this->job( $future )['available_at'] > current_time( 'mysql', true ), 'delayed jobs stay in the future' );

		$this->assert_same( [], $this->queue->claim( 10 ), 'a second worker claims nothing — the lease holds' );
	}

	private function successful_run_completes_and_hands_context_to_the_handler(): void {
		$this->fresh();

		$seen = null;
		$this->queue->register( 'cache.rebuild', function ( array $payload, JobContext $ctx ) use ( &$seen ): void {
			$seen = [ 'payload' => $payload, 'ctx' => $ctx ];
		} );

		$id      = $this->queue->enqueue( 'cache.rebuild', [ 'scope' => 'all' ], [ 'tenant_id' => 6, 'trace_id' => 'tr-1', 'idempotency_key' => 'k-1', 'group' => 'warm' ] );
		$claimed = $this->queue->claim( 10 );
		[ $done, $failed, $dead ] = $this->queue->process( $claimed );

		$this->assert_same( [ 1, 0, 0 ], [ $done, $failed, $dead ], 'one clean run: done=1 failed=0 dead=0' );
		$this->assert_same( 'done', (string) $this->job( $id )['status'], 'success marks the job done' );
		$this->assert_same( 'all', (string) ( $seen['payload']['scope'] ?? '' ), 'handler receives the payload' );
		/** @var JobContext $ctx */
		$ctx = $seen['ctx'];
		$this->assert_same( 6, $ctx->tenant_id, 'context carries the tenant' );
		$this->assert_same( 'tr-1', $ctx->trace_id, 'context carries the trace id' );
		$this->assert_same( 'k-1', $ctx->idempotency_key, 'context carries the stable idempotency key' );
		$this->assert_same( 1, $ctx->attempt, 'context knows this is attempt 1' );
		$this->assert_same( 'warm', $ctx->group, 'context carries the group' );
	}

	private function failure_schedules_a_jittered_exponential_retry(): void {
		$this->fresh();

		$this->queue->register( 'flaky', static function (): void {
			throw new RuntimeException( 'provider 503' );
		} );

		$id = $this->queue->enqueue( 'flaky', [], [ 'tenant_id' => 1, 'max_attempts' => 5 ] );
		[ $done, $failed, $dead ] = $this->queue->process( $this->queue->claim( 10 ) );

		$this->assert_same( [ 0, 1, 0 ], [ $done, $failed, $dead ], 'a failed attempt is a retry, not a dead letter' );
		$row = $this->job( $id );
		$this->assert_same( 'pending', (string) $row['status'], 'the failed job returns to pending' );
		$this->assert_same( 'provider 503', (string) $row['last_error'], 'the failure reason is recorded' );

		$delay = strtotime( (string) $row['available_at'] . ' UTC' ) - strtotime( current_time( 'mysql', true ) . ' UTC' );
		$this->assert_true(
			$delay >= JobQueue::BACKOFF_BASE_SECONDS && $delay < 2 * JobQueue::BACKOFF_BASE_SECONDS,
			'first retry waits base + jitter (10-19s), got ' . $delay . 's'
		);
	}

	private function final_failure_dead_letters_instead_of_looping(): void {
		$this->fresh();

		$this->queue->register( 'always.fails', static function (): void {
			throw new RuntimeException( 'permanent' );
		} );

		$id = $this->queue->enqueue( 'always.fails', [], [ 'tenant_id' => 1, 'max_attempts' => 2 ] );

		// Attempt 1 fails -> retry; force it due again; attempt 2 fails -> dead.
		$this->queue->process( $this->queue->claim( 10 ) );
		$this->wpdb->tables['jobs'][ $id ]['available_at'] = gmdate( 'Y-m-d H:i:s', time() - 1 );
		[ $done, $failed, $dead ] = $this->queue->process( $this->queue->claim( 10 ) );

		$this->assert_same( [ 0, 0, 1 ], [ $done, $failed, $dead ], 'the final attempt dead-letters' );
		$row = $this->job( $id );
		$this->assert_same( 'dead', (string) $row['status'], 'a poison job ends in the dead-letter state' );
		$this->assert_same( 'permanent', (string) $row['last_error'], 'the dead letter keeps the reason' );

		$this->assert_same( [], $this->queue->claim( 10 ), 'a dead job is never claimed again' );
	}

	private function unrunnable_jobs_are_isolated_not_guessed(): void {
		$this->fresh();

		// No handler registered for this type.
		$no_handler = $this->queue->enqueue( 'nobody.handles.this', [], [ 'tenant_id' => 1 ] );

		// Malformed envelope: plant it directly.
		$malformed = $this->queue->enqueue( 'x', [], [ 'tenant_id' => 1 ] );
		$this->wpdb->tables['jobs'][ $malformed ]['envelope'] = 'corrupted{{{';

		// Envelope from a future schema version.
		$future = $this->queue->enqueue( 'y', [], [ 'tenant_id' => 1 ] );
		$decoded = json_decode( (string) $this->wpdb->tables['jobs'][ $future ]['envelope'], true );
		$decoded['v'] = Envelope::VERSION + 1;
		$this->wpdb->tables['jobs'][ $future ]['envelope'] = (string) wp_json_encode( $decoded );

		[ $done, $failed, $dead ] = $this->queue->process( $this->queue->claim( 10 ) );
		$this->assert_same( 3, $dead, 'all three unrunnable jobs are isolated' );
		$this->assert_contains( 'no handler', (string) $this->job( $no_handler )['last_error'], 'missing handler is named in the dead letter' );
		$this->assert_contains( 'malformed', (string) $this->job( $malformed )['last_error'], 'garbage envelope is named in the dead letter' );
		$this->assert_contains( 'unsupported envelope version', (string) $this->job( $future )['last_error'], 'future version is dead-lettered, never guessed' );
	}

	private function expired_leases_come_back_or_die_by_attempts(): void {
		$this->fresh();

		// Job with attempts left: an expired lease (crashed worker) returns it to the queue.
		$returnable = $this->queue->enqueue( 'crashy', [], [ 'tenant_id' => 1, 'max_attempts' => 3 ] );
		// Job on its final attempt: an expired lease means it died mid-run for the last time.
		$final = $this->queue->enqueue( 'crashy-final', [], [ 'tenant_id' => 1, 'max_attempts' => 1 ] );
		$this->queue->claim( 10 );
		$past = gmdate( 'Y-m-d H:i:s', time() - 5 );
		$this->wpdb->tables['jobs'][ $returnable ]['claim_expires_at'] = $past;
		$this->wpdb->tables['jobs'][ $final ]['claim_expires_at']      = $past;

		[ $returned, $dead ] = $this->queue->reclaim_expired_leases();
		$this->assert_same( 1, $returned, 'a crashed job with attempts left comes back' );
		$this->assert_same( 1, $dead, 'a crashed job out of attempts is dead-lettered' );
		$this->assert_same( 'pending', (string) $this->job( $returnable )['status'], 'the returned job is claimable again' );
		$this->assert_same( 'dead', (string) $this->job( $final )['status'], 'the exhausted crash is isolated' );
		$this->assert_contains( 'lease expired', (string) $this->job( $final )['last_error'], 'the dead letter explains the lease expiry' );
	}

	private function cancel_stops_pending_jobs_but_not_running_ones(): void {
		$this->fresh();

		$running = $this->queue->enqueue( 'already.claimed', [], [ 'tenant_id' => 1 ] );
		$this->queue->claim( 10 );
		// Enqueued after the claim, so it stays pending.
		$pending = $this->queue->enqueue( 'to.cancel', [], [ 'tenant_id' => 1 ] );

		$this->assert_true( $this->queue->cancel( $pending ), 'a pending job can be cancelled' );
		$this->assert_same( 'cancelled', (string) $this->job( $pending )['status'], 'cancel moves it to cancelled' );

		$this->assert_false( $this->queue->cancel( $running ), 'a claimed job cannot be cancelled out from under the worker' );
		$this->assert_same( 'claimed', (string) $this->job( $running )['status'], 'the running job keeps its lease' );
	}

	private function retry_delay_is_bounded_and_capped(): void {
		$this->fresh();

		foreach ( [ 1, 2, 3, 4 ] as $attempt ) {
			$expected = JobQueue::BACKOFF_BASE_SECONDS * ( 2 ** ( $attempt - 1 ) );
			$delay    = $this->queue->retry_delay_seconds( $attempt );
			$this->assert_true(
				$delay >= $expected && $delay < $expected + JobQueue::BACKOFF_BASE_SECONDS,
				"attempt $attempt backoff is base*2^(n-1) plus jitter in [0,base), got $delay"
			);
		}

		$late = $this->queue->retry_delay_seconds( 30 );
		$this->assert_true(
			$late <= JobQueue::BACKOFF_CAP_SECONDS + JobQueue::BACKOFF_BASE_SECONDS,
			'backoff is capped — retries never drift hours into the future'
		);
	}
}
