<?php
namespace IGBZ\Suite\Support\Release;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 73 — the release gate: after a deploy, is the new version actually
 * fit to serve? The answer comes from the product's own health endpoint
 * (phase 70), not from the container being up.
 *
 * Verdicts:
 *   green    — 200, ok:true, no drift. Release verified.
 *   degraded — 200 and serving, but schema drift is visible (dbv/table
 *              mismatch). Traffic stays; the gate reports it loudly because a
 *              deploy that ships drift is a rollback conversation (phase 70
 *              semantics: warning, not a traffic gate).
 *   red      — not 200 or ok:false. Do not announce the release; roll back.
 *
 * The probe is injectable so tests drive it without sockets, and `wp igbz
 * release verify` drives the same class in production.
 */
final class ReleaseGate {

	/**
	 * @param callable(string):array{code:int,body:string} $fetch
	 * @param array{tries?:int,sleep?:int} $opts
	 * @return array{ok:bool,state:string,attempts:int,last_code:int}
	 */
	public function verify( callable $fetch, string $url, array $opts = [] ): array {
		$tries = max( 1, (int) ( $opts['tries'] ?? 6 ) );
		$sleep = max( 0, (int) ( $opts['sleep'] ?? 5 ) );

		$state = 'red';
		$code  = 0;

		for ( $attempt = 1; $attempt <= $tries; ++$attempt ) {
			[ 'code' => $code, 'body' => $body ] = $fetch( $url );
			$state = self::classify( $code, (string) $body );

			if ( 'red' !== $state ) {
				break; // green/degraded verdicts never improve by waiting
			}
			if ( $sleep > 0 ) {
				sleep( $sleep );
			}
		}

		return [
			'ok'        => 'red' !== $state,
			'state'     => $state,
			'attempts'  => min( $attempt, $tries ),
			'last_code' => $code,
		];
	}

	private static function classify( int $code, string $body ): string {
		if ( 200 !== $code ) {
			return 'red';
		}
		$doc = json_decode( $body, true );
		$ok  = is_array( $doc ) && ( true === ( $doc['ok'] ?? null ) );
		if ( ! $ok ) {
			return 'red';
		}
		// degraded:true = 200 + serving + drift — a warning, not a traffic gate.
		// The health document nests it under `data`; the top level is tolerated
		// so a future document shape cannot silently defuse the warning.
		$degraded = $doc['data']['degraded'] ?? $doc['degraded'] ?? false;
		return ! $degraded ? 'green' : 'degraded';
	}
}
