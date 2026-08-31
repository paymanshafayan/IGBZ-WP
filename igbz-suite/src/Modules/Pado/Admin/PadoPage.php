<?php
namespace IGBZ\Suite\Modules\Pado\Admin;

use IGBZ\Suite\Modules\Pado\Ai\AiGateway;
use IGBZ\Suite\Modules\Pado\Ai\AiRequest;
use IGBZ\Suite\Modules\Pado\Ai\KeyVault;
use IGBZ\Suite\Modules\Pado\Ai\ProviderDefinition;
use IGBZ\Suite\Modules\Pado\Ai\ProviderRegistry;
use IGBZ\Suite\Modules\Pado\Ai\Workload;
use IGBZ\Suite\Modules\Pado\Services\ApprovalRequestService;
use IGBZ\Suite\Modules\Pado\Services\ThemeService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * "مرکز پادو" admin page with four tabs as per the ratified design (S0):
 *   - تنظیمات (API key)
 *   - طراحی قالب (پرسش‌نامه، فراخوانی سرویس پادو و ثبت درخواست مجوز)
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
		add_action( 'admin_post_igbz_pado_save_keys', [ $this, 'handle_save_keys' ] );
		add_action( 'admin_post_igbz_pado_save_routing', [ $this, 'handle_save_routing' ] );
		add_action( 'admin_post_igbz_pado_save_provider', [ $this, 'handle_save_provider' ] );
		add_action( 'admin_post_igbz_pado_delete_provider', [ $this, 'handle_delete_provider' ] );
		add_action( 'admin_post_igbz_pado_test_connection', [ $this, 'handle_test_connection' ] );
		add_action( 'admin_post_igbz_pado_decide', [ $this, 'handle_decide' ] );
		add_action( 'admin_post_igbz_pado_start_design', [ $this, 'handle_start_design' ] );
		add_action( 'admin_post_igbz_pado_upload_theme', [ $this, 'handle_upload_theme' ] );
		add_action( 'admin_post_igbz_pado_theme_preview', [ $this, 'handle_theme_preview' ] );
		add_action( 'admin_post_igbz_pado_theme_live', [ $this, 'handle_theme_live' ] );
		add_action( 'admin_post_igbz_pado_theme_rollback', [ $this, 'handle_theme_rollback' ] );
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
		} elseif ( 'keys_saved' === $msg ) {
			$notice = 'کلیدها با موفقیت در مخزن کلید پنل ثبت شدند.';
		} elseif ( 'routing_saved' === $msg ) {
			$notice = 'مسیریابی ارائه‌دهنده‌ها ذخیره شد.';
		} elseif ( 'provider_saved' === $msg ) {
			$notice = 'ارائه‌دهنده ذخیره شد.';
		} elseif ( 'provider_deleted' === $msg ) {
			$notice = 'ارائه‌دهنده حذف شد.';
		} elseif ( 'connected' === $msg ) {
			$notice = 'اتصال به ارائه‌دهنده برقرار است.';
		} elseif ( 'queued' === $msg ) {
			$notice = 'درخواست طراحی در صف پادو قرار گرفت و به‌زودی به درخواست‌های مجوز می‌رسد.';
		} elseif ( 'uploaded' === $msg ) {
			$notice = 'قالب با موفقیت اعتبارسنجی و برای پیش‌نمایش ثبت شد.';
		} elseif ( 'previewed' === $msg ) {
			$notice = 'قالب برای پیش‌نمایش نصب شد.';
		} elseif ( 'activated' === $msg ) {
			$notice = 'قالب با موفقیت اعمال شد.';
		} elseif ( 'rolledback' === $msg ) {
			$notice = 'قالب قبلی با موفقیت بازگردانده شد.';
		} elseif ( 'approved' === $msg ) {
			$notice = 'درخواست تأیید شد و تا اجرای موفق در وضعیت تأییدشده می‌ماند.';
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
		$this->render_key_screen();
		$this->render_provider_panel();
		$this->render_gateway_form();
	}

	// --------------------------------------------- ثبت کلیدها (سطح یک — ویزارد)

	/**
	 * The two key rows show the providers currently routed to the two sections (Groq →
	 * «امور اداری», OpenRouter → «مدیریت» by default). Switching a provider in the ⚙ panel
	 * changes these rows. `key` is the provider id, the `keyRef` the vault stores under.
	 *
	 * @return array<int,array{id:string,title:string,sections:array<int,string>}>
	 */
	private function key_rows(): array {
		$registry = igbz()->get( 'pado.ai.registry' );
		$gateway  = igbz()->get( 'pado.ai.gateway' );
		$rows     = [];

		foreach ( Workload::keys() as $section ) {
			$id         = $gateway->routing_id( $section );
			$definition = $registry->get( $id );
			if ( null === $definition ) {
				$fallback = $this->settings->string( 'pado.ai.default_provider', '' );
				$definition = '' !== $fallback ? $registry->get( $fallback ) : null;
			}
			if ( null === $definition ) {
				continue;
			}
			if ( ! isset( $rows[ $definition->id() ] ) ) {
				$rows[ $definition->id() ] = [ 'id' => $definition->id(), 'title' => $definition->title(), 'sections' => [] ];
			}
			$rows[ $definition->id() ]['sections'][] = Workload::title( $section );
		}

		return array_values( $rows );
	}

	private function render_key_screen(): void {
		$vault = igbz()->get( 'pado.ai.vault' );
		$rows  = $this->key_rows();
		?>
		<h2><?php esc_html_e( 'ثبت کلیدها', 'igbz-suite' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'کلید هر ارائه‌دهنده فقط به‌صورت رمزنگاری‌شده در مخزن کلید پنل نگه‌داری می‌شود و هرگز نمایش داده یا ثبت لاگ نمی‌شود. راستی‌آزمایی اتصال از طریق همین صفحه یا ورک‌فلوی GitHub انجام می‌شود.', 'igbz-suite' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="igbz_pado_save_keys">
			<table class="form-table" role="presentation">
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<th scope="row">
							<label for="igbz-key-<?php echo esc_attr( $row['id'] ); ?>"><?php echo esc_html( $row['title'] ); ?></label>
							<p class="description" style="font-weight:normal;margin-top:2px;"><?php echo esc_html( implode( '، ', $row['sections'] ) ); ?></p>
						</th>
						<td>
							<input type="password" id="igbz-key-<?php echo esc_attr( $row['id'] ); ?>"
								name="keys[<?php echo esc_attr( $row['id'] ); ?>]"
								value="" class="regular-text" autocomplete="off"
								placeholder="<?php echo $vault->has( $row['id'] ) ? esc_attr( '•••••••••••• (کلید ذخیره‌شده است)' ) : esc_attr( 'API Key' ); ?>">
							<p class="description">
								<?php echo $vault->has( $row['id'] )
									? esc_html__( 'کلید ذخیره‌شده است؛ برای تعویض، کلید جدید را وارد کنید.', 'igbz-suite' )
									: esc_html__( 'کلیدی برای این ارائه‌دهنده ثبت نشده است.', 'igbz-suite' ); ?>
							</p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'ثبت کلیدها', 'igbz-suite' ); ?></button>
				<button type="button" class="button" id="igbz-open-provider-panel" aria-label="<?php esc_attr_e( 'تغییر ارائه‌دهنده', 'igbz-suite' ); ?>">⚙</button>
			</p>
		</form>
		<script>
			document.addEventListener( 'click', function ( e ) {
				var t = e.target && e.target.closest ? e.target.closest( '#igbz-open-provider-panel' ) : null;
				if ( ! t ) return;
				e.preventDefault();
				var p = document.getElementById( 'igbz-provider-panel' );
				if ( p ) p.style.display = ( p.style.display === 'none' ) ? 'block' : 'none';
			} );
		</script>
		<?php
	}

	// ------------------------------------------- تغییر ارائه‌دهنده (سطح دو — ⚙)

	private function render_provider_panel(): void {
		$registry  = igbz()->get( 'pado.ai.registry' );
		$gateway   = igbz()->get( 'pado.ai.gateway' );
		$providers = $registry->all();
		$shared    = $this->settings->bool( 'pado.ai.shared' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id   = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$edit      = '' !== $edit_id ? ( $providers[ $edit_id ] ?? null ) : null;
		?>
		<div id="igbz-provider-panel" style="display:none;margin:12px 0 20px;padding:16px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;">

			<h3><?php esc_html_e( 'تغییر ارائه‌دهنده', 'igbz-suite' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="igbz_pado_save_routing">
				<p>
					<label>
						<input type="checkbox" name="shared" value="1" <?php checked( $shared, true ); ?>>
						<?php esc_html_e( 'استفاده از یک ارائه‌دهنده برای هر دو بخش', 'igbz-suite' ); ?>
					</label>
				</p>

				<?php foreach ( Workload::keys() as $section ) : ?>
					<?php
					$current_id = $gateway->routing_id( $section );
					$current    = $providers[ $current_id ] ?? null;
					$model      = $gateway->model_for( $section );
					$locked     = $shared && Workload::JUDGMENT === $section;
					?>
					<div style="margin-bottom:14px;padding:12px;background:#fff;border:1px solid #dcdcde;border-radius:3px;">
						<strong><?php echo esc_html( Workload::title( $section ) ); ?></strong>
						<p>
							<label>
								<?php esc_html_e( 'ارائه‌دهنده:', 'igbz-suite' ); ?>
								<?php if ( $locked ) : ?>
									<input type="hidden" name="routing[<?php echo esc_attr( $section ); ?>]" value="<?php echo esc_attr( $current_id ); ?>">
								<?php endif; ?>
								<select name="<?php echo $locked ? '' : 'routing[' . esc_attr( $section ) . ']'; ?>" <?php disabled( $locked, true ); ?>>
									<?php foreach ( $providers as $id => $definition ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $current_id, $id ); ?>><?php echo esc_html( $definition->title() ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							&nbsp;
							<label>
								<?php esc_html_e( 'مدل:', 'igbz-suite' ); ?>
								<?php if ( $locked ) : ?>
									<input type="hidden" name="model[<?php echo esc_attr( $section ); ?>]" value="<?php echo esc_attr( $model ); ?>">
								<?php endif; ?>
								<select name="<?php echo $locked ? '' : 'model[' . esc_attr( $section ) . ']'; ?>" <?php disabled( $locked, true ); ?>>
									<option value=""><?php esc_html_e( 'پیش‌فرض ارائه‌دهنده', 'igbz-suite' ); ?></option>
									<?php if ( $current ) : ?>
										<?php foreach ( $current->models() as $candidate ) : ?>
											<option value="<?php echo esc_attr( $candidate ); ?>" <?php selected( $model, $candidate ); ?>><?php echo esc_html( $candidate ); ?></option>
										<?php endforeach; ?>
									<?php endif; ?>
									<?php if ( '' !== $model && $current && ! in_array( $model, $current->models(), true ) ) : ?>
										<option value="<?php echo esc_attr( $model ); ?>" selected><?php echo esc_html( $model ); ?></option>
									<?php endif; ?>
								</select>
							</label>
						</p>
					</div>
				<?php endforeach; ?>

				<p class="submit" style="margin-top:0;">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'ذخیره', 'igbz-suite' ); ?></button>
					<button type="button" class="button" id="igbz-close-provider-panel"><?php esc_html_e( 'انصراف', 'igbz-suite' ); ?></button>
				</p>
			</form>

			<hr>

			<h3><?php esc_html_e( 'افزودن/ویرایش ارائه‌دهنده', 'igbz-suite' ); ?></h3>
			<?php $this->render_provider_form( $edit ); ?>

			<h3><?php esc_html_e( 'ارائه‌دهنده‌های ثبت‌شده', 'igbz-suite' ); ?></h3>
			<?php $this->render_provider_list( $providers, $gateway ); ?>
		</div>
		<script>
			document.addEventListener( 'click', function ( e ) {
				var t = e.target && e.target.closest ? e.target.closest( '#igbz-close-provider-panel' ) : null;
				if ( ! t ) return;
				e.preventDefault();
				var p = document.getElementById( 'igbz-provider-panel' );
				if ( p ) p.style.display = 'none';
			} );
		</script>
		<?php
	}

	/** @param ProviderDefinition|null $edit the provider being edited, or null for a new one */
	private function render_provider_form( ?ProviderDefinition $edit ): void {
		$edit     = $edit ?? ProviderDefinition::from_array( [] );
		$protocol = $edit->protocol();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="igbz_pado_save_provider">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="igbz-provider-id">شناسه</label></th>
					<td>
						<input type="text" id="igbz-provider-id" name="provider_id" value="<?php echo esc_attr( $edit->id() ); ?>" class="regular-text" placeholder="مثلاً groq" <?php echo '' !== $edit->id() ? 'readonly' : 'required'; ?>>
						<p class="description">حروف کوچک انگلیسی و خط تیره؛ پس از ثبت تغییر نمی‌کند.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="igbz-provider-title">عنوان</label></th>
					<td><input type="text" id="igbz-provider-title" name="title" value="<?php echo esc_attr( $edit->title() ); ?>" class="regular-text" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="igbz-provider-protocol">گویش</label></th>
					<td>
						<select id="igbz-provider-protocol" name="protocol">
							<option value="openai" <?php selected( $protocol, ProviderDefinition::PROTOCOL_OPENAI ); ?>>openai</option>
							<option value="anthropic" <?php selected( $protocol, ProviderDefinition::PROTOCOL_ANTHROPIC ); ?>>anthropic</option>
							<option value="custom" <?php selected( $protocol, ProviderDefinition::PROTOCOL_CUSTOM ); ?>>custom</option>
						</select>
						<p class="description">گویش سیمِ آداپتور: <code>openai</code> (Groq، OpenRouter و هر endpoint سازگار با OpenAI)، <code>anthropic</code> (بومی Anthropic) و <code>custom</code> (نگاشت JSON پیکربندی‌محور).</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="igbz-provider-base-url">نشانی پایه</label></th>
					<td><input type="url" id="igbz-provider-base-url" name="base_url" value="<?php echo esc_attr( $edit->base_url() ); ?>" class="regular-text" placeholder="https://…" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="igbz-provider-models">مدل‌های پین‌شده</label></th>
					<td><input type="text" id="igbz-provider-models" name="model_allowlist" value="<?php echo esc_attr( implode( ', ', $edit->models() ) ); ?>" class="large-text" placeholder="مدل‌ها با ویرگول جدا شوند" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="igbz-provider-default-model">مدل پیش‌فرض</label></th>
					<td><input type="text" id="igbz-provider-default-model" name="default_model" value="<?php echo esc_attr( $edit->default_model() ); ?>" class="regular-text" required></td>
				</tr>
				<tr>
					<th scope="row">قابلیت‌ها</th>
					<td>
						<?php foreach ( ProviderDefinition::CAPABILITIES as $capability ) : ?>
							<label style="margin-inline-end:12px;">
								<input type="checkbox" name="capabilities[]" value="<?php echo esc_attr( $capability ); ?>" <?php checked( in_array( $capability, $edit->capabilities(), true ), true ); ?>>
								<?php echo esc_html( $capability ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="igbz-provider-quality">رده</label></th>
					<td>
						<select id="igbz-provider-quality" name="quality">
							<option value="standard" <?php selected( $edit->quality(), ProviderDefinition::QUALITY_STANDARD ); ?>>استاندارد</option>
							<option value="premium" <?php selected( $edit->quality(), ProviderDefinition::QUALITY_PREMIUM ); ?>>عالی</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="igbz-provider-budget">بودجهٔ روزانه (توکن)</label></th>
					<td><input type="number" id="igbz-provider-budget" name="daily_token_budget" value="<?php echo esc_attr( (string) $edit->daily_token_budget() ); ?>" class="small-text" min="0" max="1000000" step="1"></td>
				</tr>
				<tr>
					<th scope="row"><label for="igbz-provider-timeout">مهلت (ثانیه)</label></th>
					<td><input type="number" id="igbz-provider-timeout" name="timeout" value="<?php echo esc_attr( (string) $edit->timeout() ); ?>" class="small-text" min="1" max="120" step="1"></td>
				</tr>
				<tr>
					<th scope="row">گیت‌های فعال‌سازی</th>
					<td>
						<label style="margin-inline-end:12px;"><input type="checkbox" name="enabled" value="1" <?php checked( $edit->enabled(), true ); ?>> فعال</label>
						<label style="margin-inline-end:12px;"><input type="checkbox" name="benchmark_passed" value="1" <?php checked( $edit->benchmark_passed(), true ); ?>> بنچمارک تأییدشده</label>
						<label><input type="checkbox" name="geo_eligible" value="1" <?php checked( $edit->geo_eligible(), true ); ?>> واجد شرایط جغرافیایی</label>
					</td>
				</tr>
			</table>
			<div id="igbz-custom-mapping" style="<?php echo ProviderDefinition::PROTOCOL_CUSTOM === $protocol ? '' : 'display:none;'; ?>">
				<h4 style="margin-bottom:4px;"><?php esc_html_e( 'نگاشت گویش custom', 'igbz-suite' ); ?></h4>
				<p class="description" style="margin-top:0;">
					<?php esc_html_e( 'این فیلدها فقط برای گویش custom خوانده می‌شوند؛ برای openai و anthropic بی‌اثرند. مقدار خالی یعنی پیش‌فرض (POST بدون مسیر؛ پاسخ choices.0.message.content؛ مصرف usage.*_tokens).', 'igbz-suite' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="igbz-provider-request-method">متد درخواست</label></th>
						<td>
							<select id="igbz-provider-request-method" name="request_method">
								<?php foreach ( [ 'POST', 'GET', 'PUT', 'PATCH' ] as $method ) : ?>
									<option value="<?php echo esc_attr( $method ); ?>" <?php selected( $edit->request_method(), $method ); ?>><?php echo esc_html( $method ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="igbz-provider-request-path">مسیر درخواست</label></th>
						<td><input type="text" id="igbz-provider-request-path" name="request_path" value="<?php echo esc_attr( $edit->request_path() ); ?>" class="regular-text" placeholder="مثلاً chat"></td>
					</tr>
					<tr>
						<th scope="row"><label for="igbz-provider-content-path">مسیر متن پاسخ</label></th>
						<td><input type="text" id="igbz-provider-content-path" name="response_content_path" value="<?php echo esc_attr( $edit->response_content_path() ); ?>" class="regular-text" placeholder="choices.0.message.content"></td>
					</tr>
					<tr>
						<th scope="row"><label for="igbz-provider-usage-prompt">مسیر توکن ورودی</label></th>
						<td><input type="text" id="igbz-provider-usage-prompt" name="response_usage_prompt_path" value="<?php echo esc_attr( $edit->response_usage_prompt_path() ); ?>" class="regular-text" placeholder="usage.prompt_tokens"></td>
					</tr>
					<tr>
						<th scope="row"><label for="igbz-provider-usage-completion">مسیر توکن خروجی</label></th>
						<td><input type="text" id="igbz-provider-usage-completion" name="response_usage_completion_path" value="<?php echo esc_attr( $edit->response_usage_completion_path() ); ?>" class="regular-text" placeholder="usage.completion_tokens"></td>
					</tr>
					<tr>
						<th scope="row"><label for="igbz-provider-usage-total">مسیر توکن کل</label></th>
						<td><input type="text" id="igbz-provider-usage-total" name="response_usage_total_path" value="<?php echo esc_attr( $edit->response_usage_total_path() ); ?>" class="regular-text" placeholder="usage.total_tokens"></td>
					</tr>
				</table>
			</div>
			<script>
				( function () {
					var select = document.getElementById( 'igbz-provider-protocol' );
					var block  = document.getElementById( 'igbz-custom-mapping' );
					if ( ! select || ! block ) return;
					function sync() {
						block.style.display = ( select.value === 'custom' ) ? '' : 'none';
					}
					select.addEventListener( 'change', sync );
					sync();
				} )();
			</script>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo '' !== $edit->id() ? esc_html__( 'ذخیرهٔ ویرایش', 'igbz-suite' ) : esc_html__( 'افزودن ارائه‌دهنده', 'igbz-suite' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * @param array<string,ProviderDefinition> $providers
	 */
	private function render_provider_list( array $providers, AiGateway $gateway ): void {
		if ( ! $providers ) {
			echo '<p>' . esc_html__( 'هیچ ارائه‌دهنده‌ای ثبت نشده است.', 'igbz-suite' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:760px;"><thead><tr><th>عنوان</th><th>گویش</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
		foreach ( $providers as $id => $definition ) {
			$routed = in_array( $id, [ $gateway->routing_id( Workload::ROUTINE ), $gateway->routing_id( Workload::JUDGMENT ) ], true )
				|| $this->settings->string( 'pado.ai.default_provider', '' ) === $id;
			echo '<tr><td>' . esc_html( $definition->title() ) . '</td>'
				. '<td>' . esc_html( $definition->protocol() ) . '</td>'
				. '<td>' . ( $definition->activated() ? esc_html__( 'فعال', 'igbz-suite' ) : esc_html__( 'خاموش', 'igbz-suite' ) ) . '</td>'
				. '<td>'
				. '<a class="button" href="' . esc_url( Menu::url( self::SLUG, [ 'tab' => self::TAB_SETTINGS, 'edit' => $id ] ) ) . '">' . esc_html__( 'ویرایش', 'igbz-suite' ) . '</a> ';
			if ( $routed ) {
				echo '<span class="description">' . esc_html__( 'در حال استفاده — قابل حذف نیست', 'igbz-suite' ) . '</span>';
			} else {
				$delete = wp_nonce_url( admin_url( 'admin-post.php?action=igbz_pado_delete_provider&provider=' . rawurlencode( $id ) ), self::NONCE_ACTION );
				echo '<a class="button" href="' . esc_url( $delete ) . '" onclick="return confirm(\'' . esc_js( 'این ارائه‌دهنده حذف شود؟' ) . '\');">' . esc_html__( 'حذف', 'igbz-suite' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	// --------------------------------------------------- دروازهٔ ویرا (قدیمی)

	private function render_gateway_form(): void {
		$endpoint    = $this->settings->string( 'pado.endpoint', '' );
		$model_label = $this->settings->string( 'pado.model_label', '' );
		$api_key     = $this->settings->masked( 'pado.api_key' );
		?>
		<hr style="margin:24px 0;">
		<h2><?php esc_html_e( 'اتصال سرویس پادو (دروازهٔ ویرا)', 'igbz-suite' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'این بخش مربوط به سرویس طراحی قالب پادو است و ربطی به ارائه‌دهنده‌های هوش مصنوعی بالا ندارد.', 'igbz-suite' ); ?>
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
						<p class="description">کلید به‌صورت رمزنگاری‌شده ذخیره می‌شود؛ ماسک موجود را دست‌نخورده بگذارید تا کلید فعلی حفظ شود.</p>
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
			<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'igbz-suite' ); ?></button></p>
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

		<h3>بارگذاری ZIP قالب</h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="igbz_pado_upload_theme">
			<p>
				<label for="igbz-theme-zip">فایل ZIP قالب</label>
				<input type="file" id="igbz-theme-zip" name="theme_zip" accept=".zip,application/zip" aria-describedby="igbz-theme-zip-help" required>
				<span id="igbz-theme-zip-help" class="description"> فقط پروندهٔ <code>zip</code> پذیرفته می‌شود.</span>
				<?php submit_button( 'اعتبارسنجی و پیش‌نمایش', 'secondary', 'submit', false ); ?>
			</p>
		</form>

		<h3>درخواست طراحی از پادو</h3>
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
			<?php submit_button( '🚀 شروع طراحی', 'primary', 'submit', true, disabled( ! $have_key, false, false ) ); ?>
		</form>
		<?php $this->render_theme_list(); ?>
		<?php
	}

	private function render_theme_list(): void {
		$rows = igbz()->get( 'pado.themes' )->list( igbz()->tenancy()->id() );
		if ( ! $rows ) { return; }
		echo '<h3>قالب‌های ثبت‌شده</h3><table class="widefat striped"><thead><tr><th>نام</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( (string) $row['name'] ) . '</td><td>' . esc_html__( (string) $row['status'], 'igbz-suite' ) . '</td><td>';
			foreach ( [ 'preview' => 'پیش‌نمایش', 'live' => 'اعمال زنده' ] as $action => $label ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=igbz_pado_theme_' . $action . '&theme_id=' . (int) $row['id'] ), self::NONCE_ACTION );
				echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> ';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$rollback = wp_nonce_url( admin_url( 'admin-post.php?action=igbz_pado_theme_rollback' ), self::NONCE_ACTION );
		echo '<p><a class="button" href="' . esc_url( $rollback ) . '">بازگشت یک‌کلیکی به قالب قبلی</a></p>';
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

		$scope = current_user_can( Capabilities::MANAGE_TENANTS ) ? null : igbz()->tenancy()->id();
		$pending_count = $this->approvals->count( ApprovalRequestService::STATUS_PENDING, $scope );
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

		$rows  = $this->approvals->list( $status, $per_page, $offset, $scope );
		$total = $this->approvals->count( $status, $scope );
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
		$scope   = current_user_can( Capabilities::MANAGE_TENANTS ) ? null : igbz()->tenancy()->id();
		$rows    = $this->approvals->list( '', $per_page, $offset, $scope );
		$total   = $this->approvals->count( '', $scope );
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
		$api_key = sanitize_text_field( (string) ( $raw['api_key'] ?? '' ) );
		if ( '' !== $api_key && Crypto::MASK !== $api_key ) {
			$this->settings->set( 'pado.api_key', $api_key );
		}
		$this->settings->set( 'pado.endpoint', esc_url_raw( (string) ( $raw['endpoint'] ?? '' ) ) );
		$this->settings->set( 'pado.model_label', sanitize_text_field( (string) ( $raw['model_label'] ?? '' ) ) );
		wp_safe_redirect( Menu::url( self::SLUG, [ 'tab' => self::TAB_SETTINGS, 'msg' => 'saved' ] ) );
		exit;
	}

	// -------------------------------------------------- provider plane handlers

	public function handle_save_keys(): void {
		Capabilities::require( Capabilities::MANAGE_PADO );
		check_admin_referer( self::NONCE_ACTION );
		$registry = igbz()->get( 'pado.ai.registry' );
		$vault    = igbz()->get( 'pado.ai.vault' );

		$raw = isset( $_POST['keys'] ) && is_array( $_POST['keys'] ) ? wp_unslash( $_POST['keys'] ) : [];
		$saved  = [];
		$probes = [];
		foreach ( $raw as $id => $key ) {
			$id  = sanitize_key( (string) $id );
			$key = trim( (string) $key );
			if ( '' === $id || null === $registry->get( $id ) || '' === $key ) {
				continue; // empty input = leave the stored key untouched
			}
			$vault->set( $id, $key );
			$saved[] = $id;
		}

		$err = '';
		foreach ( $saved as $id ) {
			$probes[ $id ] = $this->probe_provider( $id );
			if ( ! $probes[ $id ]['ok'] ) {
				$err = sprintf( 'کلید ثبت شد؛ تست اتصال %s ناموفق بود: %s', $id, $probes[ $id ]['error'] );
			}
		}

		wp_safe_redirect( Menu::url( self::SLUG, [
			'tab' => self::TAB_SETTINGS,
			'msg' => $saved ? 'keys_saved' : '',
			'err' => $err,
		] ) );
		exit;
	}

	public function handle_save_routing(): void {
		Capabilities::require( Capabilities::MANAGE_PADO );
		check_admin_referer( self::NONCE_ACTION );
		$registry = igbz()->get( 'pado.ai.registry' );
		$shared   = ! empty( $_POST['shared'] );

		$routing = isset( $_POST['routing'] ) && is_array( $_POST['routing'] ) ? wp_unslash( $_POST['routing'] ) : [];
		$models  = isset( $_POST['model'] ) && is_array( $_POST['model'] ) ? wp_unslash( $_POST['model'] ) : [];

		$err = '';
		foreach ( Workload::keys() as $section ) {
			$id         = sanitize_key( (string) ( $routing[ $section ] ?? '' ) );
			$definition = $registry->get( $id );
			if ( '' === $id || null === $definition ) {
				$err = 'ارائه‌دهندهٔ انتخاب‌شده معتبر نیست.';
				break;
			}
			if ( ! $definition->has_capability( 'chat' ) ) {
				$err = sprintf( 'ارائه‌دهندهٔ «%s» قابلیت chat را ندارد.', $definition->title() );
				break;
			}

			$previous = $this->settings->string( 'pado.ai.routing.' . $section, Workload::default_provider( $section ) );
			$model    = '';
			if ( $id === $previous ) {
				$model = sanitize_text_field( (string) ( $models[ $section ] ?? '' ) );
				if ( '' !== $model && ! in_array( $model, $definition->models(), true ) ) {
					$err = 'مدل انتخاب‌شده در فهرست مدل‌های ارائه‌دهنده نیست.';
					break;
				}
			}
			// ADR-0005 §edge: switching a section's provider resets its model to the provider default.
			$routing[ $section ] = $id;
			$models[ $section ]  = $model;
		}

		if ( '' === $err ) {
			$this->settings->set( 'pado.ai.shared', $shared ? '1' : '' );
			foreach ( Workload::keys() as $section ) {
				$this->settings->set( 'pado.ai.routing.' . $section, (string) ( $routing[ $section ] ?? '' ) );
				$this->settings->set( 'pado.ai.model.' . $section, (string) ( $models[ $section ] ?? '' ) );
			}
			wp_safe_redirect( Menu::url( self::SLUG, [ 'tab' => self::TAB_SETTINGS, 'msg' => 'routing_saved' ] ) );
			exit;
		}

		wp_safe_redirect( Menu::url( self::SLUG, [ 'tab' => self::TAB_SETTINGS, 'err' => $err ] ) );
		exit;
	}

	public function handle_save_provider(): void {
		Capabilities::require( Capabilities::MANAGE_PADO );
		check_admin_referer( self::NONCE_ACTION );

		$id             = sanitize_key( (string) ( $_POST['provider_id'] ?? '' ) );
		$title          = sanitize_text_field( (string) ( $_POST['title'] ?? '' ) );
		$protocol       = sanitize_key( (string) ( $_POST['protocol'] ?? ProviderDefinition::PROTOCOL_OPENAI ) );
		$base_url       = esc_url_raw( (string) ( $_POST['base_url'] ?? '' ) );
		$model_allowlist = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( (string) ( $_POST['model_allowlist'] ?? '' ) ) ) ) ) );
		$default_model  = sanitize_text_field( (string) ( $_POST['default_model'] ?? '' ) );
		$capabilities   = array_values( array_intersect( ProviderDefinition::CAPABILITIES, array_map( 'sanitize_key', (array) ( $_POST['capabilities'] ?? [] ) ) ) );
		$quality        = sanitize_key( (string) ( $_POST['quality'] ?? ProviderDefinition::QUALITY_STANDARD ) );
		$budget         = (int) ( $_POST['daily_token_budget'] ?? ProviderDefinition::DEFAULT_DAILY_TOKEN_BUDGET );
		$timeout        = (int) ( $_POST['timeout'] ?? ProviderDefinition::DEFAULT_TIMEOUT );

		// custom-dialect JSON mapping (inert unless protocol=custom).
		$request_method = strtoupper( (string) ( $_POST['request_method'] ?? 'POST' ) );
		$custom = [
			'request_method'                   => in_array( $request_method, [ 'GET', 'POST', 'PUT', 'PATCH' ], true ) ? $request_method : 'POST',
			'request_path'                     => sanitize_text_field( (string) ( $_POST['request_path'] ?? '' ) ),
			'response_content_path'            => sanitize_text_field( (string) ( $_POST['response_content_path'] ?? '' ) ),
			'response_usage_prompt_path'       => sanitize_text_field( (string) ( $_POST['response_usage_prompt_path'] ?? '' ) ),
			'response_usage_completion_path'   => sanitize_text_field( (string) ( $_POST['response_usage_completion_path'] ?? '' ) ),
			'response_usage_total_path'        => sanitize_text_field( (string) ( $_POST['response_usage_total_path'] ?? '' ) ),
		];

		$err = '';
		if ( '' === $id ) {
			$err = 'شناسهٔ ارائه‌دهنده الزامی است.';
		} elseif ( '' === $title ) {
			$err = 'عنوان الزامی است.';
		} elseif ( ! in_array( $protocol, [ ProviderDefinition::PROTOCOL_OPENAI, ProviderDefinition::PROTOCOL_ANTHROPIC, ProviderDefinition::PROTOCOL_CUSTOM ], true ) ) {
			$err = 'گویش نامعتبر است.';
		} elseif ( '' === $base_url || 'https' !== strtolower( (string) wp_parse_url( $base_url, PHP_URL_SCHEME ) ) ) {
			$err = 'نشانی پایه باید یک نشانی https باشد.';
		} elseif ( ! $model_allowlist ) {
			$err = 'حداقل یک مدل پین‌شده لازم است.';
		} elseif ( '' === $default_model || ! in_array( $default_model, $model_allowlist, true ) ) {
			$err = 'مدل پیش‌فرض باید در فهرست مدل‌ها باشد.';
		} elseif ( $budget < 0 || $budget > 1000000 ) {
			$err = 'بودجهٔ روزانه خارج از بازهٔ مجاز است.';
		} elseif ( $timeout < 1 || $timeout > 120 ) {
			$err = 'مهلت خارج از بازهٔ مجاز است.';
		}

		if ( '' === $err ) {
			$record = [
				'id'                 => $id,
				'title'              => $title,
				'type'               => ProviderDefinition::TYPE_API_PROVIDER,
				'protocol'           => $protocol,
				'base_url'           => $base_url,
				'model_allowlist'    => $model_allowlist,
				'default_model'      => $default_model,
				'capabilities'       => $capabilities,
				'quality'            => $quality,
				'enabled'            => ! empty( $_POST['enabled'] ),
				'benchmark_passed'   => ! empty( $_POST['benchmark_passed'] ),
				'geo_eligible'       => ! empty( $_POST['geo_eligible'] ),
				'daily_token_budget' => $budget,
				'timeout'            => $timeout,
			];
			if ( ProviderDefinition::PROTOCOL_CUSTOM === $protocol ) {
				$record += $custom;
			}
			igbz()->get( 'pado.ai.registry' )->upsert( ProviderDefinition::from_array( $record ) );
			wp_safe_redirect( Menu::url( self::SLUG, [ 'tab' => self::TAB_SETTINGS, 'msg' => 'provider_saved' ] ) );
			exit;
		}

		wp_safe_redirect( Menu::url( self::SLUG, [ 'tab' => self::TAB_SETTINGS, 'err' => $err ] ) );
		exit;
	}

	public function handle_delete_provider(): void {
		Capabilities::require( Capabilities::MANAGE_PADO );
		check_admin_referer( self::NONCE_ACTION );
		$id       = sanitize_key( (string) ( $_GET['provider'] ?? '' ) );
		$registry = igbz()->get( 'pado.ai.registry' );
		$gateway  = igbz()->get( 'pado.ai.gateway' );

		$err = '';
		if ( '' === $id || null === $registry->get( $id ) ) {
			$err = 'ارائه‌دهنده یافت نشد.';
		} else {
			$routed = $this->settings->string( 'pado.ai.default_provider', '' ) === $id;
			foreach ( Workload::keys() as $section ) {
				$routed = $routed || $gateway->routing_id( $section ) === $id;
			}
			if ( $routed ) {
				$err = 'این ارائه‌دهنده به یک بخش متصل است؛ ابتدا بخش را به ارائه‌دهندهٔ دیگر منتقل کنید.';
			} else {
				$registry->remove( $id );
				igbz()->get( 'pado.ai.vault' )->remove( $id );
				wp_safe_redirect( Menu::url( self::SLUG, [ 'tab' => self::TAB_SETTINGS, 'msg' => 'provider_deleted' ] ) );
				exit;
			}
		}

		wp_safe_redirect( Menu::url( self::SLUG, [ 'tab' => self::TAB_SETTINGS, 'err' => $err ] ) );
		exit;
	}

	public function handle_test_connection(): void {
		Capabilities::require( Capabilities::MANAGE_PADO );
		check_admin_referer( self::NONCE_ACTION );
		$id     = sanitize_key( (string) ( $_GET['provider'] ?? '' ) );
		$result = $this->probe_provider( $id );

		if ( $result['ok'] ) {
			wp_safe_redirect( Menu::url( self::SLUG, [ 'tab' => self::TAB_SETTINGS, 'msg' => 'connected' ] ) );
		} else {
			wp_safe_redirect( Menu::url( self::SLUG, [ 'tab' => self::TAB_SETTINGS, 'err' => 'اتصال برقرار نشد: ' . $result['error'] ] ) );
		}
		exit;
	}

	/**
	 * A light connectivity probe: one tiny Persian prompt through the provider's own
	 * adapter, using the panel key from the vault. Honest failure, never a fake success.
	 *
	 * @return array{ok:bool,error:string}
	 */
	private function probe_provider( string $id ): array {
		$registry   = igbz()->get( 'pado.ai.registry' );
		$definition = $registry->get( $id );
		if ( null === $definition ) {
			return [ 'ok' => false, 'error' => 'provider_not_found' ];
		}
		if ( ! $definition->activated() ) {
			return [ 'ok' => false, 'error' => 'provider_disabled' ];
		}
		$adapter = $registry->adapter_for( $definition );
		if ( null === $adapter ) {
			return [ 'ok' => false, 'error' => 'protocol_unsupported' ];
		}

		$result = $adapter->run( new AiRequest(
			tenant_id: 0,
			user_id: get_current_user_id(),
			api_key: '', // resolved from the vault at call time
			model: $definition->default_model(),
			system: 'پاسخ تک‌کلمه‌ای بده.',
			messages: [ [ 'role' => 'user', 'content' => 'بگو: بله' ] ],
			tools: [],
			max_tokens: 8,
			timeout: 20,
			reference: 'probe:' . $id . ':' . time()
		) );

		return $result->ok ? [ 'ok' => true, 'error' => '' ] : [ 'ok' => false, 'error' => $result->error ];
	}

	public function handle_upload_theme(): void {
		Capabilities::require( Capabilities::MANAGE_PADO );
		check_admin_referer( self::NONCE_ACTION );
		$file = isset( $_FILES['theme_zip'] ) && is_array( $_FILES['theme_zip'] ) ? $_FILES['theme_zip'] : [];
		$result = igbz()->get( 'pado.themes' )->ingest_zip( $file, igbz()->tenancy()->id() );
		$args = [ 'tab' => self::TAB_DESIGN, 'msg' => $result['ok'] ? 'uploaded' : '', 'err' => $result['error'] ];
		wp_safe_redirect( Menu::url( self::SLUG, $args ) );
		exit;
	}

	public function handle_theme_preview(): void {
		Capabilities::require( Capabilities::MANAGE_PADO );
		check_admin_referer( self::NONCE_ACTION );
		$result = igbz()->get( 'pado.themes' )->install_preview( (int) ( $_GET['theme_id'] ?? 0 ) );
		wp_safe_redirect( Menu::url( self::SLUG, [ 'tab' => self::TAB_DESIGN, 'msg' => $result['ok'] ? 'previewed' : '', 'err' => $result['error'] ] ) );
		exit;
	}

	public function handle_theme_live(): void {
		Capabilities::require( Capabilities::MANAGE_PADO );
		check_admin_referer( self::NONCE_ACTION );
		$theme_id = (int) ( $_GET['theme_id'] ?? 0 );
		$this->approvals->submit( [
			'kind' => 'theme_apply',
			'title' => 'درخواست اعمال قالب برای فروشگاه',
			'reason' => 'اعمال قالب زنده فقط پس از تأیید صریح مدیر انجام می‌شود.',
			'payload' => [ 'theme_id' => $theme_id ],
			'impact' => ApprovalRequestService::IMPACT_HIGH,
		] );
		wp_safe_redirect( Menu::url( self::SLUG, [ 'tab' => self::TAB_APPROVALS, 'msg' => 'queued' ] ) );
		exit;
	}

	public function handle_theme_rollback(): void {
		Capabilities::require( Capabilities::MANAGE_PADO );
		check_admin_referer( self::NONCE_ACTION );
		$this->approvals->submit( [
			'kind' => 'theme_rollback',
			'title' => 'درخواست بازگشت قالب فروشگاه',
			'reason' => 'بازگشت قالب زنده نیز مانند اعمال آن نیازمند تأیید مدیر است.',
			'payload' => [ 'tenant_id' => igbz()->tenancy()->id() ],
			'impact' => ApprovalRequestService::IMPACT_HIGH,
		] );
		wp_safe_redirect( Menu::url( self::SLUG, [ 'tab' => self::TAB_APPROVALS, 'msg' => 'queued' ] ) );
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

		$gateway = igbz()->get( 'pado.gateway' );
		$remote  = $gateway->submit(
			'theme_design',
			[
				'tenant_id' => igbz()->tenancy()->id(),
				'brief'     => $brief,
			]
		);
		$brief['gateway_job_id'] = $remote['job_id'];
		$gateway_note = $remote['ok']
			? "شناسهٔ کار سرویس پادو: {$remote['job_id']}"
			: "فراخوانی سرویس پادو ناموفق بود: {$remote['error']}";

		// Persist a pending request even when the remote service is temporarily unavailable;
		// the failure is visible and can be retried rather than silently becoming a fake success.
		$this->approvals->submit( [
			'kind'    => 'theme_design',
			'title'   => sprintf( 'پیشنهاد طراحی قالب برای فروشگاه: %s', $brief['topic'] ?: '(بدون موضوع)' ),
			'reason'  => "پادو درخواست دارد یک پیشنهاد طراحی یک‌صفحه‌ای (پالت رنگ، فونت — وزیرمتن پیش‌فرض — چیدمان صفحه‌ها، سطح سه‌بعدی/عمق) برای فروشگاه شما آماده کند.\n\nموضوع: {$brief['topic']}\nمخاطب: {$brief['audience']}\nلحن: {$brief['tone']}\nکلمات کلیدی حس مطلوب: {$brief['keywords']}\nمرجع: {$brief['ref_site']}\nخط قرمزها: {$brief['redlines']}\n\n{$gateway_note}\nپس از تأیید، نتیجهٔ سرویس پادو (zip قالب فرزند FSE) باید از همین صف دریافت و پس از اعتبارسنجی وارد مرحلهٔ پیش‌نمایش شود.",
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

		// Approval is persisted separately from execution. For a completed design job, download,
		// validate and store the ZIP before allowing the row to become executed. A pending remote
		// job remains approved and can be retried without pretending that work was completed.
		$executor = null;
		$scope = current_user_can( Capabilities::MANAGE_TENANTS ) ? null : igbz()->tenancy()->id();

		// Phase 58 — the sensitive commercial kinds execute through the queue's
		// claim/complete contract; the page only supplies the human yes.
		$sensitive = [ 'price_change', 'payment_refund', 'bulk_product_delete' ];
		if ( 'approved' === $decision && $row && in_array( (string) $row['kind'], $sensitive, true ) ) {
			$ops     = igbz()->get( 'pado.ops' );
			$ops_row = $row;
			$executor = static function ( array $request ) use ( $ops, $ops_row ): bool {
				return $ops->run( 0 !== (int) ( $request['id'] ?? 0 ) ? $request : $ops_row );
			};
		}

		// Phase 59 — publishing, campaigns and policy changes ride the same contract.
		$content_ops = [
			'ig_publish_viral', 'ig_publish_trust', 'ig_publish_lifestyle', 'ig_publish_campaign',
			'campaign_send', 'policy_change',
		];
		if ( 'approved' === $decision && $row && in_array( (string) $row['kind'], $content_ops, true ) ) {
			$cops     = igbz()->get( 'pado.content_ops' );
			$cops_row = $row;
			$executor = static function ( array $request ) use ( $cops, $cops_row ): bool {
				return $cops->run( 0 !== (int) ( $request['id'] ?? 0 ) ? $request : $cops_row );
			};
		}
		$row = $this->approvals->get( $id, $scope );
		if ( 'approved' === $decision && $row && in_array( (string) $row['kind'], [ 'theme_apply', 'theme_rollback' ], true ) ) {
			$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
			$action_result = 'theme_apply' === $row['kind']
				? igbz()->get( 'pado.themes' )->activate_live( (int) ( $payload['theme_id'] ?? 0 ) )
				: igbz()->get( 'pado.themes' )->rollback( (int) ( $payload['tenant_id'] ?? igbz()->tenancy()->id() ) );
			$executor = static function ( array $request ) use ( $action_result ): bool { return (bool) $action_result['ok']; };
		}
		if ( 'approved' === $decision && $row && 'theme_design' === (string) $row['kind'] ) {
			$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
			$job_id  = is_array( $payload ) ? sanitize_text_field( (string) ( $payload['gateway_job_id'] ?? '' ) ) : '';
			if ( '' !== $job_id ) {
				$remote = igbz()->get( 'pado.gateway' )->status( $job_id );
				$remote_data = $remote['data'];
				$zip_url = (string) ( $remote_data['zip_url'] ?? $remote_data['result']['zip_url'] ?? '' );
				if ( '' !== $zip_url ) {
					$download = igbz()->get( 'pado.gateway' )->download( $zip_url );
					if ( $download['ok'] ) {
						$tmp = wp_tempnam( 'igbz-pado-theme.zip' );
						if ( $tmp && false !== file_put_contents( $tmp, $download['body'] ) ) {
							$ingested = igbz()->get( 'pado.themes' )->ingest_zip( [ 'tmp_name' => $tmp, 'name' => 'pado-' . $job_id . '.zip', 'error' => UPLOAD_ERR_OK ], igbz()->tenancy()->id(), $id );
							@unlink( $tmp );
							$executor = static function ( array $request ) use ( $ingested ): bool { return (bool) $ingested['ok']; };
						}
					}
				}
			}
		}
		// Phase 57: the page already required MANAGE_PADO above; that check is the proof
		// the queue's capability gate asks for, so hand it through explicitly.
		$ok = $this->approvals->decide(
			$id,
			'approved' === $decision ? ApprovalRequestService::STATUS_APPROVED : ApprovalRequestService::STATUS_REJECTED,
			get_current_user_id(),
			$note,
			$executor,
			$scope,
			true
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
