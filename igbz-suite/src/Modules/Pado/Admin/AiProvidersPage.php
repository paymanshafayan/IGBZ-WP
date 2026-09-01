<?php
namespace IGBZ\Suite\Modules\Pado\Admin;

use IGBZ\Suite\Modules\Pado\Ai\AiGateway;
use IGBZ\Suite\Modules\Pado\Ai\AiRequest;
use IGBZ\Suite\Modules\Pado\Ai\ProviderDefinition;
use IGBZ\Suite\Modules\Pado\Ai\Workload;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * «ارائه‌دهنده‌های هوش مصنوعی» — the provider/wiring plane of ADR-0005.
 *
 * Provider management is the domain of the central IGBZ control panel and the
 * senior administrator only (MANAGE_SUITE), never the tenant store panel. The
 * tenant owner keeps the Pado design wizard (PadoPage) but cannot touch which
 * provider, model or wire a section routes through, nor the panel key vault.
 *
 * The screen keeps the ratified two-level wizard: the key screen (two providers,
 * two key textboxes, one «ثبت کلیدها» button, one ⚙) and, behind the ⚙, the
 * routing/shared-switch panel plus the provider registry CRUD.
 */
final class AiProvidersPage {

	public const SLUG = 'igbz-ai-providers';
	public const NONCE_ACTION = 'igbz_ai_providers';

	private Settings $settings;

	public function __construct() {
		$this->settings = igbz()->settings();
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 30 );
		add_action( 'admin_post_igbz_ai_provider_save_keys', [ $this, 'handle_save_keys' ] );
		add_action( 'admin_post_igbz_ai_provider_save_routing', [ $this, 'handle_save_routing' ] );
		add_action( 'admin_post_igbz_ai_provider_save_provider', [ $this, 'handle_save_provider' ] );
		add_action( 'admin_post_igbz_ai_provider_delete_provider', [ $this, 'handle_delete_provider' ] );
		add_action( 'admin_post_igbz_ai_provider_test_connection', [ $this, 'handle_test_connection' ] );
	}

	public function add_page(): void {
		Menu::add(
			self::SLUG,
			__( 'AI providers', 'igbz-suite' ),
			[ $this, 'render' ],
			Capabilities::MANAGE_SUITE
		);
	}

	public function render(): void {
		Capabilities::require( Capabilities::MANAGE_SUITE );

		$notice = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$err = isset( $_GET['err'] ) ? sanitize_text_field( wp_unslash( $_GET['err'] ) ) : '';
		if ( 'keys_saved' === $msg ) {
			$notice = 'کلیدها با موفقیت در مخزن کلید پنل ثبت شدند.';
		} elseif ( 'routing_saved' === $msg ) {
			$notice = 'مسیریابی ارائه‌دهنده‌ها ذخیره شد.';
		} elseif ( 'provider_saved' === $msg ) {
			$notice = 'ارائه‌دهنده ذخیره شد.';
		} elseif ( 'provider_deleted' === $msg ) {
			$notice = 'ارائه‌دهنده حذف شد.';
		} elseif ( 'connected' === $msg ) {
			$notice = 'اتصال به ارائه‌دهنده برقرار است.';
		}
		if ( '' !== $err ) {
			$notice = $err;
		}

		View::open( __( 'AI providers', 'igbz-suite' ), 'مدیریت ارائه‌دهنده‌های هوش مصنوعی پادو — فقط در دسترس مدیر ارشد.' );
		if ( '' !== $notice ) {
			View::notice( $notice, '' !== $err ? 'error' : 'success' );
		}
		echo '<div class="igbz-card" dir="rtl" style="max-width:980px;text-align:right;">';
		$this->render_key_screen();
		$this->render_provider_panel();
		echo '</div>';
		View::close();
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
				$fallback   = $this->settings->string( 'pado.ai.default_provider', '' );
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
			<input type="hidden" name="action" value="igbz_ai_provider_save_keys">
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
		$edit_id = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$edit    = '' !== $edit_id ? ( $providers[ $edit_id ] ?? null ) : null;
		?>
		<div id="igbz-provider-panel" style="display:none;margin:12px 0 20px;padding:16px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;">

			<h3><?php esc_html_e( 'تغییر ارائه‌دهنده', 'igbz-suite' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="igbz_ai_provider_save_routing">
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
			<input type="hidden" name="action" value="igbz_ai_provider_save_provider">
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
				. '<a class="button" href="' . esc_url( Menu::url( self::SLUG, [ 'edit' => $id ] ) ) . '">' . esc_html__( 'ویرایش', 'igbz-suite' ) . '</a> ';
			if ( $routed ) {
				echo '<span class="description">' . esc_html__( 'در حال استفاده — قابل حذف نیست', 'igbz-suite' ) . '</span>';
			} else {
				$delete = wp_nonce_url( admin_url( 'admin-post.php?action=igbz_ai_provider_delete_provider&provider=' . rawurlencode( $id ) ), self::NONCE_ACTION );
				echo '<a class="button" href="' . esc_url( $delete ) . '" onclick="return confirm(\'' . esc_js( 'این ارائه‌دهنده حذف شود؟' ) . '\');">' . esc_html__( 'حذف', 'igbz-suite' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	// ---------------------------------------------------------- handler plane

	public function handle_save_keys(): void {
		Capabilities::require( Capabilities::MANAGE_SUITE );
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
			'msg' => $saved ? 'keys_saved' : '',
			'err' => $err,
		] ) );
		exit;
	}

	public function handle_save_routing(): void {
		Capabilities::require( Capabilities::MANAGE_SUITE );
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
			wp_safe_redirect( Menu::url( self::SLUG, [ 'msg' => 'routing_saved' ] ) );
			exit;
		}

		wp_safe_redirect( Menu::url( self::SLUG, [ 'err' => $err ] ) );
		exit;
	}

	public function handle_save_provider(): void {
		Capabilities::require( Capabilities::MANAGE_SUITE );
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
			wp_safe_redirect( Menu::url( self::SLUG, [ 'msg' => 'provider_saved' ] ) );
			exit;
		}

		wp_safe_redirect( Menu::url( self::SLUG, [ 'err' => $err ] ) );
		exit;
	}

	public function handle_delete_provider(): void {
		Capabilities::require( Capabilities::MANAGE_SUITE );
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
				wp_safe_redirect( Menu::url( self::SLUG, [ 'msg' => 'provider_deleted' ] ) );
				exit;
			}
		}

		wp_safe_redirect( Menu::url( self::SLUG, [ 'err' => $err ] ) );
		exit;
	}

	public function handle_test_connection(): void {
		Capabilities::require( Capabilities::MANAGE_SUITE );
		check_admin_referer( self::NONCE_ACTION );
		$id     = sanitize_key( (string) ( $_GET['provider'] ?? '' ) );
		$result = $this->probe_provider( $id );

		if ( $result['ok'] ) {
			wp_safe_redirect( Menu::url( self::SLUG, [ 'msg' => 'connected' ] ) );
		} else {
			wp_safe_redirect( Menu::url( self::SLUG, [ 'err' => 'اتصال برقرار نشد: ' . $result['error'] ] ) );
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
}
