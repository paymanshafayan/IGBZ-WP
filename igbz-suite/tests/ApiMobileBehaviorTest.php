<?php
/**
 * Phase 67 — mobile API behaviour: the opaque cursor and idempotent writes.
 *
 * The cursor is opaque and strict — round-trips work, and everything else (malformed
 * base64, a foreign endpoint's token, a hand-edited tuple, an oversize bookmark) is
 * rejected so a corrupted bookmark can never address a different slice.
 *
 * Idempotency is the Stripe/Adyen key model: the claim is atomic (the unique index
 * decides), replays return the stored response verbatim — errors included, so a failed
 * write replays its failure — an in-flight first attempt answers busy, a key reused for
 * a different request answers conflict, a crashed claimant's lease may be taken over,
 * expired outcomes are forgotten and re-claimed, and the prune clears only rows past
 * their window.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\RestApi\Idempotency\IdempotencyService;
use IGBZ\Suite\Modules\RestApi\Contract\ApiContractService;
use IGBZ\Suite\Modules\RestApi\Pagination\CursorCodec;
use IGBZ\Suite\Support\Db;

/** Flat store for api_idempotency with honest claim/update/delete/DELETE semantics. */
final class IdemDb extends wpdb {
	public array $rows = [];
	protected int $next_id = 1;

	public function insert( string $table, array $data, $format = null ): int|bool {
		if ( ! str_contains( $table, 'igbz_api_idempotency' ) ) {
			return parent::insert( $table, $data, $format );
		}
		// The unique key idem_claim (user_id, idem_key) is the atomic arbiter.
		foreach ( $this->rows as $row ) {
			if ( (int) $row['user_id'] === (int) $data['user_id'] && (string) $row['idem_key'] === (string) $data['idem_key'] ) {
				return false;
			}
		}
		$data['id'] = $this->next_id++;
		foreach ( [ 'response_code', 'response_body' ] as $column ) {
			$data[ $column ] = $data[ $column ] ?? null;
		}
		$this->rows[ $data['id'] ] = $data;
		$this->insert_id = $data['id'];

		return 1;
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		if ( ! str_contains( $table, 'igbz_api_idempotency' ) ) {
			return parent::update( $table, $data, $where, $format, $where_format );
		}
		$changed = 0;
		foreach ( $this->rows as $id => $row ) {
			if ( ! $this->where_match( $row, $where ) ) { continue; }
			$this->rows[ $id ] = array_merge( $row, $data );
			++$changed;
		}

		return $changed;
	}

	public function delete( string $table, array $where, $format = null ): int|bool {
		if ( ! str_contains( $table, 'igbz_api_idempotency' ) ) {
			return parent::delete( $table, $where, $format );
		}
		$removed = 0;
		foreach ( $this->rows as $id => $row ) {
			if ( ! $this->where_match( $row, $where ) ) { continue; }
			unset( $this->rows[ $id ] );
			++$removed;
		}

		return $removed;
	}

	public function get_row( string $sql, ...$args ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'igbz_api_idempotency' ) ) {
			// Prepared SQL: user_id = '7' AND idem_key = 'key'.
			preg_match_all( "/([a-z_]+) = '([^']*)'/", $sql, $pairs, PREG_SET_ORDER );
			foreach ( $this->rows as $row ) {
				if ( $this->sql_where_match( $row, $pairs ) ) { return $row; }
			}

			return null;
		}

		return parent::get_row( $sql, ...$args );
	}

	/** DELETE batches from prune_expired(): "DELETE FROM t WHERE expires_at < '...' ORDER BY id LIMIT %d". */
	public function query( string $sql, ...$args ): int|bool {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'DELETE' ) && str_contains( $sql, 'igbz_api_idempotency' ) && preg_match( "/expires_at < '([^']+)'/", $sql, $m ) ) {
			$cutoff = $m[1];
			$removed = 0;
			foreach ( $this->rows as $id => $row ) {
				if ( (string) $row['expires_at'] < $cutoff ) {
					unset( $this->rows[ $id ] );
					++$removed;
				}
			}

			return $removed;
		}

		return parent::query( $sql, ...$args );
	}

	private function where_match( array $row, array $where ): bool {
		foreach ( $where as $column => $value ) {
			if ( is_int( $value ) ) {
				if ( (int) ( $row[ $column ] ?? -1 ) !== $value ) { return false; }
				continue;
			}
			if ( null === $value ) {
				if ( null !== ( $row[ $column ] ?? null ) ) { return false; }
				continue;
			}
			if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) { return false; }
		}

		return true;
	}

	/** @param array<int,array{0:string,1:string,2:string}> $pairs regex sets: full match, column, value */
	private function sql_where_match( array $row, array $pairs ): bool {
		foreach ( $pairs as $pair ) {
			if ( (string) ( $row[ $pair[1] ] ?? '' ) !== $pair[2] ) { return false; }
		}

		return true;
	}
}

