<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Gateway registry + payment lifecycle (create -> redirect -> verify) with a persistent audit row
 * per attempt. Verification is strictly server-side against the PSP; a callback alone never marks
 * a payment paid (the nop original had an "always success" verify bug in an earlier revision).
 */
final class PaymentService {

	public const STATUS_CREATED  = 'created';
	public const STATUS_PENDING  = 'pending';
	public const STATUS_PAID     = 'paid';
	public const STATUS_FAILED   = 'failed';
	public const STATUS_CANCELLED = 'cancelled';

	public const PURPOSE_ORDER        = 'order';
	public const PURPOSE_WALLET_TOPUP = 'wallet_topup';
	public const PURPOSE_SUBSCRIPTION = 'subscription';

	/** @var array<string,GatewayInterface> */
	private array $gateways = [];

	public function __construct( private Db $db, private Http $http, private WalletService $wallet, private Logger $logger ) {
		$this->register( new ZarinpalGateway( $http ) );
		$this->register( new IdPayGateway( $http ) );
		$this->register( new NextPayGateway( $http ) );
		$this->register( new PayirGateway( $http ) );
		$this->register( new HttpPspGateway( $http ) );
		$this->register( new NowPaymentsGateway( $http ) );
		$this->register( new BalePayGateway( $http ) );
		$this->register( new SadadGateway( $http ) );
		$this->register( new AsanPardakhtGateway( $http ) );
		$this->register( new ParsianGateway( $http ) );
		$this->register( new IranKishGateway( $http ) );
		$this->register( new MellatGateway( $http ) );
		$this->register( new SamanGateway( $http ) );
		$this->register( new PasargadGateway( $http ) );
		$this->register( new SepehrGateway( $http ) );
		/**
		 * Register additional PSP adapters.
		 *
		 * @param PaymentService $service
		 */
		do_action( 'igbz_register_payment_gateways', $this );
	}

	public function register( GatewayInterface $gateway ): void {
		$this->gateways[ $gateway->id() ] = $gateway;
	}

	/** @return array<string,GatewayInterface> */
	public function gateways(): array {
		return $this->gateways;
	}

	public function gateway( string $id ): ?GatewayInterface {
		return $this->gateways[ $id ] ?? null;
	}

	/**
	 * Gateways the merchant switched on AND that hold working credentials.
	 *
	 * This is what checkout and the wallet top-up form should offer: an adapter that is registered
	 * but disabled, or enabled but missing its API key, must never reach a customer.
	 *
	 * @return array<string,GatewayInterface>
	 */
	public function enabled_gateways(): array {
		return array_filter(
			$this->gateways,
			function ( GatewayInterface $gateway ): bool {
				if ( ! igbz()->settings()->bool( 'payments.' . $gateway->id() . '.enabled', false ) || ! $gateway->is_configured() ) {
					return false;
				}
				// Bank (PSP) gateways are locked until the store has a verified
				// standalone domain and an active Enamad (phase-6 rule).
				if ( in_array( $gateway->id(), [ 'zarinpal', 'idpay', 'nextpay', 'payir', 'httppsp', 'sadad', 'asanpardakht', 'parsian', 'irankish', 'mellat', 'saman', 'pasargad', 'sepehr' ], true ) && ! $this->bank_gateway_allowed() ) {
					return false;
				}
				return true;
			}
		);
	}

	/** Bank gateways require a verified standalone domain + active Enamad. */
	private function bank_gateway_allowed(): bool {
		$domain_ok = true;
		if ( igbz()->has( 'domain' ) ) {
			$domain_ok = igbz()->get( 'domain' )->has_verified_domain( (int) igbz()->tenancy()->id() );
		}
		if ( ! $domain_ok || ! igbz()->settings()->bool( 'legal.enamad_active', false ) ) {
			return false;
		}
		if ( igbz()->has( 'legal.waiver' ) ) {
			return (bool) igbz()->get( 'legal.waiver' )->payment_allowed( (int) igbz()->tenancy()->id() )['allowed'];
		}
		return false;
	}

