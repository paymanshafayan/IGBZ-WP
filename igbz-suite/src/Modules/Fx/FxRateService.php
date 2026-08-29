<?php
namespace IGBZ\Suite\Modules\Fx;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Current Rial-per-USD rate, locked per top-up.
 *
 * The source is `fx.rate_source`: `auto` fetches a rate from `fx.rate_url`
 * (a dotted `fx.rate_json_path` picks the number out of the JSON response,
 * same convention as the STT response path), `manual` uses `fx.rate_manual`.
 * A failed auto fetch falls back to the manual value rather than refusing a
 * top-up. The result is cached in an option for `fx.rate_cache_ttl` seconds.
 */
class FxRateService {

	public const SOURCE_AUTO   = 'auto';
	public const SOURCE_MANUAL = 'manual';

	private const CACHE_OPTION = 'igbz_fx_rate_cache';

	public function __construct(
		private Db $db,
		private Settings $settings,
		private Http $http
	) {}

	/** The rate the UI shows and a fresh top-up would lock. */
	public function current(): float {
		$cached = get_option( self::CACHE_OPTION, null );
		$ttl    = max( 60, $this->settings->int( 'fx.rate_cache_ttl', 3600 ) );

		if ( is_array( $cached ) && isset( $cached['rate'], $cached['at'] ) ) {
			$age = time() - (int) $cached['at'];
			if ( $age >= 0 && $age < $ttl ) {
				return (float) $cached['rate'];
			}
		}

		$rate = $this->resolve();
		update_option( self::CACHE_OPTION, [ 'rate' => $rate, 'at' => time() ], true );

		return $rate;
	}

	/** Phase 35: the operator spread added on top of the market rate, percent (never negative). */
	public function spread_percent(): float {
		return max( 0.0, min( 50.0, (float) $this->settings->float( 'fx.spread_percent', 0 ) ) );
	}

	/** Phase 35: how many minutes a locked quote stays a promise (never below 1). */
	public function quote_ttl_minutes(): int {
		return max( 1, (int) $this->settings->int( 'fx.quote_ttl_minutes', 15 ) );
	}

	/**
	 * Insert a row in fx_rates and return its id — the number a top-up is locked to.
	 *
	 * Phase 35: the row records its own evidence — raw rate, spread, the applied rate the quote
	 * is priced with, and a deadline. When no rate exists (auto failed AND manual unset) it
	 * returns 0 and the caller must refuse the top-up: pricing at zero is how money disappears.
	 */
	public function lock_rate(): int {
		$rate = $this->current();
		if ( $rate <= 0 ) {
			return 0;
		}

		$spread  = $this->spread_percent();
		$applied = round( $rate * ( 1 + $spread / 100 ), 4 );
		$source  = $this->settings->string( 'fx.rate_source', self::SOURCE_MANUAL );

		$id = $this->db->insert(
			'fx_rates',
			[
				'rate_irt_per_usd' => $rate,
				'spread_percent'   => $spread,
				'rate_applied'     => $applied,
				'source'           => $source,
				'captured_at'      => current_time( 'mysql', true ),
				'expires_at'       => gmdate( 'Y-m-d H:i:s', time() + $this->quote_ttl_minutes() * 60 ),
			]
		);

		return (int) $id;
	}

	/** @return array<string,mixed>|null */
	public function locked_rate( int $rate_id ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'fx_rates' ) . ' WHERE id = %d',
			$rate_id
		);
	}

	/**
	 * Phase 35: is a locked quote still a promise? A quote past its deadline is dead — honoring
	 * it would sell at a rate the market left behind minutes ago.
	 */
	public function quote_is_valid( int $rate_id ): bool {
		$row = $this->locked_rate( $rate_id );
		if ( null === $row ) {
			return false;
		}
		if ( ! empty( $row['expires_at'] ) && strtotime( (string) $row['expires_at'] ) <= time() ) {
			return false;
		}
		return (float) $row['rate_applied'] > 0;
	}

	/** Purge the cache so the next read refetches. */
	public function refresh(): void {
		delete_option( self::CACHE_OPTION );
	}

	private function resolve(): float {
		$manual = (float) $this->settings->float( 'fx.rate_manual', 0 );

		if ( self::SOURCE_AUTO === $this->settings->string( 'fx.rate_source', self::SOURCE_MANUAL ) ) {
			$auto = $this->fetch_auto_rate();
			if ( $auto > 0 ) {
				return $auto;
			}
		}

		return $manual;
	}

	/**
	 * Fetch the rate from the configured endpoint. Separated so tests can
	 * override it without touching the network.
	 */
	protected function fetch_auto_rate(): float {
		$url  = trim( $this->settings->string( 'fx.rate_url', '' ) );
		if ( '' === $url ) {
			return 0.0;
		}

		$response = $this->http->get( $url );
		if ( ! $response->ok() ) {
			return 0.0;
		}
		$decoded = $response->json();
		if ( ! is_array( $decoded ) ) {
			return 0.0;
		}

		$path = trim( $this->settings->string( 'fx.rate_json_path', '' ) );
		if ( '' !== $path ) {
			$value = $decoded;
			foreach ( explode( '.', $path ) as $segment ) {
				if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
					return 0.0;
				}
				$value = $value[ $segment ];
			}
			return (float) $value;
		}

		foreach ( [ 'price', 'rate', 'usdt', 'price_irt' ] as $key ) {
			if ( isset( $decoded[ $key ] ) ) {
				return (float) $decoded[ $key ];
			}
		}

		return 0.0;
	}
}