final class ApiMobileBehaviorTest extends TestCase {

	private IdemDb $db;
	private IdempotencyService $service;
	private int $clock = 1_800_000_000;

	public function run(): void {
		$GLOBALS['wpdb'] = $this->db = new IdemDb();
		$db              = new Db();
		$this->service   = new IdempotencyService( $db, null, fn (): int => $this->clock );

		$this->the_cursor_round_trips_and_stays_opaque();
		$this->the_cursor_rejects_everything_not_canonical();
		$this->the_idempotency_key_has_a_format();
		$this->the_fingerprint_is_canonical();
		$this->a_retry_replays_the_first_outcome_verbatim();
		$this->an_in_flight_attempt_answers_busy();
		$this->a_key_reused_for_another_request_answers_conflict();
		$this->a_dead_claimants_lease_can_be_taken_over();
		$this->an_expired_outcome_is_forgotten_and_reclaimed();
		$this->the_prune_clears_only_expired_rows();
		$this->the_contract_keeps_http_methods_apart();
	}

	// ------------------------------------------------------------- cursor

	private function the_cursor_round_trips_and_stays_opaque(): void {
		$cursor = CursorCodec::encode( CursorCodec::KIND_ORDERS, [ 't' => 1_799_000_000, 'i' => 4242 ] );

		$this->assert_not_contains( '4242', $cursor, 'the cursor is opaque: the row id is not readable' );
		$this->assert_same( [ 't' => 1_799_000_000, 'i' => 4242 ], CursorCodec::decode( $cursor, CursorCodec::KIND_ORDERS ), 'round trip' );
		$this->assert_null_ok( CursorCodec::decode( $cursor, CursorCodec::KIND_WALLET ), 'a wallet feed refuses an orders cursor' );
	}

	private function the_cursor_rejects_everything_not_canonical(): void {
		$good = CursorCodec::encode( CursorCodec::KIND_WALLET, [ 'i' => 9 ] );

		$this->assert_null_ok( CursorCodec::decode( 'not-a-cursor!', CursorCodec::KIND_WALLET ), 'non-token characters' );
		$this->assert_null_ok( CursorCodec::decode( base64_encode( '{not json' ), CursorCodec::KIND_WALLET ), 'valid base64 of garbage' );
		$this->assert_null_ok( CursorCodec::decode( $good . 'x', CursorCodec::KIND_WALLET ), 'a hand-edited tail changes the token' );
		$this->assert_null_ok( CursorCodec::decode( str_repeat( 'A', 600 ), CursorCodec::KIND_WALLET ), 'oversize bookmark' );

		$float = rtrim( strtr( base64_encode( (string) wp_json_encode( [ 'v' => 1, 'k' => 'wallet', 'p' => [ 'i' => 1.5 ] ] ) ), '+/', '-_' ), '=' );
		$this->assert_null_ok( CursorCodec::decode( $float, CursorCodec::KIND_WALLET ), 'a float position is not a canonical tuple' );

		$wrong_version = rtrim( strtr( base64_encode( (string) wp_json_encode( [ 'v' => 2, 'k' => 'wallet', 'p' => [ 'i' => 9 ] ] ) ), '+/', '-_' ), '=' );
		$this->assert_null_ok( CursorCodec::decode( $wrong_version, CursorCodec::KIND_WALLET ), 'a future cursor version is refused, not guessed' );
	}

	// -------------------------------------------------------- idempotency

	private function the_idempotency_key_has_a_format(): void {
		$this->assert_true( IdempotencyService::valid_key( wp_generate_uuid4() ), 'a UUID is the intended key' );
		$this->assert_true( IdempotencyService::valid_key( 'order-123:create' ), 'readable client keys are allowed' );
		$this->assert_false( IdempotencyService::valid_key( 'short' ), 'too short' );
		$this->assert_false( IdempotencyService::valid_key( 'has spaces in it' ), 'whitespace' );
		$this->assert_false( IdempotencyService::valid_key( '' ), 'empty' );
	}

