<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

use IGBZ\Suite\Modules\MultiTenant\Affiliate\AffiliateService;
use IGBZ\Suite\Modules\MultiTenant\Bnpl\BnplService;
use IGBZ\Suite\Modules\MultiTenant\Lms\LmsService;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentService;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Modules\RestApi\Pagination\CursorCodec;

defined( 'ABSPATH' ) || exit;

/**
 * The customer's own data: profile, orders, wallet, instalments, courses and affiliate stats.
 * Everything here is scoped to the authenticated user; nothing accepts a user id from the client.
 *
 *   GET/POST /igbz/v1/account/profile
 *   GET      /igbz/v1/account/orders            ?page=&per_page=
 *   GET      /igbz/v1/account/orders/{id}
 *   GET      /igbz/v1/account/wallet            ?page=&per_page=
 *   POST     /igbz/v1/account/wallet/topup      { amount, gateway? }
 *   GET      /igbz/v1/account/instalments
 *   POST     /igbz/v1/account/instalments/{id}/pay
 *   GET      /igbz/v1/account/courses
 *   POST     /igbz/v1/account/courses/progress  { enrollment_id, lesson_id, seconds, completed }
 *   GET      /igbz/v1/account/courses/{id}/quizzes
 *   POST     /igbz/v1/account/quizzes/{id}/submit  { answers }
 *   GET      /igbz/v1/account/certificates
 *   GET      /igbz/v1/account/affiliate
 *   GET      /igbz/v1/account/payments
 */
final class AccountController extends BaseController {

	public function register_routes(): void {
		$ns   = self::NAMESPACE;
		$auth = [ $this, 'is_logged_in' ];

		register_rest_route( $ns, '/account/profile', $this->route( 'GET', [ $this, 'get_profile' ], $auth ) );
		register_rest_route( $ns, '/account/profile', $this->route( 'POST', [ $this, 'update_profile' ], $auth, [
			'expected_revision' => [
				'type'        => 'integer',
				'required'    => false,
				'description' => __( 'The profile revision the client last saw; a mismatch answers 409 so an offline edit cannot silently overwrite a newer one.', 'igbz-suite' ),
			],
		] ) );
		register_rest_route( $ns, '/account/orders', $this->route( 'GET', [ $this, 'orders' ], $auth, $this->cursor_args() ) );
		register_rest_route( $ns, '/account/orders/(?P<id>\d+)', $this->route( 'GET', [ $this, 'order' ], $auth ) );
		register_rest_route( $ns, '/account/wallet', $this->route( 'GET', [ $this, 'wallet' ], $auth, $this->cursor_args() ) );
		register_rest_route( $ns, '/account/wallet/topup', $this->route( 'POST', [ $this, 'wallet_topup' ], $auth ) );
		register_rest_route( $ns, '/account/instalments', $this->route( 'GET', [ $this, 'instalments' ], $auth ) );
		register_rest_route( $ns, '/account/instalments/(?P<id>\d+)/pay', $this->route( 'POST', [ $this, 'pay_instalment' ], $auth ) );
		register_rest_route( $ns, '/account/courses', $this->route( 'GET', [ $this, 'courses' ], $auth ) );
		register_rest_route( $ns, '/account/courses/progress', $this->route( 'POST', [ $this, 'course_progress' ], $auth ) );
		register_rest_route( $ns, '/account/courses/(?P<id>\d+)/quizzes', $this->route( 'GET', [ $this, 'course_quizzes' ], $auth ) );
		register_rest_route( $ns, '/account/quizzes/(?P<id>\d+)/submit', $this->route( 'POST', [ $this, 'submit_quiz' ], $auth ) );
		register_rest_route( $ns, '/account/certificates', $this->route( 'GET', [ $this, 'certificates' ], $auth ) );
		register_rest_route( $ns, '/account/affiliate', $this->route( 'GET', [ $this, 'affiliate' ], $auth ) );
		register_rest_route( $ns, '/account/payments', $this->route( 'GET', [ $this, 'payments' ], $auth ) );
	}

	// ------------------------------------------------------------- profile

