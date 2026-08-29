<?php
namespace IGBZ\Suite\Modules\MultiTenant\Marketplace;

use IGBZ\Suite\Modules\MultiTenant\Payments\Money;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Durable marketplace sync queue.
 *
 * Product saves enqueue rows; a cron worker drains them through the
 * configured adapters. Phase 46 hardened the drain itself:
 *
 * - publishing is idempotent — every payload carries a canonical hash and a
 *   product that already published this exact hash is not pushed again;
 * - a throttled (429) or broken (5xx) marketplace does not burn retries: the
 *   row becomes invisible until `not_before` passes, so the next sweep IS the
 *   retry;
 * - a 409 means somebody changed the remote listing behind our back: the row
 *   stops as `conflict` and is never silently overwritten;
 * - each marketplace gets at most `marketplace.<id>_per_tick` pushes per
 *   drain, so one busy store cannot starve the queue or hammer a provider.
 */
final class MarketplaceSyncService {

	public const STATUS_PENDING = 'pending';
	public const STATUS_DONE    = 'done';
	public const STATUS_FAILED  = 'failed';

	/** Conflict is terminal but deserves its own name in the row. */
	public const ERROR_CONFLICT = 'conflict';

	/** Pushes already sent to each marketplace during the current drain. @var array<string,int> */
	private array $tick_counts = [];

	public function __construct(
		private Db $db,
		private Logger $logger
	) {}

	/** Enqueue a product change. Idempotent per (product, marketplace). */
	public function enqueue( int $product_id, string $marketplace, string $action = 'upsert', int $tenant_id = 0 ): void {
		$existing = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_marketplace_sync' ) . '
			 WHERE product_id = %d AND marketplace = %s AND status = %s',
			$product_id,
			$marketplace,
			self::STATUS_PENDING
		);
		if ( $existing > 0 ) {
			return;
		}

