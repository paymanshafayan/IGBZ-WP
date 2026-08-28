<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Daily analytics collection. Manus reads the account's insights and reports them back; we store
 * one row per (account, metric, dimension, day) so peak-hour learning has a data source.
 */
final class InsightsService {

	public function __construct( private Db $db, private ManusService $manus, private PromptBuilder $prompts, private Logger $logger ) {}

	/** Runs on igbz_cron_daily. */
	public function collect_all(): void {
		if ( ! igbz()->settings()->bool( 'manus.collect_insights', true ) ) {
			return;
		}

		// Phase 20: keyset pagination — the platform-wide account list is the one list that
		// grows with every new store, so it is walked in bounded id-ordered pages instead of
		// one unbounded fetch; every account is still visited.
		$after = 0;
		do {
			$accounts = $this->db->results(
				'SELECT * FROM ' . $this->db->table( 'ig_accounts' ) . ' WHERE is_active = 1 AND id > %d ORDER BY id LIMIT 200',
				$after
			);
			foreach ( $accounts as $account ) {
				// Each account pays for its own insight task with its own key.
				if ( ! $this->manus->account_is_configured( $account ) ) {
					continue;
				}
				$task_id = $this->manus->client_for( $account )->create_task(
					$this->prompts->insights( $account ),
					[
						'project_id'        => (string) $account['manus_project_id'],
						'title'             => sprintf( 'Insights: @%s', (string) $account['username'] ),
						'hide_in_task_list' => true,
					]
				);
				if ( $task_id['ok'] ) {
					set_transient( 'igbz_ig_insights_' . (int) $account['id'], $task_id['task_id'], DAY_IN_SECONDS );
				}
			}
			$after = $accounts ? (int) end( $accounts )['id'] : 0;
		} while ( $accounts && $after > 0 );
	}

	/** Runs on igbz_cron_hourly: pick up finished insight tasks. */
	public function reconcile(): void {
		// Phase 20: same bounded keyset walk as collect_all().
		$after = 0;
		do {
			$accounts = $this->db->results(
				'SELECT * FROM ' . $this->db->table( 'ig_accounts' ) . ' WHERE is_active = 1 AND id > %d ORDER BY id LIMIT 200',
				$after
			);
			foreach ( $accounts as $account ) {
				$key     = 'igbz_ig_insights_' . (int) $account['id'];
				$task_id = get_transient( $key );
				if ( ! $task_id ) {
					continue;
				}
				$state = $this->manus->client_for( $account )->task_state( (string) $task_id );
				if ( ManusClient::STATUS_STOPPED !== $state['status'] ) {
					continue;
				}
				delete_transient( $key );
				$this->store( (int) $account['id'], (int) $account['tenant_id'], $this->manus->parse_json_block( $state['text'] ) );
			}
			$after = $accounts ? (int) end( $accounts )['id'] : 0;
		} while ( $accounts && $after > 0 );
	}

	/** @param array<string,mixed> $payload */
	public function store( int $account_id, int $tenant_id, array $payload ): void {
		$day = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );

		foreach ( (array) ( $payload['metrics'] ?? [] ) as $metric => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$this->upsert( $account_id, $tenant_id, (string) $metric, '', (float) $value, $day );
		}

		foreach ( (array) ( $payload['engagement_by_hour'] ?? [] ) as $hour => $value ) {
			$this->upsert( $account_id, $tenant_id, 'engagement_by_hour', (string) $hour, (float) $value, $day );
		}

		$peak = array_values( array_filter( array_map( 'strval', (array) ( $payload['peak_hours'] ?? [] ) ) ) );
		if ( $peak ) {
			$this->db->update(
				'ig_accounts',
				[ 'peak_hours' => implode( ',', array_slice( $peak, 0, 5 ) ), 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => $account_id ]
			);
			foreach ( $peak as $index => $hour ) {
				// Keep a weighted trace so learned hours survive even without hourly breakdowns.
				$this->upsert( $account_id, $tenant_id, 'engagement_by_hour', $hour, (float) ( 100 - $index * 10 ), $day );
			}
		}

		$this->logger->info( 'manus', 'Insights stored', [ 'account_id' => $account_id, 'day' => $day ] );
		do_action( 'igbz_ig_insights_stored', $account_id, $payload );
	}

	private function upsert( int $account_id, int $tenant_id, string $metric, string $dimension, float $value, string $day ): void {
		$this->db->upsert(
			'ig_insights',
			[
				'tenant_id'    => $tenant_id,
				'account_id'   => $account_id,
				'metric'       => $metric,
				'dimension'    => $dimension,
				'value'        => $value,
				'captured_for' => $day,
				'created_at'   => current_time( 'mysql', true ),
			],
			[ 'value' => 'value' ],
			[ 'account_id', 'metric', 'dimension', 'captured_for' ]
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function series( int $account_id, string $metric, int $days = 30 ): array {
		return $this->db->results(
			'SELECT captured_for, value FROM ' . $this->db->table( 'ig_insights' ) . '
			 WHERE account_id = %d AND metric = %s AND dimension = %s AND captured_for >= %s
			 ORDER BY captured_for',
			$account_id,
			$metric,
			'',
			gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS )
		);
	}

	/** @return array<string,float> */
	public function summary( int $account_id ): array {
		$rows = $this->db->results(
			'SELECT metric, value FROM ' . $this->db->table( 'ig_insights' ) . '
			 WHERE account_id = %d AND dimension = %s AND captured_for = (
				SELECT MAX(captured_for) FROM ' . $this->db->table( 'ig_insights' ) . ' WHERE account_id = %d
			 )',
			$account_id,
			'',
			$account_id
		);

		$out = [];
		foreach ( $rows as $row ) {
			$out[ (string) $row['metric'] ] = (float) $row['value'];
		}
		return $out;
	}
}
