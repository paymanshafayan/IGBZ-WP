<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Typed wrapper around a single wp_options row so every module reads settings the same way.
 *
 * Secret values are transparently encrypted at rest (see Crypto) and never returned to the
 * browser in clear text; the admin screens render a masked placeholder instead.
 */
final class Settings {

	public const OPTION = 'igbz_settings';

	/** @var array<string,mixed>|null */
	private ?array $cache = null;

	/** @var string[] Keys whose values are encrypted at rest. */
	private const SECRETS = [
		'payments.zarinpal.merchant_id',
		'payments.idpay.api_key',
		'payments.nextpay.api_key',
		'payments.payir.api_key',
		'api.jwt_secret',
		'api.fcm_service_account',
		'otp.kavenegar.api_key',
		'otp.smsir.api_key',
		'lms.video_hmac_secret',
		'hub.vip_link_secret',
		'pado.api_key',
		// Phase 05 (API-KEYS.md §5 audit): the 22 password fields previously stored and
		// re-rendered in plaintext. DriftGuardTest keeps this list in lockstep with the forms.
		'bnpl.snapppay.password',
		'bnpl.tara.api_key',
		'domain.provider_api_key',
		'fx.pstnet_api_key',
		'fx.ramp_api_key',
		'fx.redotpay_api_key',
		'fx.webhook_token',
		'legal.shahkar_api_key',
		'logistics.postex_api_key',
		'logistics.tapin_api_key',
		'marketplace.digikala_api_key',
		'marketplace.divar_token',
		'nowpayments.api_key',
		'payments.asanpardakht.api_key',
		'payments.httppsp.api_key',
		'payments.irankish.api_key',
		'payments.mellat.password',
		'payments.sepehr.api_key',
		'paypal.client_id',
		'seo.triboon_api_key',
		'stripe.secret_key',
		'translation.api_key',
		// Found during phase 05: generated signing token, was never registered.
		'vip.media_hmac_secret',
		// Phase 49: the central Zernio account key (profile keys live in ig_zernio_profiles).
		'zernio.central_api_key',
	];

	/** @return array<string,mixed> */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, [] );
			$this->cache = is_array( $stored ) ? $stored : [];
		}
		return $this->cache;
	}

	public function get( string $key, mixed $default = null ): mixed {
		$value = $this->all()[ $key ] ?? null;
		if ( null === $value || '' === $value ) {
			return $default;
		}
		if ( in_array( $key, self::SECRETS, true ) ) {
			$plain = Crypto::decrypt( (string) $value );
			return null === $plain ? $default : $plain;
		}
		return $value;
	}

	public function bool( string $key, bool $default = false ): bool {
		$value = $this->get( $key, $default );
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? $default;
	}

	public function int( string $key, int $default = 0 ): int {
		$value = $this->get( $key, null );
		return null === $value ? $default : (int) $value;
	}

	public function float( string $key, float $default = 0.0 ): float {
		$value = $this->get( $key, null );
		return null === $value ? $default : (float) $value;
	}

	public function string( string $key, string $default = '' ): string {
		$value = $this->get( $key, null );
		return null === $value ? $default : (string) $value;
	}

	/** Required secrets must be configured explicitly - never silently fall back. */
	public function required( string $key ): string {
		$value = $this->string( $key );
		if ( '' === $value ) {
			throw new \RuntimeException(
				sprintf( 'IGBZ Suite: required setting "%s" is not configured.', $key )
			);
		}
		return $value;
	}

	public function is_secret( string $key ): bool {
		return in_array( $key, self::SECRETS, true );
	}

	/** @return string[] Every key whose value is encrypted at rest. */
	public static function secret_keys(): array {
		return self::SECRETS;
	}

	public function has( string $key ): bool {
		$raw = $this->all()[ $key ] ?? '';
		return '' !== $raw && null !== $raw;
	}

	public function set( string $key, mixed $value ): void {
		$all = $this->all();
		if ( in_array( $key, self::SECRETS, true ) && is_string( $value ) && '' !== $value ) {
			$value = Crypto::encrypt( $value );
		}
		$all[ $key ]  = $value;
		$this->cache  = $all;
		update_option( self::OPTION, $all, false );
	}

	/** @param array<string,mixed> $values */
	public function set_many( array $values ): void {
		$all = $this->all();
		foreach ( $values as $key => $value ) {
			if ( in_array( $key, self::SECRETS, true ) && is_string( $value ) ) {
				if ( '' === $value || Crypto::MASK === $value ) {
					continue; // keep the stored secret when the masked placeholder comes back.
				}
				$value = Crypto::encrypt( $value );
			}
			$all[ $key ] = $value;
		}
		$this->cache = $all;
		update_option( self::OPTION, $all, false );
	}

	/** Masked value suitable for rendering in an admin form. */
	public function masked( string $key ): string {
		return $this->has( $key ) ? Crypto::MASK : '';
	}

	/**
	 * One-shot migration aid (DB v20): encrypt every registered secret that is still stored in
	 * plaintext. Idempotent — values already carrying the `igbz1:` payload prefix, empty values
	 * and mask placeholders are left untouched, and the read path kept working all along
	 * because Crypto::decrypt() passes unversioned payloads through.
	 *
	 * @return int Number of values re-encrypted.
	 */
	public function encrypt_legacy_secrets(): int {
		$count = 0;
		foreach ( $this->all() as $key => $raw ) {
			if ( ! $this->is_secret( (string) $key ) ) {
				continue;
			}
			if ( ! is_string( $raw ) || '' === $raw || Crypto::MASK === $raw ) {
				continue;
			}
			if ( str_starts_with( $raw, 'igbz1:' ) ) {
				continue;
			}
			$this->set( (string) $key, $raw );
			$count++;
		}
		return $count;
	}

	/**
	 * Replace a registered secret with a freshly generated random token and return the new
	 * plaintext value. Consumers of the old value must treat it as revoked from this moment.
	 */
	public function rotate_secret( string $key ): string {
		if ( ! $this->is_secret( $key ) ) {
			throw new \RuntimeException(
				sprintf( 'IGBZ Suite: "%s" is not a registered secret; refusing to rotate it.', $key )
			);
		}
		$token = Crypto::token( 32 );
		$this->set( $key, $token );
		return $token;
	}
}
