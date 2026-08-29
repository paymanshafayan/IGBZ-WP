<?php
namespace IGBZ\Suite\Modules\Pado\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * The tool allowlist and its schemas (phase 56).
 *
 * A model may only call a tool that (a) exists here with a schema and (b) was named by
 * the Playbook for this run. The adapter intersects the two; anything else never reaches
 * the provider and is dropped from results. Tool *arguments* are validated against these
 * parameter shapes before a caller sees them — the model never gets the benefit of the
 * doubt.
 *
 * v1 tools are deliberately read-only or draft-shaped: real world effects belong to the
 * permission queue (phase 57+), not to the model.
 */
final class AiToolbox {

	/**
	 * name => parameter schema. Types: string|int|float|bool|array. `required` lists
	 * mandatory keys; extra keys are rejected.
	 *
	 * @var array<string,array{description:string,params:array<string,array{type:string,required:bool}>}>
	 */
	private const TOOLS = [
		'product_search'  => [
			'description' => 'Search the store\'s own product catalog. Read-only.',
			'params'      => [
				'query' => [ 'type' => 'string', 'required' => true ],
				'limit' => [ 'type' => 'int', 'required' => false ],
			],
		],
		'insight_read'    => [
			'description' => 'Read a stored account insight metric for the store. Read-only.',
			'params'      => [
				'metric' => [ 'type' => 'string', 'required' => true ],
				'days'   => [ 'type' => 'int', 'required' => false ],
			],
		],
		'competitor_read' => [
			'description' => 'Read the store\'s tracked competitors and snapshots. Read-only.',
			'params'      => [
				'handle' => [ 'type' => 'string', 'required' => false ],
			],
		],
		'content_draft'   => [
			'description' => 'Produce a draft caption/content proposal. Creates nothing by itself.',
			'params'      => [
				'caption' => [ 'type' => 'string', 'required' => true ],
				'kind'    => [ 'type' => 'string', 'required' => false ],
			],
		],
	];

	/** @return array<int,string> */
	public function allowlist(): array {
		return array_keys( self::TOOLS );
	}

	public function exists( string $name ): bool {
		return isset( self::TOOLS[ $name ] );
	}

	/**
	 * The OpenAI-style tool definitions for the allowlisted subset the Playbook named.
	 *
	 * @param array<int,string> $names
	 * @return array<int,array<string,mixed>>
	 */
	public function definitions( array $names ): array {
		$out = [];
		foreach ( array_unique( $names ) as $name ) {
			if ( ! $this->exists( $name ) ) {
				continue; // not allowlisted: the model never hears about it
			}
			$out[] = [
				'type'     => 'function',
				'function' => [
					'name'        => $name,
					'description' => self::TOOLS[ $name ]['description'],
					'parameters'  => $this->json_schema( $name ),
				],
			];
		}
		return $out;
	}

	/** @return array<string,mixed> */
	public function json_schema( string $name ): array {
		$spec    = self::TOOLS[ $name ] ?? null;
		$schema  = [ 'type' => 'object', 'properties' => new \stdClass(), 'required' => [], 'additionalProperties' => false ];
		if ( null === $spec ) {
			return $schema;
		}

		$properties = [];
		$required   = [];
		foreach ( $spec['params'] as $param => $meta ) {
			$properties[ $param ] = [ 'type' => $meta['type'] ];
			if ( $meta['required'] ) {
				$required[] = $param;
			}
		}

		return [
			'type'                 => 'object',
			'properties'           => $properties ?: new \stdClass(),
			'required'             => $required,
			'additionalProperties' => false,
		];
	}

	/**
	 * Validate tool-call arguments against the schema. Unknown tool, missing required
	 * key, extra key or wrong scalar type ⇒ false. This is the backend validator the
	 * ADR requires; the model's say-so is not evidence.
	 *
	 * @param array<string,mixed> $args
	 */
	public function valid_args( string $name, array $args ): bool {
		$spec = self::TOOLS[ $name ] ?? null;
		if ( null === $spec ) {
			return false;
		}

		foreach ( $spec['params'] as $param => $meta ) {
			if ( ! array_key_exists( $param, $args ) ) {
				if ( $meta['required'] ) {
					return false;
				}
				continue;
			}
			if ( ! $this->is_type( $args[ $param ], $meta['type'] ) ) {
				return false;
			}
		}

		foreach ( array_keys( $args ) as $key ) {
			if ( ! isset( $spec['params'][ $key ] ) ) {
				return false; // additionalProperties: false
			}
		}

		return true;
	}

	private function is_type( mixed $value, string $type ): bool {
		switch ( $type ) {
			case 'string':
				return is_string( $value );
			case 'int':
				return is_int( $value );
			case 'float':
				return is_float( $value ) || is_int( $value );
			case 'bool':
				return is_bool( $value );
			case 'array':
				return is_array( $value );
		}
		return false;
	}
}
