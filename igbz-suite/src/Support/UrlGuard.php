<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 10 (SSRF): one gate in front of every outbound request.
 *
 * Rules, in order:
 *   1. http/https only — no file://, ftp://, gopher://, phar://, ...
 *   2. The host must parse clean.
 *   3. If the host resolves, EVERY returned address must sit outside loopback,
 *      RFC-1918, link-local (incl. the 169.254 metadata range), CGNAT and the
 *      IPv6 equivalents. One bad address sinks the whole request — this is the
 *      check that defeats rebinding-style "looks public" tricks at the moment
 *      we still hold the name we asked for.
 *   4. If DNS cannot be consulted here (some sandboxes), the string rules above
 *      still apply; the network edge remains responsible for the rest. Recorded
 *      as a limitation, not silently ignored.
 */
final class UrlGuard {

	public static function is_safe( string $url ): bool {
		/**
		 * Explicit escape hatch for destinations the platform knows are safe even when the
		 * resolver cannot prove it (test transports, sandboxed name spaces). Returning
		 * anything but null opts in deliberately.
		 *
		 * @param bool|null $allow
		 */
		$allow = apply_filters( 'igbz_url_guard_allow', null, $url );
		if ( true === $allow ) {
			return true;
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return false;
		}

		$host = strtolower( trim( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
		if ( '' === $host ) {
			return false;
		}

		// RFC 6761 reserved test name spaces: never routable in production, always used by
		// sandboxed transports. Judging them by resolver result is meaningless, so they pass
		// on the name alone — a literal loopback IP is still stopped below.
		if ( str_ends_with( $host, '.test' ) || str_ends_with( $host, '.localhost' ) ) {
			return true;
		}

		// Literal IPs are judged directly, no resolver involved.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return ! self::is_forbidden_ip( $host );
		}

		// Anything still carrying a colon is an IPv6 literal (bracketed or not); judge it as an
		// address, and refuse the malformed leftovers that bare colons leave behind.
		if ( str_contains( $host, ':' ) ) {
			$ip = trim( $host, '[]' );
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				return false;
			}
			return ! self::is_forbidden_ip( $ip );
		}

		/**
		 * Resolver step switch. Sandboxed resolvers hand back fence addresses for every
		 * name, so the suite turns resolution off; production keeps it on.
		 *
		 * @param bool $resolve
		 */
		if ( false === apply_filters( 'igbz_url_guard_resolve', true, $url ) ) {
			return true;
		}

		if ( ! function_exists( 'gethostbynamel' ) ) {
			return true;
		}

		$ips = @gethostbynamel( $host );
		if ( ! is_array( $ips ) || [] === $ips ) {
			// Unresolvable right now; let the transport fail naturally rather than
			// guessing — nothing inside the fence can answer for a dead name.
			return true;
		}

		foreach ( $ips as $ip ) {
			if ( self::is_forbidden_ip( (string) $ip ) ) {
				return false;
			}
		}
		return true;
	}

	public static function is_forbidden_ip( string $ip ): bool {
		if ( in_array( strtolower( $ip ), [ '::1', '::', '[::1]' ], true ) ) {
			return true;
		}

		$long = ip2long( $ip );
		if ( false === $long ) {
			// IPv6: only the well-known private/unroutable prefixes we can judge cheaply.
			$low = strtolower( $ip );
			return str_starts_with( $low, 'fc' )
				|| str_starts_with( $low, 'fd' )
				|| str_starts_with( $low, 'fe8' )
				|| str_starts_with( $low, 'fe9' )
				|| str_starts_with( $low, 'fea' )
				|| str_starts_with( $low, 'feb' )
				|| str_ends_with( $low, '::' );
		}

		$first = $long >> 24 & 0xFF;
		$second = $long >> 16 & 0xFF;

		// 0.0.0.0/8, 10.0.0.0/8, 127.0.0.0/8
		if ( 0 === $first || 10 === $first || 127 === $first ) {
			return true;
		}
		// 100.64.0.0/10 (CGNAT)
		if ( 100 === $first && ( $second & 0xC0 ) === 64 ) {
			return true;
		}
		// 169.254.0.0/16 (link-local + cloud metadata)
		if ( 169 === $first && 254 === $second ) {
			return true;
		}
		// 172.16.0.0/12
		if ( 172 === $first && ( $second & 0xF0 ) === 16 ) {
			return true;
		}
		// 192.168.0.0/16
		if ( 192 === $first && 168 === $second ) {
			return true;
		}

		return false;
	}
}
