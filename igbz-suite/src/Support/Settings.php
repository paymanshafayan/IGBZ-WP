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
		'manus.api_key',
		'manus.webhook_token',
		'manychat.api_key',
		'manychat.webhook_token',
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
		'stt.api_key',
		'dm.custom.api_key',
		'pado.api_key',
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
}
