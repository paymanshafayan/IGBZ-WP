<?php
/**
 * Phase 35 — the FX quote: spread and TTL are clamped settings, a lock records its own evidence
 * (raw rate, spread, applied rate, deadline), a missing rate refuses to lock at zero, and an
 * expired quote stops being a promise.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Fx\FxRateService;
use IGBZ\Suite\Support\Db;

/** In-memory fx_rates store. */
final class FxRatesDb extends wpdb {

	/** @var array<int,array<string,mixed>> */
	public array $rows = [];

	private int $next_id = 1;

	public function insert( string $table, array $data, $format = null ): int|bool {
		if ( ! str_contains( $table, 'fx_rates' ) ) {
			return parent::insert( $table, $data, $format );
		}
		$id = $this->next_id++;
		$data['id'] = $id;
		$this->rows[ $id ] = $data;
		$this->insert_id = $id;
		return 1;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;
		if ( preg_match( "/WHERE id = '?(\d+)'?/", $sql, $m ) ) {
			return $this->rows[ (int) $m[1] ] ?? null;
		}
		return parent::get_row( $sql, $output );
	}
}

final class FxQuoteTest extends TestCase {

	private FxRatesDb $wpdb;
	private FxRateService $rates;

	public function run(): void {
		$this->spread_and_ttl_are_clamped();
		$this->a_missing_rate_refuses_to_lock();
		$this->a_lock_records_its_own_evidence();
		$this->an_expired_quote_stops_being_a_promise();
	}

	private function fresh(): void {
		igbz_test_reset_settings();
		delete_option( 'igbz_fx_rate_cache' );
		$this->wpdb      = new FxRatesDb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->rates     = new FxRateService( new Db(), igbz()->settings(), igbz()->get( 'http' ) );
	}

	private function spread_and_ttl_are_clamped(): void {
		$this->fresh();

		$this->assert_same( 0.0, $this->rates->spread_percent(), 'spread defaults to zero' );

		igbz()->settings()->set( 'fx.spread_percent', '2.5' );
		$this->assert_same( 2.5, $this->rates->spread_percent(), 'a sane spread is honoured' );

		igbz()->settings()->set( 'fx.spread_percent', '-4' );
		$this->assert_same( 0.0, $this->rates->spread_percent(), 'never negative' );

		igbz()->settings()->set( 'fx.spread_percent', '999' );
		$this->assert_same( 50.0, $this->rates->spread_percent(), 'never above 50' );

		$this->assert_same( 15, $this->rates->quote_ttl_minutes(), 'the quote deadline defaults to 15 minutes' );

		igbz()->settings()->set( 'fx.quote_ttl_minutes', '30' );
		$this->assert_same( 30, $this->rates->quote_ttl_minutes(), 'configurable' );

		igbz()->settings()->set( 'fx.quote_ttl_minutes', '0' );
		$this->assert_same( 1, $this->rates->quote_ttl_minutes(), 'never below a minute' );
	}

	private function a_missing_rate_refuses_to_lock(): void {
		$this->fresh();

		$id = $this->rates->lock_rate();
		$this->assert_same( 0, $id, 'no manual rate, no auto source — nothing locks' );
		$this->assert_same( 0, count( $this->wpdb->rows ), 'and no row pretends otherwise' );
		$this->assert_true( ! $this->rates->quote_is_valid( 0 ), 'an absent quote is not valid' );
	}

	private function a_lock_records_its_own_evidence(): void {
		$this->fresh();
		igbz()->settings()->set( 'fx.rate_source', 'manual' );
		igbz()->settings()->set( 'fx.rate_manual', '600000' );
		igbz()->settings()->set( 'fx.spread_percent', '2' );

		$id  = $this->rates->lock_rate();
		$row = $this->wpdb->rows[ $id ];

		$this->assert_same( 600000.0, (float) $row['rate_irt_per_usd'], 'the raw rate is recorded' );
		$this->assert_same( 2.0, (float) $row['spread_percent'], 'the spread is recorded' );
		$this->assert_same( 612000.0, (float) $row['rate_applied'], 'the applied rate is raw plus spread' );
		$this->assert_true( strtotime( (string) $row['expires_at'] ) > time(), 'the deadline is in the future' );
		$this->assert_true( $this->rates->quote_is_valid( $id ), 'a fresh quote is a live promise' );

		// The cache must not leak between scenarios: a new manual value shows up after refresh().
		igbz()->settings()->set( 'fx.rate_manual', '700000' );
		$this->assert_same( 600000.0, $this->rates->current(), 'the cached rate still answers within the TTL' );
		$this->rates->refresh();
		$this->assert_same( 700000.0, $this->rates->current(), 'refresh() forces a re-read' );
	}

	private function an_expired_quote_stops_being_a_promise(): void {
		$this->fresh();
		igbz()->settings()->set( 'fx.rate_source', 'manual' );
		igbz()->settings()->set( 'fx.rate_manual', '600000' );

		$id = $this->rates->lock_rate();
		$this->wpdb->rows[ $id ]['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 1 );

		$this->assert_true( ! $this->rates->quote_is_valid( $id ), 'past its deadline the quote is dead' );
		$this->assert_true( ! $this->rates->quote_is_valid( 99999 ), 'an unknown quote is dead too' );
	}
}
