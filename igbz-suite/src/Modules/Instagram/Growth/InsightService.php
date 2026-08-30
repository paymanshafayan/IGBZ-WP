<?php
namespace IGBZ\Suite\Modules\Instagram\Growth;

use IGBZ\Suite\Modules\Instagram\Services\ZernioSocialService;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Account insights with provenance and retention (phase 55).
 *
 * Every row records where it came from: `source` (manual | zernio) plus the provider
 * reference, so a manager-entered number and a provider-fetched number are never
 * confused later. The official ingestion path is the connected profile's analytics
 * endpoint through the same Zernio plumbing the rest of the social plane uses; a store
 * that has not connected gets an honest `not_connected` — never a guessed number.
 *
 * Retention: insights are operational telemetry, not records. The daily prune keeps
 * `ig.insights_retention_days` days (default 730, floor 90 — a shorter setting is a
 * data-destroying foot-gun, so the floor wins).
 */
final class InsightService {

	public const SOURCE_MANUAL  = 'manual';
	public const SOURCE_ZERNIO  = 'zernio';

	public const DEFAULT_RETENTION_DAYS = 730;
	public const MIN_RETENTION_DAYS     = 90;

	public function __construct(
		private Db $db,
		private Logger $logger,
		private Settings $settings,
		private ZernioSocialService $social
	) {}

	// ---------------------------------------------------------------- record

	/**
	 * Record one metric row, manual by default. Upserts on the account+metric+day key, so
	 * correcting today's typo is the same call again.
	 *
	 * @param array<string,mixed> $data
	 * @return array{ok:bool,id:int,error:string}
	 */
	public function record( int $tenant_id, array $data ): array {
		if ( $tenant_id <= 0 ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'no_tenant' ];
		}

