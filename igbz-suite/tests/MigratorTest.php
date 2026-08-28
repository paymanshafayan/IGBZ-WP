<?php
use IGBZ\Suite\Support\Migrator;

/**
 * Phase 19: upgrades run one at a time, leave a checkpoint after every step, resume instead
 * of replaying, and always say where they are.
 */
final class MigratorTest extends TestCase {

	public function run(): void {
		$this->runs_steps_in_order_and_finishes_clean();
		$this->failure_leaves_a_resumable_checkpoint();
		$this->fresh_lock_blocks_a_second_runner();
		$this->stale_lock_is_taken_over();
		$this->direct_upgrade_from_an_old_version_runs_only_the_missing_rungs();
		$this->real_activation_ladder_is_constructible();
	}

	/**
	 * Regression: on 1405-06-06 a version bump crashed the live site with
	 * "Argument #2 ($step) must be of type callable, array given". The ladder entries were
	 * [ Activator::class, 'migrate_to_vN' ] pointing at PRIVATE static methods, and an array
	 * callable to a private method fails the `callable` parameter type check when the Migrator
	 * (a different class) receives it. Every rung must be a genuine callable.
	 */
	private function real_activation_ladder_is_constructible(): void {
		$this->fresh();

		$method = new ReflectionMethod( \IGBZ\Suite\Support\Activator::class, 'migration_steps' );
		$steps  = $method->invoke( null );

		$this->assert_true( count( $steps ) > 0, 'the activation ladder has rungs' );

		foreach ( $steps as $version => $step ) {
			$this->assert_true(
				is_callable( $step ),
				"ladder rung {$version} is a genuine callable (a private method reference is not)"
			);
		}

		// Building the migrator is exactly where the production crash happened.
		$mig = new Migrator();
		foreach ( $steps as $version => $step ) {
			$mig->add( $version, $step );
		}
		$this->assert_true( true, 'the real ladder builds without a TypeError' );
	}

	private function fresh(): void {
		igbz_test_reset_settings();
	}

	private function runs_steps_in_order_and_finishes_clean(): void {
		$this->fresh();
		$ran  = [];
		$mig  = new Migrator();
		foreach ( [ 1, 2, 3 ] as $v ) {
			$mig->add( $v, static function () use ( &$ran, $v ): void { $ran[] = $v; } );
		}

		$result = $mig->run( 0, 3 );

		$this->assert_true( $result['ok'], 'the upgrade finishes' );
		$this->assert_same( [ 1, 2, 3 ], $ran, 'steps run in ascending order' );
		$this->assert_same( 3, (int) get_option( 'igbz_db_version' ), 'the version option is bumped once, at the end' );
		$this->assert_same( false, get_option( Migrator::CHECKPOINT_OPTION ), 'the checkpoint is cleared on success' );
		$this->assert_same( false, get_option( Migrator::LOCK_OPTION ), 'the lock is released on success' );
		$this->assert_same( 0, Migrator::previous_version(), 'the rollback marker records where the upgrade came from' );
	}

	private function failure_leaves_a_resumable_checkpoint(): void {
		$this->fresh();
		$ran   = [];
		$break = true;
		$mig   = new Migrator();
		$mig->add( 1, static function () use ( &$ran ): void { $ran[] = 1; } );
		$mig->add( 2, static function () use ( &$break ): void {
			if ( $break ) {
				throw new \RuntimeException( 'simulated failure' );
			}
		} );
		$mig->add( 3, static function () use ( &$ran ): void { $ran[] = 3; } );

		$first = $mig->run( 0, 3 );
		$this->assert_false( $first['ok'], 'a broken step fails the upgrade' );
		$this->assert_same( 2, $first['failed'], 'the failure names the broken step' );
		$this->assert_same( false, get_option( 'igbz_db_version' ), 'the version is not bumped after a failure' );
		$this->assert_same( 1, (int) get_option( Migrator::CHECKPOINT_OPTION ), 'the checkpoint keeps the last completed step' );
		$this->assert_same( false, get_option( Migrator::LOCK_OPTION ), 'the lock is released even on failure' );

		$break = false;
		$second = $mig->run( 0, 3 );
		$this->assert_true( $second['ok'], 'the retry finishes' );
		$this->assert_true( $second['resumed'], 'the retry reports that it resumed' );
		$this->assert_same( [ 1, 3 ], $ran, 'step one does not run twice; the ladder continues from the checkpoint' );
		$this->assert_same( 3, (int) get_option( 'igbz_db_version' ), 'the version is bumped after the resumed run' );
	}

	private function fresh_lock_blocks_a_second_runner(): void {
		$this->fresh();
		update_option( Migrator::LOCK_OPTION, [ 'token' => 'someone-else', 'at' => time() ], false );

		$mig = new Migrator();
		$mig->add( 1, static function (): void {} );
		$result = $mig->run( 0, 1 );

		$this->assert_false( $result['ok'], 'a second runner stands down while a fresh lock is held' );
		$this->assert_same( 'locked', $result['error'], 'the reason is the lock' );
		$this->assert_same( false, get_option( 'igbz_db_version' ), 'nothing moved' );
	}

	private function stale_lock_is_taken_over(): void {
		$this->fresh();
		update_option( Migrator::LOCK_OPTION, [ 'token' => 'dead-runner', 'at' => time() - 3600 ], false );

		$mig = new Migrator();
		$ran = false;
		$mig->add( 1, static function () use ( &$ran ): void { $ran = true; } );
		$result = $mig->run( 0, 1 );

		$this->assert_true( $result['ok'], 'a lock left behind by a dead runner is taken over' );
		$this->assert_true( $ran, 'the upgrade proceeds after taking the stale lock' );
		$this->assert_same( false, get_option( Migrator::LOCK_OPTION ), 'the taken lock is released afterwards' );
	}

	private function direct_upgrade_from_an_old_version_runs_only_the_missing_rungs(): void {
		$this->fresh();
		$ran = [];
		$mig = new Migrator();
		foreach ( [ 6, 17, 22 ] as $v ) {
			$mig->add( $v, static function () use ( &$ran, $v ): void { $ran[] = $v; } );
		}

		$result = $mig->run( 6, 22 );

		$this->assert_true( $result['ok'], 'a site on an old supported version upgrades directly' );
		$this->assert_same( [ 17, 22 ], $ran, 'already-applied rungs are skipped' );
		$this->assert_same( 6, Migrator::previous_version(), 'the rollback marker keeps the old version' );
	}
}