	public function is_enabled( string $id ): bool {
		$gateway = $this->gateway( $id );
		if ( null === $gateway
			|| ! igbz()->settings()->bool( 'payments.' . $id . '.enabled', false )
			|| ! $gateway->is_configured() ) {
			return false;
		}
		return ! $this->is_bank_gateway( $id ) || $this->bank_gateway_allowed();
	}

	private function is_bank_gateway( string $id ): bool {
		return in_array( $id, [ 'zarinpal', 'idpay', 'nextpay', 'payir', 'httppsp', 'sadad', 'asanpardakht', 'parsian', 'irankish', 'mellat', 'saman', 'pasargad', 'sepehr' ], true );
	}

	/**
	 * The gateway a payment defaults to.
	 *
	 * Falls back to the first usable gateway rather than the first registered one, so a store that
	 * points `payments.default_gateway` at a gateway it later disabled still takes money.
	 */
	public function default_gateway(): ?GatewayInterface {
		$id      = igbz()->settings()->string( 'payments.default_gateway', 'zarinpal' );
		$enabled = $this->enabled_gateways();

		if ( isset( $enabled[ $id ] ) ) {
			return $enabled[ $id ];
		}
		if ( $enabled ) {
			return reset( $enabled );
		}

		// Nothing is both enabled and configured; hand back the named adapter so the caller can
		// report a precise "not configured" error instead of a bare "no gateway".
		return $this->gateway( $id ) ?? ( $this->gateways ? reset( $this->gateways ) : null );
	}

	// ---------------------------------------------------------------- flow

	/**
	 * Create a payment attempt and return the PSP redirect URL.
	 *
	 * @param array<string,mixed> $context
	 * @return array{ok:bool,payment_id:int,redirect_url:string,error:string}
	 */
	public function start( float $amount, string $purpose, array $context = [], string $gateway_id = '' ): array {
		$gateway = '' !== $gateway_id ? $this->gateway( $gateway_id ) : $this->default_gateway();
		if ( ! $gateway ) {
			return [ 'ok' => false, 'payment_id' => 0, 'redirect_url' => '', 'error' => __( 'No payment gateway is available.', 'igbz-suite' ) ];
		}
		if ( ! igbz()->settings()->bool( 'payments.' . $gateway->id() . '.enabled', false ) ) {
			return [ 'ok' => false, 'payment_id' => 0, 'redirect_url' => '', 'error' => __( 'The selected payment gateway is disabled.', 'igbz-suite' ) ];
		}
		if ( ! $gateway->is_configured() ) {
			return [ 'ok' => false, 'payment_id' => 0, 'redirect_url' => '', 'error' => __( 'The selected payment gateway is not configured.', 'igbz-suite' ) ];
		}
		if ( $this->is_bank_gateway( $gateway->id() ) && ! $this->bank_gateway_allowed() ) {
			return [ 'ok' => false, 'payment_id' => 0, 'redirect_url' => '', 'error' => __( 'This bank gateway is locked until the verified-domain and legal requirements are complete.', 'igbz-suite' ) ];
		}
		if ( $amount <= 0 ) {
			return [ 'ok' => false, 'payment_id' => 0, 'redirect_url' => '', 'error' => __( 'Invalid payment amount.', 'igbz-suite' ) ];
		}

		$now        = current_time( 'mysql', true );
		$payment_id = $this->db->insert(
			'payments',
			[
				'tenant_id'  => (int) ( $context['tenant_id'] ?? igbz()->tenancy()->id() ),
				'user_id'    => (int) ( $context['user_id'] ?? get_current_user_id() ),
				'order_id'   => (int) ( $context['order_id'] ?? 0 ),
				'gateway'    => $gateway->id(),
				'purpose'    => $purpose,
				'amount'     => $amount,
				'currency'   => igbz()->settings()->string( 'general.default_currency', 'IRT' ),
				'status'     => self::STATUS_CREATED,
				'meta'       => wp_json_encode( $context ),
				'created_at' => $now,
				'updated_at' => $now,
			]
		);

		$callback = add_query_arg(
			[ 'igbz_payment_callback' => $gateway->id(), 'payment_id' => $payment_id ],
			home_url( '/' )
		);

		$result = $gateway->request( $amount, $callback, $context );

		if ( ! $result->success ) {
			$this->db->update(
				'payments',
				[
					'status'        => self::STATUS_FAILED,
					'error_code'    => $result->error_code,
					'error_message' => mb_substr( $result->error_message, 0, 255 ),
					'updated_at'    => current_time( 'mysql', true ),
				],
				[ 'id' => $payment_id ]
			);
			$this->logger->error( 'payments', 'Payment request failed', [ 'payment_id' => $payment_id, 'gateway' => $gateway->id(), 'code' => $result->error_code ] );
			return [ 'ok' => false, 'payment_id' => $payment_id, 'redirect_url' => '', 'error' => $result->error_message ];
		}

		$this->db->update(
			'payments',
			[
				'status'     => self::STATUS_PENDING,
				'authority'  => $result->authority,
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $payment_id ]
		);

		return [ 'ok' => true, 'payment_id' => $payment_id, 'redirect_url' => $result->redirect_url, 'error' => '' ];
	}

