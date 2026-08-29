<?php
namespace IGBZ\Suite\Modules\Pado\Services;

use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentService;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 58 — the sensitive commercial operations, wired to the atomic permission queue.
 *
 * Price change, refund and bulk delete never execute directly any more: each call site
 * submits a request (kind + payload + capability + idempotency key), a human approves
 * through the phase-57 queue, and only then does `run()` execute — under the queue's
 * claim/complete contract, so exactly once.
 *
 * Every executor is compensable:
 *   - price_change: the old price travels inside the payload; if the apply does not
 *     verify on re-read, the old price is restored before reporting failure;
 *   - payment_refund: delegates to PaymentService::refund_payment, which guards
 *     not_paid/over_refund and carries its own PSP idempotency key — a refused refund
 *     moves nothing;
 *   - bulk_product_delete: products are TRASHED, never hard-deleted; if a batch fails
 *     midway, everything trashed so far is untrashed again.
 *
 * Defence in depth: `run()` re-verifies the payload digest before executing — a row
 * edited after submission dies as `failed`, whatever the approver clicked.
 *
 * Not final on purpose: the three protected environment seams (load/trash/untrash) are
 * the WooCommerce boundary, and tests subclass them instead of defining global
 * functions over each other.
 */
class SensitiveOperationsService {

	public const KIND_PRICE_CHANGE = 'price_change';
	public const KIND_REFUND       = 'payment_refund';
	public const KIND_BULK_DELETE  = 'bulk_product_delete';

	/** The capability an approver must prove for any of these kinds. */
	public const CAPABILITY = 'manage_tenant';

	public function __construct(
		private Db $db,
		private Logger $logger,
		private ApprovalRequestService $approvals,
		private PaymentService $payments
	) {}

	// --------------------------------------------------------------- requests

	/** @return array{ok:bool,id:int,error:string,duplicate:bool} */
	public function request_price_change( int $tenant_id, int $product_id, float $new_price, int $requested_by, string $reason = '' ): array {
		if ( $new_price < 0 || $product_id <= 0 ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'invalid_request', 'duplicate' => false ];
		}

		$old = $this->product_price( $product_id );