	private function the_fingerprint_is_canonical(): void {
		$a = IdempotencyService::fingerprint( 'POST', '/igbz/v1/account/wallet/topup', [ 'amount' => 100, 'gateway' => 'zarinpal' ] );
		$b = IdempotencyService::fingerprint( 'POST', '/igbz/v1/account/wallet/topup', [ 'gateway' => 'zarinpal', 'amount' => 100 ] );

		$this->assert_same( $a, $b, 'body key order does not change the intent' );
		$this->assert_not_same( $a, IdempotencyService::fingerprint( 'POST', '/igbz/v1/account/wallet/topup', [ 'amount' => 200, 'gateway' => 'zarinpal' ] ), 'a different body is a different intent' );
		$this->assert_not_same( $a, IdempotencyService::fingerprint( 'POST', '/igbz/v1/fx/topup', [ 'amount' => 100, 'gateway' => 'zarinpal' ] ), 'a different path is a different intent' );
	}

	private function a_retry_replays_the_first_outcome_verbatim(): void {
		$key   = wp_generate_uuid4();
		$claim = $this->service->claim( 7, $key, 'POST', '/igbz/v1/account/wallet/topup', [ 'amount' => 100 ] );

		$this->assert_same( 'new', $claim['status'], 'the first attempt claims the key' );

		$this->service->complete( (int) $claim['id'], 200, [ 'ok' => true, 'payment_id' => 51 ] );

		$replay = $this->service->claim( 7, $key, 'POST', '/igbz/v1/account/wallet/topup', [ 'amount' => 100 ] );
		$this->assert_same( 'replay', $replay['status'], 'the retry replays' );
		$this->assert_same( 200, $replay['code'], 'same status' );
		$this->assert_same( [ 'ok' => true, 'payment_id' => 51 ], $replay['body'], 'same body verbatim' );

		// Errors replay too: a retry after a failed write must not re-run the write.
		$key2   = wp_generate_uuid4();
		$claim2 = $this->service->claim( 7, $key2, 'POST', '/igbz/v1/account/instalments/3/pay', [] );
		$this->service->complete( (int) $claim2['id'], 400, [ 'ok' => false, 'code' => 'payment_failed' ] );
		$replay2 = $this->service->claim( 7, $key2, 'POST', '/igbz/v1/account/instalments/3/pay', [] );
		$this->assert_same( 400, $replay2['code'], 'a stored failure replays as the failure' );

		// Keys are scoped to the caller: another user may hold the same key.
		$other = $this->service->claim( 8, $key, 'POST', '/igbz/v1/account/wallet/topup', [ 'amount' => 100 ] );
		$this->assert_same( 'new', $other['status'], 'the same key belongs to a different namespace per user' );
	}

	private function an_in_flight_attempt_answers_busy(): void {
		$key = wp_generate_uuid4();
		$this->service->claim( 7, $key, 'POST', '/igbz/v1/ai/studio/generate', [ 'prompt' => 'x' ] );

		$this->clock += 10; // still well inside the lease
		$second = $this->service->claim( 7, $key, 'POST', '/igbz/v1/ai/studio/generate', [ 'prompt' => 'x' ] );

		$this->assert_same( 'busy', $second['status'], 'a concurrent retry is told to come back' );
	}

	private function a_key_reused_for_another_request_answers_conflict(): void {
		$key = wp_generate_uuid4();
		$this->service->claim( 7, $key, 'POST', '/igbz/v1/account/wallet/topup', [ 'amount' => 100 ] );
		$this->service->complete( 1, 200, [ 'ok' => true ] );

		$misuse = $this->service->claim( 7, $key, 'POST', '/igbz/v1/account/wallet/topup', [ 'amount' => 500 ] );

		$this->assert_same( 'conflict', $misuse['status'], 'key reuse across intents is a client bug, never a silent replay' );

		$bad = $this->service->claim( 7, 'nope', 'POST', '/igbz/v1/account/wallet/topup', [] );
		$this->assert_same( 'invalid_key', $bad['status'], 'an unusable key is refused outright' );
	}

