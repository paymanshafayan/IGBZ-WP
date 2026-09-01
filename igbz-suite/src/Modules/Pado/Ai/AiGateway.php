<?php
namespace IGBZ\Suite\Modules\Pado\Ai;

use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The provider router (ADR-0005): maps a workload section to a provider, checks the
 * provider's declared capabilities against what the request needs, resolves the wire
 * adapter through the registry and runs it.
 *
 * Routing is a settings policy, not hardcoded logic. `pado.ai.routing.routine` /
 * `pado.ai.routing.judgment` hold the provider id per section, with groq / openrouter
 * as the defaults. When `pado.ai.shared` is on, «مدیریت» follows «امور اداری». A
 * section whose provider id no longer exists falls back to `pado.ai.default_provider`
 * once, then refuses honestly.
 */
final class AiGateway {

	public function __construct(
		private Settings $settings,
		private ProviderRegistry $registry
	) {}

	/** The provider id a section currently routes to (shared switch applied). */
	public function routing_id( string $section ): string {
		$section = $this->effective_section( $section );
		return $this->settings->string( 'pado.ai.routing.' . $section, Workload::default_provider( $section ) );
	}

	/** The definition a section routes to, after the one-shot default fallback. */
	public function definition_for( string $section ): ?ProviderDefinition {
		$id         = $this->routing_id( $section );
		$definition = $this->registry->get( $id );
		if ( null === $definition ) {
			$fallback = $this->settings->string( 'pado.ai.default_provider', '' );
			if ( '' !== $fallback && $fallback !== $id ) {
				$definition = $this->registry->get( $fallback );
			}
		}
		return $definition;
	}

	/** The model a section should use: an explicit override, else the provider default. */
	public function model_for( string $section ): string {
		$section  = $this->effective_section( $section );
		$override = $this->settings->string( 'pado.ai.model.' . $section, '' );
		if ( '' !== $override ) {
			return $override;
		}
		$definition = $this->definition_for( $section );
		return $definition ? $definition->default_model() : '';
	}

	/** The wire adapter a section resolves to; null when unrouted or unimplemented. */
	public function resolve( string $section ): ?AiProviderInterface {
		$definition = $this->definition_for( $section );
		return $definition ? $this->registry->adapter_for( $definition ) : null;
	}

	/** Run one request through the section's provider, gated on capabilities. */
	public function run( string $section, AiRequest $request ): AiResult {
		$definition = $this->definition_for( $section );
		if ( null === $definition ) {
			return AiResult::refused( 'provider_not_configured' );
		}
		$adapter = $this->registry->adapter_for( $definition );
		if ( null === $adapter ) {
			return AiResult::refused( 'protocol_unsupported' );
		}
		if ( $request->tools && ! in_array( 'tools', $adapter->capabilities(), true ) ) {
			return AiResult::refused( 'capability_unsupported' );
		}
		return $adapter->run( $request );
	}

	/** When the shared switch is on, every section routes through «امور اداری». */
	private function effective_section( string $section ): string {
		if ( Workload::exists( $section ) && Workload::ROUTINE !== $section && $this->settings->bool( 'pado.ai.shared' ) ) {
			return Workload::ROUTINE;
		}
		return $section;
	}
}