		return $this->approvals->enqueue( [
			'tenant_id'       => $tenant_id,
			'kind'            => self::KIND_PRICE_CHANGE,
			'title'           => sprintf( 'تغییر قیمت محصول #%d', $product_id ),
			'reason'          => $reason,
			'payload'         => [
				'product_id' => $product_id,
				'old_price'  => $old,
				'new_price'  => $new_price,
			],
			'capability'      => self::CAPABILITY,
			'idempotency_key' => sprintf( 'price:%d:%s', $product_id, hash( 'sha256', number_format( $old, 4, '.', '' ) . '|' . number_format( $new_price, 4, '.', '' ) ) ),
			'impact'          => ApprovalRequestService::IMPACT_MEDIUM,
			'requested_by'    => $requested_by,
		] );
	}

	/** @return array{ok:bool,id:int,error:string,duplicate:bool} */
	public function request_refund( int $tenant_id, int $payment_id, float $amount, int $requested_by, string $reason = '', string $idempotency_key = '' ): array {
		if ( $amount <= 0 || $payment_id <= 0 ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'invalid_request', 'duplicate' => false ];
		}

		return $this->approvals->enqueue( [
			'tenant_id'       => $tenant_id,
			'kind'            => self::KIND_REFUND,
			'title'           => sprintf( 'بازپرداخت پرداخت #%d', $payment_id ),
			'reason'          => $reason,
			'payload'         => [
				'payment_id' => $payment_id,
				'amount'     => round( $amount, 4 ),
			],
			'capability'      => self::CAPABILITY,
			'idempotency_key' => '' !== $idempotency_key ? $idempotency_key : null,
			'impact'          => ApprovalRequestService::IMPACT_HIGH,
			'requested_by'    => $requested_by,
		] );
	}

	/**
	 * @param array<int,int> $product_ids
	 * @return array{ok:bool,id:int,error:string,duplicate:bool}
	 */
	public function request_bulk_delete( array $product_ids, int $tenant_id, int $requested_by, string $reason = '' ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ), static fn ( int $id ): bool => $id > 0 ) ) );
		if ( ! $ids ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'invalid_request', 'duplicate' => false ];
		}
		if ( count( $ids ) > 500 ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'batch_too_large', 'duplicate' => false ];
		}

		sort( $ids );

		return $this->approvals->enqueue( [
			'tenant_id'       => $tenant_id,
			'kind'            => self::KIND_BULK_DELETE,
			'title'           => sprintf( 'حذف انبوه %d محصول', count( $ids ) ),
			'reason'          => $reason,
			'payload'         => [ 'product_ids' => $ids ],
			'capability'      => self::CAPABILITY,
			'idempotency_key' => 'bulk:' . hash( 'sha256', implode( ',', $ids ) ),
			'impact'          => ApprovalRequestService::IMPACT_CRITICAL,
			'requested_by'    => $requested_by,
		] );
	}

	// --------------------------------------------------------------- execute

	/**
	 * The queue's executor. Runs under the claim/complete contract; compensates on
	 * failure; refuses a tampered payload whatever the approval said.
	 *
	 * @param array<string,mixed> $row
	 */
	public function run( array $row ): bool {
		$id = (int) ( $row['id'] ?? 0 );
		if ( $id <= 0 ) {
			return false;
		}

		if ( ! $this->approvals->verify_payload_integrity( $id, (int) ( $row['tenant_id'] ?? 0 ) ) ) {
			$this->logger->error( 'pado', 'Refused to execute a request whose payload was edited after submission', [ 'request' => $id ] );
			return false;
		}

		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		if ( ! is_array( $payload ) ) {
			return false;
		}

		switch ( (string) $row['kind'] ) {
			case self::KIND_PRICE_CHANGE:
				return $this->run_price_change( $payload );
			case self::KIND_REFUND:
				return $this->run_refund( $payload );
			case self::KIND_BULK_DELETE:
				return $this->run_bulk_delete( $payload );
		}

		return false; // unknown kind: nothing executes
	}

	/** @param array<string,mixed> $payload */
	private function run_price_change( array $payload ): bool {
		$product_id = (int) ( $payload['product_id'] ?? 0 );
		$new_price  = (float) ( $payload['new_price'] ?? 0 );
		$old_price  = (float) ( $payload['old_price'] ?? 0 );
		if ( $product_id <= 0 ) {
			return false;
		}

		$product = $this->load_product( $product_id );
		if ( null === $product ) {
			return false; // the product vanished between request and approval — nothing to touch
		}

		$product->set_regular_price( (string) $new_price );
		$product->set_price( $new_price );
		$product->save();

		/*
		 * Verify on re-read; compensate when the write does not stick. The check reads
		 * the REGULAR price back: while a sale is active WooCommerce serves the sale
		 * price as the effective one, so reading get_price() here would reject every
		 * discounted product (phase-58 live-smoke finding).
		 */
		$refreshed = $this->load_product( $product_id );
		if ( null === $refreshed || abs( $this->regular_of( $refreshed ) - $new_price ) > 0.0001 ) {
			$this->compensate_price( $product_id, $old_price );
			return false;
		}

		/*
		 * The active price must agree with what the queue approved. WooCommerce derives
		 * _price from regular/sale on save, but the live smoke of this phase watched a
		 * save where that derivation lagged once (regular moved, _price did not). Retry
		 * the activation once; if it still disagrees, compensate — the shop must never
		 * display a price nobody approved.
		 */
		$sale = $this->sale_of( $refreshed );
		if ( $sale <= 0 && abs( (float) $refreshed->get_price() - $new_price ) > 0.0001 ) {
			$refreshed->set_price( $new_price );
			$refreshed->save();
			$again = $this->load_product( $product_id );
			if ( null === $again || abs( (float) $again->get_price() - $new_price ) > 0.0001 ) {
				$this->compensate_price( $product_id, $old_price );
				return false;
			}
		}

		$this->logger->info( 'pado', 'Price change executed', [ 'product' => $product_id, 'from' => $old_price, 'to' => $new_price ] );
		return true;
	}

	private function compensate_price( int $product_id, float $old_price ): void {
		$product = $this->load_product( $product_id );
		if ( null === $product ) {
			return;
		}
		$sale = method_exists( $product, 'get_sale_price' ) ? (float) $product->get_sale_price() : 0.0;
		$product->set_regular_price( (string) $old_price );
		$product->set_price( $sale > 0 ? $sale : $old_price );
		$product->save();
		$this->logger->warning( 'pado', 'Price change compensated back to the captured price', [ 'product' => $product_id, 'price' => $old_price ] );
	}

	/** @param array<string,mixed> $payload */
	private function run_refund( array $payload ): bool {
		$payment_id = (int) ( $payload['payment_id'] ?? 0 );
		$amount     = (float) ( $payload['amount'] ?? 0 );
		if ( $payment_id <= 0 || $amount <= 0 ) {
			return false;
		}

		// PaymentService guards not_paid / over_refund / unsupported gateway itself and
		// carries the PSP idempotency key; a refused refund moves nothing.
		$result = $this->payments->refund_payment( $payment_id, $amount, 'pado approval' );

		if ( empty( $result['ok'] ) ) {
			$this->logger->error( 'pado', 'Refund execution refused', [ 'payment' => $payment_id, 'reason' => (string) ( $result['reason'] ?? '' ) ] );
			return false;
		}

		return true;
	}

	/** @param array<string,mixed> $payload */
	private function run_bulk_delete( array $payload ): bool {
		$ids = (array) ( $payload['product_ids'] ?? [] );
		$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) );
		if ( ! $ids ) {
			return false;
		}

		$trashed = [];
		foreach ( $ids as $product_id ) {
			$product = $this->load_product( $product_id );
			if ( null === $product ) {
				continue; // already gone is not a failure for a delete batch
			}

			$post_id = (int) $product->get_id();
			if ( $this->trash_post( $post_id ) ) {
				$trashed[] = $post_id;
				continue;
			}

			// Mid-batch failure: undo everything this run trashed, then report.
			foreach ( $trashed as $done ) {
				$this->untrash_post( $done );
			}
			$this->logger->error( 'pado', 'Bulk delete failed midway; trashed rows restored', [ 'failed_at' => $product_id, 'restored' => count( $trashed ) ] );
			return false;
		}

		$this->logger->info( 'pado', 'Bulk delete executed (trash, reversible)', [ 'count' => count( $trashed ) ] );
		return true;
	}

	// ------------------------------------------------------------------ util

	/**
	 * The operation owns the REGULAR price: WooCommerce serves the sale price while a
	 * sale is active, so the captured before-state (and the idempotency digest) must be
	 * the regular one — found live in the phase-58 smoke, where a discounted product
	 * made the verify-on-re-read fire and the honest change die as failed.
	 */
	private function product_price( int $product_id ): float {
		$product = $this->load_product( $product_id );
		if ( null === $product ) {
			return 0.0;
		}
		return method_exists( $product, 'get_regular_price' ) ? (float) $product->get_regular_price() : (float) $product->get_price();
	}

	private function sale_of( object $product ): float {
		return method_exists( $product, 'get_sale_price' ) ? (float) $product->get_sale_price() : 0.0;
	}

	private function regular_of( object $product ): float {
		return method_exists( $product, 'get_regular_price' ) ? (float) $product->get_regular_price() : (float) $product->get_price();
	}

	// ------------------------------------------------- environment seams

	/**
	 * The product, or null when it does not exist. The WooCommerce boundary, kept as a
	 * protected seam so tests drive a real world without defining global functions.
	 */
	protected function load_product( int $product_id ): ?object {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
		return $product ? $product : null;
	}

	/** Trash (reversible delete). */
	protected function trash_post( int $post_id ): bool {
		return function_exists( 'wp_trash_post' ) && (bool) wp_trash_post( $post_id );
	}

	/** Restore a trashed post. */
	protected function untrash_post( int $post_id ): void {
		if ( function_exists( 'wp_untrash_post' ) ) {
			wp_untrash_post( $post_id );
		}
	}
}