		$account_id = (int) ( $data['account_id'] ?? 0 );
		$metric     = strtolower( sanitize_key( (string) ( $data['metric'] ?? '' ) ) );
		$value      = (float) ( $data['value'] ?? 0 );
		if ( $account_id <= 0 || '' === $metric || strlen( $metric ) > 64 ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'bad_request' ];
		}

		$captured_for = $this->to_date( (string) ( $data['captured_for'] ?? '' ) );
		if ( null === $captured_for ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'bad_date' ];
		}

		$source = (string) ( $data['source'] ?? self::SOURCE_MANUAL );
		if ( ! in_array( $source, [ self::SOURCE_MANUAL, self::SOURCE_ZERNIO ], true ) ) {
			$source = self::SOURCE_MANUAL;
		}

		$dimension    = substr( sanitize_text_field( (string) ( $data['dimension'] ?? '' ) ), 0, 64 );
		$provider_ref = substr( sanitize_text_field( (string) ( $data['provider_ref'] ?? '' ) ), 0, 191 );

		// Upsert by hand (SELECT then UPDATE-or-INSERT): the unique key is
		// account+metric+dimension+day, and re-submitting the day corrects it.
		$existing = $this->db->scalar(
			"SELECT id FROM {$this->db->table('ig_insights')} WHERE account_id = %d AND metric = %s AND dimension = %s AND captured_for = %s",
			$account_id,
			$metric,
			$dimension,
			$captured_for
		);

		if ( null !== $existing ) {
			$this->db->update(
				'ig_insights',
				[ 'value' => $value, 'source' => $source, 'provider_ref' => $provider_ref ],
				[ 'id' => (int) $existing ]
			);
			return [ 'ok' => true, 'id' => (int) $existing, 'error' => '' ];
		}

		$id = $this->db->insert( 'ig_insights', [
			'tenant_id'     => $tenant_id,
			'account_id'    => $account_id,
			'metric'        => $metric,
			'dimension'     => $dimension,
			'value'         => $value,
			'captured_for'  => $captured_for,
			'source'        => $source,
			'provider_ref'  => $provider_ref,
			'created_at'    => current_time( 'mysql', true ),
		] );

		return $id > 0
			? [ 'ok' => true, 'id' => $id, 'error' => '' ]
			: [ 'ok' => false, 'id' => 0, 'error' => 'insert_failed' ];
	}

	// ---------------------------------------------------------------- ingest

	/**
	 * Pull the connected account's analytics through the official path and store them as
	 * `zernio`-sourced rows. One metric map per call; non-numeric values are skipped
	 * honestly rather than coerced.
	 *
	 * @return array{ok:bool,stored:int,skipped:int,error:string}
	 */
	public function ingest( int $tenant_id, string $period = '30d' ): array {
		$profile = $this->social->profile( $tenant_id );
		if ( null === $profile ) {
			return [ 'ok' => false, 'stored' => 0, 'skipped' => 0, 'error' => 'not_connected' ];
		}

		$result = $this->social->analytics( $tenant_id, $period );
		if ( empty( $result['ok'] ) ) {
			return [ 'ok' => false, 'stored' => 0, 'skipped' => 0, 'error' => (string) ( $result['error'] ?? 'provider_unavailable' ) ];
		}

		// ig_insights.account_id is the store's own account row (ig_accounts), the entity
		// the rest of the module keys on; the provider's account string rides provider_ref.
		$account_id = (int) $this->db->scalar(
			"SELECT id FROM {$this->db->table('ig_accounts')} WHERE tenant_id = %d AND is_active = 1 ORDER BY id DESC",
			$tenant_id
		);
		if ( $account_id <= 0 ) {
			return [ 'ok' => false, 'stored' => 0, 'skipped' => 0, 'error' => 'no_account' ];
		}

		$provider_ref = (string) ( $profile['account_id'] ?? '' );
		$metrics      = (array) ( $result['metrics'] ?? [] );

		$stored = 0;
		$skipped = 0;
		foreach ( $metrics as $metric => $value ) {
			if ( is_array( $value ) || ! is_numeric( $value ) ) {
				$skipped++;
				continue;
			}
			$record = $this->record( $tenant_id, [
				'account_id'   => $account_id,
				'metric'       => (string) $metric,
				'value'        => (float) $value,
				'source'       => self::SOURCE_ZERNIO,
				'provider_ref' => $provider_ref,
			] );
			$stored += $record['ok'] ? 1 : 0;
			$skipped += $record['ok'] ? 0 : 1;
		}

		return [ 'ok' => true, 'stored' => $stored, 'skipped' => $skipped, 'error' => '' ];
	}

	// ------------------------------------------------------------------ read

	/** @return array<int,array<string,mixed>> */
	public function list( int $tenant_id, int $account_id = 0, string $metric = '', int $limit = 200 ): array {
		$table = $this->db->table( 'ig_insights' );
		$sql   = "SELECT account_id,metric,dimension,value,captured_for,source,provider_ref,created_at FROM {$table} WHERE tenant_id = %d";
		$args  = [ $tenant_id ];
		if ( $account_id > 0 ) {
			$sql   .= ' AND account_id = %d';
			$args[] = $account_id;
		}
		if ( '' !== $metric ) {
			$sql   .= ' AND metric = %s';
			$args[] = $metric;
		}
		$sql   .= ' ORDER BY captured_for DESC, metric ASC LIMIT %d';
		$args[] = max( 1, min( 1000, $limit ) );

		return $this->db->results( $sql, ...$args );
	}

	// ---------------------------------------------------------------- prune

	/** Delete rows older than the retention window. Returns the deleted row count. */
	public function prune(): int {
		$days  = (int) $this->settings->get( 'ig.insights_retention_days', self::DEFAULT_RETENTION_DAYS );
		$days  = max( self::MIN_RETENTION_DAYS, $days );
		$cutoff = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$deleted = $this->db->query(
			"DELETE FROM {$this->db->table('ig_insights')} WHERE captured_for < %s",
			$cutoff
		);

		if ( $deleted > 0 ) {
			$this->logger->info( 'insights', 'Retention prune', [ 'days' => $days, 'deleted' => $deleted ] );
		}

		return $deleted;
	}

	// ----------------------------------------------------------------- util

	private function to_date( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return gmdate( 'Y-m-d' );
		}
		$ts = strtotime( $value );
		return false !== $ts ? gmdate( 'Y-m-d', $ts ) : null;
	}
}
