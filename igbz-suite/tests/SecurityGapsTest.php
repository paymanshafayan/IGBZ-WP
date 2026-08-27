<?php
/**
 * The three gaps closed on 1405/05/31.
 *
 * Each test asserts the behaviour, not the implementation, and each was run against the old code
 * first to confirm it failed there. A test that passes before the fix proves nothing.
 */

declare( strict_types=1 );

use IGBZ\Suite\Modules\MultiTenant\Payments\LegalWaiverService;
use IGBZ\Suite\Support\CoreSurfaceGuard;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

final class SecurityGapsTest extends TestCase {

	private function logger(): Logger {
		return new Logger( igbz()->settings() );
	}

	public function run(): void {
		$this->test_bulk_people_routes_are_recognised();
		$this->test_single_record_reads_stay_open();
		$this->test_batch_is_treated_as_bulk();
		$this->test_unrelated_routes_are_untouched();
		$this->test_otp_cooldown_query_matches_phone_and_ip();
		$this->test_otp_index_exists_for_the_new_query();
		$this->test_waiver_blocks_payment_until_accepted();
		$this->test_waiver_hash_changes_with_wording();
	}

	// ------------------------------------------------- gap 1: core surfaces

	/**
	 * The routes that hand back people's data in bulk must be recognised as such.
	 *
	 * Kept as a data-shape assertion rather than a live REST call because the harness has no REST
	 * server; the routing decision is the part worth pinning down.
	 */
	private function test_bulk_people_routes_are_recognised(): void {
		$guard  = new CoreSurfaceGuard( $this->logger() );
		$method = new ReflectionMethod( $guard, 'is_bulk_people_route' );

		foreach ( [ '/wc/v3/customers', '/wc/v3/orders', '/wp/v2/users', '/wc-analytics/customers' ] as $route ) {
			$this->assert_true(
				(bool) $method->invoke( $guard, $route ),
				"bulk route recognised: {$route}"
			);
		}
	}

	/**
	 * Reading one record is not bulk collection.
	 *
	 * This matters as much as the blocking does: our own admin screens read single customers all
	 * day, and breaking them would be a self-inflicted outage with no security gain — the caller
	 * already had to know the id.
	 */
	private function test_single_record_reads_stay_open(): void {
		$guard  = new CoreSurfaceGuard( $this->logger() );
		$method = new ReflectionMethod( $guard, 'is_single_record' );

		$this->assert_true( (bool) $method->invoke( $guard, '/wc/v3/customers/42' ), 'single customer read allowed' );
		$this->assert_true( (bool) $method->invoke( $guard, '/wc/v3/orders/1001' ), 'single order read allowed' );
		$this->assert_false( (bool) $method->invoke( $guard, '/wc/v3/customers' ), 'collection is not a single record' );
	}

	/** `/batch` is bulk wearing a single-record shape. */
	private function test_batch_is_treated_as_bulk(): void {
		$guard  = new CoreSurfaceGuard( $this->logger() );
		$method = new ReflectionMethod( $guard, 'is_single_record' );

		$this->assert_false(
			(bool) $method->invoke( $guard, '/wc/v3/customers/batch' ),
			'batch endpoint is not treated as a single record'
		);
	}

	/** The guard must not touch routes that have nothing to do with people's data. */
	private function test_unrelated_routes_are_untouched(): void {
		$guard  = new CoreSurfaceGuard( $this->logger() );
		$method = new ReflectionMethod( $guard, 'is_bulk_people_route' );

		foreach ( [ '/wp/v2/posts', '/wc/v3/products', '/igbz/v1/auth/otp/request' ] as $route ) {
			$this->assert_false(
				(bool) $method->invoke( $guard, $route ),
				"unrelated route untouched: {$route}"
			);
		}
	}

	// ------------------------------------------------------- gap 2: OTP cooldown

	/**
	 * The resend cooldown must filter on phone AND ip_hash.
	 *
	 * Asserted against the source because the send path needs an SMS provider and a live request
	 * context. The bug being guarded was precisely that ip_hash was written but never read, so
	 * checking that it appears in the WHERE clause is the assertion that would have caught it.
	 */
	private function test_otp_cooldown_query_matches_phone_and_ip(): void {
		$src = (string) file_get_contents(
			dirname( __DIR__ ) . '/src/Modules/MultiTenant/Otp/OtpService.php'
		);

		$this->assert_contains(
			'WHERE phone = %s AND ip_hash = %s AND purpose = %s',
			$src,
			'resend cooldown filters on phone AND ip_hash'
		);
	}

	/** A new WHERE clause without a matching index is a slow query waiting to happen. */
	private function test_otp_index_exists_for_the_new_query(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/src/Support/Schema.php' );

		$this->assert_contains(
			'KEY phone_ip_purpose (phone,ip_hash,purpose)',
			$src,
			'otp_codes carries an index matching the phone+ip+purpose lookup'
		);
	}

	// --------------------------------------------------- gap 3: legal waiver

	/**
	 * With national-id matching off and no waiver on file, bank payments must be refused.
	 *
	 * This is the arm of the fork that was missing entirely: the table shipped in DB v17 and
	 * nothing read it, so payments could run with neither the technical check nor the legal cover.
	 */
	private function test_waiver_blocks_payment_until_accepted(): void {
		igbz_test_reset_settings();

		$db      = new Db();
		$service = new LegalWaiverService( $db, $this->logger() );

		$verdict = $service->payment_allowed( 1 );
		$this->assert_false( (bool) $verdict['allowed'], 'payment refused with no waiver and no nid check' );
		$this->assert_true( (bool) $verdict['needs_waiver'], 'the refusal points at the waiver as the remedy' );

		// The switch is not enough by itself; the Shahkar credentials must also exist.
		igbz()->settings()->set( 'legal.national_id_check', true );
		$verdict = $service->payment_allowed( 1 );
		$this->assert_false( (bool) $verdict['allowed'], 'nid switch without Shahkar credentials remains blocked' );
		igbz()->settings()->set( 'legal.shahkar_api_key', 'test-key' );
		igbz()->settings()->set( 'legal.shahkar_base_url', 'https://shahkar.test' );
		$verdict = $service->payment_allowed( 1 );
		$this->assert_true( (bool) $verdict['allowed'], 'configured nid matching permits payment' );
	}

	/**
	 * Consent is to specific wording, not to a boolean.
	 *
	 * If the waiver text is reworded, previously stored acceptances must stop counting — otherwise
	 * we would claim an admin agreed to terms they never saw.
	 */
	private function test_waiver_hash_changes_with_wording(): void {
		igbz_test_reset_settings();

		$service = new LegalWaiverService( new Db(), $this->logger() );
		$before  = $service->current_hash();

		$filter = static fn( string $text ): string => $text . ' (revised)';
		add_filter( 'igbz_legal_waiver_text', $filter );
		$after = $service->current_hash();
		remove_filter( 'igbz_legal_waiver_text', $filter );

		$this->assert_not_same( $before, $after, 'reworded terms produce a different hash' );
		$this->assert_same( $before, $service->current_hash(), 'hash returns to the original once reverted' );
	}
}
