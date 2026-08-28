<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 19: the migration runner.
 *
 * Upgrades used to run every step in a single unprotected pass: a timeout halfway through
 * left no trace of what had completed, two concurrent requests could run the same steps at
 * once, and nobody could tell how far an upgrade had got. This runner adds the four missing
 * guarantees, all on plain options so they work on any WordPress storage backend:
 *
 * - lock:        one runner at a time; a stale lock (dead process) is taken over, a fresh
 *                one makes the second request stand down;
 * - checkpoint:  after every completed step the runner records where it got to, so a
 *                re-run resumes instead of starting over;
 * - idempotency: every registered step must be safe to re-run (the contract the Activator
 *                steps already follow), which is what makes resume safe;
 * - progress:    a readable record of from/to/done/pending for the admin screens.
 *
 * The rollback path is deliberately honest instead of magical: schema steps here are
 * forward-only (dbDelta), so the runner remembers the version it upgraded FROM and the
 * documented recovery is restoring a database backup and writing that version back —
 * never silently rewinding schema state.
 */
final class Migrator {

	public const LOCK_OPTION        = 'igbz_migration_lock';
	public const CHECKPOINT_OPTION  = 'igbz_migration_checkpoint';
	public const PROGRESS_OPTION    = 'igbz_migration_progress';
	public const PREVIOUS_OPTION    = 'igbz_previous_db_version';

	/** A lock older than this belonged to a process that died mid-upgrade. */
	private const LOCK_TTL = 600;

	/** @var array<int,callable> target version => step */
	private array $steps = [];

	public function add( int $version, callable $step ): void {
		$this->steps[ $version ] = $step;
	}

	/**
	 * Run every pending step strictly after $from up to $to.
	 *
	 * @return array{ok:bool,error:string,executed:int[],failed:int,resumed:bool}
	 */
	public function run( int $from, int $to ): array {
		$fail = static fn ( string $error, array $executed = [], int $failed = 0, bool $resumed = false ): array =>
			[ 'ok' => false, 'error' => $error, 'executed' => $executed, 'failed' => $failed, 'resumed' => $resumed ];

		if ( $from >= $to ) {
			return [ 'ok' => true, 'error' => '', 'executed' => [], 'failed' => 0, 'resumed' => false ];
		}

		$token = Crypto::token( 12 );
		if ( ! $this->acquire_lock( $token ) ) {
			return $fail( 'locked' );
		}

		try {
			$checkpoint = max( $from, (int) get_option( self::CHECKPOINT_OPTION, 0 ) );
			$resumed    = $checkpoint > $from;

			$pending = array_values( array_filter(
				array_keys( $this->steps ),
				static fn ( int $version ): bool => $version > $checkpoint && $version <= $to
			) );
			sort( $pending );

			if ( $pending ) {
				update_option( self::PREVIOUS_OPTION, $from, false );
			}

			$executed = [];
			foreach ( $pending as $version ) {
				$this->report( $from, $to, $executed, $pending );
				try {
					( $this->steps[ $version ] )();
				} catch ( \Throwable $e ) {
					// The checkpoint stays where it was: the next run resumes from here.
					$this->report( $from, $to, $executed, $pending );
					return $fail( 'step_failed: ' . $e->getMessage(), $executed, $version, $resumed );
				}
				$executed[] = $version;
				update_option( self::CHECKPOINT_OPTION, $version, false );
			}

			update_option( 'igbz_db_version', $to, true );
			delete_option( self::CHECKPOINT_OPTION );
			delete_option( self::PROGRESS_OPTION );

			return [ 'ok' => true, 'error' => '', 'executed' => $executed, 'failed' => 0, 'resumed' => $resumed ];
		} finally {
			$this->release_lock( $token );
		}
	}

	/** @return array{from:int,to:int,done:int[],pending:int[],updated_at:string} */
	public function status(): array {
		$progress = get_option( self::PROGRESS_OPTION, [] );
		return is_array( $progress )
			? $progress + [ 'from' => 0, 'to' => 0, 'done' => [], 'pending' => [], 'updated_at' => '' ]
			: [ 'from' => 0, 'to' => 0, 'done' => [], 'pending' => [], 'updated_at' => '' ];
	}

	/** The version upgraded from — the documented rollback target once a backup is restored. */
	public static function previous_version(): int {
		return (int) get_option( self::PREVIOUS_OPTION, 0 );
	}

	/** @param int[] $done @param int[] $pending */
	private function report( int $from, int $to, array $done, array $pending ): void {
		update_option(
			self::PROGRESS_OPTION,
			[
				'from'       => $from,
				'to'         => $to,
				'done'       => array_values( array_intersect( $pending, $done ) ),
				'pending'    => array_values( array_diff( $pending, $done ) ),
				'updated_at' => current_time( 'mysql', true ),
			],
			false
		);
	}

	private function acquire_lock( string $token ): bool {
		$existing = get_option( self::LOCK_OPTION, false );
		if ( is_array( $existing ) ) {
			$age = time() - (int) ( $existing['at'] ?? 0 );
			if ( $age < self::LOCK_TTL ) {
				return false;
			}
			// The previous runner died mid-upgrade; take the lock over.
			delete_option( self::LOCK_OPTION );
		}

		update_option( self::LOCK_OPTION, [ 'token' => $token, 'at' => time() ], false );

		// Re-read to lose a race honestly: whoever's token is stored owns the lock.
		$stored = get_option( self::LOCK_OPTION, false );
		return is_array( $stored ) && ( $stored['token'] ?? '' ) === $token;
	}

	private function release_lock( string $token ): void {
		$stored = get_option( self::LOCK_OPTION, false );
		if ( is_array( $stored ) && ( $stored['token'] ?? '' ) === $token ) {
			delete_option( self::LOCK_OPTION );
		}
	}
}
