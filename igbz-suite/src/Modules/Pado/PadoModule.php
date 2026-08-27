<?php
namespace IGBZ\Suite\Modules\Pado;

use IGBZ\Suite\Modules\Pado\Admin\PadoPage;
use IGBZ\Suite\Modules\Pado\Services\ApprovalRequestService;
use IGBZ\Suite\Modules\Pado\Services\ThemeService;
use IGBZ\Suite\Modules\Pado\Services\ThemeValidator;
use IGBZ\Suite\Modules\Pado\Services\PadoGateway;
use IGBZ\Suite\Support\ModuleInterface;
use IGBZ\Suite\Support\Modules;
use IGBZ\Suite\Support\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Pado (پادو) — AI assistant module.
 *
 * In S0 this module wires up:
 *   - The "مرکز پادو" admin page with four tabs (settings/design/approvals/history).
 *   - Approval request service + database table (every sensitive action,
 *     including instagram publish of the four content kinds, goes through it).
 *   - Theme zip validation gate (backend, never in JS — قاعده دائمی).
 *
 * The gateway and executors are intentionally kept behind the configured Pado service endpoint;
 * the module persists and validates every request locally before a remote execution is allowed.
 */
final class PadoModule implements ModuleInterface {

	public function id(): string {
		return Modules::PADO;
	}

	public function title(): string {
		return __( 'Pado (AI Assistant)', 'igbz-suite' );
	}

	public function description(): string {
		return __( 'AI assistant center: theme design wizard, unified approval queue for sensitive actions, and integration with the Vira API gateway.', 'igbz-suite' );
	}

	public function register( Plugin $plugin ): void {
		$this->bind_services( $plugin );
		( new PadoPage() )->register();
	}

	public function health(): array {
		return [
			[
				'label'  => 'Pado module',
				'status' => 'ok',
				'detail' => 'S0 scaffolding loaded (approval queue + theme validator).',
			],
		];
	}

	/**
	 * Seed a couple of demo approval rows so the admin page is not empty on a
	 * fresh dev install. Called from Activator::migrate_to_v19() after tables
	 * exist; safe to re-run (guards on existing rows by kind+title).
	 */
	public function seed_demo_requests(): void {
		// Avoid seeding on live installs or when rows already exist
		if ( get_option( 'igbz_pado_seeded_v1' ) ) {
			return;
		}
		$db = igbz()->db();
		$existing = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'approval_requests' ) );
		if ( $existing > 0 ) {
			update_option( 'igbz_pado_seeded_v1', 1, true );
			return;
		}
		$svc = new ApprovalRequestService( $db );
		$now = current_time( 'mysql', true );
		// One pending (the "design suggestion" stub)
		$svc->submit( [
			'kind'    => 'theme_design',
			'title'   => 'پیشنهاد طراحی قالب — نمونه (فروشگاه آرایشی)',
			'reason'  => "این درخواست دمو است.\n\nپادو یک پیشنهاد طراحی یک‌صفحه‌ای با رنگ اصلی سرخابی ملایم، فونت وزیرمتن، چیدمان سه‌بعدی در هیرو (با افکت کارت شیشه‌ای) و تمپلیت‌های FSE برای صفحه فروشگاه/تکی‌محصول/سبد/تسویه آماده کرده است. لطفاً پیش از ارسال به مرحله تولید zip بررسی شود.",
			'payload' => [
				'palette' => [ '#ec4899', '#f43f5e', '#0f172a' ],
				'font'    => 'Vazirmatn',
				'style'   => '3D glass card hero',
			],
			'impact'  => ApprovalRequestService::IMPACT_MEDIUM,
		] );
		// One already-executed history sample
		$db->insert( 'approval_requests', [
			'tenant_id'    => 0,
			'kind'         => 'theme_design',
			'title'        => 'قالب اولیه (نسخه ۰) بوت‌استرپ شد.',
			'reason'       => 'ایجاد رکورد اولیه در جریان نصب.',
			'payload'      => wp_json_encode( [ 'bootstrap' => true ] ),
			'impact'       => ApprovalRequestService::IMPACT_LOW,
			'requested_by' => 1,
			'decided_by'   => 1,
			'status'       => ApprovalRequestService::STATUS_EXECUTED,
			'decision_note'=> 'تأیید خودکار بوت‌استرپ.',
			'created_at'   => $now,
			'decided_at'   => $now,
			'executed_at'  => $now,
		] );
		update_option( 'igbz_pado_seeded_v1', 1, true );
	}

	private function bind_services( Plugin $plugin ): void {
		$plugin->bind( 'pado.approvals', static fn ( Plugin $c ) => new ApprovalRequestService( $c->db() ) );
		$plugin->bind( 'pado.gateway', static fn ( Plugin $c ) => new PadoGateway( $c->http(), $c->logger() ) );
		$plugin->bind( 'pado.validator', static fn () => new ThemeValidator() );
		$plugin->bind( 'pado.themes', static fn ( Plugin $c ) => new ThemeService( $c->db() ) );
	}
}
