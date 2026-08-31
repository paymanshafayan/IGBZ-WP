<?php
namespace IGBZ\Suite\Modules\Pado\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * The routing sections the provider-agnostic plane routes by capability.
 *
 * Two sections exist (decision of the employer, ADR-0005):
 *   - `routine`  («امور اداری») — default provider groq
 *   - `judgment` («مدیریت»)    — default provider openrouter
 *
 * These are the «دو بخش» of the «مسیریابی بارکاری پادو» panel. The machine keys are
 * the settings keys (`pado.ai.routing.*`); the Persian titles are what the panel
 * shows. The section list is data here, not scattered string literals across the
 * router and the admin page.
 */
final class Workload {

	public const ROUTINE  = 'routine';
	public const JUDGMENT = 'judgment';

	/** @var array<string,string> machine key => Persian title */
	public const SECTIONS = [
		self::ROUTINE  => 'امور اداری',
		self::JUDGMENT => 'مدیریت',
	];

	/** @var array<string,string> machine key => default provider id */
	public const DEFAULT_PROVIDER = [
		self::ROUTINE  => 'groq',
		self::JUDGMENT => 'openrouter',
	];

	/** @return array<int,string> */
	public static function keys(): array {
		return array_keys( self::SECTIONS );
	}

	public static function exists( string $section ): bool {
		return isset( self::SECTIONS[ $section ] );
	}

	public static function title( string $section ): string {
		return self::SECTIONS[ $section ] ?? '';
	}

	public static function default_provider( string $section ): string {
		return self::DEFAULT_PROVIDER[ $section ] ?? '';
	}
}
