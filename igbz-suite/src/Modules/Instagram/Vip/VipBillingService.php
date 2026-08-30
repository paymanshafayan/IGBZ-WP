<?php
namespace IGBZ\Suite\Modules\Instagram\Vip;

use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentService;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentVerifyResult;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Money for the VIP channel: memberships, single-post purchases and tips.
 *
 * Settlement rides on the `igbz_payment_verified` action rather than a new branch inside
 * PaymentService::settle(). The gateway layer has no business knowing what a VIP post is, and
 * hooking the action means an already-shipped, already-tested payment path stays untouched.
 */
final class VipBillingService {

	public const PURPOSE_MEMBERSHIP = 'vip_membership';
	public const PURPOSE_POST       = 'vip_post';
	public const PURPOSE_TIP        = 'vip_tip';

	public const SOURCE_PURCHASE = 'purchase';
	public const SOURCE_GIFT     = 'gift';
	public const SOURCE_REFUND   = 'refund';

	public function __construct(
		private Db $db,
		private Settings $settings,
		private Logger $logger,
		private VipAccessService $access
	) {}

	public function register(): void {
		add_action( 'igbz_payment_verified', [ $this, 'on_payment_verified' ], 10, 2 );
		add_action( 'igbz_cron_hourly', [ $this, 'expire_memberships' ] );
	}

	// ------------------------------------------------------------ membership