		$now = current_time( 'mysql', true );
		$this->db->insert(
			'ig_marketplace_sync',
			[
				'tenant_id'   => $tenant_id > 0 ? $tenant_id : (int) igbz()->tenancy()->id(),
				'product_id'  => $product_id,
				'marketplace' => $marketplace,
				'action'      => $action,
				'status'      => self::STATUS_PENDING,
				'not_before'  => null,
				'created_at'  => $now,
				'updated_at'  => $now,
			]
		);
	}

	/** Cron worker: drain the queue. Returns the number of rows examined. */
	public function process_pending( int $limit = 20 ): int {
		$this->tick_counts = [];

		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_marketplace_sync' ) . '
			 WHERE status = %s AND (not_before IS NULL OR not_before <= %s) ORDER BY id ASC LIMIT %d',
			self::STATUS_PENDING,
			current_time( 'mysql', true ),
			$limit
		);

		$processed = 0;
		foreach ( $rows as $row ) {
			$this->process_row( $row );
			++$processed;
		}

		return $processed;
	}

	/** @param array<string,mixed> $row */
	private function process_row( array $row ): void {
		$adapter = $this->adapter_for( (string) $row['marketplace'] );
		if ( ! $adapter || ! $adapter->is_configured() ) {
			$this->fail( $row, __( 'Marketplace is not configured.', 'igbz-suite' ) );
			return;
		}

		// Per-tick rate limit: leave the row untouched for the next sweep.
		$marketplace = (string) $row['marketplace'];
		$cap         = igbz()->settings()->int( 'marketplace.' . $marketplace . '_per_tick', 10 );
		if ( ( $this->tick_counts[ $marketplace ] ?? 0 ) >= $cap ) {
			return;
		}

		$product = $this->product_payload( (int) $row['product_id'] );
		if ( null === $product ) {
			$this->db->update( 'ig_marketplace_sync', [ 'status' => self::STATUS_DONE, 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => (int) $row['id'] ] );
			return;
		}

		$hash = $this->payload_hash( $product );

		// Idempotent publishing: the exact same payload is never pushed twice.
		if ( $this->already_published( (int) $row['product_id'], $marketplace, (int) $row['tenant_id'], $hash ) ) {
			$this->db->update(
				'ig_marketplace_sync',
				[ 'status' => self::STATUS_DONE, 'last_error' => '', 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => (int) $row['id'] ]
			);
			return;
		}

		++$this->tick_counts[ $marketplace ];

		$mapping = $this->category_mapping( (int) $row['tenant_id'], $marketplace, (string) ( $product['category'] ?? '' ) );
		$result  = $adapter->upsert( $product, $mapping );
		$this->record_outcome( $row, $result, $hash );
	}

	/**
	 * Canonical hash of the payload we are about to publish. Two payloads that
	 * differ only by key order hash identically.
	 *
	 * @param array<string,mixed> $product
	 */
	public function payload_hash( array $product ): string {
		ksort( $product );
		return hash( 'sha256', wp_json_encode( $product ) );
	}

	/** Has this exact payload already been published to this channel? */
	public function already_published( int $product_id, string $marketplace, int $tenant_id, string $hash ): bool {
		$link = $this->link_row( $product_id, $marketplace );
		return null !== $link
			&& 'synced' === (string) $link['sync_status']
			&& (string) $link['payload_hash'] === $hash;
	}

	/**
	 * Classify an adapter outcome into the durable queue's language.
	 *
	 * @param array<string,mixed> $row    the queue row
	 * @param array<string,mixed> $result the adapter answer
	 * @return array{status:string,attempts:int,not_before:?string,last_error:string,link_status:string}
	 */
	public function classify_result( array $row, array $result ): array {
		$attempts = (int) $row['attempts'];
		$now      = time();

		if ( ! empty( $result['ok'] ) ) {
			return [ 'status' => self::STATUS_DONE, 'attempts' => $attempts, 'not_before' => null, 'last_error' => '', 'link_status' => 'synced' ];
		}

		$status = (int) ( $result['http_status'] ?? 0 );

		// Somebody changed the listing remotely: stop and surface the conflict.
		if ( 409 === $status ) {
			return [ 'status' => self::STATUS_FAILED, 'attempts' => $attempts, 'not_before' => null, 'last_error' => self::ERROR_CONFLICT, 'link_status' => 'conflict' ];
		}

		// Throttled: obey the provider, do not count this as a failure.
		if ( 429 === $status ) {
			$wait = (int) ( $result['retry_after'] ?? 0 );
			if ( $wait <= 0 ) {
				$wait = $this->backoff_seconds( $attempts + 1 );
			}
			return [ 'status' => self::STATUS_PENDING, 'attempts' => $attempts, 'not_before' => gmdate( 'Y-m-d H:i:s', $now + $wait ), 'last_error' => 'throttled', 'link_status' => 'throttled' ];
		}

		// Provider-side breakage or transport error: the sweep is the retry.
		if ( 0 === $status || $status >= 500 ) {
			$next = $attempts + 1;
			$max  = igbz()->settings()->int( 'marketplace.sync_retries', 3 );
			if ( $next >= $max ) {
				return [ 'status' => self::STATUS_FAILED, 'attempts' => $next, 'not_before' => null, 'last_error' => mb_substr( (string) ( $result['message'] ?? '' ), 0, 255 ), 'link_status' => 'failed' ];
			}
			return [ 'status' => self::STATUS_PENDING, 'attempts' => $next, 'not_before' => gmdate( 'Y-m-d H:i:s', $now + $this->backoff_seconds( $next ) ), 'last_error' => mb_substr( (string) ( $result['message'] ?? '' ), 0, 255 ), 'link_status' => 'pending' ];
		}

		// Any other 4xx is the payload's fault; retrying will not help.
		return [ 'status' => self::STATUS_FAILED, 'attempts' => $attempts, 'not_before' => null, 'last_error' => mb_substr( (string) ( $result['message'] ?? '' ), 0, 255 ), 'link_status' => 'failed' ];
	}

	/** Exponential backoff, capped: base * 2^(attempt-1), never above an hour. */
	private function backoff_seconds( int $attempt ): int {
		$base = igbz()->settings()->int( 'marketplace.retry_base_seconds', 60 );
		return min( $base * ( 2 ** max( 0, $attempt - 1 ) ), 3600 );
	}

	/**
	 * Persist a classified outcome: the queue row and, for outcomes that know
	 * the listing, the marketplace link.
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $result
	 */
	public function record_outcome( array $row, array $result, string $hash = '' ): void {
		$verdict = $this->classify_result( $row, $result );
		$now     = current_time( 'mysql', true );

		$this->db->update(
			'ig_marketplace_sync',
			[
				'status'     => $verdict['status'],
				'attempts'   => $verdict['attempts'],
				'not_before' => $verdict['not_before'],
				'last_error' => $verdict['last_error'],
				'updated_at' => $now,
			],
			[ 'id' => (int) $row['id'] ]
		);

		$this->save_link(
			(int) $row['product_id'],
			(string) $row['marketplace'],
			(string) ( $result['remote_id'] ?? '' ),
			(int) $row['tenant_id'],
			$verdict['link_status'],
			$verdict['last_error'],
			$hash,
			(string) ( $result['remote_rev'] ?? '' )
		);

		if ( self::STATUS_DONE === $verdict['status'] ) {
			$this->logger->info( 'marketplace', 'Product synced', [ 'row' => (int) $row['id'], 'marketplace' => (string) $row['marketplace'], 'remote' => (string) ( $result['remote_id'] ?? '' ) ] );
		} elseif ( '' !== $verdict['last_error'] ) {
			$this->logger->warning( 'marketplace', 'Marketplace sync failed', [ 'row' => (int) $row['id'], 'error' => $verdict['last_error'] ] );
		}
	}

	/** Insert-or-update the product↔channel link row. */
	private function save_link( int $product_id, string $marketplace, string $external_id, int $tenant_id, string $status, string $message, string $hash, string $remote_rev ): void {
		$existing = $this->link_row( $product_id, $marketplace );
		$now      = current_time( 'mysql', true );

		$data = [
			'tenant_id'      => $tenant_id,
			'product_id'     => $product_id,
			'channel'        => $marketplace,
			'external_id'    => $external_id,
			'payload_hash'   => $hash,
			'remote_rev'     => $remote_rev,
			'last_synced_at' => $now,
			'sync_status'    => $status,
			'sync_message'   => mb_substr( $message, 0, 255 ),
		];

		if ( $existing ) {
			$this->db->update( 'marketplace_links', $data, [ 'id' => (int) $existing['id'] ] );
			return;
		}
		$this->db->insert( 'marketplace_links', $data );
	}

	/** @return array<string,mixed>|null */
	private function link_row( int $product_id, string $marketplace ): ?array {
		$row = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'marketplace_links' ) . '
			 WHERE product_id = %d AND channel = %s LIMIT 1',
			$product_id,
			$marketplace
		);

		return $row ?: null;
	}

	/** @param array<string,mixed> $row */
	private function fail( array $row, string $error ): void {
		$this->db->update(
			'ig_marketplace_sync',
			[
				'status'     => self::STATUS_FAILED,
				'attempts'   => (int) $row['attempts'] + 1,
				'last_error' => mb_substr( $error, 0, 255 ),
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => (int) $row['id'] ]
		);
		$this->logger->warning( 'marketplace', 'Marketplace sync failed', [ 'row' => (int) $row['id'], 'error' => $error ] );
	}

	public function adapter_for( string $marketplace ): ?MarketplaceAdapterInterface {
		$http = igbz()->get( 'http' );

		if ( 'digikala' === $marketplace ) {
			return new HttpMarketplaceAdapter( 'digikala', 'Digikala', 'marketplace.digikala', $http );
		}
		if ( 'divar' === $marketplace ) {
			return new HttpMarketplaceAdapter( 'divar', 'Divar', 'marketplace.divar', $http );
		}
		if ( 'basalam' === $marketplace ) {
			return new BasalamAdapter( $http );
		}
		return null;
	}

	/** @return array<string,mixed>|null */
	private function product_payload( int $product_id ): ?array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return null;
		}

		$images = [];
		$image_id = (int) $product->get_image_id();
		if ( $image_id > 0 ) {
			$images[] = (string) wp_get_attachment_url( $image_id );
		}

		$terms = wc_get_product_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] );

		return [
			'id'          => $product_id,
			'name'        => (string) $product->get_name(),
			'description' => (string) $product->get_description(),
			'price_irt'   => Money::to_rial( (float) $product->get_price() ),
			'stock'       => max( 0, (int) $product->get_stock_quantity() ),
			'category'    => $terms ? (string) $terms[0] : 'default',
			'images'      => $images,
		];
	}

	/** @return array{local_category:string,remote_category:string} */
	private function category_mapping( int $tenant_id, string $marketplace, string $local_category ): array {
		$row = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_category_mapping' ) . '
			 WHERE tenant_id = %d AND marketplace = %s AND local_category = %s LIMIT 1',
			$tenant_id,
			$marketplace,
			$local_category
		);
		if ( ! $row ) {
			$row = $this->db->row(
				'SELECT * FROM ' . $this->db->table( 'ig_category_mapping' ) . '
				 WHERE tenant_id = 0 AND marketplace = %s AND local_category = %s LIMIT 1',
				$marketplace,
				$local_category
			);
		}

		return [
			'local_category'  => $local_category,
			'remote_category' => $row ? (string) $row['remote_category'] : '',
		];
	}

	/** @return array<int,array<string,mixed>> */
	public function pending( int $limit = 50 ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_marketplace_sync' ) . ' ORDER BY id DESC LIMIT %d',
			$limit
		);
	}
}
