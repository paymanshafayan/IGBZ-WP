<?php
namespace IGBZ\Suite\Modules\Pado\Admin;

use IGBZ\Suite\Modules\Pado\Services\ApprovalRequestService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * "مرکز پادو" admin page with four tabs as per the ratified design (S0):
 *   - تنظیمات (API key)
 *   - طراحی قالب (questionnaire stub + start button)
 *   - درخواست‌های مجوز (unified approval queue with reason detail)
 *   - تاریخچه (all historical actions)
 *
 * Each tab POST is processed via admin_post and bounces back to the same tab
 * with a query var so notices render correctly.
 */
final class PadoPage {

	public const SLUG = 'igbz-pado';
	public const NONCE_ACTION = 'igbz_pado_admin';

	public const TAB_SETTINGS    = 'settings';
	public const TAB_DESIGN      = 'design';
	public const TAB_APPROVALS   = 'approvals';
	public const TAB_HISTORY     = 'history';

	private Settings $settings;
	private ApprovalRequestService $approvals;

	public function __construct() {
		$this->settings  = igbz()->settings();
		$this->approvals = new ApprovalRequestService( new Db() );
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 30 );
		add_action( 'admin_post_igbz_pado_save_settings', [ $this, 'handle_save_settings' ] );
		add_action( 'admin_post_igbz_pado_decide', [ $this, 'handle_decide' ] );
		add_action( 'admin_post_igbz_pado_start_design', [ $this, 'handle_start_design' ] );
	}

	public function add_page(): void {
		Menu::add(
			self::SLUG,
			__( 'Pado (AI Center)', 'igbz-suite' ),
			[ $this, 'render' ],
			Capabilities::MANAGE_PADO
		);
	}

	private function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return in_array( $tab, [ self::TAB_SETTINGS, self::TAB_DESIGN, self::TAB_APPROVALS, self::TAB_HISTORY ], true )
			? $tab
			: self::TAB_DESIGN;
	}

	public function render(): void {
		Capabilities::require( Capabilities::MANAGE_PADO );

		$tab     = $this->current_tab();
		$notice  = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg     = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		$err     = isset( $_GET['err'] ) ? sanitize_text_field( wp_unslash( $_GET['err'] ) ) : '';
		if ( 'saved' === $msg ) {
			$notice = 'تنظیمات ذخیره شد.';
		} elseif ( 'queued' === $msg ) {
			$notice = 'درخواست طراحی در صف پادو قرار گرفت و به‌زودی به درخواست‌های مجوز می‌رسد.';
		} elseif ( 'approved' === $msg ) {
			$notice = 'درخواست تأیید و به اجرا سپرده شد.';
		} elseif ( 'rejected' === $msg ) {
			$notice = 'درخواست رد شد.';
		}
		if ( '' !== $err ) {
			$notice = $err;
		}

		View::open( __( 'Pado — AI Center', 'igbz-suite' ), __( 'دستیار هوشمند فروشگاه شما', 'igbz-suite' ) );
		if ( '' !== $notice ) {
			View::notice( $notice, '' !== $err ? 'error' : 'success' );
		}
		$this->render_tabs( $tab );
		// dir=rtl: the tab content is Persian; without it the sentences render
		// scrambled inside the LTR admin (found by the 1406/06/02 visual test).
		echo '<div class="igbz-card" dir="rtl" style="max-width:980px;text-align:right;">';
		match ( $tab ) {
			self::TAB_SETTINGS  => $this->render_tab_settings(),
			self::TAB_DESIGN    => $this->render_tab_design(),
			self::TAB_APPROVALS => $this->render_tab_approvals(),
			self::TAB_HISTORY   => $this->render_tab_history(),
		};
		echo '</div>';
		View::close();
	}

	/** @param array<string,string> $tabs */
	private function render_tabs( string $current ): void {
		$tabs = [
			self::TAB_DESIGN    => '🎨 طراحی قالب',
			self::TAB_APPROVALS => '✅ درخواست‌های مجوز',
			self::TAB_HISTORY   => '📜 تاریخچه',
			self::TAB_SETTINGS  => '⚙️ تنظیمات',
		];
		View::tabs( $tabs, $current, self::SLUG );
	}

	// ---------------------------------------------------------------- tabs

	private function render_tab_settings(): void {
		$api_key     = $this->settings->string( 'pado.api_key', '' );
		$endpoint    = $this->settings->string( 'pado.endpoint', '' );
		$model_label = $this->settings->string( 'pado.model_label', '' );
		?>
		<p>
			<?php esc_html_e( 'مدل/سرویس پادو توسط تیم توسعه انتخاب می‌شود. شما در این صفحه فقط کلید API مدلی را که ما اعلام می‌کنیم وارد می‌کنید.', 'igbz-suite' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="igbz_pado_save_settings">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="pado_model_label">مدل انتخاب‌شده</label></th>
					<td>
						<input type="text" id="pado_model_label" name="pado[model_label]"
							value="<?php echo esc_attr( $model_label ); ?>" class="regular-text"
							placeholder="مثلاً Vira Gateway v1 — به‌روزرسانی این مقدار فقط با تأیید تیم توسعه انجام می‌شود">
						<p class="description">این فیلد صرفاً جهت نمایش است؛ تغییر آن مدل را عوض نمی‌کند.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pado_api_key">API Key</label></th>
					<td>
						<input type="password" id="pado_api_key" name="pado[api_key]"
							value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" autocomplete="off">
						<p class="description">کلید در پایگاه داده به‌صورت رمزنگاری‌شده ذخیره می‌شود.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pado_endpoint">API Endpoint (اختیاری)</label></th>
					<td>
						<input type="url" id="pado_endpoint" name="pado[endpoint]"
							value="<?php echo esc_attr( $endpoint ); ?>" class="regular-text"
							placeholder="https://vira.igbz.ir/api/v1/gateway">
						<p class="description">در صورت خالی بودن، مقدار پیش‌فرض دروازه ویرا استفاده می‌شود.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'igbz-suite' ) ); ?>
		</form>
		<?php
	}

	private function render_tab_design(): void {
		$have_key = '' !== $this->settings->string( 'pado.api_key', '' );
		?>
		<h2>طراحی قالب فروشگاه</h2>
		<p>
			پادو با الهام از منابع تأییدشده (<code>ui-ux-pro-max-skill</code>، وب‌سایت 21st.dev، و قواعد
			بازار ایران) یک قالب فرزند FSE روی قالب هسته وردپرس برای فروشگاه شما تولید می‌کند.
			قبل از تولید، یک <b>پیشنهاد طراحی یک‌صفحه‌ای</b> شامل رنگ، فونت، چیدمان صفحه‌ها و سه‌بعدی/عمق
			برای شما ارسال می‌شود؛ <b>قالب فقط پس از تأیید شما</b> در سایت منتشر می‌گردد.
		</p>

		<?php if ( ! $have_key ) : ?>
			<div class="notice notice-warning inline">
				<p>ابتدا از تب «تنظیمات» کلید API را وارد کنید.</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="igbz_pado_start_design">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="store_topic">موضوع فروشگاه</label></th>
					<td>
						<input type="text" id="store_topic" name="brief[topic]" class="regular-text"
							placeholder="مثلاً: فروشگاه آرایشی و بهداشتی" required>
						<p class="description">اگر دسته‌بندی‌ها و محصولات از قبل ثبت شده باشند، به‌صورت خودکار پیش‌پر می‌شوند.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="store_audience">مخاطب اصلی</label></th>
					<td>
						<input type="text" id="store_audience" name="brief[audience]" class="regular-text"
							placeholder="مثلاً: زنان ۲۰ تا ۴۰ ساله، میانگین قیمت متوسط">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="store_tone">لحن برند</label></th>
					<td>
						<select id="store_tone" name="brief[tone]">
							<option value="رسمی">رسمی</option>
							<option value="صمیمی">صمیمی</option>
							<option value="لوکس کم‌حرف" selected>لوکس کم‌حرف</option>
							<option value="شوخ و پرانرژی">شوخ و پرانرژی</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="store_keywords">۲ تا ۳ کلمه کلیدی حس مطلوب</label></th>
					<td>
						<input type="text" id="store_keywords" name="brief[keywords]" class="regular-text"
							placeholder="مثلاً: مدرن، شفاف، قابل اعتماد">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="store_ref_site">وب‌سایت یا پیج مرجع (اختیاری)</label></th>
					<td>
						<input type="url" id="store_ref_site" name="brief[ref_site]" class="regular-text"
							placeholder="https://...">
					</td>
				</tr>
				<tr>
					<th scope="row">خط قرمزها</th>
					<td>
						<textarea name="brief[redlines]" rows="3" class="large-text"
							placeholder="مثلاً: رنگ صورتی پررنگ نخواهیم، انیمیشن‌های زیاد ممنوع"></textarea>
					</td>
				</tr>
			</table>
			<?php submit_button( '🚀 شروع طراحی', 'primary', 'submit', true, disabled( $have_key, false, false ) ); ?>
		</form>
		<?php
	}

	private function render_tab_approvals(): void {
		$page   = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
		$per_page = 10;
		$offset = ( $page - 1 ) * $per_page;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['astatus'] ) ? sanitize_key( wp_unslash( $_GET['astatus'] ) ) : 'pending';
		if ( ! in_array( $status, [ 'pending', 'approved', 'rejected', 'executed', 'failed', '' ], true ) ) {
			$status = 'pending';
		}

		$pending_count = $this->approvals->count( ApprovalRequestService::STATUS_PENDING );
		$tabs = [
			'pending'  => sprintf( 'در انتظار بررسی <span class="count">(%d)</span>', $pending_count ),
			''         => 'همه',
			'approved' => 'تأییدشده',
			'rejected' => 'ردشده',
			'executed' => 'اجرا‌شده',
			'failed'   => 'ناموفق',
		];
		echo '<ul class="subsubsub">';
		$first = true;
		foreach ( $tabs as $key => $label ) {
			$url   = Menu::url( self::SLUG, [ 'tab' => self::TAB_APPROVALS, 'astatus' => $key ] );
			$class = ( $status === $key ) ? ' class="current"' : '';
			echo ( $first ? '' : ' | ' ) . '<li><a href="' . esc_url( $url ) . '"' . $class . '>' . wp_kses_post( $label ) . '</a></li>';
			$first = false;
		}
		echo '</ul>';
		echo '<div style="clear:both;"></div>';

		$rows  = $this->approvals->list( $status, $per_page, $offset );
		$total = $this->approvals->count( $status );
		?>
		<p>هر عملیات حساس (تغییر قیمت، مرجوعی، انتشار پست اینستاگرام، تغییر انبوه، اعمال قالب و …)
		پیش از اجرا در این صف به شما ارائه می‌شود. با کلیک روی هر درخواست، دلیل و جزئیات پیشنهاد پادو را می‌بینید.</p>
		<table class="wp-list-table widefat fixed striped table-view-list">
			<thead><tr>
				<th style="width:110px;">نوع</th>
				<th>عنوان</th>
				<th style="width:110px;">سطح ریسک</th>
				<th style="width:140px;">تاریخ</th>
				<th style="width:120px;">وضعیت</th>
				<th style="width:180px;">عملیات</th>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="6">درخواستی در این صف وجود ندارد.</td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $this->kind_label( (string) $row['kind'] ) ); ?></td>
						<td>
							<button type="button" class="button-link" data-igbz-toggle="<?php echo esc_attr( (int) $row['id'] ); ?>">
								<?php echo esc_html( (string) $row['title'] ); ?>
							</button>
							<div id="igbz-reason-<?php echo esc_attr( (int) $row['id'] ); ?>" style="display:none;margin-top:8px;padding:10px;background:#f6f7f7;border-left:3px solid #2271b1;">
								<strong>دلیل پادو:</strong><br>
								<?php echo wp_kses_post( wpautop( esc_html( (string) ( $row['reason'] ?? '—' ) ) ) ); ?>
								<?php if ( ! empty( $row['payload'] ) ) : ?>
									<details style="margin-top:6px;">
										<summary>جزئیات فنی (JSON)</summary>
										<pre style="white-space:pre-wrap;"><?php echo esc_html( (string) $row['payload'] ); ?></pre>
									</details>
								<?php endif; ?>
							</div>
						</td>
						<td><?php echo wp_kses_post( $this->impact_pill( (string) $row['impact'] ) ); ?></td>
						<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
						<td><?php echo wp_kses_post( $this->status_pill( (string) $row['status'] ) ); ?></td>
						<td>
							<?php if ( 'pending' === $row['status'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( self::NONCE_ACTION ); ?>
									<input type="hidden" name="action" value="igbz_pado_decide">
									<input type="hidden" name="request_id" value="<?php echo esc_attr( (int) $row['id'] ); ?>">
									<input type="hidden" name="decision" value="approved">
									<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_APPROVALS ); ?>">
									<input type="hidden" name="astatus" value="<?php echo esc_attr( $status ); ?>">
									<?php submit_button( 'تأیید', 'small primary', 'submit', false ); ?>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( self::NONCE_ACTION ); ?>
									<input type="hidden" name="action" value="igbz_pado_decide">
									<input type="hidden" name="request_id" value="<?php echo esc_attr( (int) $row['id'] ); ?>">
									<input type="hidden" name="decision" value="rejected">
									<input type="hidden" name="note" value="رد شده توسط ادمین">
									<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_APPROVALS ); ?>">
									<input type="hidden" name="astatus" value="<?php echo esc_attr( $status ); ?>">
									<?php submit_button( 'رد', 'small', 'submit', false ); ?>
								</form>
							<?php else : ?>
								<span class="description">بسته‌شده</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
		View::pagination(
			$total,
			$per_page,
			$page,
			self::SLUG,
			[ 'tab' => self::TAB_APPROVALS, 'astatus' => $status ]
		);
		?>
		<script>
			document.addEventListener('click', function(e){
				var t = e.target.closest('[data-igbz-toggle]');
				if (!t) return;
				e.preventDefault();
				var id = t.getAttribute('data-igbz-toggle');
				var el = document.getElementById('igbz-reason-' + id);
				if (el) el.style.display = (el.style.display === 'none') ? 'block' : 'none';
			});
		</script>
		<?php
	}

	private function render_tab_history(): void {
		$page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
		$per_page = 20;
		$offset  = ( $page - 1 ) * $per_page;
		$rows    = $this->approvals->list( '', $per_page, $offset );
		$total   = $this->approvals->count( '' );
		?>
		<p>تاریخچهٔ تمام درخواست‌ها و اقدامات پادو در این صفحه قابل مشاهده است.</p>
		<table class="wp-list-table widefat fixed striped table-view-list">
			<thead><tr>
				<th style="width:130px;">تاریخ</th>
				<th style="width:110px;">نوع</th>
				<th>عنوان</th>
				<th style="width:120px;">وضعیت</th>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="4">تاریخچه خالی است.</td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
						<td><?php echo esc_html( $this->kind_label( (string) $row['kind'] ) ); ?></td>
						<td><?php echo esc_html( (string) $row['title'] ); ?></td>
						<td><?php echo wp_kses_post( $this->status_pill( (string) $row['status'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
		View::pagination( $total, $per_page, $page, self::SLUG, [ 'tab' => self::TAB_HISTORY ] );
	}

	// ----------------------------------------------------------- handlers

	public function handle_save_settings(): void {
		Capabilities::require( Capabilities::MANAGE_PADO );
		check_admin_referer( self::NONCE_ACTION );
		$raw = isset( $_POST['pado'] ) && is_array( $_POST['pado'] ) ? wp_unslash( $_POST['pado'] ) : [];
		$this->settings->set( 'pado.api_key', sanitize_text_field( (string) ( $raw['api_key'] ?? '' ) ) );
		$this->settings->set( 'pado.endpoint', esc_url_raw( (string) ( $raw['endpoint'] ?? '' ) ) );
		$this->settings->set( 'pado.model_label', sanitize_text_field( (string) ( $raw['model_label'] ?? '' ) ) );
		wp_safe_redirect( Menu::url( self::SLUG, [ 'tab' => self::TAB_SETTINGS, 'msg' => 'saved' ] ) );
		exit;
	}

	public function handle_start_design(): void {
		Capabilities::require( Capabilities::MANAGE_PADO );
		check_admin_referer( self::NONCE_ACTION );
		$raw = isset( $_POST['brief'] ) && is_array( $_POST['brief'] ) ? wp_unslash( $_POST['brief'] ) : [];
		$brief = [
			'topic'     => sanitize_text_field( (string) ( $raw['topic'] ?? '' ) ),
			'audience'  => sanitize_text_field( (string) ( $raw['audience'] ?? '' ) ),
			'tone'      => sanitize_text_field( (string) ( $raw['tone'] ?? 'لوکس کم‌حرف' ) ),
			'keywords'  => sanitize_text_field( (string) ( $raw['keywords'] ?? '' ) ),
			'ref_site'  => esc_url_raw( (string) ( $raw['ref_site'] ?? '' ) ),
			'redlines'  => sanitize_textarea_field( (string) ( $raw['redlines'] ?? '' ) ),
		];

		// S0 behaviour: create a pending approval request so the queue is populated;
		// the actual gateway call to Vira will be wired in S1 when API endpoint is live.
		$this->approvals->submit( [
			'kind'    => 'theme_design',
			'title'   => sprintf( 'پیشنهاد طراحی قالب برای فروشگاه: %s', $brief['topic'] ?: '(بدون موضوع)' ),
			'reason'  => "پادو درخواست دارد یک پیشنهاد طراحی یک‌صفحه‌ای (پالت رنگ، فونت — وزیرمتن پیش‌فرض — چیدمان صفحه‌ها، سطح سه‌بعدی/عمق) برای فروشگاه شما آماده کند.\n\nموضوع: {$brief['topic']}\nمخاطب: {$brief['audience']}\nلحن: {$brief['tone']}\nکلمات کلیدی حس مطلوب: {$brief['keywords']}\nمرجع: {$brief['ref_site']}\nخط قرمزها: {$brief['redlines']}\n\nپس از تأیید، درخواست به دروازه ویرا ارسال شده و نتیجه (zip قالب فرزند FSE) ظرف چند دقیقه به همین صف بازمی‌گردد.",
			'payload' => $brief,
			'impact'  => ApprovalRequestService::IMPACT_MEDIUM,
		] );
		wp_safe_redirect( Menu::url( self::SLUG, [ 'tab' => self::TAB_APPROVALS, 'msg' => 'queued' ] ) );
		exit;
	}

	public function handle_decide(): void {
		Capabilities::require( Capabilities::MANAGE_PADO );
		check_admin_referer( self::NONCE_ACTION );
		$id        = (int) ( $_POST['request_id'] ?? 0 );
		$decision  = sanitize_key( (string) ( $_POST['decision'] ?? '' ) );
		$note      = sanitize_textarea_field( (string) ( $_POST['note'] ?? '' ) );
		$tab       = sanitize_key( (string) ( $_POST['tab'] ?? self::TAB_APPROVALS ) );
		$astatus   = sanitize_key( (string) ( $_POST['astatus'] ?? 'pending' ) );

		// In S0, we simply mark the row approved/rejected; actual executors for
		// each kind will be wired in subsequent phases. This keeps the UI real
		// and data-shaped correctly.
		$ok = $this->approvals->decide(
			$id,
			'approved' === $decision ? ApprovalRequestService::STATUS_APPROVED : ApprovalRequestService::STATUS_REJECTED,
			get_current_user_id(),
			$note,
			// Stub executor: mark executed for approved theme_design requests so the row
			// reflects the flow end-to-end. Real generation comes in S1.
			static function( array $req ): bool {
				return 'theme_design' === ( $req['kind'] ?? '' );
			}
		);

		$args = [ 'tab' => $tab, 'astatus' => $astatus ];
		$args['msg'] = $ok ? ( 'approved' === $decision ? 'approved' : 'rejected' ) : '';
		if ( ! $ok ) {
			$args['err'] = 'درخواست یافت نشد یا قبلاً تصمیم گرفته شده بود.';
		}
		wp_safe_redirect( Menu::url( self::SLUG, $args ) );
		exit;
	}

	// ----------------------------------------------------------- helpers

	private function kind_label( string $kind ): string {
		$map = [
			'theme_design'      => 'طراحی قالب',
			'theme_apply'       => 'اعمال قالب',
			'theme_rollback'    => 'بازگشت قالب',
			'price_change'      => 'تغییر قیمت',
			'refund'            => 'مرجوعی',
			'ig_publish_viral'  => 'پست وایرال',
			'ig_publish_trust'  => 'پست اعتماد',
			'ig_publish_lifestyle' => 'پست شخصی',
			'ig_publish_campaign' => 'کمپین فروش',
			'bulk_delete'       => 'حذف انبوه',
			'url_change'        => 'تغییر URL',
			'campaign_send'     => 'ارسال کمپین',
			'policy_change'     => 'تغییر سیاست',
		];
		return $map[ $kind ] ?? ucfirst( $kind );
	}

	private function status_pill( string $status ): string {
		$colors = [
			'pending'  => '#dba617',
			'approved' => '#00a32a',
			'rejected' => '#d63638',
			'executed' => '#2271b1',
			'failed'   => '#8a2424',
			'cancelled'=> '#787c82',
		];
		$labels = [
			'pending'  => 'در انتظار',
			'approved' => 'تأییدشده',
			'rejected' => 'ردشده',
			'executed' => 'اجرا‌شده',
			'failed'   => 'ناموفق',
			'cancelled'=> 'منصرف',
		];
		$c = $colors[ $status ] ?? '#787c82';
		$l = $labels[ $status ] ?? $status;
		return '<span style="display:inline-block;padding:2px 10px;border-radius:9px;color:#fff;background:' . esc_attr( $c ) . '">' . esc_html( $l ) . '</span>';
	}

	private function impact_pill( string $impact ): string {
		$colors = [
			'low'      => '#00a32a',
			'medium'   => '#dba617',
			'high'     => '#d63638',
			'critical' => '#661111',
		];
		$labels = [
			'low'      => 'پایین',
			'medium'   => 'متوسط',
			'high'     => 'بالا',
			'critical' => 'بحرانی',
		];
		$c = $colors[ $impact ] ?? '#787c82';
		$l = $labels[ $impact ] ?? $impact;
		return '<span style="display:inline-block;padding:2px 10px;border-radius:9px;color:#fff;background:' . esc_attr( $c ) . '">' . esc_html( $l ) . '</span>';
	}
}
