<?php
namespace IGBZ\Suite\Modules\MultiTenant\Domain;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Web presence: auto-register stores with a verified standalone domain in
 * Google Search Console, Google Indexing and Bing Webmaster via the
 * operator's central account (automatic + default). GBP/Emalls/Torob need
 * per-store steps and are tracked here for Pado guidance.
 */
final class WebPresenceService {

	public function __construct(
		private Db $db,
		private Http $http,
		private Logger $logger
	) {}

	/** Register a store's domain with the automatic services. */
	public function register( int $tenant_id, string $domain ): array {
		$results = [];
		foreach ( [ 'google_search_console', 'google_indexing', 'bing_webmaster' ] as $service ) {
			$results[ $service ] = $this->register_one( $tenant_id, $service, $domain );
		}
		foreach ( [ 'emalls', 'torob', 'google_business' ] as $service ) {
			$this->set_status( $tenant_id, $service, 'pending', __( 'Per-store step — Pado guides the admin.', 'igbz-suite' ) );
		}
		return [ 'ok' => true, 'results' => $results ];
	}

	private function register_one( int $tenant_id, string $service, string $domain ): array {
		$base = igbz()->settings()->string( 'webpresence.' . $service . '_base_url' );
		$key  = igbz()->settings()->string( 'webpresence.' . $service . '_api_key' );
		if ( '' === $base || '' === $key ) {
			$this->set_status( $tenant_id, $service, 'skipped', __( 'Operator credentials not set.', 'igbz-suite' ) );
			return [ 'ok' => false, 'error' => 'not_configured' ];
		}

		$response = $this->http->post(
			rtrim( $base, '/' ) . '/v1/sites',
			[
				'json'    => [ 'domain' => $domain, 'sitemap' => 'https://' . $domain . '/sitemap_index.xml' ],
				'headers' => [ 'Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json' ],
				'channel' => 'webpresence',
				'timeout' => 30,
			]
		);
		$ok = $response->ok();
		$this->set_status( $tenant_id, $service, $ok ? 'active' : 'failed', $ok ? '' : $response->error_message() );

		return [ 'ok' => $ok, 'error' => $ok ? '' : $response->error_message() ];
	}

	private function set_status( int $tenant_id, string $service, string $status, string $detail ): void {
		$existing = $this->db->row(
			'SELECT id FROM ' . $this->db->table( 'ig_web_presence' ) . ' WHERE tenant_id = %d AND service = %s',
			$tenant_id,
			$service
		);
		$now = current_time( 'mysql', true );
		if ( $existing ) {
			$this->db->update(
				'ig_web_presence',
				[ 'status' => $status, 'detail' => mb_substr( $detail, 0, 255 ), 'updated_at' => $now ],
				[ 'id' => (int) $existing['id'] ]
			);
			return;
		}
		$this->db->insert(
			'ig_web_presence',
			[
				'tenant_id'  => $tenant_id,
				'service'    => $service,
				'status'     => $status,
				'detail'     => mb_substr( $detail, 0, 255 ),
				'updated_at' => $now,
			]
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function status( int $tenant_id ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_web_presence' ) . ' WHERE tenant_id = %d ORDER BY id',
			$tenant_id
		);
	}
}
