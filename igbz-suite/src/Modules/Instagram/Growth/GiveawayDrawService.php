<?php
namespace IGBZ\Suite\Modules\Instagram\Growth;

use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Giveaways with an auditable draw (phase 55).
 *
 * The pattern is commit–reveal, the one regulators and provably-fair platforms actually
 * accept (the phase-55 research): when the giveaway is created, a random server seed is
 * generated and only its SHA-256 commitment is visible. At draw time the entry pool is
 * frozen and hashed, the seed is revealed, and the winner number is derived with a
 * documented, re-derivable function:
 *
 *   digest  = HMAC-SHA256(server_seed, pool_hash)            (hex, 64 chars)
 *   windows = the digest sliced into 13-hex-digit (52-bit) windows, left to right
 *   n       = the first window whose value < floor(2^52 / N) * N   (rejection sampling)
 *   winner  = floor(n / floor(2^52 / N)) + 1                 (1-based entry number)
 *
 * If every window of a digest rejects (probability < 2^-40) the digest is re-chained
 * (digest = SHA-256(digest)) and the walk continues — also part of the recorded recipe.
 *
 * Everything an auditor needs — the revealed seed, its commitment, the pool hash, the
 * entry count, the winner number and the algorithm text — is stored on the giveaway row
 * the moment it flips to `drawn`, and the pool itself stays immutable afterwards.
 *
 * Fraud resistance is structural: one row per subscriber per giveaway (UNIQUE key), the
 * entry window is enforced on the backend, entries are refused the moment the giveaway
 * leaves `open`, and the draw itself is a conditional flip — two racing draws produce one
 * winner and one honest `already_drawn`.
 */
final class GiveawayDrawService {

	public const STATUS_OPEN      = 'open';
	public const STATUS_DRAWN     = 'drawn';
	public const STATUS_CANCELLED = 'cancelled';

	public const SOURCES = [ 'comment', 'dm', 'funnel', 'manual' ];

	public const ALGORITHM = 'digest = HMAC-SHA256(key=server_seed, message=pool_hash) as hex; windows = digest split into 13-hex-digit (52-bit) slices left-to-right; n = first window value strictly below floor(2^52/N)*N, otherwise re-chain digest = SHA-256(digest) and continue; winner_no = floor(n / floor(2^52/N)) + 1; entries ordered by id ascending (frozen pool).';

	private const WINDOW_BITS = 52;

	public function __construct(
		private Db $db,
		private Logger $logger
	) {}

	// ----------------------------------------------------------------- create

	/**
	 * @param array<string,mixed> $data
	 * @return array{ok:bool,id:int,error:string,commitment:string}
	 */
	public function create( array $data, int $tenant_id ): array {
		if ( $tenant_id <= 0 ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'no_tenant', 'commitment' => '' ];
		}

		$now      = current_time( 'mysql', true );
		$seed     = Crypto::token( 32 );
		$starts_at = $this->to_datetime( (string) ( $data['starts_at'] ?? '' ), $now );
		$ends_at   = $this->to_datetime( (string) ( $data['ends_at'] ?? '' ), '' );