	public function get_profile(): \WP_REST_Response {
		$user_id  = get_current_user_id();
		$customer = function_exists( 'wc_get_customer_object' ) || class_exists( \WC_Customer::class ) ? new \WC_Customer( $user_id ) : null;

		return $this->ok(
			[
				'id'           => $user_id,
				'display_name' => wp_get_current_user()->display_name,
				'email'        => wp_get_current_user()->user_email,
				'phone'        => (string) get_user_meta( $user_id, 'igbz_phone', true ),
				'avatar'       => get_avatar_url( $user_id ),
				'billing'      => $customer ? $customer->get_billing() : [],
				'shipping'     => $customer ? $customer->get_shipping() : [],
				'wallet'       => [
					'balance'  => $this->wallet_service()->balance( $user_id, $this->scoped_tenant_id() ),
					'currency' => igbz()->settings()->string( 'general.default_currency', 'IRT' ),
				],
				// Phase 67: the offline-edit guard. The app sends this back as
				// expected_revision; a mismatch means someone edited elsewhere first.
				'revision'     => $this->profile_revision( $user_id ),
			]
		);
	}

	private function profile_revision( int $user_id ): int {
		return (int) get_user_meta( $user_id, 'igbz_profile_revision', true );
	}

	public function update_profile( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();

		// Phase 67: offline conflict. An app that edited offline sends the revision it
		// based its edit on; if the profile moved on since, the write is refused with the
		// current revision so the app can re-fetch and merge instead of clobbering.
		// Without the field the v1 behaviour (last write wins) is untouched.
		$expected = $request->get_param( 'expected_revision' );
		if ( null !== $expected && '' !== $expected && (int) $expected !== $this->profile_revision( $user_id ) ) {
			$response = new \WP_REST_Response(
				[
					'ok'               => false,
					'code'             => 'revision_conflict',
					'error'            => __( 'The profile changed on another device; re-fetch and merge before saving.', 'igbz-suite' ),
					'current_revision' => $this->profile_revision( $user_id ),
				],
				409
			);

			return $response;
		}

		$display_name = sanitize_text_field( (string) $request->get_param( 'display_name' ) );
		if ( '' !== $display_name ) {
			wp_update_user( [ 'ID' => $user_id, 'display_name' => $display_name ] );
		}

		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( '' !== $email && is_email( $email ) ) {
			$owner = email_exists( $email );
			if ( $owner && (int) $owner !== $user_id ) {
				return $this->fail( 'email_taken', __( 'That email address belongs to another account.', 'igbz-suite' ), 409 );
			}
			wp_update_user( [ 'ID' => $user_id, 'user_email' => $email ] );
		}

		if ( class_exists( \WC_Customer::class ) ) {
			$customer = new \WC_Customer( $user_id );

			foreach ( [ 'billing', 'shipping' ] as $group ) {
				$fields = $request->get_param( $group );
				if ( ! is_array( $fields ) ) {
					continue;
				}
				foreach ( $fields as $key => $value ) {
					$setter = 'set_' . $group . '_' . sanitize_key( (string) $key );
					if ( method_exists( $customer, $setter ) ) {
						$customer->{$setter}( sanitize_text_field( (string) $value ) );
					}
				}
			}

			$customer->save();
		}

		update_user_meta( $user_id, 'igbz_profile_revision', $this->profile_revision( $user_id ) + 1 );

		return $this->get_profile();
	}

	// -------------------------------------------------------------- orders

	public function orders( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $this->fail( 'woocommerce_missing', __( 'WooCommerce is not active.', 'igbz-suite' ), 503 );
		}

		$position = $this->cursor_position( $request, CursorCodec::KIND_ORDERS );
		if ( $position instanceof \WP_REST_Response ) {
			return $position;
		}

		if ( null !== $position ) {
			return $this->orders_by_cursor( $request, $position );
		}

		[ $page, $per_page, ] = $this->page_args( $request, 15 );

		$result = wc_get_orders(
			[
				'customer_id' => get_current_user_id(),
				'limit'       => $per_page,
				'page'        => $page,
				'paginate'    => true,
				'orderby'     => 'date',
				'order'       => 'DESC',
			]
		);

