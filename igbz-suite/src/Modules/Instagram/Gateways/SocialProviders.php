<?php
namespace IGBZ\Suite\Modules\Instagram\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * The social-provider architecture guard (phase 50, ADR-0004 §5-7).
 *
 * Zernio is the project's only social provider. This is the one place the
 * allowed set lives, and every social entry point (the module container, the
 * tenant facade) must pass through assert_allowed() before touching a
 * provider. Adding a second online provider — or, worse, a session/cookie-
 * based Instagram channel such as the rejected Agent Reach proposal — is an
 * architecture change requiring a successor ADR, and this guard makes it
 * impossible to smuggle one in quietly.
 *
 * The forbidden list is not decoration: `SocialArchitectureGuardTest` scans
 * the source for these identifiers so a regression fails the suite instead of
 * shipping.
 */
final class SocialProviders {

	public const ZERNIO = 'zernio';

	/** The only permitted social provider (ADR-0004 §5). */
	private const ALLOWED = [ self::ZERNIO ];

	/**
	 * Providers that must never appear as an integration: every channel the
	 * architecture removed, plus session-based Instagram channels of any kind.
	 */
	private const FORBIDDEN = [
		// Removed online providers (ADR-0004 §6).
		'manus',
		'manychat',
		'chatplace',
		'ayrshare',
		'unipile',
		'phyllo',
		'insightiq',
		'buffer',
		'zapier',
		// Session/cookie based Instagram access — the rejected Agent Reach
		// channel and any future equivalent (ADR-0004 §7).
		'agent_reach',
		'agentreach',
		'instagram_session',
		'ig_session',
	];

	/** Whether a provider may be used for social operations. */
	public static function is_allowed( string $provider ): bool {
		return in_array( strtolower( trim( $provider ) ), self::ALLOWED, true );
	}

	/** Whether a provider is explicitly banned from the architecture. */
	public static function is_forbidden( string $provider ): bool {
		return in_array( strtolower( trim( $provider ) ), self::FORBIDDEN, true );
	}

	/**
	 * The guard every social entry point calls first. A provider outside the
	 * allowed set throws — silently degrading or registering a second channel
	 * would defeat the single-provider guarantee.
	 *
	 * @throws \DomainException when the provider is not allowed.
	 */
	public static function assert_allowed( string $provider ): void {
		if ( ! self::is_allowed( $provider ) ) {
			throw new \DomainException( 'social_provider_not_allowed' );
		}
	}
}