	/**
	 * Verify a PSP callback. Idempotent: a payment already marked paid short-circuits.
	 *
	 * @param array<string,mixed> $params
	 */
	public function handle_callback( string $gateway_id, int $payment_id, array $params ): PaymentVerifyResult {
		$payment = $this->payment( $payment_id );
		if ( ! $payment ) {
			return PaymentVerifyResult::failure( 'not_found', __( 'Payment record not found.', 'igbz-suite' ) );
		}
		if ( self::STATUS_PAID === $payment['status'] ) {
			return PaymentVerifyResult::duplicate( (string) $payment['reference_id'] );
		}
		$gateway = $this->gateway( $gateway_id );
		if ( ! $gateway || $gateway->id() !== $payment['gateway'] ) {
			return PaymentVerifyResult::failure( 'gateway_mismatch', __( 'Gateway mismatch for this payment.', 'igbz-suite' ) );
		}

		$result = $gateway->verify( (float) $payment['amount'], $params );
		$now    = current_time( 'mysql', true );

		if ( ! $result->success ) {
			$this->db->update(
				'payments',
				[
					'status'        => 'cancelled' === $result->error_code ? self::STATUS_CANCELLED : self::STATUS_FAILED,
					'error_code'    => $result->error_code,
					'error_message' => mb_substr( $result->error_message, 0, 255 ),
					'updated_at'    => $now,
				],
				[ 'id' => $payment_id ]
			);
			do_action( 'igbz_payment_failed', $payment_id, $result );
			return $result;
		}

		$this->db->update(
			'payments',
			[
				'status'       => self::STATUS_PAID,
				'reference_id' => $result->reference_id,
				'card_pan'     => $result->card_pan,
				'verified_at'  => $now,
				'updated_at'   => $now,
			],
			[ 'id' => $payment_id ]
		);

		$this->settle( $payment, $result );
		do_action( 'igbz_payment_verified', $payment_id, $result );

		return $result;
	}

