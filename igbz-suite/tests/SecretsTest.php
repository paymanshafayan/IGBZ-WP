<?php
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Settings;

/**
 * Phase 05: the secret registry. Encryption at rest, legacy plaintext migration, HTML masking,
 * keep-the-stored-value-on-empty-submit and rotation (API-KEYS.md §5 mandatory actions).
 */
final class SecretsTest extends TestCase {

	public function run(): void {
		$this->registered_secret_is_encrypted_at_rest();
		$this->legacy_plaintext_still_reads_then_migrates();
		$this->migration_is_idempotent();
		$this->masked_submit_keeps_the_stored_secret();
		$this->masking_for_forms();
		$this->rotation_replaces_and_reencrypts();
		$this->unregistered_key_cannot_rotate();
	}

	private function fresh(): Settings {
		return igbz_test_reset_settings();
	}

	/** @return array<string,mixed> */
	private function raw_option(): array {
		$stored = get_option( Settings::OPTION, [] );
		return is_array( $stored ) ? $stored : [];
	}

	private function registered_secret_is_encrypted_at_rest(): void {
		$settings = $this->fresh();
		$settings->set( 'legal.shahkar_api_key', 'shahkar-secret-123' );

		$raw = (string) ( $this->raw_option()['legal.shahkar_api_key'] ?? '' );
		$this->assert_true( str_starts_with( $raw, 'igbz1:' ), 'new registry member is stored with the versioned encrypted payload' );
		$this->assert_false( str_contains( $raw, 'shahkar-secret-123' ), 'plaintext never appears in the stored option' );
		$this->assert_same( 'shahkar-secret-123', $settings->get( 'legal.shahkar_api_key' ), 'get() transparently decrypts' );
	}

	private function legacy_plaintext_still_reads_then_migrates(): void {
		$settings = $this->fresh();
		// Simulate an install that stored the value before the key joined the registry.
		update_option(
			Settings::OPTION,
			array_merge( $this->raw_option(), [ 'fx.webhook_token' => 'legacy-plain-token' ] ),
			false
		);
		$settings = new Settings();

		$this->assert_same( 'legacy-plain-token', $settings->get( 'fx.webhook_token' ), 'legacy plaintext keeps reading during the migration window' );

		$migrated = $settings->encrypt_legacy_secrets();
		$this->assert_true( $migrated >= 1, 'migration reports the re-encrypted value' );

		$raw = (string) ( $this->raw_option()['fx.webhook_token'] ?? '' );
		$this->assert_true( str_starts_with( $raw, 'igbz1:' ), 'legacy value is rewritten with the encrypted payload' );
		$this->assert_same( 'legacy-plain-token', ( new Settings() )->get( 'fx.webhook_token' ), 'the value survives the migration intact' );
	}

	private function migration_is_idempotent(): void {
		$settings = $this->fresh();
		$settings->set( 'stripe.secret_key', 'sk_live_abc' );
		$before = (string) ( $this->raw_option()['stripe.secret_key'] ?? '' );

		$this->assert_same( 0, $settings->encrypt_legacy_secrets(), 'already-encrypted values are not touched again' );
		$this->assert_same( $before, (string) ( $this->raw_option()['stripe.secret_key'] ?? '' ), 'stored payload is byte-identical after a second run' );

		// Empty values and mask placeholders must never be "migrated" into garbage payloads.
		update_option(
			Settings::OPTION,
			array_merge(
				$this->raw_option(),
				[ 'seo.triboon_api_key' => '', 'translation.api_key' => Crypto::MASK ]
			),
			false
		);
		$settings = new Settings();
		$settings->encrypt_legacy_secrets();
		$after = $this->raw_option();
		$this->assert_same( '', (string) ( $after['seo.triboon_api_key'] ?? 'x' ), 'empty stays empty' );
		$this->assert_same( Crypto::MASK, (string) ( $after['translation.api_key'] ?? '' ), 'mask placeholder is never persisted as a secret' );
	}

	private function masked_submit_keeps_the_stored_secret(): void {
		$settings = $this->fresh();
		$settings->set( 'payments.mellat.password', 'mellat-pass-1' );
		$before = (string) ( $this->raw_option()['payments.mellat.password'] ?? '' );

		// The form round-trips the mask when the operator leaves the field untouched.
		$settings->set_many(
			[
				'payments.mellat.password' => Crypto::MASK,
				'payments.mellat.enabled'  => true,
				'nowpayments.api_key'      => '',
			]
		);

		$this->assert_same( $before, (string) ( $this->raw_option()['payments.mellat.password'] ?? '' ), 'masked resubmit does not overwrite the stored secret' );
		$this->assert_same( 'mellat-pass-1', $settings->get( 'payments.mellat.password' ), 'the secret still decrypts after a masked save' );
		$this->assert_true( $settings->bool( 'payments.mellat.enabled' ), 'non-secret fields in the same submit still persist' );
		$this->assert_false( $settings->has( 'nowpayments.api_key' ), 'clearing a secret field stores an empty value, not an encrypted empty string' );
	}

	private function masking_for_forms(): void {
		$settings = $this->fresh();
		$this->assert_same( '', $settings->masked( 'bnpl.tara.api_key' ), 'unset secret renders as an empty field' );
		$settings->set( 'bnpl.tara.api_key', 'tara-123' );
		$this->assert_same( Crypto::MASK, $settings->masked( 'bnpl.tara.api_key' ), 'set secret renders only the mask, never the value' );
		$this->assert_false( str_contains( $settings->masked( 'bnpl.tara.api_key' ), 'tara-123' ), 'mask carries no part of the plaintext' );
	}

	private function rotation_replaces_and_reencrypts(): void {
		$settings = $this->fresh();
		$settings->set( 'fx.webhook_token', 'old-token' );

		$new = $settings->rotate_secret( 'fx.webhook_token' );
		$this->assert_true( strlen( $new ) >= 32, 'rotation yields a fresh high-entropy token' );
		$this->assert_true( 'old-token' !== $new, 'the rotated value differs from the old one' );
		$this->assert_same( $new, $settings->get( 'fx.webhook_token' ), 'the new token reads back' );

		$raw = (string) ( $this->raw_option()['fx.webhook_token'] ?? '' );
		$this->assert_true( str_starts_with( $raw, 'igbz1:' ), 'rotated value is stored encrypted' );
		$this->assert_false( str_contains( $raw, $new ), 'the new token is not visible in the stored payload' );
	}

	private function unregistered_key_cannot_rotate(): void {
		$settings = $this->fresh();
		try {
			$settings->rotate_secret( 'general.default_currency' );
			$this->assert_true( false, 'rotating a non-secret must throw' );
		} catch ( \RuntimeException $e ) {
			$this->assert_contains( 'not a registered secret', $e->getMessage(), 'rotation refuses non-registry keys' );
		}
	}
}