	/**
	 * Start a subscription purchase.
	 *
	 * @return array{ok:bool,membership_id:int,payment_id:int,redirect_url:string,error:string}
	 */
	public function subscribe( int $user_id, int $plan_id, string $gateway_id = '', bool $use_wallet = false ): array {
		$fail = static fn( string $msg ): array => [
			'ok'            => false,
			'membership_id' => 0,
			'payment_id'    => 0,
			'redirect_url'  => '',
			'error'         => $msg,
		];

		if ( $user_id <= 0 ) {
			return $fail( __( 'Please sign in first.', 'igbz-suite' ) );
		}

		$plan = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'vip_plans' ) . ' WHERE id = %d AND is_active = 1',
			$plan_id
		);
		if ( ! $plan ) {
			return $fail( __( 'That plan is not available.', 'igbz-suite' ) );
		}

		$price     = (float) $plan['price'];
		$tenant_id = (int) $plan['tenant_id'];
		$now       = current_time( 'mysql', true );

		// Phase 54: a free plan that is already live mints nothing — a second tap returns
		// the existing membership instead of stacking a fresh free term. Paid plans
		// deliberately skip this: buying a new term before the old one ends is a renewal,
		// and activate_membership() extends from the paid-for end date.
		if ( $price <= 0 ) {
			$active_id = (int) $this->db->scalar(
				'SELECT id FROM ' . $this->db->table( 'vip_memberships' ) .
				' WHERE user_id = %d AND plan_id = %d AND tenant_id = %d AND status = %s
				   AND (ends_at IS NULL OR ends_at > %s) ORDER BY id DESC LIMIT 1',
				$user_id,
				$plan_id,
				$tenant_id,
				VipAccessService::STATUS_ACTIVE,
				$now
			);
			if ( $active_id > 0 ) {
				return [ 'ok' => true, 'membership_id' => $active_id, 'payment_id' => 0, 'redirect_url' => '', 'error' => '' ];
			}
		}

		// An earlier attempt that never reached the PSP is reused as-is; its payment row is
		// recovered through the same dedupe key below, so the shopper sees one payment, not two.
		$membership_id = (int) $this->db->scalar(
			'SELECT id FROM ' . $this->db->table( 'vip_memberships' ) .
			' WHERE user_id = %d AND plan_id = %d AND tenant_id = %d AND status = %s ORDER BY id DESC LIMIT 1',
			$user_id,
			$plan_id,
			$tenant_id,
			VipAccessService::STATUS_PENDING
		);

		if ( $membership_id > 0 ) {
			$this->db->update(
				'vip_memberships',
				[ 'price_paid' => $price, 'updated_at' => $now ],
				[ 'id' => $membership_id ]
			);
		} else {
			$membership_id = $this->db->insert(
				'vip_memberships',
				[
					'tenant_id'  => $tenant_id,
					'user_id'    => $user_id,
					'plan_id'    => $plan_id,
					'status'     => VipAccessService::STATUS_PENDING,
					'price_paid' => $price,
					'created_at' => $now,
					'updated_at' => $now,
				]
			);
		}

		if ( $membership_id <= 0 ) {
			return $fail( __( 'Could not start the subscription.', 'igbz-suite' ) );
		}

		// A free plan needs no gateway round trip.
		if ( $price <= 0 ) {
			$this->activate_membership( $membership_id, 0 );
			return [ 'ok' => true, 'membership_id' => $membership_id, 'payment_id' => 0, 'redirect_url' => '', 'error' => '' ];
		}

		if ( $use_wallet ) {
			$wallet = $this->wallet();
			if ( ! $wallet ) {
				return $fail( __( 'Wallet payments are unavailable.', 'igbz-suite' ) );
			}

			$paid = $wallet->debit(
				$user_id,
				$price,
				WalletService::REASON_SUBSCRIPTION,
				'vip_membership:' . $membership_id,
				[ 'plan_id' => $plan_id ],
				$tenant_id,
				0,
				__( 'VIP membership', 'igbz-suite' )
			);

			if ( ! $paid->success ) {
				return $fail( $paid->error_message ?: __( 'Your wallet balance is not enough.', 'igbz-suite' ) );
			}

			$this->activate_membership( $membership_id, 0 );
			return [ 'ok' => true, 'membership_id' => $membership_id, 'payment_id' => 0, 'redirect_url' => '', 'error' => '' ];
		}

		$payments = $this->payments();
		if ( ! $payments ) {
			return $fail( __( 'No payment gateway is available.', 'igbz-suite' ) );
		}

		$started = $payments->start(
			$price,
			self::PURPOSE_MEMBERSHIP,
			[
				'tenant_id'     => $tenant_id,
				'user_id'       => $user_id,
				'membership_id' => $membership_id,
				'plan_id'       => $plan_id,
				'dedupe_key'    => 'vip_membership:' . $membership_id,
			],
			$gateway_id
		);

		if ( ! $started['ok'] ) {
			return $fail( (string) $started['error'] );
		}

		$this->db->update(
			'vip_memberships',
			[
				'payment_id' => (int) $started['payment_id'],
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $membership_id ]
		);

		return [
			'ok'            => true,
			'membership_id' => $membership_id,
			'payment_id'    => (int) $started['payment_id'],
			'redirect_url'  => (string) $started['redirect_url'],
			'error'         => '',
		];
	}

	/**
	 * Turn a pending membership into an active one.
	 *
	 * A renewal bought before the current term ends extends from the existing end date, not from
	 * today — otherwise renewing early silently throws away the days already paid for.
	 */
	public function activate_membership( int $membership_id, int $payment_id = 0 ): bool {
		$membership = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'vip_memberships' ) . ' WHERE id = %d',
			$membership_id
		);
		if ( ! $membership || VipAccessService::STATUS_ACTIVE === $membership['status'] ) {
			return false;
		}

		$plan = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'vip_plans' ) . ' WHERE id = %d',
			(int) $membership['plan_id']
		);

		$days = $plan ? (int) $plan['duration_days'] : 30;
		$now  = current_time( 'mysql', true );

		$current_end = $this->db->scalar(
			'SELECT MAX(ends_at) FROM ' . $this->db->table( 'vip_memberships' ) . '
			 WHERE user_id = %d AND tenant_id = %d AND status = %s AND ends_at > %s',
			(int) $membership['user_id'],
			(int) $membership['tenant_id'],
			VipAccessService::STATUS_ACTIVE,
			$now
		);

		$base = $current_end ? (string) $current_end : $now;
		$ends = $days > 0 ? gmdate( 'Y-m-d H:i:s', strtotime( $base ) + ( $days * DAY_IN_SECONDS ) ) : null;

		// Phase 54: conditional flip. Two webhook deliveries racing on the same pending row
		// must produce one activation and one hook, not two — the loser reads 0 rows and
		// walks away without firing anything.
		$won = $this->db->query(
			'UPDATE ' . $this->db->table( 'vip_memberships' ) . '
			 SET status = %s, starts_at = %s, ends_at = %s,
			     payment_id = %d, updated_at = %s
			 WHERE id = %d AND status = %s',
			VipAccessService::STATUS_ACTIVE,
			$now,
			$ends,
			$payment_id > 0 ? $payment_id : (int) $membership['payment_id'],
			$now,
			$membership_id,
			VipAccessService::STATUS_PENDING
		) > 0;

		if ( ! $won ) {
			return false;
		}

		$this->logger->info( 'vip', 'VIP membership activated', [ 'membership_id' => $membership_id, 'ends_at' => $ends ] );
		do_action( 'igbz_vip_membership_activated', $membership_id, (int) $membership['user_id'] );

		return true;
	}

	public function cancel_membership( int $membership_id ): bool {
		$now = current_time( 'mysql', true );

		// Cancelling stops the renewal; it does not cut the term short. The member paid for those
		// days and taking them back on cancellation is the fastest way to earn a chargeback.
		$done = $this->db->update(
			'vip_memberships',
			[
				'auto_renew'   => 0,
				'cancelled_at' => $now,
				'updated_at'   => $now,
			],
			[ 'id' => $membership_id ]
		) > 0;

		if ( $done ) {
			do_action( 'igbz_vip_membership_cancelled', $membership_id );
		}

		return $done;
	}

	/** Mark lapsed memberships so the admin lists and stats stop counting them as active. */
	public function expire_memberships(): int {
		$now = current_time( 'mysql', true );

		$ids = $this->db->column(
			'SELECT id FROM ' . $this->db->table( 'vip_memberships' ) . '
			 WHERE status = %s AND ends_at IS NOT NULL AND ends_at <= %s
			 LIMIT 200',
			VipAccessService::STATUS_ACTIVE,
			$now
		);

		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			// Phase 54: conditional flip. A renewal that lands between the SELECT and this
			// write must not expire a row that just gained fresh days; the WHERE clause is
			// the whole guard, and the hook fires only for the writer that actually flipped.
			$won = $this->db->query(
				'UPDATE ' . $this->db->table( 'vip_memberships' ) . '
				 SET status = %s, updated_at = %s
				 WHERE id = %d AND status = %s AND ends_at IS NOT NULL AND ends_at <= %s',
				VipAccessService::STATUS_EXPIRED,
				$now,
				$id,
				VipAccessService::STATUS_ACTIVE,
				$now
			) > 0;

			if ( $won ) {
				do_action( 'igbz_vip_membership_expired', $id );
			}
		}

		return count( (array) $ids );
	}

	// ------------------------------------------------------- single purchase

	/**
	 * Buy one post.
	 *
	 * @return array{ok:bool,payment_id:int,redirect_url:string,granted:bool,error:string}
	 */
	public function purchase_post( int $user_id, int $post_id, string $gateway_id = '', bool $use_wallet = false ): array {
		$fail = static fn( string $msg ): array => [
			'ok'           => false,
			'payment_id'   => 0,
			'redirect_url' => '',
			'granted'      => false,
			'error'        => $msg,
		];

		if ( $user_id <= 0 ) {
			return $fail( __( 'Please sign in first.', 'igbz-suite' ) );
		}

		$post = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'vip_posts' ) . ' WHERE id = %d', $post_id );
		if ( ! $post || VipPostService::STATUS_PUBLISHED !== $post['status'] ) {
			return $fail( __( 'That post is not available.', 'igbz-suite' ) );
		}
		if ( VipAccessService::ACCESS_PURCHASE !== (string) $post['access'] ) {
			return $fail( __( 'This post is not sold individually.', 'igbz-suite' ) );
		}
		// Already paid for, one way or the other. The entitlement is the obvious case; the
		// membership is the one that matters, because the app and the share page both show a
		// "buy this post" button off a cached payload, and a member who taps it a moment after
		// subscribing must not be charged a second time for something they can already open.
		if ( $this->access->has_entitlement( $user_id, $post_id ) || $this->access->check_row( $user_id, $post )->allowed ) {
			return [ 'ok' => true, 'payment_id' => 0, 'redirect_url' => '', 'granted' => true, 'error' => '' ];
		}

		$price     = (float) $post['price'];
		$tenant_id = (int) $post['tenant_id'];

		if ( $price <= 0 ) {
			$this->grant_entitlement( $user_id, $post_id, self::SOURCE_GIFT, 0, 0.0 );
			return [ 'ok' => true, 'payment_id' => 0, 'redirect_url' => '', 'granted' => true, 'error' => '' ];
		}

		if ( $use_wallet ) {
			$wallet = $this->wallet();
			if ( ! $wallet ) {
				return $fail( __( 'Wallet payments are unavailable.', 'igbz-suite' ) );
			}

			$paid = $wallet->debit(
				$user_id,
				$price,
				WalletService::REASON_ORDER_PAY,
				'vip_post:' . $post_id . ':' . $user_id,
				[ 'post_id' => $post_id ],
				$tenant_id,
				0,
				__( 'VIP post purchase', 'igbz-suite' )
			);

			if ( ! $paid->success ) {
				return $fail( $paid->error_message ?: __( 'Your wallet balance is not enough.', 'igbz-suite' ) );
			}

			$this->grant_entitlement( $user_id, $post_id, self::SOURCE_PURCHASE, 0, $price );
			return [ 'ok' => true, 'payment_id' => 0, 'redirect_url' => '', 'granted' => true, 'error' => '' ];
		}

		$payments = $this->payments();
		if ( ! $payments ) {
			return $fail( __( 'No payment gateway is available.', 'igbz-suite' ) );
		}

		$started = $payments->start(
			$price,
			self::PURPOSE_POST,
			[
				'tenant_id'  => $tenant_id,
				'user_id'    => $user_id,
				'post_id'    => $post_id,
				'dedupe_key' => 'vip_post:' . $post_id . ':' . $user_id,
			],
			$gateway_id
		);

		if ( ! $started['ok'] ) {
			return $fail( (string) $started['error'] );
		}

		return [
			'ok'           => true,
			'payment_id'   => (int) $started['payment_id'],
			'redirect_url' => (string) $started['redirect_url'],
			'granted'      => false,
			'error'        => '',
		];
	}

	public function grant_entitlement( int $user_id, int $post_id, string $source, int $payment_id, float $price ): int {
		$now = current_time( 'mysql', true );

		$existing = $this->db->row(
			'SELECT id FROM ' . $this->db->table( 'vip_entitlements' ) . ' WHERE user_id = %d AND post_id = %d',
			$user_id,
			$post_id
		);

		// A re-purchase after a refund reuses the row: UNIQUE (user_id,post_id) makes a second
		// insert fail, and the member would pay and get nothing.
		if ( $existing ) {
			$this->db->update(
				'vip_entitlements',
				[
					'source'     => $source,
					'payment_id' => $payment_id,
					'price_paid' => $price,
					'revoked_at' => null,
					'updated_at' => $now,
				],
				[ 'id' => (int) $existing['id'] ]
			);
			do_action( 'igbz_vip_entitlement_granted', (int) $existing['id'], $user_id, $post_id );
			return (int) $existing['id'];
		}

		$tenant_id = (int) $this->db->scalar(
			'SELECT tenant_id FROM ' . $this->db->table( 'vip_posts' ) . ' WHERE id = %d',
			$post_id
		);

		$id = $this->db->insert(
			'vip_entitlements',
			[
				'tenant_id'  => $tenant_id,
				'user_id'    => $user_id,
				'post_id'    => $post_id,
				'source'     => $source,
				'payment_id' => $payment_id,
				'price_paid' => $price,
				'created_at' => $now,
				'updated_at' => $now,
			]
		);

		// Phase 54: two grants racing on the same (user, post) — the pre-check above saw
		// nothing, the UNIQUE key rejects the second insert. Falling through with 0 would
		// hand back "nothing granted" for money that was actually paid, so the row is
		// re-read and revived instead: the member keeps the entitlement either way.
		if ( $id <= 0 ) {
			$raced = $this->db->row(
				'SELECT id FROM ' . $this->db->table( 'vip_entitlements' ) . ' WHERE user_id = %d AND post_id = %d',
				$user_id,
				$post_id
			);
			if ( ! $raced ) {
				return 0;
			}

			$this->db->update(
				'vip_entitlements',
				[
					'source'     => $source,
					'payment_id' => $payment_id,
					'price_paid' => $price,
					'revoked_at' => null,
					'updated_at' => $now,
				],
				[ 'id' => (int) $raced['id'] ]
			);

			do_action( 'igbz_vip_entitlement_granted', (int) $raced['id'], $user_id, $post_id );
			return (int) $raced['id'];
		}

		do_action( 'igbz_vip_entitlement_granted', $id, $user_id, $post_id );

		return $id;
	}

	public function revoke_entitlement( int $user_id, int $post_id ): bool {
		return $this->db->update(
			'vip_entitlements',
			[
				'revoked_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			],
			[
				'user_id' => $user_id,
				'post_id' => $post_id,
			]
		) > 0;
	}

	// ------------------------------------------------------------------ tips

	/**
	 * Send financial support for a public post.
	 *
	 * Tips are the one VIP payment that does not need a membership or an account gate: they are
	 * offered from the public share page, which is exactly where a passer-by decides to support
	 * the shop.
	 *
	 * @return array{ok:bool,payment_id:int,redirect_url:string,error:string}
	 */
	public function tip( int $user_id, float $amount, int $post_id = 0, string $message = '', string $gateway_id = '' ): array {
		$fail = static fn( string $msg ): array => [
			'ok'           => false,
			'payment_id'   => 0,
			'redirect_url' => '',
			'error'        => $msg,
		];

		if ( ! $this->settings->bool( 'vip.tips_enabled', true ) ) {
			return $fail( __( 'Tips are turned off.', 'igbz-suite' ) );
		}

		$min = (float) $this->settings->int( 'vip.tip_min', 10000 );
		if ( $amount < $min ) {
			return $fail(
				sprintf(
					/* translators: %s: minimum amount. */
					__( 'The smallest tip is %s.', 'igbz-suite' ),
					number_format_i18n( $min )
				)
			);
		}

		$payments = $this->payments();
		if ( ! $payments ) {
			return $fail( __( 'No payment gateway is available.', 'igbz-suite' ) );
		}

		$tenant_id = $post_id > 0
			? (int) $this->db->scalar( 'SELECT tenant_id FROM ' . $this->db->table( 'vip_posts' ) . ' WHERE id = %d', $post_id )
			: 0;

		$started = $payments->start(
			$amount,
			self::PURPOSE_TIP,
			[
				'tenant_id' => $tenant_id,
				'user_id'   => $user_id,
				'post_id'   => $post_id,
				'message'   => mb_substr( wp_strip_all_tags( $message ), 0, 255 ),
			],
			$gateway_id
		);

		if ( ! $started['ok'] ) {
			return $fail( (string) $started['error'] );
		}

		return [
			'ok'           => true,
			'payment_id'   => (int) $started['payment_id'],
			'redirect_url' => (string) $started['redirect_url'],
			'error'        => '',
		];
	}

	/** @return array<string,int|float> */
	public function tip_presets(): array {
		$raw = $this->settings->string( 'vip.tip_presets', '50000,100000,200000,500000' );
		$out = [];
		foreach ( explode( ',', $raw ) as $value ) {
			$value = (float) trim( $value );
			if ( $value > 0 ) {
				$out[] = $value;
			}
		}
		return $out;
	}

	// ------------------------------------------------------------ settlement

	/**
	 * Grant whatever the shopper just paid for.
	 *
	 * Hooked on `igbz_payment_verified`, which fires only after PaymentService has confirmed the
	 * PSP verification and written status = paid.
	 */
	public function on_payment_verified( int $payment_id, $result = null ): void {
		$payment = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'payments' ) . ' WHERE id = %d',
			$payment_id
		);
		if ( ! $payment ) {
			return;
		}

		$purpose = (string) $payment['purpose'];
		if ( ! in_array( $purpose, [ self::PURPOSE_MEMBERSHIP, self::PURPOSE_POST, self::PURPOSE_TIP ], true ) ) {
			return;
		}

		$meta    = json_decode( (string) ( $payment['meta'] ?? '{}' ), true );
		$meta    = is_array( $meta ) ? $meta : [];
		$user_id = (int) $payment['user_id'];

		if ( self::PURPOSE_MEMBERSHIP === $purpose ) {
			$membership_id = (int) ( $meta['membership_id'] ?? 0 );
			if ( $membership_id > 0 ) {
				$this->activate_membership( $membership_id, $payment_id );
			}
			return;
		}

		if ( self::PURPOSE_POST === $purpose ) {
			$post_id = (int) ( $meta['post_id'] ?? 0 );
			if ( $post_id > 0 && $user_id > 0 ) {
				$this->grant_entitlement( $user_id, $post_id, self::SOURCE_PURCHASE, $payment_id, (float) $payment['amount'] );
			}
			return;
		}

		// A tip has nothing to unlock; it is recorded so the admin dashboard can show it and so a
		// thank-you can be triggered by anything listening.
		$this->logger->info(
			'vip',
			'VIP tip received',
			[
				'payment_id' => $payment_id,
				'user_id'    => $user_id,
				'post_id'    => (int) ( $meta['post_id'] ?? 0 ),
				'amount'     => (float) $payment['amount'],
			]
		);
		do_action( 'igbz_vip_tip_received', $payment_id, $user_id, (int) ( $meta['post_id'] ?? 0 ), (float) $payment['amount'] );
	}

	// -------------------------------------------------------------- revenue

	/**
	 * Revenue and membership figures for the admin dashboard.
	 *
	 * @return array<string,mixed>
	 */
	public function stats( int $tenant_id = 0, int $days = 30 ): array {
		$payments = $this->db->table( 'payments' );
		$since    = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );
		$paid     = PaymentService::STATUS_PAID;

		$sum = function ( string $purpose ) use ( $payments, $since, $paid, $tenant_id ): array {
			$row = $this->db->row(
				"SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS total
				 FROM {$payments}
				 WHERE purpose = %s AND status = %s AND verified_at >= %s AND (tenant_id = %d OR %d = 0)",
				$purpose,
				$paid,
				$since,
				$tenant_id,
				$tenant_id
			);
			return [
				'count' => (int) ( $row['n'] ?? 0 ),
				'total' => (float) ( $row['total'] ?? 0 ),
			];
		};

		$memberships = $this->db->table( 'vip_memberships' );
		$now         = current_time( 'mysql', true );

		return [
			'days'               => $days,
			'memberships'        => $sum( self::PURPOSE_MEMBERSHIP ),
			'post_purchases'     => $sum( self::PURPOSE_POST ),
			'tips'               => $sum( self::PURPOSE_TIP ),
			'active_members'     => (int) $this->db->scalar(
				"SELECT COUNT(DISTINCT user_id) FROM {$memberships}
				 WHERE status = %s AND (ends_at IS NULL OR ends_at > %s) AND (tenant_id = %d OR %d = 0)",
				VipAccessService::STATUS_ACTIVE,
				$now,
				$tenant_id,
				$tenant_id
			),
			'cancelling_members' => (int) $this->db->scalar(
				"SELECT COUNT(*) FROM {$memberships}
				 WHERE status = %s AND cancelled_at IS NOT NULL AND (ends_at IS NULL OR ends_at > %s) AND (tenant_id = %d OR %d = 0)",
				VipAccessService::STATUS_ACTIVE,
				$now,
				$tenant_id,
				$tenant_id
			),
		];
	}

	// ------------------------------------------------------------- container

	/**
	 * Payments and wallet live in the MultiTenant module, which can be switched off. Resolving them
	 * lazily keeps VIP loadable in that state instead of fataling at boot.
	 */
	private function payments(): ?PaymentService {
		$service = igbz()->has( 'payments' ) ? igbz()->get( 'payments' ) : null;
		return $service instanceof PaymentService ? $service : null;
	}

	private function wallet(): ?WalletService {
		$service = igbz()->has( 'wallet' ) ? igbz()->get( 'wallet' ) : null;
		return $service instanceof WalletService ? $service : null;
	}
}