	/**
	 * Phase 29 — the provider-notification path (async webhooks).
	 *
	 * Unlike handle_callback() there is no browser to re-verify with, so the verdict travels in
	 * the signed payload and the transition goes through the shared state machine: only legal
	 * hops apply, the write is pinned on the current status, and a racing callback loses cleanly
	 * instead of corrupting state. Settlement re-uses the exact same code path as the return URL.
	 * `unknown` is reported back honestly so the inbox retries later instead of guessing.
	 *
	 * @param array<string,mixed> $extra Optional reference_id / error_code / error_message.
	 * @return array<string,mixed>
	 */
	public function apply_notification( int $payment_id, string $verdict, array $extra = [] ): array {
		$payment = $this->payment( $payment_id );
		if ( ! $payment ) {
			return [ 'ok' => false, 'reason' => 'not_found' ];
		}
		if ( self::STATUS_PAID === $payment['status'] ) {
			return [ 'ok' => true, 'reason' => 'already_paid' ];
		}

		$map = [
			'paid'      => self::STATUS_PAID,
			'failed'    => self::STATUS_FAILED,
			'cancelled' => self::STATUS_CANCELLED,
		];
		$to = $map[ $verdict ] ?? PaymentStateMachine::STATUS_UNKNOWN;

		$write = array_intersect_key( $extra, array_flip( [ 'reference_id', 'error_code', 'error_message' ] ) );
		if ( self::STATUS_PAID === $to ) {
			$write['verified_at'] = current_time( 'mysql', true );
		}

		$result = PaymentStateMachine::make( $this->db )->advance( $payment_id, $to, $write );
		if ( ! $result['ok'] ) {
			return $result;
		}

		if ( self::STATUS_PAID === $to ) {
			$verify = PaymentVerifyResult::ok( (string) ( $extra['reference_id'] ?? '' ) );
			$this->settle( $payment, $verify );
			do_action( 'igbz_payment_verified', $payment_id, $verify );
		} elseif ( self::STATUS_PAID !== $to && PaymentStateMachine::STATUS_UNKNOWN !== $to ) {
			do_action( 'igbz_payment_failed', $payment_id, PaymentVerifyResult::failure( (string) ( $extra['error_code'] ?? $verdict ), (string) ( $extra['error_message'] ?? '' ) ) );
		}

		return [ 'ok' => true, 'from' => $result['from'], 'to' => $to ];
	}

	/**
	 * @param array<string,mixed> $payment
	 */
	private function settle( array $payment, PaymentVerifyResult $result ): void {
		$purpose = (string) $payment['purpose'];

		if ( self::PURPOSE_WALLET_TOPUP === $purpose ) {
			$this->wallet->credit(
				(int) $payment['user_id'],
				(float) $payment['amount'],
				WalletService::REASON_TOPUP,
				'payment:' . (int) $payment['id'],
				[ 'gateway' => $payment['gateway'], 'ref_id' => $result->reference_id ],
				(int) $payment['tenant_id'],
				0,
				__( 'Wallet top-up', 'igbz-suite' )
			);
			return;
		}

		if ( self::PURPOSE_ORDER === $purpose && (int) $payment['order_id'] > 0 ) {
			$order = wc_get_order( (int) $payment['order_id'] );
			if ( $order && ! $order->is_paid() ) {
				$order->payment_complete( $result->reference_id );
				$order->add_order_note(
					sprintf(
						/* translators: 1: gateway id, 2: reference id */
						__( 'Paid via %1$s. Reference: %2$s', 'igbz-suite' ),
						(string) $payment['gateway'],
						$result->reference_id
					)
				);
			}
		}
	}

	// --------------------------------------------------------------- queries

	/** @return array<string,mixed>|null */
	public function payment( int $id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'payments' ) . ' WHERE id = %d AND tenant_id = %d', $id, igbz()->tenancy()->id() );
	}

	/** @return array<string,mixed>|null */
	public function payment_by_authority( string $authority ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'payments' ) . ' WHERE authority = %s ORDER BY id DESC', $authority );
	}

	/** @return array<int,array<string,mixed>> */
	public function payments_for_user( int $user_id, int $limit = 50 ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'payments' ) . ' WHERE user_id = %d AND tenant_id = %d ORDER BY id DESC LIMIT %d',
			$user_id,
			igbz()->tenancy()->id(),
			$limit
		);
	}

	/** @return array{count:int,paid:int,volume:float} */
	public function stats( int $tenant_id = 0 ): array {
		$row = $this->db->row(
			'SELECT COUNT(*) AS cnt,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS paid,
					COALESCE(SUM(CASE WHEN status = %s THEN amount ELSE 0 END),0) AS volume
			 FROM ' . $this->db->table( 'payments' ) . ' WHERE tenant_id = %d',
			self::STATUS_PAID,
			self::STATUS_PAID,
			$tenant_id
		);
		return [
			'count'  => (int) ( $row['cnt'] ?? 0 ),
			'paid'   => (int) ( $row['paid'] ?? 0 ),
			'volume' => (float) ( $row['volume'] ?? 0 ),
		];
	}
}