	private function a_dead_claimants_lease_can_be_taken_over(): void {
		$key   = wp_generate_uuid4();
		$claim = $this->service->claim( 7, $key, 'POST', '/igbz/v1/courier/shipments/9/deliver', [ 'signature' => 'abc' ] );
		$this->assert_same( 'new', $claim['status'], 'claim won' );

		// The claimant crashes mid-request: past the lease the row is reclaimable, same id.
		$this->clock += IdempotencyService::LEASE_SECONDS + 5;
		$takeover = $this->service->claim( 7, $key, 'POST', '/igbz/v1/courier/shipments/9/deliver', [ 'signature' => 'abc' ] );

		$this->assert_same( 'new', $takeover['status'], 'the lease expires' );
		$this->assert_same( (int) $claim['id'], (int) $takeover['id'], 'the takeover reuses the claim row' );
	}

	private function an_expired_outcome_is_forgotten_and_reclaimed(): void {
		$key   = wp_generate_uuid4();
		$claim = $this->service->claim( 7, $key, 'POST', '/igbz/v1/account/quizzes/2/submit', [ 'answers' => [ '1' => 'a' ] ] );
		$this->service->complete( (int) $claim['id'], 200, [ 'ok' => true, 'score' => 4 ] );

		$this->clock += IdempotencyService::RETENTION_HOURS * HOUR_IN_SECONDS + 300;
		$fresh = $this->service->claim( 7, $key, 'POST', '/igbz/v1/account/quizzes/2/submit', [ 'answers' => [ '1' => 'a' ] ] );

		$this->assert_same( 'new', $fresh['status'], 'past the replay window the key is new again' );
	}

	private function the_prune_clears_only_expired_rows(): void {
		$a = $this->service->claim( 7, wp_generate_uuid4(), 'POST', '/p/a', [] );
		$this->service->complete( (int) $a['id'], 200, [ 'ok' => true ] );

		$this->clock += IdempotencyService::RETENTION_HOURS * HOUR_IN_SECONDS + 3600;
		$b = $this->service->claim( 7, wp_generate_uuid4(), 'POST', '/p/b', [] ); // expires far in the future again

		$deleted = $this->service->prune_expired();

		// Every earlier row is past its window too (the clock has moved days); the fresh
		// claim must be the only survivor.
		$this->assert_true( $deleted > 0, 'expired outcomes are pruned' );
		$this->assert_same( 1, count( $this->db->rows ), 'only the fresh claim survives' );
		$this->assert_true( isset( $this->db->rows[ (int) $b['id'] ] ), 'the survivor is the fresh claim' );
	}

	/**
	 * Regression (found live in phase 67): WordPress registers methods as an associative
	 * array ('GET' => true). Iterating it by value turned every operation into '1', so GET
	 * and POST on one path collapsed into a single operation and the parameters of the
	 * surviving method silently vanished from the published contract.
	 */
	private function the_contract_keeps_http_methods_apart(): void {
		$routes = [
			'/igbz/v1/profile' => [
				[
					'methods'             => [ 'GET' => true ],
					'permission_callback' => '__return_true',
					'args'                => [ 'cursor' => [ 'type' => 'string', 'required' => false ] ],
				],
				[
					'methods'             => [ 'POST' => true ],
					'permission_callback' => '__return_true',
					'args'                => [ 'expected_revision' => [ 'type' => 'integer' ] ],
				],
			],
		];

		$doc     = ( new ApiContractService() )->document( $routes );
		$methods = array_keys( $doc['paths']['/profile'] );

		$this->assert_same( [ 'GET', 'POST' ], $methods, 'each HTTP method is its own operation' );
		$this->assert_same( [ 'cursor' ], array_column( $doc['paths']['/profile']['GET']['parameters'], 'name' ), 'the GET parameters survive' );
		$this->assert_same( [ 'expected_revision' ], array_column( $doc['paths']['/profile']['POST']['parameters'], 'name' ), 'the POST parameters survive' );

		// The gate must see the fixed document as an additive change against the old one.
		$old = ( new ApiContractService() )->document( $routes );
		$new = json_decode( (string) wp_json_encode( $old ), true );
		$new['paths']['/profile']['GET']['parameters'][] = [ 'name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => [ 'type' => 'integer' ] ];
		$this->assert_same( [], ApiContractService::breaking_changes( $old, $new ), 'an added optional parameter stays additive' );
	}

	/** CursorCodec::decode returns array|null; TestCase has no assert_null. */
	private function assert_null_ok( mixed $actual, string $message ): void {
		$this->assert_same( null, $actual, $message );
	}
}
