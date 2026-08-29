<?php
/**
 * Phase 50 — the single-social-provider architecture guard (ADR-0004 §5-7).
 *
 * The deleted stack (Manus, ManyChat, ChatPlace, the DM fallbacks, the
 * session-based channels) must not come back. This test scans the source for
 * the integration identifiers of the removed architecture — class names,
 * container binding keys, settings keys, session markers, REST route
 * namespaces — so a regression fails the suite instead of shipping.
 *
 * Deliberately targeted at identifiers, not bare substrings: the legacy
 * schema columns (manychat_subscriber_id, provider columns, ...) survive as
 * data until offboarding, and they are not an integration.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Instagram\Gateways\SocialProviders;
use IGBZ\Suite\Support\Settings;

final class SocialArchitectureGuardTest extends TestCase {

	/** Class names that must not exist anywhere in the source. */
	private const FORBIDDEN_CLASSES = [
		'ManusClient',
		'ManusService',
		'ManusWebhook',
		'ManusSpeechToText',
		'PromptBuilder',
		'ManyChatClient',
		'ManyChatGateway',
		'ManyChatWebhook',
		'ManyChatBridge',
		'ChatPlaceClient',
		'ConfigurableDmGateway',
		'DirectMessenger',
		'ContentScheduler',
		'InsightsService',
		'FunnelService',
		'SubscriberService',
		'ProductIntakeService',
		'ProductPublisher',
		'IntakeWorker',
		'AccountCredentials',
		'GiveawayService',
		'HttpSpeechToText',
		'ProductIntakeController',
	];

	/** Container bindings the Instagram module must not declare. */
	private const FORBIDDEN_BINDINGS = [
		"ig.manus_client'",
		"ig.manus'",
		"ig.manychat'",
		"ig.manychat_bridge'",
		"ig.dm_manychat'",
		"ig.dm_custom'",
		"ig.dm'",
		"ig.stt'",
		"ig.scheduler'",
		"ig.intake'",
		"ig.intake_worker'",
		"ig.insights'",
		"ig.publisher'",
		"ig.subscribers'",
		"ig.funnels'",
		"ig.credentials'",
		"ig.giveaway'",
	];

	/** Settings key prefixes the secret registry must not contain. */
	private const FORBIDDEN_SETTING_PREFIXES = [
		'manus.',
		'manychat.',
		'chatplace.',
		'stt.',
		'dm.',
	];

	/** Session/cookie-based Instagram channel markers (ADR-0004 §7 ban). */
	private const SESSION_MARKERS = [
		'instagram_session',
		'ig_session',
		'agent_reach',
		'agentreach',
	];

	/** @var array<int,string> */
	private array $files = [];

	public function run(): void {
		$this->scan();

		$this->no_forbidden_classes();
		$this->no_forbidden_bindings();
		$this->no_forbidden_secret_registrations();
		$this->no_session_based_channels();
		$this->no_legacy_webhook_routes();
		$this->guard_forbids_the_removed_providers();
	}

	/** @return array<string,string> path => source */
	private function scan(): array {
		$root = __DIR__ . '/../src';
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$this->files[ $file->getPathname() ] = (string) file_get_contents( $file->getPathname() );
		}
		return $this->files;
	}

	private function no_forbidden_classes(): void {
		foreach ( self::FORBIDDEN_CLASSES as $class ) {
			foreach ( $this->files as $path => $source ) {
				$this->assert_false(
					str_contains( $source, $class ),
					"removed architecture identifier '$class' reappeared in " . basename( $path )
				);
			}
		}
	}

	private function no_forbidden_bindings(): void {
		foreach ( self::FORBIDDEN_BINDINGS as $binding ) {
			foreach ( $this->files as $path => $source ) {
				$this->assert_false(
					str_contains( $source, $binding ),
					"removed container binding '$binding' reappeared in " . basename( $path )
				);
			}
		}
	}

	private function no_forbidden_secret_registrations(): void {
		$reflection = new \ReflectionClass( Settings::class );
		$secrets    = $reflection->getConstant( 'SECRETS' );

		foreach ( $secrets as $key ) {
			foreach ( self::FORBIDDEN_SETTING_PREFIXES as $prefix ) {
				$this->assert_false(
					str_starts_with( (string) $key, $prefix ),
					"legacy provider secret '$key' is still registered — it must leave with its provider"
				);
			}
		}
	}

	private function no_session_based_channels(): void {
		foreach ( self::SESSION_MARKERS as $marker ) {
			foreach ( $this->files as $path => $source ) {
				// The guard's own forbidden list names them once, on purpose.
				if ( str_ends_with( $path, 'Gateways/SocialProviders.php' ) ) {
					continue;
				}
				$this->assert_false(
					str_contains( $source, $marker ),
					"session-based channel marker '$marker' reappeared in " . basename( $path )
				);
			}
		}
	}

	private function no_legacy_webhook_routes(): void {
		foreach ( [ "'/manychat/'", "'/manus/'", "'/chatplace/'" ] as $route ) {
			foreach ( $this->files as $path => $source ) {
				$this->assert_false(
					str_contains( $source, $route ),
					"legacy provider callback route $route reappeared in " . basename( $path )
				);
			}
		}
	}

	private function guard_forbids_the_removed_providers(): void {
		foreach ( [ 'manus', 'manychat', 'chatplace', 'ayrshare', 'agent_reach', 'instagram_session' ] as $provider ) {
			$this->assert_true( SocialProviders::is_forbidden( $provider ), "the guard explicitly forbids '$provider'" );
		}
		$this->assert_true( SocialProviders::is_allowed( SocialProviders::ZERNIO ), 'the guard allows the single provider' );
	}
}