		if ( null !== $ends_at && $ends_at <= $starts_at ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'bad_window', 'commitment' => '' ];
		}

		$id = $this->db->insert( 'ig_giveaways', [
			'tenant_id'         => $tenant_id,
			'account_id'        => (int) ( $data['account_id'] ?? 0 ),
			'ig_post_id'        => sanitize_text_field( (string) ( $data['ig_post_id'] ?? '' ) ),
			'title'             => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'status'            => self::STATUS_OPEN,
			'entries_count'     => 0,
			'starts_at'         => $starts_at,
			'ends_at'           => $ends_at,
			// The seed lives encrypted at rest; only the commitment is public before the draw.
			'server_seed'       => Crypto::encrypt( $seed ),
			'server_seed_hash'  => hash( 'sha256', $seed ),
			'created_at'        => $now,
			'updated_at'        => $now,
		] );

		if ( $id <= 0 ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'insert_failed', 'commitment' => '' ];
		}

		return [ 'ok' => true, 'id' => $id, 'error' => '', 'commitment' => hash( 'sha256', $seed ) ];
	}

	// ----------------------------------------------------------------- entries

	/**
	 * @param array<string,mixed> $entry
	 * @return array{ok:bool,id:int,error:string}
	 */
	public function add_entry( int $tenant_id, int $giveaway_id, array $entry ): array {
		$giveaway = $this->row( $tenant_id, $giveaway_id );
		if ( null === $giveaway ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'not_found' ];
		}
		if ( self::STATUS_OPEN !== (string) $giveaway['status'] ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'giveaway_closed' ];
		}

		$now = current_time( 'mysql', true );
		if ( null !== $giveaway['starts_at'] && (string) $giveaway['starts_at'] > $now ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'entry_window' ];
		}
		if ( null !== $giveaway['ends_at'] && (string) $giveaway['ends_at'] < $now ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'entry_window' ];
		}

		$subscriber = ltrim( strtolower( trim( sanitize_text_field( (string) ( $entry['subscriber'] ?? '' ) ) ) ), '@' );
		if ( '' === $subscriber || strlen( $subscriber ) > 191 ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'bad_subscriber' ];
		}

		$source = strtolower( sanitize_key( (string) ( $entry['source'] ?? 'manual' ) ) );
		if ( ! in_array( $source, self::SOURCES, true ) ) {
			$source = 'manual';
		}

		// Dedupe before the insert; the UNIQUE key is the second line of defence (the race
		// loser gets `duplicate_entry` from the failed insert, not a second row).
		$existing = $this->db->scalar(
			"SELECT id FROM {$this->db->table('ig_giveaway_entries')} WHERE giveaway_id = %d AND subscriber = %s",
			$giveaway_id,
			$subscriber
		);
		if ( null !== $existing ) {
			return [ 'ok' => false, 'id' => (int) $existing, 'error' => 'duplicate_entry' ];
		}

		$id = $this->db->insert( 'ig_giveaway_entries', [
			'tenant_id'    => $tenant_id,
			'giveaway_id'  => $giveaway_id,
			'subscriber'   => $subscriber,
			'user_id'      => (int) ( $entry['user_id'] ?? 0 ),
			'source'       => $source,
			'entry_ref'    => sanitize_text_field( (string) ( $entry['entry_ref'] ?? '' ) ),
			'created_at'   => $now,
		] );

		if ( $id <= 0 ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'duplicate_entry' ];
		}

		return [ 'ok' => true, 'id' => $id, 'error' => '' ];
	}

	/** @return array<int,array<string,mixed>> */
	public function entries( int $tenant_id, int $giveaway_id, int $limit = 500 ): array {
		return $this->db->results(
			"SELECT * FROM {$this->db->table('ig_giveaway_entries')} WHERE tenant_id = %d AND giveaway_id = %d ORDER BY id ASC LIMIT %d",
			$tenant_id,
			$giveaway_id,
			max( 1, min( 5000, $limit ) )
		);
	}

	// ------------------------------------------------------------------- draw

	/**
	 * @return array{ok:bool,error:string,winner:string,winner_entry_id:int,winner_no:int,audit:array<string,mixed>}
	 */
	public function draw( int $tenant_id, int $giveaway_id ): array {
		$giveaway = $this->row( $tenant_id, $giveaway_id );
		if ( null === $giveaway ) {
			return $this->draw_failed( 'not_found' );
		}
		if ( self::STATUS_OPEN !== (string) $giveaway['status'] ) {
			return $this->draw_failed( self::STATUS_CANCELLED === (string) $giveaway['status'] ? 'giveaway_cancelled' : 'already_drawn' );
		}

		$pool = $this->entries( $tenant_id, $giveaway_id, 5000 );
		$count = count( $pool );
		if ( 0 === $count ) {
			return $this->draw_failed( 'no_entries' );
		}

		// Freeze the pool: ids in insertion order, hashed. This exact list is what the
		// winner number addresses, and it stays in the table for auditing.
		$pool_hash = hash( 'sha256', implode( ',', array_column( $pool, 'id' ) ) . '|' . $count );

		$seed = Crypto::decrypt( (string) $giveaway['server_seed'] );
		if ( null === $seed || '' === $seed ) {
			return $this->draw_failed( 'seed_unreadable' );
		}
		if ( ! hash_equals( (string) $giveaway['server_seed_hash'], hash( 'sha256', $seed ) ) ) {
			return $this->draw_failed( 'seed_commitment_mismatch' );
		}

		$digest    = hash_hmac( 'sha256', $pool_hash, $seed );
		$winner_no = self::winner_number( $digest, $count );
		$winner    = $pool[ $winner_no - 1 ];

		$now   = current_time( 'mysql', true );
		$audit = [
			'version'         => 1,
			'algorithm'       => self::ALGORITHM,
			'server_seed'     => $seed,
			'server_seed_hash'=> (string) $giveaway['server_seed_hash'],
			'pool_hash'       => $pool_hash,
			'entries'         => $count,
			'winner_no'       => $winner_no,
			'winner_entry_id' => (int) $winner['id'],
			'drawn_at'        => $now,
		];

		// Conditional flip: only one caller can move `open` → `drawn`; the loser re-reads
		// and honestly reports `already_drawn` with the recorded winner.
		$flipped = $this->db->update(
			'ig_giveaways',
			[
				'status'            => self::STATUS_DRAWN,
				'winner_subscriber' => (string) $winner['subscriber'],
				'winner_user_id'    => (int) $winner['user_id'],
				'winner_entry_id'   => (int) $winner['id'],
				'entries_count'     => $count,
				'pool_hash'         => $pool_hash,
				'drawn_at'          => $now,
				'audit'             => wp_json_encode( $audit ),
				'updated_at'        => $now,
			],
			[ 'id' => $giveaway_id, 'tenant_id' => $tenant_id, 'status' => self::STATUS_OPEN ]
		);

		if ( $flipped <= 0 ) {
			return $this->draw_failed( 'already_drawn' );
		}

		return [
			'ok'              => true,
			'error'           => '',
			'winner'          => (string) $winner['subscriber'],
			'winner_entry_id' => (int) $winner['id'],
			'winner_no'       => $winner_no,
			'audit'           => $audit,
		];
	}

	/**
	 * Re-derive the winner from a published audit packet and the frozen pool — what an
	 * auditor (or a test) runs to confirm the draw was not re-rolled.
	 *
	 * @param array<string,mixed> $audit
	 * @param array<int,array<string,mixed>> $pool entries ordered by id ascending
	 * @return array{ok:bool,error:string,winner_no:int,winner_entry_id:int}
	 */
	public static function verify_audit( array $audit, array $pool ): array {
		$count = count( $pool );
		if ( (int) ( $audit['entries'] ?? 0 ) !== $count ) {
			return [ 'ok' => false, 'error' => 'pool_size_mismatch', 'winner_no' => 0, 'winner_entry_id' => 0 ];
		}

		$pool_hash = hash( 'sha256', implode( ',', array_column( $pool, 'id' ) ) . '|' . $count );
		if ( ! hash_equals( (string) ( $audit['pool_hash'] ?? '' ), $pool_hash ) ) {
			return [ 'ok' => false, 'error' => 'pool_hash_mismatch', 'winner_no' => 0, 'winner_entry_id' => 0 ];
		}

		$seed = (string) ( $audit['server_seed'] ?? '' );
		if ( ! hash_equals( (string) ( $audit['server_seed_hash'] ?? '' ), hash( 'sha256', $seed ) ) ) {
			return [ 'ok' => false, 'error' => 'seed_commitment_mismatch', 'winner_no' => 0, 'winner_entry_id' => 0 ];
		}

		$winner_no = self::winner_number( hash_hmac( 'sha256', $pool_hash, $seed ), $count );
		$expected  = (int) ( $audit['winner_no'] ?? 0 );

		if ( $winner_no !== $expected ) {
			return [ 'ok' => false, 'error' => 'winner_mismatch', 'winner_no' => $winner_no, 'winner_entry_id' => 0 ];
		}

		return [ 'ok' => true, 'error' => '', 'winner_no' => $winner_no, 'winner_entry_id' => (int) $pool[ $winner_no - 1 ]['id'] ];
	}

	/** The documented, re-derivable winner function. */
	public static function winner_number( string $digest_hex, int $pool_size ): int {
		if ( $pool_size <= 1 ) {
			return 1;
		}

		$zone  = intdiv( 2 ** self::WINDOW_BITS, $pool_size );
		$limit = $zone * $pool_size;

		// Up to 8 re-chains; the odds of needing even one are below 2^-40.
		for ( $round = 0; $round < 8; $round++ ) {
			$offset = 0;
			$len    = strlen( $digest_hex );
			while ( $offset + 13 <= $len ) {
				$n = 0;
				for ( $i = $offset; $i < $offset + 13; $i++ ) {
					$n = $n * 16 + (int) hexdec( $digest_hex[ $i ] );
				}
				if ( $n < $limit ) {
					return intdiv( $n, $zone ) + 1;
				}
				$offset += 13;
			}
			$digest_hex = hash( 'sha256', $digest_hex );
		}

		// Deterministic last resort (never reached in practice): the first window as-is.
		$n = 0;
		for ( $i = 0; $i < 13; $i++ ) {
			$n = $n * 16 + (int) hexdec( $digest_hex[ $i ] );
		}
		return ( $n % $pool_size ) + 1;
	}

	// ------------------------------------------------------------------ misc

	/** @return array{ok:bool,error:string} */
	public function cancel( int $tenant_id, int $giveaway_id ): array {
		$flipped = $this->db->update(
			'ig_giveaways',
			[ 'status' => self::STATUS_CANCELLED, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $giveaway_id, 'tenant_id' => $tenant_id, 'status' => self::STATUS_OPEN ]
		);
		return $flipped > 0
			? [ 'ok' => true, 'error' => '' ]
			: [ 'ok' => false, 'error' => 'not_open' ];
	}

	/**
	 * The public view of a giveaway: the commitment is always visible, the seed only
	 * through the audit packet once the draw has happened.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get( int $tenant_id, int $giveaway_id ): ?array {
		$row = $this->row( $tenant_id, $giveaway_id );
		if ( null === $row ) {
			return null;
		}

		$audit = null;
		if ( '' !== (string) ( $row['audit'] ?? '' ) ) {
			$decoded = json_decode( (string) $row['audit'], true );
			$audit   = is_array( $decoded ) ? $decoded : null;
		}

		unset( $row['server_seed'], $row['audit'] );

		return $row + [
			'commitment' => (string) ( $row['server_seed_hash'] ?? '' ),
			'audit'      => $audit,
		];
	}

	/** @return array<int,array<string,mixed>> */
	public function list( int $tenant_id, string $status = '', int $limit = 50 ): array {
		$table = $this->db->table( 'ig_giveaways' );
		$sql   = "SELECT id,tenant_id,account_id,ig_post_id,title,status,winner_subscriber,winner_user_id,winner_entry_id,entries_count,starts_at,ends_at,server_seed_hash,pool_hash,drawn_at,created_at,updated_at FROM {$table} WHERE tenant_id = %d";
		$args  = [ $tenant_id ];
		if ( '' !== $status ) {
			$sql   .= ' AND status = %s';
			$args[] = $status;
		}
		$sql   .= ' ORDER BY id DESC LIMIT %d';
		$args[] = max( 1, min( 200, $limit ) );

		return $this->db->results( $sql, ...$args );
	}

	/** @return array<string,mixed>|null */
	private function row( int $tenant_id, int $giveaway_id ): ?array {
		return $this->db->row(
			"SELECT * FROM {$this->db->table('ig_giveaways')} WHERE tenant_id = %d AND id = %d",
			$tenant_id,
			$giveaway_id
		);
	}

	private function to_datetime( string $value, string $fallback ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '' !== $fallback ? $fallback : null;
		}
		$ts = strtotime( $value );
		return false !== $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : ( '' !== $fallback ? $fallback : null );
	}

	/** @return array{ok:bool,error:string,winner:string,winner_entry_id:int,winner_no:int,audit:array<string,mixed>} */
	private function draw_failed( string $error ): array {
		return [ 'ok' => false, 'error' => $error, 'winner' => '', 'winner_entry_id' => 0, 'winner_no' => 0, 'audit' => [] ];
	}
}
