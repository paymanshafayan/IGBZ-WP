<?php
/**
 * Doubles that have to live inside a plugin namespace.
 *
 * PHP resolves an unqualified call to a function in the caller's own namespace before falling back
 * to the global one, which is how every other double in `bootstrap.php` works. That fallback does
 * not help for functions PHP already defines internally — `header()` is the one this suite needs.
 * The share page sends a `Content-Type` before printing, and under the CLI that both warns and
 * flushes the output buffer a test is using to capture the page, so the header call is intercepted
 * here instead. What was sent is recorded rather than dropped, so a test can assert on it.
 */

declare( strict_types=1 );

namespace IGBZ\Suite\Modules\Instagram\Vip;

function header( string $header, bool $replace = true, int $response_code = 0 ): void {
	$GLOBALS['igbz_test_headers'][] = $header;
}


namespace IGBZ\Suite\Modules\RestApi\Auth;

/**
 * The token payload reads the stored phone; the global harness has no usermeta
 * table, so the double answers from the test-scoped map (empty by default).
 */
function get_user_meta( int $user_id, string $key, bool $single = false ) {
	return $GLOBALS['igbz_test_usermeta'][ $user_id ][ $key ] ?? '';
}