		$orders = is_object( $result ) ? ( $result->orders ?? [] ) : (array) $result;
		$total  = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $orders );

		$items = [];
		foreach ( $orders as $order ) {
			$items[] = $this->order_summary( $order );
		}

		return $this->paged( $items, $total, $page, $per_page );
	}

	/**
	 * Phase 67 — keyset pagination for the orders feed: rows strictly before the cursor's
	 * (date_created, id) tuple, DESC (an empty position is the first page: no filter). The
	 * tie at the cursor's own second is handled exactly: WooCommerce's range query fetches
	 * the same-second rows, the `<` query the strictly-older ones, and the merge keeps
	 * (date, id) tuples below the cursor — so an order placed in the same second as the
	 * bookmarked one is neither duplicated nor skipped.
	 *
	 * @param array<string,int|string> $position
	 */
	private function orders_by_cursor( \WP_REST_Request $request, array $position ): \WP_REST_Response {
		$limit     = $this->cursor_limit( $request, 15 );
		$before_ts = (int) ( $position['t'] ?? 0 );
		$before_id = (int) ( $position['i'] ?? 0 );
		$base      = [
			'customer_id' => get_current_user_id(),
			'limit'       => $limit + 1,
			'orderby'     => 'date',
			'order'       => 'DESC',
		];

		if ( $position ) {
			$fetched = array_merge(
				(array) wc_get_orders( $base + [ 'date_created' => '<' . $before_ts ] ),
				(array) wc_get_orders( $base + [ 'date_created' => $before_ts . '...' . $before_ts ] )
			);
		} else {
			$fetched = (array) wc_get_orders( $base );
		}

		$batch = [];
		foreach ( $fetched as $order ) {
			$ts = (int) ( $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0 );
			$id = (int) $order->get_id();
			if ( $position && ( $ts > $before_ts || ( $ts === $before_ts && $id >= $before_id ) ) ) {
				continue;
			}
			$batch[ $id ] = [ 'item' => $this->order_summary( $order ), 'cursor' => [ 't' => $ts, 'i' => $id ] ];
		}

		usort( $batch, static fn ( array $a, array $b ) => $b['cursor']['t'] <=> $a['cursor']['t'] ?: $b['cursor']['i'] <=> $a['cursor']['i'] );

		return $this->cursor_page( array_values( $batch ), $limit, CursorCodec::KIND_ORDERS );
	}

	public function order( \WP_REST_Request $request ): \WP_REST_Response {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $request->get_param( 'id' ) ) : null;
		if ( ! $order || (int) $order->get_customer_id() !== get_current_user_id() ) {
			return $this->fail( 'not_found', __( 'Order not found.', 'igbz-suite' ), 404 );
		}

		$payload          = $this->order_summary( $order );
		$payload['items'] = [];

		foreach ( $order->get_items() as $item ) {
			$product  = $item->get_product();
			$image_id = $product ? (int) $product->get_image_id() : 0;

			$payload['items'][] = [
				'product_id' => $item->get_product_id(),
				'name'       => $item->get_name(),
				'quantity'   => $item->get_quantity(),
				'total'      => (float) $item->get_total(),
				'image_url'  => $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_gallery_thumbnail' ) : '',
			];
		}

		$payload['billing']       = $order->get_address( 'billing' );
		$payload['shipping']      = $order->get_address( 'shipping' );
		$payload['shipping_total'] = (float) $order->get_shipping_total();
		$payload['discount_total'] = (float) $order->get_discount_total();
		$payload['downloads']      = $this->downloads( $order );

		return $this->ok( $payload );
	}

	/** @return array<int,array<string,mixed>> */
	private function downloads( \WC_Order $order ): array {
		$out = [];
		foreach ( (array) $order->get_downloadable_items() as $download ) {
			$out[] = [
				'name'          => (string) ( $download['download_name'] ?? '' ),
				'url'           => (string) ( $download['download_url'] ?? '' ),
				'downloads_remaining' => $download['downloads_remaining'] ?? '',
				'access_expires' => $download['access_expires'] ?? null,
			];
		}
		return $out;
	}

	/** @return array<string,mixed> */
	private function order_summary( \WC_Order $order ): array {
		return [
			'id'           => $order->get_id(),
			'number'       => $order->get_order_number(),
			'status'       => $order->get_status(),
			'status_label' => wc_get_order_status_name( $order->get_status() ),
			'total'        => (float) $order->get_total(),
			'currency'     => $order->get_currency(),
			'created_at'   => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
			'paid'         => $order->is_paid(),
			'item_count'   => $order->get_item_count(),
			'payment_method' => $order->get_payment_method_title(),
		];
	}

	// -------------------------------------------------------------- wallet

	private function wallet_service(): WalletService {
		return igbz()->get( 'wallet' );
	}

	public function wallet( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id   = get_current_user_id();
		$tenant_id = $this->scoped_tenant_id( $request );
		$wallet    = $this->wallet_service();

		$position = $this->cursor_position( $request, CursorCodec::KIND_WALLET );
		if ( $position instanceof \WP_REST_Response ) {
			return $position;
		}

		if ( null !== $position ) {
			// Keyset on the append-only ledger: rows with id strictly below the cursor's.
			$limit = $this->cursor_limit( $request, 25 );
			$rows  = $wallet->history( $user_id, [ 'tenant_id' => $tenant_id, 'limit' => $limit + 1, 'before_id' => (int) ( $position['i'] ?? 0 ) ] );
			$batch = [];
			foreach ( $rows as $row ) {
				$row_id = (int) $row['id'];
				$batch[] = [ 'item' => $this->wallet_entry( $row ), 'cursor' => [ 'i' => $row_id ] ];
			}

			return $this->cursor_page(
				$batch,
				$limit,
				CursorCodec::KIND_WALLET,
				[
					'balance'  => $wallet->balance( $user_id, $tenant_id ),
					'currency' => igbz()->settings()->string( 'general.default_currency', 'IRT' ),
				]
			);
		}

		[ $page, $per_page, $offset ] = $this->page_args( $request, 25 );

		$rows    = $wallet->history( $user_id, [ 'tenant_id' => $tenant_id, 'limit' => $per_page, 'offset' => $offset ] );
		$entries = [];
		foreach ( $rows as $row ) {
			$entries[] = $this->wallet_entry( $row );
		}

		return $this->ok(
			[
				'balance'  => $wallet->balance( $user_id, $tenant_id ),
				'currency' => igbz()->settings()->string( 'general.default_currency', 'IRT' ),
				'entries'  => $entries,
				'page'     => $page,
				'per_page' => $per_page,
			]
		);
	}

	/** @param array<string,mixed> $row */
	private function wallet_entry( array $row ): array {
		return [
			'id'           => (int) $row['id'],
			'amount'       => (float) $row['amount'],
			'direction'    => (string) $row['direction'],
			'reason'       => (string) $row['reason'],
			'note'         => (string) $row['note'],
			'balance_after' => (float) $row['balance_after'],
			'created_at'   => (string) $row['created_at'],
		];
	}

	public function wallet_topup( \WP_REST_Request $request ): \WP_REST_Response {
		// Phase 67: retried writes replay their first outcome instead of repeating it.
		return $this->with_idempotency( $request, fn (): \WP_REST_Response => $this->do_wallet_topup( $request ) );
	}

	private function do_wallet_topup( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! igbz()->settings()->bool( 'wallet.topup_enabled', true ) ) {
			return $this->fail( 'topup_disabled', __( 'Wallet top-up is disabled.', 'igbz-suite' ), 403 );
		}

		$amount = (float) $request->get_param( 'amount' );
		$min    = igbz()->settings()->float( 'wallet.min_topup', 0 );

		if ( $amount <= 0 || ( $min > 0 && $amount < $min ) ) {
			return $this->fail(
				'invalid_amount',
				sprintf(
					/* translators: %s: minimum amount */
					__( 'The minimum top-up is %s.', 'igbz-suite' ),
					(string) $min
				)
			);
		}

		/** @var PaymentService $payments */
		$payments = igbz()->get( 'payments' );

		$result = $payments->start(
			$amount,
			PaymentService::PURPOSE_WALLET_TOPUP,
			[
				'user_id'   => get_current_user_id(),
				'tenant_id' => $this->scoped_tenant_id( $request ),
				'source'    => 'mobile_app',
			],
			sanitize_key( (string) $request->get_param( 'gateway' ) )
		);

		if ( ! $result['ok'] ) {
			return $this->fail( 'payment_failed', $result['error'] );
		}

		return $this->ok(
			[
				'ok'           => true,
				'payment_id'   => $result['payment_id'],
				'redirect_url' => $result['redirect_url'],
			]
		);
	}

	// ---------------------------------------------------------- instalments

	public function instalments( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var BnplService $bnpl */
		$bnpl      = igbz()->get( 'bnpl' );
		$user_id   = get_current_user_id();
		$tenant_id = $this->scoped_tenant_id( $request );

		$contracts = [];
		foreach ( $bnpl->contracts_for_user( $user_id, $tenant_id ) as $contract ) {
			$contract_id = (int) $contract['id'];

			$schedule = [];
			foreach ( $bnpl->installments( $contract_id ) as $installment ) {
				$schedule[] = [
					'id'       => (int) $installment['id'],
					'sequence' => (int) $installment['sequence'],
					'amount'   => (float) $installment['amount'],
					'penalty'  => (float) $installment['penalty'],
					'due_date' => (string) $installment['due_date'],
					'status'   => (string) $installment['status'],
					'paid_at'  => $installment['paid_at'],
				];
			}

			$contracts[] = [
				'id'            => $contract_id,
				'order_id'      => (int) $contract['order_id'],
				'principal'     => (float) $contract['principal'],
				'fee_amount'    => (float) $contract['fee_amount'],
				'total_payable' => (float) $contract['total_payable'],
				'status'        => (string) $contract['status'],
				'outstanding'   => $bnpl->outstanding( $contract_id ),
				'installments'  => $schedule,
			];
		}

		$profile = $bnpl->credit_profile( $user_id, $tenant_id );

		return $this->ok(
			[
				'credit' => [
					'limit'     => (float) ( $profile['credit_limit'] ?? 0 ),
					'used'      => (float) ( $profile['used_credit'] ?? 0 ),
					'score'     => (int) ( $profile['score'] ?? 0 ),
					'available' => max( 0, (float) ( $profile['credit_limit'] ?? 0 ) - (float) ( $profile['used_credit'] ?? 0 ) ),
				],
				'contracts' => $contracts,
			]
		);
	}

	public function pay_instalment( \WP_REST_Request $request ): \WP_REST_Response {
		// Phase 67: retried writes replay their first outcome instead of repeating it.
		return $this->with_idempotency( $request, fn (): \WP_REST_Response => $this->do_pay_instalment( $request ) );
	}

	private function do_pay_instalment( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var BnplService $bnpl */
		$bnpl = igbz()->get( 'bnpl' );
		$db   = igbz()->db();

		$installment_id = (int) $request->get_param( 'id' );

		$row = $db->row(
			'SELECT i.id FROM ' . $db->table( 'bnpl_installments' ) . ' i
			 INNER JOIN ' . $db->table( 'bnpl_contracts' ) . ' c ON c.id = i.contract_id
			 WHERE i.id = %d AND c.user_id = %d',
			$installment_id,
			get_current_user_id()
		);

		if ( ! $row ) {
			return $this->fail( 'not_found', __( 'Instalment not found.', 'igbz-suite' ), 404 );
		}

		if ( ! $bnpl->pay_installment_from_wallet( $installment_id ) ) {
			return $this->fail( 'payment_failed', __( 'The wallet balance is not enough to cover this instalment.', 'igbz-suite' ) );
		}

		return $this->ok( [ 'ok' => true ] );
	}

	// ------------------------------------------------------------- courses

	public function courses( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var LmsService $lms */
		$lms     = igbz()->get( 'lms' );
		$user_id = get_current_user_id();

		$items = [];
		foreach ( $lms->enrollments_for_user( $user_id, $this->scoped_tenant_id( $request ) ) as $enrollment ) {
			$course_id = (int) $enrollment['course_id'];

			// Counts only — the questions come from /courses/{id}/quizzes, so the course list
			// stays one query per course rather than one per quiz.
			$quiz_summary = [];
			foreach ( $lms->quizzes( $course_id ) as $quiz ) {
				$best           = $lms->best_attempt( (int) $quiz['id'], $user_id );
				$quiz_summary[] = [
					'id'        => (int) $quiz['id'],
					'lesson_id' => (int) $quiz['lesson_id'],
					'title'     => (string) $quiz['title'],
					'passed'    => (bool) ( $best['passed'] ?? false ),
				];
			}

			$lessons = [];
			foreach ( $lms->lessons( $course_id ) as $lesson ) {
				$lessons[] = [
					'id'           => (int) $lesson['id'],
					'title'        => (string) $lesson['title'],
					'duration'     => (int) $lesson['duration_minutes'],
					'is_preview'   => (bool) $lesson['is_free_preview'],
					'video_url'    => '' !== (string) $lesson['video_key']
						? $lms->signed_video_url( (string) $lesson['video_key'], $user_id )
						: '',
					'attachment_url' => (string) $lesson['attachment_url'],
				];
			}

			$items[] = [
				'enrollment_id' => (int) $enrollment['id'],
				'course_id'     => $course_id,
				'title'         => (string) $enrollment['title'],
				'slug'          => (string) $enrollment['slug'],
				'cover_url'     => (string) $enrollment['cover_url'],
				'progress'      => (int) $enrollment['progress_percent'],
				'expires_at'    => $enrollment['expires_at'],
				'lessons'       => $lessons,
				'quizzes'       => $quiz_summary,
				'certificate_code' => (string) $enrollment['certificate_code'],
			];
		}

		return $this->ok( [ 'courses' => $items ] );
	}

	public function course_progress( \WP_REST_Request $request ): \WP_REST_Response {
		// Phase 67: retried writes replay their first outcome instead of repeating it.
		return $this->with_idempotency( $request, fn (): \WP_REST_Response => $this->do_course_progress( $request ) );
	}

	private function do_course_progress( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var LmsService $lms */
		$lms = igbz()->get( 'lms' );
		$db  = igbz()->db();

		$enrollment_id = (int) $request->get_param( 'enrollment_id' );

		$owned = $db->scalar(
			'SELECT id FROM ' . $db->table( 'enrollments' ) . ' WHERE id = %d AND user_id = %d',
			$enrollment_id,
			get_current_user_id()
		);
		if ( ! $owned ) {
			return $this->fail( 'not_found', __( 'Enrolment not found.', 'igbz-suite' ), 404 );
		}

		$lms->record_progress(
			$enrollment_id,
			(int) $request->get_param( 'lesson_id' ),
			(int) $request->get_param( 'seconds' ),
			(bool) $request->get_param( 'completed' )
		);

		$progress   = $lms->refresh_progress( $enrollment_id );
		$enrollment = $db->row( 'SELECT certificate_code FROM ' . $db->table( 'enrollments' ) . ' WHERE id = %d', $enrollment_id );

		return $this->ok(
			[
				'ok'               => true,
				'progress'         => $progress,
				// The app shows the certificate the moment it is earned, so it has to learn about
				// it from the same call that finished the last lesson.
				'certificate_code' => (string) ( $enrollment['certificate_code'] ?? '' ),
			]
		);
	}

	// ------------------------------------------------------------- quizzes

	/**
	 * Every quiz on a course, without its answer key.
	 *
	 * Enrollment is checked here rather than trusted from the client, and the questions come from
	 * LmsService::questions_for_client(), which is the only sanctioned way to get a quiz out of
	 * the database and into a response — the raw `questions` column contains the answers.
	 */
	public function course_quizzes( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var LmsService $lms */
		$lms       = igbz()->get( 'lms' );
		$user_id   = get_current_user_id();
		$course_id = (int) $request->get_param( 'id' );

		if ( ! $lms->is_enrolled( $course_id, $user_id ) ) {
			return $this->fail( 'not_enrolled', __( 'You are not enrolled on this course.', 'igbz-suite' ), 403 );
		}

		$items = [];
		foreach ( $lms->quizzes( $course_id ) as $quiz ) {
			$items[] = $lms->quiz_for_user( $quiz, $user_id );
		}

		return $this->ok( [ 'quizzes' => $items ] );
	}

	/**
	 * Grade an answer sheet.
	 *
	 * `answers` is a map of question id to the chosen option index, or to a list of them for a
	 * multiple-answer question. Grading, the attempt ceiling and the enrollment check all live in
	 * LmsService so this route and the storefront shortcode cannot drift apart.
	 */
	public function submit_quiz( \WP_REST_Request $request ): \WP_REST_Response {
		// Phase 67: retried writes replay their first outcome instead of repeating it.
		return $this->with_idempotency( $request, fn (): \WP_REST_Response => $this->do_submit_quiz( $request ) );
	}

	private function do_submit_quiz( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var LmsService $lms */
		$lms     = igbz()->get( 'lms' );
		$quiz_id = (int) $request->get_param( 'id' );
		$answers = $request->get_param( 'answers' );

		if ( ! is_array( $answers ) ) {
			return $this->fail( 'invalid_answers', __( 'Answers must be sent as an object of question id to answer.', 'igbz-suite' ) );
		}

		try {
			$result = $lms->submit_quiz( $quiz_id, get_current_user_id(), $answers );
		} catch ( \RuntimeException $e ) {
			// One exception type, three meanings; the app needs to tell "try again tomorrow" from
			// "buy the course" apart, so the message is mapped back onto a status.
			return $this->fail( 'quiz_rejected', $e->getMessage(), 403 );
		}

		return $this->ok( $result );
	}

	/**
	 * The customer's certificates, with the public address each one can be checked at.
	 */
	public function certificates( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var LmsService $lms */
		$lms  = igbz()->get( 'lms' );
		$slug = trim( igbz()->settings()->string( 'lms.certificate_slug', 'certificate' ), '/' );
		$slug = '' !== $slug ? $slug : 'certificate';

		$items = [];
		foreach ( $lms->enrollments_for_user( get_current_user_id(), $this->scoped_tenant_id( $request ) ) as $enrollment ) {
			$code = (string) $enrollment['certificate_code'];
			if ( '' === $code ) {
				continue;
			}

			$items[] = [
				'code'         => $code,
				'course_id'    => (int) $enrollment['course_id'],
				'course'       => (string) $enrollment['title'],
				'completed_at' => (string) ( $enrollment['completed_at'] ?? '' ),
				'verify_url'   => home_url( '/' . $slug . '/' . rawurlencode( $code ) ),
			];
		}

		return $this->ok( [ 'certificates' => $items ] );
	}

	// ----------------------------------------------------------- affiliate

	public function affiliate( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var AffiliateService $affiliates */
		$affiliates = igbz()->get( 'affiliate' );
		$user_id    = get_current_user_id();

		$affiliate = $affiliates->find_by_user( $user_id, $this->scoped_tenant_id( $request ) );
		if ( ! $affiliate ) {
			return $this->ok( [ 'enrolled' => false ] );
		}

		$affiliate_id = (int) $affiliate['id'];

		return $this->ok(
			[
				'enrolled'     => true,
				'code'         => (string) $affiliate['code'],
				'referral_url' => $affiliates->referral_url( (string) $affiliate['code'] ),
				'stats'        => $affiliates->stats( $affiliate_id ),
				'commissions'  => $affiliates->commissions( $affiliate_id, 20 ),
			]
		);
	}

	// ------------------------------------------------------------ payments

	public function payments(): \WP_REST_Response {
		/** @var PaymentService $payments */
		$payments = igbz()->get( 'payments' );

		$items = [];
		foreach ( $payments->payments_for_user( get_current_user_id(), 50 ) as $payment ) {
			$items[] = [
				'id'           => (int) $payment['id'],
				'gateway'      => (string) $payment['gateway'],
				'purpose'      => (string) $payment['purpose'],
				'amount'       => (float) $payment['amount'],
				'status'       => (string) $payment['status'],
				'reference_id' => (string) $payment['reference_id'],
				'created_at'   => (string) $payment['created_at'],
			];
		}

		return $this->ok( [ 'payments' => $items ] );
	}
}
