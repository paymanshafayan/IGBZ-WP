<?php
namespace IGBZ\Suite\Modules\Domain;

use IGBZ\Suite\Modules\Fx\FxWalletService;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Domain commerce — phase 37: search, quote and the idempotent order.
 *
 * A quote is a price with a deadline, not a promise forever; an order is one
 * reservation per (tenant, idempotency key). Reserving a name is local — an
 * `ig_domain_orders` row — and the provider's `register()` is NEVER called
 * before the order is paid. Registration, signed callbacks and compensation
 * belong to phase 38.
 */
final class DomainService {

	public const ORDER_RESERVED   = 'reserved';
	public const ORDER_PAID       = 'paid';
	public const ORDER_REGISTERING = 'registering';
	public const ORDER_REGISTERED = 'registered';
	public const ORDER_FAILED     = 'failed';
	public const ORDER_REFUNDED   = 'refunded';
	public const ORDER_CANCELLED  = 'cancelled';

	/** RFC-ish sanity check only — the provider answers the real availability question. */
	public static function valid_domain_name( string $name ): bool {
		$name = strtolower( trim( $name ) );
		if ( '' === $name || strlen( $name ) > 253 ) {
			return false;
		}
		return 1 === preg_match( '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $name );
	}

	/** @return array{ok:bool,results:array<int,array<string,mixed>>,error:string} */
	public function search( string $term ): array {
		$term = strtolower( trim( $term ) );
		if ( '' === $term || strlen( $term ) > 63 || 1 !== preg_match( '/^[a-z0-9-]+$/', $term ) ) {
			return [ 'ok' => false, 'results' => [], 'error' => 'invalid_term' ];
		}

		$adapter = $this->adapters->active();
		if ( ! $adapter || ! $adapter->is_configured() ) {
			return [ 'ok' => false, 'results' => [], 'error' => 'no_domain_provider' ];
		}

		return $adapter->search( $term );
	}

	/**
	 * Price one name and store the quote with its deadline. A provider that
	 * cannot price honestly (no price, zero, negative) gets refused — nothing
	 * is ever orderable at zero.
	 *
	 * @return array{ok:bool,quote:array<string,mixed>,error:string}
	 */
	public function quote( int $tenant_id, string $name ): array {
		$name = strtolower( trim( $name ) );
		if ( ! self::valid_domain_name( $name ) ) {
			return [ 'ok' => false, 'quote' => [], 'error' => 'invalid_name' ];
		}

		$adapter = $this->adapters->active();
		if ( ! $adapter || ! $adapter->is_configured() ) {
			return [ 'ok' => false, 'quote' => [], 'error' => 'no_domain_provider' ];
		}

		$answer = $adapter->quote( $name );
		if ( ! $answer['ok'] ) {
			return [ 'ok' => false, 'quote' => [], 'error' => (string) $answer['error'] ];
		}

		$price = (float) $answer['price'];
		if ( $price <= 0 ) {
			$this->logger->warning( 'domain', 'Provider returned a non-positive price, quote refused', [ 'name' => $name, 'price' => $price ] );
			return [ 'ok' => false, 'quote' => [], 'error' => 'invalid_price' ];
		}

		$ttl = self::quote_ttl_minutes( $this->settings, (int) ( $answer['ttl_minutes'] ?? 0 ) );

		$id = $this->db->insert(
			'ig_domain_quotes',
			[
				'tenant_id'  => $tenant_id,
				'name'       => $name,
				'price'      => $price,
				'currency'   => mb_substr( strtoupper( (string) ( $answer['currency'] ?? 'USD' ) ), 0, 8 ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl * 60 ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			]
		);

		return [
			'ok'    => true,
			'quote' => $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_domain_quotes' ) . ' WHERE id = %d', (int) $id ) ?? [],
			'error' => '',
		];
	}

	/**
	 * Reserve one name: create the order row, idempotent per (tenant, key).
	 * A valid (unexpired) quote for the name is required — the order carries
	 * the quoted price. No provider call happens here, ever.
	 *
	 * @return array{ok:bool,order:array<string,mixed>,created:bool,error:string}
	 */
	public function order( int $tenant_id, string $name, string $idempotency_key ): array {
		$name = strtolower( trim( $name ) );
		if ( ! self::valid_domain_name( $name ) ) {
			return [ 'ok' => false, 'order' => [], 'created' => false, 'error' => 'invalid_name' ];
		}
		if ( '' === $idempotency_key ) {
			return [ 'ok' => false, 'order' => [], 'created' => false, 'error' => 'missing_idempotency_key' ];
		}

		$existing = $this->order_by_key( $tenant_id, $idempotency_key );
		if ( null !== $existing ) {
			return [ 'ok' => true, 'order' => $existing, 'created' => false, 'error' => '' ];
		}

		$quote = $this->valid_quote( $tenant_id, $name );
		if ( null === $quote ) {
			return [ 'ok' => false, 'order' => [], 'created' => false, 'error' => 'no_valid_quote' ];
		}

		$id = $this->db->insert(
			'ig_domain_orders',
			[
				'tenant_id'       => $tenant_id,
				'domain_id'       => 0,
				'action'          => 'register',
				'amount'          => (float) $quote['price'],
				'status'          => self::ORDER_RESERVED,
				'provider_ref'    => '',
				'idempotency_key' => $idempotency_key,
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			]
		);

		$this->logger->info( 'domain', 'Domain reserved', [ 'tenant_id' => $tenant_id, 'name' => $name, 'order_id' => (int) $id, 'amount' => (float) $quote['price'] ] );

		return [
			'ok'      => true,
			'order'   => $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_domain_orders' ) . ' WHERE id = %d', (int) $id ) ?? [],
			'created' => true,
			'error'   => '',
		];
	}

	/**
	 * The payment landed: flip reserved → paid exactly once. Provider
	 * registration itself is phase 38 (signed callback + compensation); this
	 * is the single gate that unlocks it.
	 *
	 * @return array{ok:bool,status:string,error:string}
	 */
	public function confirm_paid( array $order ): array {
		$fresh = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_domain_orders' ) . ' WHERE id = %d',
			(int) $order['id']
		);
		if ( null === $fresh ) {
			return [ 'ok' => false, 'status' => 'missing', 'error' => 'order_missing' ];
		}
		if ( self::ORDER_PAID === (string) $fresh['status'] ) {
			return [ 'ok' => true, 'status' => self::ORDER_PAID, 'error' => '' ];
		}
		if ( self::ORDER_RESERVED !== (string) $fresh['status'] ) {
			return [ 'ok' => false, 'status' => (string) $fresh['status'], 'error' => 'not_reserved' ];
		}

		$this->db->update(
			'ig_domain_orders',
			[ 'status' => self::ORDER_PAID ],
			[ 'id' => (int) $order['id'] ]
		);
		$this->logger->info( 'domain', 'Domain order paid, ready for registration', [ 'order_id' => (int) $order['id'] ] );

		return [ 'ok' => true, 'status' => self::ORDER_PAID, 'error' => '' ];
	}

	/** The latest unexpired quote for a name, or null. @return array<string,mixed>|null */
	public function valid_quote( int $tenant_id, string $name ): ?array {
		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_domain_quotes' ) . '\n\t\t\t WHERE tenant_id = %d AND name = %s ORDER BY id DESC LIMIT 1',
			$tenant_id,
			strtolower( trim( $name ) )
		);
		$quote = $rows[0] ?? null;
		if ( null === $quote ) {
			return null;
		}

		$expires = (string) ( $quote['expires_at'] ?? '' );
		if ( '' === $expires || strtotime( $expires . ' UTC' ) < time() ) {
			return null;
		}

		return $quote;
	}

