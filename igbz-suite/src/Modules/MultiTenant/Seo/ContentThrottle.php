<?php
namespace IGBZ\Suite\Modules\MultiTenant\Seo;

use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Bulk low-value-content guard.
 *
 * Mass-generated SEO pages are exactly what search engines punish; the guard
 * keeps an honest per-tenant daily ledger and refuses content generation once
 * the cap is spent. The cap is settings-driven and resets with the day.
 */
final class ContentThrottle {

	public function __construct( private Db $db ) {}

	private function cap(): int {
		return max( 1, igbz()->settings()->int( 'seo.daily_content_cap', 20 ) );
	}

	private function today(): string {
		return gmdate( 'Y-m-d' );
	}

	/** How much of today's cap is left for this tenant? */
	public function remaining( int $tenant_id ): int {
		$count = (int) $this->db->scalar(
			'SELECT COALESCE(SUM(count),0) FROM ' . $this->db->table( 'ig_seo_activity' ) . '
			 WHERE tenant_id = %d AND activity_date = %s',
			$tenant_id,
			$this->today()
		);

		return max( 0, $this->cap() - $count );
	}

	/** May this tenant generate one more SEO artefact right now? */
	public function within_cap( int $tenant_id ): bool {
		return $this->remaining( $tenant_id ) > 0;
	}

	/** Record one generated artefact. Refuses when the cap is spent. */
	public function record( int $tenant_id ): bool {
		if ( ! $this->within_cap( $tenant_id ) ) {
			return false;
		}

		$existing = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_seo_activity' ) . '
			 WHERE tenant_id = %d AND activity_date = %s LIMIT 1',
			$tenant_id,
			$this->today()
		);

		if ( $existing ) {
			$this->db->update(
				'ig_seo_activity',
				[ 'count' => (int) $existing['count'] + 1 ],
				[ 'id' => (int) $existing['id'] ]
			);
			return true;
		}

		$this->db->insert( 'ig_seo_activity', [ 'tenant_id' => $tenant_id, 'activity_date' => $this->today(), 'count' => 1 ] );

		return true;
	}
}
