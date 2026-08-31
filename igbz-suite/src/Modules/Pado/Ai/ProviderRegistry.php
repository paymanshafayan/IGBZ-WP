<?php
namespace IGBZ\Suite\Modules\Pado\Ai;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The registry of `api_provider` records (ADR-0005).
 *
 * One serialized settings value (`pado.ai.providers`) holds every record; the registry
 * reads it into `ProviderDefinition`s and builds the wire adapter for a record's
 * `protocol`. Adding, removing or swapping a provider is a settings edit — never a
 * class change. When the option is empty the two seed defaults (groq, openrouter) are
 * served, so a fresh install has a working, honest starting point before anything is
 * written.
 */
final class ProviderRegistry {

	public const OPTION = 'pado.ai.providers';

	public function __construct(
		private Settings $settings,
		private Http $http,
		private Db $db,
		private Logger $logger,
		private AiToolbox $toolbox,
		private KeyVault $vault
	) {}

	/** @return array<string,ProviderDefinition> id => definition */
	public function all(): array {
		$out = [];
		foreach ( $this->records() as $record ) {
			$definition = ProviderDefinition::from_array( (array) $record );
			if ( '' !== $definition->id() ) {
				$out[ $definition->id() ] = $definition;
			}
		}
		return $out;
	}

	/** @return array<int,string> */
	public function ids(): array {
		return array_keys( $this->all() );
	}

	public function get( string $id ): ?ProviderDefinition {
		return $this->all()[ $id ] ?? null;
	}

	/** @return array<int,array<string,mixed>> */
	public function records(): array {
		$raw = $this->settings->string( self::OPTION, '' );
		if ( '' === $raw ) {
			return ProviderDefinition::seed_defaults();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : ProviderDefinition::seed_defaults();
	}

	/**
	 * Build the wire adapter for a definition. `null` is an honest refusal: the record
	 * names a dialect no adapter implements yet. The three ADR-0005 §7 dialects are
	 * openai, anthropic and custom; anything else is refused as protocol_unsupported.
	 */
	public function adapter_for( ProviderDefinition $definition ): ?AiProviderInterface {
		return match ( $definition->protocol() ) {
			ProviderDefinition::PROTOCOL_OPENAI => new OpenAiProtocolAdapter(
				$definition,
				$this->vault,
				$this->http,
				$this->db,
				$this->logger,
				$this->toolbox
			),
			ProviderDefinition::PROTOCOL_ANTHROPIC => new AnthropicProtocolAdapter(
				$definition,
				$this->vault,
				$this->http,
				$this->db,
				$this->logger,
				$this->toolbox
			),
			ProviderDefinition::PROTOCOL_CUSTOM => new CustomProtocolAdapter(
				$definition,
				$this->vault,
				$this->http,
				$this->db,
				$this->logger,
				$this->toolbox
			),
			default => null,
		};
	}

	/** Write the two seed defaults once, when the registry is still empty. */
	public function seed_defaults(): bool {
		return self::seed_into( $this->settings );
	}

	/**
	 * Static seeding used by the migration ladder (Activator::migrate_to_v49), where
	 * building the full registry (http/db/logger) is unnecessary work.
	 */
	public static function seed_into( Settings $settings ): bool {
		if ( '' !== $settings->string( self::OPTION, '' ) ) {
			return false;
		}
		$settings->set( self::OPTION, wp_json_encode( ProviderDefinition::seed_defaults() ) );
		return true;
	}

	/** @param array<int,array<string,mixed>> $records */
	public function save( array $records ): void {
		$this->settings->set( self::OPTION, wp_json_encode( array_values( $records ) ) );
	}

	/** Add or replace one record. */
	public function upsert( ProviderDefinition $definition ): void {
		$records = $this->records();
		$found   = false;
		foreach ( $records as $index => $record ) {
			if ( ProviderDefinition::from_array( (array) $record )->id() === $definition->id() ) {
				$records[ $index ] = $definition->to_array();
				$found             = true;
				break;
			}
		}
		if ( ! $found ) {
			$records[] = $definition->to_array();
		}
		$this->save( $records );
	}

	public function remove( string $id ): void {
		$records = array_filter(
			$this->records(),
			static fn ( array $record ): bool => ProviderDefinition::from_array( $record )->id() !== $id
		);
		$this->save( array_values( $records ) );
	}
}