	/** @return array<string,mixed>|null */
	public function order_by_key( int $tenant_id, string $idempotency_key ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_domain_orders' ) . '\n\t\t\t WHERE tenant_id = %d AND idempotency_key = %s',
			$tenant_id,
			$idempotency_key
		);
	}

	/** Quote lifetime: the provider's ttl clamped to domain.quote_ttl_minutes (default 15, ≥1). */
	public static function quote_ttl_minutes( Settings $settings, int $provider_ttl = 0 ): int {
		$ttl = $settings->int( 'domain.quote_ttl_minutes', 15 );
		$ttl = max( 1, $ttl );
		if ( $provider_ttl > 0 ) {
			$ttl = min( $ttl, $provider_ttl );
		}
		return $ttl;
	}

	/**
	 * Phase 38 — registration happens ONLY on a paid order. The order flips
	 * paid → registering before the provider is asked (so a crash mid-call is
	 * visible, not silent), then lands registered or failed. A failed
	 * registration refunds the FX wallet idempotently and leaves a journal.
	 *
	 * @return array{ok:bool,status:string,error:string}
	 */
	public function register_paid( array $order ): array {
		$fresh = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_domain_orders' ) . ' WHERE id = %d',
			(int) $order['id']
		);
		if ( null === $fresh ) {
			return [ 'ok' => false, 'status' => 'missing', 'error' => 'order_missing' ];
		}
		if ( self::ORDER_REGISTERED === (string) $fresh['status'] ) {
			return [ 'ok' => true, 'status' => self::ORDER_REGISTERED, 'error' => '' ];
		}
		if ( self::ORDER_PAID !== (string) $fresh['status'] ) {
			return [ 'ok' => false, 'status' => (string) $fresh['status'], 'error' => 'not_paid' ];
		}

		$adapter = $this->adapters->active();
		if ( ! $adapter || ! $adapter->is_configured() ) {
			return [ 'ok' => false, 'status' => self::ORDER_PAID, 'error' => 'no_domain_provider' ];
		}

		$this->db->update( 'ig_domain_orders', [ 'status' => self::ORDER_REGISTERING ], [ 'id' => (int) $fresh['id'] ] );
		$this->record( $fresh, 'register_started', '' );

		$result = null;
		try {
			$result = $adapter->register( $fresh );
		} catch ( \Throwable $e ) {
			$result = [ 'ok' => false, 'reference' => '', 'error' => $e->getMessage() ];
		}

		if ( $result['ok'] ) {
			$this->db->update(
				'ig_domain_orders',
				[ 'status' => self::ORDER_REGISTERED, 'provider_ref' => mb_substr( (string) $result['reference'], 0, 191 ) ],
				[ 'id' => (int) $fresh['id'] ]
			);
			$this->record( $fresh, 'registered', (string) $result['reference'] );
			$this->logger->info( 'domain', 'Domain registered', [ 'order_id' => (int) $fresh['id'], 'reference' => (string) $result['reference'] ] );

			return [ 'ok' => true, 'status' => self::ORDER_REGISTERED, 'error' => '' ];
		}

		$this->db->update( 'ig_domain_orders', [ 'status' => self::ORDER_FAILED ], [ 'id' => (int) $fresh['id'] ] );
		$this->record( $fresh, 'register_failed', (string) $result['error'] );
		$this->refund_order( $fresh );
		$this->logger->error( 'domain', 'Domain registration failed, order refunded', [ 'order_id' => (int) $fresh['id'], 'error' => (string) $result['error'] ] );

		return [ 'ok' => false, 'status' => self::ORDER_FAILED, 'error' => (string) $result['error'] ];
	}

	/**
	 * Phase 38 — apply one provider verdict to a registering order (signed
	 * callback and backup polling share this single path). Unknown changes
	 * nothing. Replays on a settled order are inert.
	 *
	 * @return array{ok:bool,status:string,error:string}
	 */
	public function apply_provider_result( int $order_id, bool $ok, string $reference = '' ): array {
		$fresh = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_domain_orders' ) . ' WHERE id = %d',
			$order_id
		);
		if ( null === $fresh ) {
			return [ 'ok' => false, 'status' => 'missing', 'error' => 'order_missing' ];
		}

		$status = (string) $fresh['status'];
		if ( self::ORDER_REGISTERED === $status || self::ORDER_REFUNDED === $status ) {
			return [ 'ok' => true, 'status' => $status, 'error' => '' ];
		}
		if ( self::ORDER_REGISTERING !== $status && self::ORDER_FAILED !== $status ) {
			return [ 'ok' => false, 'status' => $status, 'error' => 'not_registering' ];
		}

		if ( $ok ) {
			$this->db->update(
				'ig_domain_orders',
				[ 'status' => self::ORDER_REGISTERED, 'provider_ref' => mb_substr( $reference, 0, 191 ) ],
				[ 'id' => $order_id ]
			);
			$this->record( $fresh, 'registered', $reference );
			return [ 'ok' => true, 'status' => self::ORDER_REGISTERED, 'error' => '' ];
		}

		if ( self::ORDER_FAILED !== $status ) {
			$this->db->update( 'ig_domain_orders', [ 'status' => self::ORDER_FAILED ], [ 'id' => $order_id ] );
			$this->record( $fresh, 'register_failed', 'provider_verdict' );
		}
		$this->refund_order( $fresh );

		return [ 'ok' => false, 'status' => self::ORDER_FAILED, 'error' => 'provider_verdict_failed' ];
	}

	/**
	 * Phase 38 — verify a provider callback signature (HMAC-SHA256 over the
	 * raw body with domain.webhook_secret). A callback that cannot be
	 * verified is treated as never received.
	 */
	public function verify_callback( string $raw_body, string $signature ): bool {
		$secret = (string) $this->settings->get( 'domain.webhook_secret', '' );
		if ( '' === $secret || '' === $signature ) {
			return false;
		}

		return hash_equals( hash_hmac( 'sha256', $raw_body, $secret ), strtolower( trim( $signature ) ) );
	}

	/**
	 * Phase 38 — backup polling for orders stuck in `registering` longer than
	 * the given age. The provider is asked through query(); a registered or
	 * failed verdict is applied, unknown is counted and left alone.
	 *
	 * @return array{scanned:int,resolved:int,unresolved:int}
	 */
	public function poll_stuck( int $max_age_hours = 2 ): array {
		$out     = [ 'scanned' => 0, 'resolved' => 0, 'unresolved' => 0 ];
		$adapter = $this->adapters->active();

		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_domain_orders' ) . "\n\t\t\t WHERE status = %s ORDER BY id ASC LIMIT %d",
			self::ORDER_REGISTERING,
			100
		);
		$cutoff = time() - max( 1, $max_age_hours ) * 3600;

		foreach ( $rows as $order ) {
			if ( strtotime( (string) ( $order['created_at'] ?? '' ) . ' UTC' ) > $cutoff ) {
				continue; // Too young to be stuck.
			}
			++$out['scanned'];

			if ( null === $adapter ) {
				++$out['unresolved'];
				continue;
			}

			$verdict = null;
			try {
				$verdict = $adapter->query( $order );
			} catch ( \Throwable $e ) {
				$verdict = [ 'state' => 'unknown', 'reference' => '', 'error' => $e->getMessage() ];
			}

			$state = (string) ( $verdict['state'] ?? 'unknown' );
			if ( 'registered' === $state ) {
				$this->apply_provider_result( (int) $order['id'], true, (string) ( $verdict['reference'] ?? '' ) );
				++$out['resolved'];
				continue;
			}
			if ( 'failed' === $state ) {
				$this->apply_provider_result( (int) $order['id'], false );
				++$out['resolved'];
				continue;
			}
			++$out['unresolved'];
		}

		return $out;
	}

	/**
	 * Phase 38 — idempotent refund of a failed registration back to the FX
	 * wallet; the journal records it once, the wallet credit's own
	 * (reason, reference) idempotency makes a replay a no-op.
	 */
	public function refund_order( array $order ): void {
		$this->wallet->credit(
			(int) $order['tenant_id'],
			(float) $order['amount'],
			FxWalletService::REASON_REFUND,
			'domain_refund:' . (int) $order['id'],
			[ 'order_id' => (int) $order['id'] ]
		);
		$this->record( $order, 'refunded', (string) (float) $order['amount'] );
	}

	/** @param array<string,mixed> $order */
	public function record( array $order, string $event, string $detail ): void {
		$this->db->insert(
			'ig_domain_journal',
			[
				'tenant_id'  => (int) ( $order['tenant_id'] ?? 0 ),
				'order_id'   => (int) ( $order['id'] ?? 0 ),
				'event'      => $event,
				'detail'     => mb_substr( $detail, 0, 255 ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			]
		);
	}

	public function __construct(
		private Db $db,
		private Settings $settings,
		private DomainAdapterRegistry $adapters,
		private Logger $logger,
		private FxWalletService $wallet
	) {}
}
