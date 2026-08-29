<?php
/**
 * Phase 34 — the Shahkar verifier tells its three outcomes apart (matched / mismatch / error),
 * validates inputs before spending a registry call, rate-limits attempts per user per day, and
 * keeps PII out of storage and logs.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\MultiTenant\Otp\NationalIdVerifier;
use IGBZ\Suite\Support\Db;

/** In-memory attempts log. */
final class NidDb extends wpdb {

	/** @var array<int,array<string,mixed>> */
	public array $rows = [];

	private int $next_id = 1;

	public function insert( string $table, array $data, $format = null ): int|bool {
		if ( ! str_contains( $table, 'ig_nid_verifications' ) ) {
			return parent::insert( $table, $data, $format );
		}
		$id = $this->next_id++;
		$data['id'] = $id;
		$this->rows[ $id ] = $data;
		$this->insert_id = $id;
		return 1;
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'COUNT(*)' ) && str_contains( $sql, 'ig_nid_verifications' ) ) {
			$count = 0;
			foreach ( $this->rows as $row ) {
				if ( preg_match( "/user_id = '?(\d+)'?/", $sql, $m ) && (string) $row['user_id'] === $m[1] ) {
					++$count;
				}
			}
			return (string) $count;
		}
		return parent::get_var( $sql );
	}
}

final class NationalIdVerifierTest extends TestCase {

	public function run(): void {
		$this->national_id_checksum_is_enforced();
		$this->response_classification_never_guesses();
		$this->verification_gates_run_before_any_registry_call();
		$this->attempts_are_rate_limited_per_day();
	}

	private function national_id_checksum_is_enforced(): void {
		$this->assert_true( NationalIdVerifier::valid_national_id( '0499370899' ), 'a valid checksum passes' );
		$this->assert_true( NationalIdVerifier::valid_national_id( '0790419904' ), 'another valid one passes' );
		$this->assert_true( ! NationalIdVerifier::valid_national_id( '0499370898' ), 'a wrong check digit fails' );
		$this->assert_true( ! NationalIdVerifier::valid_national_id( '1111111111' ), 'repeated digits are rejected' );
		$this->assert_true( ! NationalIdVerifier::valid_national_id( '049937089' ), 'nine digits fail' );
		$this->assert_true( ! NationalIdVerifier::valid_national_id( '04993708990' ), 'eleven digits fail' );
		$this->assert_true( ! NationalIdVerifier::valid_national_id( '049937O899' ), 'letters fail' );
	}

	private function response_classification_never_guesses(): void {
		$this->assert_same( NationalIdVerifier::RESULT_ERROR, NationalIdVerifier::classify_response( false, 'timeout', [ 'matched' => true ] ), 'a failed round-trip is an error even if the stale body claims a match' );
		$this->assert_same( NationalIdVerifier::RESULT_ERROR, NationalIdVerifier::classify_response( true, '', [] ), 'an empty body is an error' );

		foreach ( [ true, 'matched', 'true', 1, '1' ] as $yes ) {
			$this->assert_same( NationalIdVerifier::RESULT_MATCHED, NationalIdVerifier::classify_response( true, '', [ 'matched' => $yes ] ), 'every matched spelling reads as matched' );
		}
		foreach ( [ false, 'mismatch', 'false', 0, '0' ] as $no ) {
			$this->assert_same( NationalIdVerifier::RESULT_MISMATCH, NationalIdVerifier::classify_response( true, '', [ 'matched' => $no ] ), 'every mismatch spelling reads as mismatch' );
		}

		$this->assert_same( NationalIdVerifier::RESULT_ERROR, NationalIdVerifier::classify_response( true, '', [ 'something' => 'else' ] ), 'no verdict is an error — never guessed' );
	}

	private function verification_gates_run_before_any_registry_call(): void {
		igbz_test_reset_settings();
		$GLOBALS['wpdb'] = new NidDb();
		$verifier = new NationalIdVerifier( new Db(), igbz()->get( 'http' ) );

		$locked = $verifier->verify( 1, '09123456789', '0499370899' );
		$this->assert_true( ! $locked['ok'], 'without a Shahkar key nothing is attempted' );
		$this->assert_same( NationalIdVerifier::RESULT_ERROR, $locked['status'], 'and it reads as error, not mismatch' );

		igbz()->settings()->set( 'legal.shahkar_api_key', 'KEY' );

		$bad_id = $verifier->verify( 1, '09123456789', '1234567890' );
		$this->assert_true( ! $bad_id['ok'], 'an invalid national id is rejected locally' );
		$this->assert_same( 0, count( $GLOBALS['wpdb']->rows ), 'no registry call and no record for invalid input' );

		$bad_phone = $verifier->verify( 1, '12345', '0499370899' );
		$this->assert_true( ! $bad_phone['ok'], 'an invalid phone is rejected locally' );
		$this->assert_same( 0, count( $GLOBALS['wpdb']->rows ), 'still nothing recorded' );
	}

	private function attempts_are_rate_limited_per_day(): void {
		igbz_test_reset_settings();
		$db            = new NidDb();
		$GLOBALS['wpdb'] = $db;
		igbz()->settings()->set( 'legal.shahkar_api_key', 'KEY' );
		igbz()->settings()->set( 'legal.shahkar_max_attempts_per_day', '2' );

		$verifier = new NationalIdVerifier( new Db(), igbz()->get( 'http' ) );
		$this->assert_same( 2, $verifier->max_attempts_per_day(), 'the cap is configurable' );

		// Two recorded attempts already exist for this user.
		$db->insert( 'ig_nid_verifications', [ 'user_id' => 9, 'national_id_hash' => 'h', 'status' => 'error', 'ref' => '', 'created_at' => current_time( 'mysql', true ) ] );
		$db->insert( 'ig_nid_verifications', [ 'user_id' => 9, 'national_id_hash' => 'h', 'status' => 'error', 'ref' => '', 'created_at' => current_time( 'mysql', true ) ] );

		$blocked = $verifier->verify( 9, '09123456789', '0499370899' );
		$this->assert_true( ! $blocked['ok'], 'the third attempt in a day is blocked' );
		$this->assert_same( 2, count( $db->rows ), 'without spending another registry call' );
		$this->assert_same( NationalIdVerifier::RESULT_ERROR, $blocked['status'], 'as an error — the user is not marked mismatched' );
	}
}
