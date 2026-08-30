<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Structured logger writing to a dedicated table (bounded) and, when WP_DEBUG_LOG is on,
 * to the PHP error log. Secrets are redacted before anything is persisted.
 */
final class Logger {

	public const DEBUG   = 'debug';
	public const INFO    = 'info';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	private const LEVELS = [ self::DEBUG => 10, self::INFO => 20, self::WARNING => 30, self::ERROR => 40 ];

	public function __construct( private Settings $settings ) {}

	public function debug( string $channel, string $message, array $context = [] ): void {
		$this->log( self::DEBUG, $channel, $message, $context );
	}

	public function info( string $channel, string $message, array $context = [] ): void {
		$this->log( self::INFO, $channel, $message, $context );
	}

	public function warning( string $channel, string $message, array $context = [] ): void {
		$this->log( self::WARNING, $channel, $message, $context );
	}

	public function error( string $channel, string $message, array $context = [] ): void {
		$this->log( self::ERROR, $channel, $message, $context );
	}

	public function log( string $level, string $channel, string $message, array $context = [] ): void {
		$min = self::LEVELS[ $this->settings->string( 'log.level', self::INFO ) ] ?? 20;
		if ( ( self::LEVELS[ $level ] ?? 20 ) < $min ) {
			return;
		}

		global $wpdb;
		// Phase 71: every line carries the request/job trace so support can correlate
		// a customer report, the request that served it and the jobs it spawned.
		if ( ! isset( $context['request_id'] ) ) {
			$context['request_id'] = Trace::id();
		}
		$context = self::redact( $context );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'igbz_logs',
			[
				'tenant_id'  => (int) ( $context['tenant_id'] ?? 0 ),
				'level'      => $level,
				'channel'    => $channel,
				'message'    => mb_substr( $message, 0, 1000 ),
				'context'    => wp_json_encode( $context, JSON_UNESCAPED_UNICODE ),
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( '[IGBZ][%s][%s][%s] %s %s', $level, $channel, (string) ( $context['request_id'] ?? '' ), $message, wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) ) ); // phpcs:ignore
		}
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public static function redact( array $context ): array {
		$needles = [ 'key', 'token', 'secret', 'password', 'authorization', 'merchant', 'signature' ];
		// Phase 13: PII is masked at ingestion — if it reaches the table it is already a
		// breach, so display-time redaction would be too late. Partial masks keep entries
		// correlatable without carrying the value itself.
		$pii = [ 'phone' => 'phone', 'mobile' => 'phone', 'email' => 'email', 'address' => 'text', 'national' => 'text', 'card' => 'text', 'postal' => 'text', 'birthdate' => 'text' ];
		foreach ( $context as $k => $v ) {
			$lower = strtolower( (string) $k );
			foreach ( $needles as $needle ) {
				if ( str_contains( $lower, $needle ) ) {
					$context[ $k ] = Crypto::MASK;
					continue 2;
				}
			}
			foreach ( $pii as $needle => $kind ) {
				if ( str_contains( $lower, $needle ) ) {
					$context[ $k ] = is_scalar( $v ) ? self::mask_pii( (string) $v, $kind ) : Crypto::MASK;
					continue 2;
				}
			}
			if ( is_array( $v ) ) {
				$context[ $k ] = self::redact( $v );
			}
		}
		return $context;
	}

	private static function mask_pii( string $value, string $kind ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( 'phone' === $kind ) {
			$digits = preg_replace( '/\D+/', '', $value ) ?? '';
			return strlen( $digits ) > 4 ? '***' . substr( $digits, -4 ) : '***';
		}
		if ( 'email' === $kind && str_contains( $value, '@' ) ) {
			[ $local, $domain ] = explode( '@', $value, 2 );
			return substr( $local, 0, 1 ) . '***@' . $domain;
		}
		return '[PII]';
	}

	/**
	 * Trim the log table to the configured retention window.
	 *
	 * Phase 20: retention runs in bounded, id-ordered batches — the audit table is the one
	 * table guaranteed to grow forever, and one unbounded delete on it could lock the site
	 * exactly when somebody is reading the audit trail.
	 */
	public function prune( int $days = 30 ): int {
		global $wpdb;
		$table   = $wpdb->prefix . 'igbz_logs';
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$batch   = 500;
		$deleted = 0;

		for ( $i = 0; $i < 200; ++$i ) {
			$affected = (int) $wpdb->query( // phpcs:ignore
				$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s ORDER BY id LIMIT %d", $cutoff, $batch ) // phpcs:ignore
			);
			if ( $affected <= 0 ) {
				break;
			}
			$deleted += $affected;
			if ( $affected < $batch ) {
				break;
			}
		}

		return $deleted;
	}
}
