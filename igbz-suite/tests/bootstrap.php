<?php
/**
 * A very small WordPress test double.
 *
 * The suite has no Composer tree and CI has no WordPress install, so instead of pulling in the
 * WordPress test library we stub the handful of core functions the pure-logic classes touch.
 * Only classes that do not talk to the database or the network are exercised here; anything that
 * does is covered by the health checks on the Status screen instead.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
// The share page prints its own <head>, so it needs the plugin's asset constants.
define( 'IGBZ_URL', 'https://example.test/wp-content/plugins/igbz-suite/' );
define( 'IGBZ_VERSION', '1.0.0' );
define( 'AUTH_KEY', 'test-auth-key-0123456789abcdefghijklmnop' );
define( 'SECURE_AUTH_SALT', 'test-secure-salt-0123456789abcdefghijkl' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'OBJECT', 'OBJECT' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['igbz_test_options'] = [];

function get_option( string $name, $default = false ) {
	return $GLOBALS['igbz_test_options'][ $name ] ?? $default;
}

function add_option( string $name, $value, $autoload = null ): bool {
	if ( array_key_exists( $name, $GLOBALS['igbz_test_options'] ) ) {
		return false;
	}
	$GLOBALS['igbz_test_options'][ $name ] = $value;
	return true;
}

function update_option( string $name, $value, $autoload = null ): bool {
	$GLOBALS['igbz_test_options'][ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	unset( $GLOBALS['igbz_test_options'][ $name ] );
	return true;
}

function wp_json_encode( $data, int $flags = 0 ) {
	return json_encode( $data, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

function home_url( string $path = '' ): string {
	return 'https://shop.test' . $path;
}

function site_url( string $path = '' ): string {
	return home_url( $path );
}

function rest_url( string $path = '' ): string {
	return home_url( '/wp-json/' . ltrim( $path, '/' ) );
}

function esc_url_raw( string $url ): string {
	return $url;
}

/**
 * Both core signatures: add_query_arg( $key, $value, $url ) and add_query_arg( array, $url ).
 */
function add_query_arg( ...$args ): string {
	if ( is_array( $args[0] ) ) {
		$pairs = $args[0];
		$url   = (string) ( $args[1] ?? '' );
	} else {
		$pairs = [ (string) $args[0] => (string) ( $args[1] ?? '' ) ];
		$url   = (string) ( $args[2] ?? '' );
	}

	foreach ( $pairs as $key => $value ) {
		$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . rawurlencode( (string) $value );
	}

	return $url;
}

function get_permalink( int $post_id = 0 ) {
	return 0 === $post_id ? false : 'https://shop.test/?p=' . $post_id;
}

function wp_schedule_single_event( int $timestamp, string $hook, array $args = [] ): bool {
	$GLOBALS['igbz_test_scheduled'][] = [ 'timestamp' => $timestamp, 'hook' => $hook, 'args' => $args ];
	return true;
}

function get_user_by( string $field, $value ) {
	return $GLOBALS['igbz_test_users'][ $field ][ (string) $value ] ?? false;
}

function get_users( array $args = [] ): array {
	return [];
}

function sanitize_email( string $value ): string {
	return trim( $value );
}

function is_email( string $value ): bool {
	return false !== filter_var( trim( $value ), FILTER_VALIDATE_EMAIL );
}

function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}

// ------------------------------------------------------------------------ HTTP

/**
 * Outbound HTTP double.
 *
 * Http::request() is the only door to the network, so stubbing wp_remote_request() here lets the
 * gateway clients (and everything layered on them) be exercised for real: the JSON envelope, the
 * ok/error mapping and the retry rules all run as they do in production.
 *
 * Tests push responses onto $GLOBALS['igbz_test_http']. A response carrying a `match` key is
 * served to the first request whose URL contains that fragment, whenever it arrives; responses
 * without one are consumed in order. Matching on the endpoint keeps a test from having to know
 * that, say, writing four custom fields costs four HTTP calls. Anything not queued gets a bland
 * provider-shaped success, so a test only describes the calls it actually cares about.
 *
 * @var array<int,array{status?:int,body?:string,error?:string,match?:string}>
 */
$GLOBALS['igbz_test_http']         = [];
$GLOBALS['igbz_test_http_requests'] = [];
$GLOBALS['igbz_test_scheduled']    = [];
$GLOBALS['igbz_test_users']        = [];
$GLOBALS['igbz_test_user_roles']   = [];

/** Queue one response for the next outbound request. */
function igbz_test_queue_http( array $response ): void {
	$GLOBALS['igbz_test_http'][] = $response;
}

class WP_Error {
	public function __construct( private string $message = '' ) {}

	public function get_error_message(): string {
		return $this->message;
	}
}

/** Minimal stand-in for the core request object; only the accessors the guard reads. */
class WP_REST_Request {
	public function __construct( private string $route = '/wc/v3/customers', private string $method = 'GET' ) {}

	public function get_route(): string {
		return $this->route;
	}

	public function get_method(): string {
		return $this->method;
	}
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

function wp_remote_request( string $url, array $args = [] ) {
	$GLOBALS['igbz_test_http_requests'][] = [
		'url'     => $url,
		'method'  => (string) ( $args['method'] ?? 'GET' ),
		'body'    => (string) ( $args['body'] ?? '' ),
		'headers' => (array) ( $args['headers'] ?? [] ),
	];

	$next = null;
	foreach ( $GLOBALS['igbz_test_http'] as $index => $queued ) {
		$fragment = (string) ( $queued['match'] ?? '' );
		if ( '' === $fragment ) {
			continue;
		}
		if ( str_contains( $url, $fragment ) ) {
			$next = $queued;
			unset( $GLOBALS['igbz_test_http'][ $index ] );
			$GLOBALS['igbz_test_http'] = array_values( $GLOBALS['igbz_test_http'] );
			break;
		}
	}

	if ( null === $next ) {
		// Skip past any endpoint-targeted responses still waiting for their own request.
		foreach ( $GLOBALS['igbz_test_http'] as $index => $queued ) {
			if ( '' === (string) ( $queued['match'] ?? '' ) ) {
				$next = $queued;
				unset( $GLOBALS['igbz_test_http'][ $index ] );
				$GLOBALS['igbz_test_http'] = array_values( $GLOBALS['igbz_test_http'] );
				break;
			}
		}
	}

	if ( is_array( $next ) && isset( $next['error'] ) ) {
		return new WP_Error( (string) $next['error'] );
	}

	return [
		'status' => (int) ( $next['status'] ?? 200 ),
		'body'   => (string) ( $next['body'] ?? '{"status":"success","data":{}}' ),
	];
}

function wp_remote_retrieve_response_code( $response ): int {
	return (int) ( $response['status'] ?? 0 );
}

function wp_remote_retrieve_body( $response ): string {
	return (string) ( $response['body'] ?? '' );
}

function wp_remote_retrieve_headers( $response ): object {
	return new class() {
		/** @return array<string,string> */
		public function getAll(): array {
			return [];
		}
	};
}

function sanitize_key( string $key ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) );
}

function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function absint( $value ): int {
	return abs( (int) $value );
}

function sanitize_textarea_field( string $value ): string {
	return trim( $value );
}

/**
 * A deliberately narrow stand-in for wp_kses_post().
 *
 * The real one allows post markup and strips everything else — most importantly form controls,
 * which is how a VIP share page once lost its buttons. Modelling that one rule keeps the double
 * honest about the thing that actually bit us, without dragging in the KSES tables.
 */
function wp_kses_post( string $value ): string {
	return (string) preg_replace( '#<(/?)(form|input|button|select|option|textarea|fieldset|legend|label)\b[^>]*>#i', '', $value );
}

function wp_strip_all_tags( string $value ): string {
	return trim( strip_tags( $value ) );
}

/** The signed-in user. Tests set $GLOBALS['igbz_test_user_id'] when the identity matters. */
function is_user_logged_in(): bool {
	return (int) ( $GLOBALS['igbz_test_user_id'] ?? 0 ) > 0;
}

function get_current_user_id(): int {
	return (int) ( $GLOBALS['igbz_test_user_id'] ?? 0 );
}

/**
 * Product categories, keyed by term id.
 *
 * @var array<int,object>
 */
$GLOBALS['igbz_test_terms'] = [];

function get_term( int $term_id, string $taxonomy = '' ) {
	unset( $taxonomy );
	return $GLOBALS['igbz_test_terms'][ $term_id ] ?? null;
}

function igbz_test_add_term( int $term_id, string $name ): void {
	$GLOBALS['igbz_test_terms'][ $term_id ] = (object) [ 'term_id' => $term_id, 'name' => $name ];
}

// ---------------------------------------------------------------------- VIP
//
// The VIP channel needs a handful of core helpers the rest of the suite never touched: an identity
// for the commenter, a clock the tests can move, and the two-line helpers WordPress provides for
// uploads and dates. `user_can` is the interesting one — VipAccessService treats anyone who can
// `manage_woocommerce` as the channel's owner, so a test that forgets to say who is asking would
// otherwise silently be asking as an administrator and see every locked post unlocked.

/** @var array<int,array<string,mixed>> user id => capabilities and profile */
$GLOBALS['igbz_test_capabilities'] = [];

function igbz_test_grant( int $user_id, string $capability ): void {
	$GLOBALS['igbz_test_capabilities'][ $user_id ][ $capability ] = true;
}

function user_can( $user, string $capability, ...$args ): bool {
	unset( $args );
	$user_id = is_object( $user ) ? (int) ( $user->ID ?? 0 ) : (int) $user;

	return ! empty( $GLOBALS['igbz_test_capabilities'][ $user_id ][ $capability ] );
}

function current_user_can( string $capability, ...$args ): bool {
	return user_can( get_current_user_id(), $capability, ...$args );
}

/**
 * Mirrors core semantics on a single site: a super admin is whoever holds `delete_users`.
 * Driven by the same capability table the other stubs use.
 */
function is_super_admin( $user_id = false ): bool {
	$id = $user_id ? (int) $user_id : get_current_user_id();
	return user_can( $id, 'delete_users' );
}

/** Tests assert against the thrown exception instead of a dead process. */
function wp_die( $message = '', $title = '', $args = [] ): void {
	throw new \RuntimeException( 'wp_die: ' . ( is_scalar( $message ) ? (string) $message : 'died' ) );
}

function get_transient( string $key ) {
	return $GLOBALS['igbz_test_transients'][ $key ] ?? false;
}

function set_transient( string $key, $value, int $ttl = 0 ): bool {
	$GLOBALS['igbz_test_transients'][ $key ] = $value;
	return true;
}

function wp_cache_get( $key, $group = '' ) {
	return $GLOBALS['igbz_test_cache'][ $group . ':' . $key ] ?? false;
}

function wp_cache_set( $key, $value, $group = '', $ttl = 0 ): bool {
	$GLOBALS['igbz_test_cache'][ $group . ':' . $key ] = $value;
	return true;
}

function wp_cache_delete( $key, $group = '' ): bool {
	unset( $GLOBALS['igbz_test_cache'][ $group . ':' . $key ] );
	return true;
}

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function check_admin_referer( string $action = '-1', string $query_arg = '_wpnonce' ): bool {
	$nonce = (string) ( $_POST[ $query_arg ] ?? $_GET[ $query_arg ] ?? '' );
	if ( ! wp_verify_nonce( $nonce, $action ) ) {
		throw new \RuntimeException( 'check_admin_referer: nonce failed' );
	}
	return true;
}

function get_userdata( int $user_id ) {
	if ( $user_id <= 0 ) {
		return false;
	}

	$user             = new class() {
		public int $ID = 0;
		public string $display_name = '';
		public string $user_email = '';
		public string $user_login = '';
		/** @var string[] */
		public array $roles = [];

		public function add_role( string $role ): void {
			if ( in_array( $role, $this->roles, true ) ) {
				return;
			}
			$this->roles[]                                  = $role;
			$GLOBALS['igbz_test_user_roles'][ $this->ID ][] = $role;
		}
	};
	$user->ID           = $user_id;
	$user->display_name = 'User ' . $user_id;
	$user->user_email   = 'user' . $user_id . '@shop.test';
	$user->user_login   = 'user' . $user_id;
	$user->roles        = $GLOBALS['igbz_test_user_roles'][ $user_id ] ?? [];

	return $user;
}

function get_avatar_url( $id_or_email, array $args = [] ): string {
	unset( $args );
	return 'https://shop.test/avatar/' . (string) $id_or_email;
}

function trailingslashit( string $value ): string {
	return rtrim( $value, "/\\" ) . '/';
}

function wp_get_upload_dir(): array {
	return [ 'basedir' => '/tmp/igbz-uploads', 'baseurl' => 'https://shop.test/wp-content/uploads' ];
}

function wp_upload_dir(): array {
	return wp_get_upload_dir();
}

function wp_mkdir_p( string $dir ): bool {
	$GLOBALS['igbz_test_dirs'][] = $dir;
	return true;
}

function path_is_absolute( string $path ): bool {
	return str_starts_with( $path, '/' );
}

function wp_delete_attachment( int $id, bool $force = false ) {
	unset( $force );
	unset( $GLOBALS['igbz_test_attachments'][ $id ] );
	return true;
}

function wp_delete_file_from_directory( string $file, string $directory ): bool {
	return str_starts_with( $file, $directory );
}

function human_time_diff( int $from, int $to = 0 ): string {
	$diff = abs( ( 0 === $to ? time() : $to ) - $from );
	return $diff < HOUR_IN_SECONDS
		? sprintf( '%d mins', max( 1, (int) round( $diff / MINUTE_IN_SECONDS ) ) )
		: sprintf( '%d days', max( 1, (int) round( $diff / DAY_IN_SECONDS ) ) );
}

/**
 * The share page sets a status and disables caching before it prints anything. Both are recorded
 * rather than ignored, so a test can assert that an expired share really answered 410.
 */
function status_header( int $code, string $description = '' ): void {
	$GLOBALS['igbz_test_status_header'] = $code;
}

function nocache_headers(): void {
	$GLOBALS['igbz_test_nocache'] = true;
}

function is_rtl(): bool {
	return (bool) ( $GLOBALS['igbz_test_rtl'] ?? false );
}

function wp_rand( int $min = 0, int $max = 0 ): int {
	return random_int( $min, 0 === $max ? PHP_INT_MAX : $max );
}

/**
 * Core's URL filter, modelled only as far as the scheme allow-list.
 *
 * That list is the whole point here: the share page's "Open in the app" button uses a custom
 * scheme, and core's esc_url() silently returns an empty string for a scheme it does not know —
 * which renders as a button that looks fine and goes nowhere.
 *
 * @param string[]|null $protocols
 */
function esc_url( string $url, ?array $protocols = null, string $context = 'display' ): string {
	unset( $context );
	$url       = trim( $url );
	$protocols = $protocols ?? wp_allowed_protocols();

	if ( '' === $url || str_starts_with( $url, '/' ) || str_starts_with( $url, '#' ) ) {
		return $url;
	}

	$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	if ( '' === $scheme ) {
		return $url;
	}

	return in_array( $scheme, $protocols, true ) ? $url : '';
}

/** @return string[] */
function wp_allowed_protocols(): array {
	return [ 'http', 'https', 'mailto', 'tel' ];
}

function sanitize_title( string $title ): string {
	$slug = strtolower( trim( preg_replace( '/[^A-Za-z0-9\-]+/', '-', $title ) ?? '', '-' ) );
	return $slug;
}

// -------------------------------------------------------------------- media
//
// The intake pipeline copies every asset the assistant produces into the media library,
// because a remote attachment URL expires and a product image that 404s a fortnight later is
// worse than no automation at all. There is no filesystem or HTTP here, so the sideload is doubled: by default
// it "succeeds" and hands back a local-looking URL. Setting $GLOBALS['igbz_test_sideload_fails']
// exercises the other branch, where the remote URL is kept rather than the registration failing.

$GLOBALS['igbz_test_sideload_fails'] = false;
$GLOBALS['igbz_test_attachments']    = [];

function media_handle_sideload( array $file, int $post_id = 0, $desc = null, array $post_data = [] ) {
	unset( $post_id, $desc, $post_data );

	if ( $GLOBALS['igbz_test_sideload_fails'] ) {
		return new WP_Error( 'sideload failed' );
	}

	$id = count( $GLOBALS['igbz_test_attachments'] ) + 900;

	$GLOBALS['igbz_test_attachments'][ $id ] = 'https://shop.test/wp-content/uploads/' . (string) ( $file['name'] ?? 'file' );

	return $id;
}

function download_url( string $url, int $timeout = 300 ) {
	unset( $timeout );

	return $GLOBALS['igbz_test_sideload_fails'] ? new WP_Error( 'download failed' ) : '/tmp/igbz-' . md5( $url );
}

function wp_get_attachment_url( int $attachment_id ) {
	return $GLOBALS['igbz_test_attachments'][ $attachment_id ] ?? false;
}

function wp_delete_file( string $path ): void {
	unset( $path );
}

function wp_check_filetype( string $filename, $mimes = null ): array {
	unset( $mimes );

	$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
	$known     = [
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'mp4'  => 'video/mp4',
		'm4a'  => 'audio/m4a',
		'mp3'  => 'audio/mpeg',
		'wav'  => 'audio/wav',
		'ogg'  => 'audio/ogg',
	];

	return [ 'ext' => $extension, 'type' => $known[ $extension ] ?? '' ];
}

function wp_parse_args( $args, array $defaults = [] ): array {
	return array_merge( $defaults, is_array( $args ) ? $args : [] );
}

/**
 * Minimal filter dispatch.
 *
 * Was a no-op returning $value untouched, which quietly made every filter-based behaviour
 * untestable — a test could register a filter, see no effect, and still pass. Callbacks now run in
 * registration order, which is enough to assert that a value is filterable.
 */
function apply_filters( string $hook, $value, ...$rest ) {
	foreach ( $GLOBALS['igbz_test_filters'][ $hook ] ?? [] as $callback ) {
		$value = $callback( $value, ...$rest );
	}
	return $value;
}

/**
 * Minimal action dispatch.
 *
 * Priority and accepted-arg counts are not modelled: callbacks run in registration order and
 * receive every argument. That is enough to assert that a hook fired with the right payload, which
 * is the only thing the suite uses actions for.
 */
function do_action( string $hook, ...$args ): void {
	foreach ( $GLOBALS['igbz_test_actions'][ $hook ] ?? [] as $callback ) {
		$callback( ...$args );
	}
}

function add_action( string $hook, $callback, int $priority = 10, int $accepted = 1 ): bool {
	$GLOBALS['igbz_test_actions'][ $hook ][] = $callback;
	return true;
}

/** Drop every registered callback, so one test's listener cannot fire during the next. */
function igbz_test_reset_actions(): void {
	$GLOBALS['igbz_test_actions'] = [];
}

function add_filter( string $hook, $callback, int $priority = 10, int $accepted = 1 ): bool {
	$GLOBALS['igbz_test_filters'][ $hook ][] = $callback;
	return true;
}

function remove_filter( string $hook, $callback, int $priority = 10 ): bool {
	foreach ( $GLOBALS['igbz_test_filters'][ $hook ] ?? [] as $index => $registered ) {
		if ( $registered === $callback ) {
			unset( $GLOBALS['igbz_test_filters'][ $hook ][ $index ] );
			return true;
		}
	}
	return false;
}

/** Drop every registered filter, so one test's filter cannot leak into the next. */
function igbz_test_reset_filters(): void {
	$GLOBALS['igbz_test_filters'] = [];
}

/**
 * Number of times each action has "fired". Tests set this directly to simulate a point in the
 * WordPress request lifecycle — see CronScheduleTest, which checks that translation is deferred
 * until `init`.
 *
 * @var array<string,int>
 */
$GLOBALS['igbz_test_did_action'] = [];
$GLOBALS['igbz_test_actions']    = [];
$GLOBALS['igbz_test_filters']    = [];

function did_action( string $hook ): int {
	return (int) ( $GLOBALS['igbz_test_did_action'][ $hook ] ?? 0 );
}

/**
 * Records a call to __() so tests can assert that translation did not happen too early.
 *
 * @var array<int,string>
 */
$GLOBALS['igbz_test_translated'] = [];

function __( string $text, string $domain = '' ): string {
	$GLOBALS['igbz_test_translated'][] = $text;
	return $text;
}

function esc_like( string $text ): string {
	return addcslashes( $text, '_%\\' );
}

function esc_html__( string $text, string $domain = '' ): string {
	return $text;
}

function esc_attr__( string $text, string $domain = '' ): string {
	return $text;
}

function esc_html_e( string $text, string $domain = '' ): void {
	echo $text;
}

function checked( $checked, $current = true, bool $display = true ): string {
	$out = (string) $checked === (string) $current ? " checked='checked'" : '';
	if ( $display ) {
		echo $out;
	}
	return $out;
}

function wp_create_nonce( string $action = '-1' ): string {
	return 'nonce-' . md5( $action );
}

function wp_verify_nonce( string $nonce, string $action = '-1' ): bool {
	return $nonce === wp_create_nonce( $action );
}

function wp_nonce_field( string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $display = true ): string {
	$field = sprintf( '<input type="hidden" name="%s" value="%s" />', $name, wp_create_nonce( $action ) );
	if ( $display ) {
		echo $field;
	}
	return $field;
}

function wp_login_url( string $redirect = '' ): string {
	return 'https://example.test/wp-login.php' . ( '' === $redirect ? '' : '?redirect_to=' . rawurlencode( $redirect ) );
}

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function _n( string $single, string $plural, int $number, string $domain = '' ): string {
	return 1 === $number ? $single : $plural;
}

function current_time( string $type = 'mysql', $gmt = 0 ): string {
	return gmdate( 'Y-m-d H:i:s' );
}

function wp_generate_uuid4(): string {
	return sprintf(
		'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
		random_int( 0, 0xffff ),
		random_int( 0, 0xffff ),
		random_int( 0, 0xffff ),
		random_int( 0, 0x0fff ) | 0x4000,
		random_int( 0, 0x3fff ) | 0x8000,
		random_int( 0, 0xffff ),
		random_int( 0, 0xffff ),
		random_int( 0, 0xffff )
	);
}

/** Just enough of $wpdb for Schema to build its statements. */
/**
 * Named `wpdb` so that Db::wpdb()'s `: \wpdb` return type is satisfied. Real WordPress declares
 * this class in wp-includes/wp-db.php, which is not loaded here.
 */
class wpdb {
	public string $prefix = 'wp_';

	public string $posts = 'wp_posts';

	public string $postmeta = 'wp_postmeta';

	/** Every query passed to query(), so tests can assert on generated SQL. */
	public array $queries = [];

	public int $insert_id = 0;

	public string $last_error = '';

	/** When true, query() reports a driver-level rejection (as $wpdb does) instead of succeeding. */
	public bool $fail_query = false;

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( string $query, ...$args ): string {
		$query = str_replace( [ '%d', '%f' ], '%s', $query );
		return vsprintf( $query, array_map( static fn ( $a ): string => "'" . $a . "'", $args ) );
	}

	public function query( string $sql ): int|bool {
		$this->queries[] = $sql;

		// The real $wpdb returns false when the driver rejects a statement.
		if ( $this->fail_query ) {
			return false;
		}

		// Affected-row counts matter for conditional UPDATEs, where 0 rows is how the database
		// reports "somebody else got there first".
		if ( $this->next_affected ) {
			return (int) array_shift( $this->next_affected );
		}

		return 1;
	}

	public function last_query(): string {
		return $this->queries ? $this->queries[ count( $this->queries ) - 1 ] : '';
	}

	/**
	 * Column-name => format map, mirroring the real wpdb::$field_types.
	 *
	 * Only the entries that collide with IGBZ column names are modelled. `post_id` is the dangerous
	 * one: core forces it to %d even on a VARCHAR column in a plugin table.
	 *
	 * @var array<string,string>
	 */
	public array $field_types = [
		'post_id' => '%d',
		'user_id' => '%d',
		'ID'      => '%d',
	];

	/** Records the [$data, $formats] of the last write, so tests can assert on the formats. */
	public array $last_write = [];

	/** @var array<int,array<string,mixed>> Every write in order; last_write is just the tail. */
	public array $writes = [];

	/**
	 * Rows handed back by get_row()/get_results()/get_var(), newest first.
	 *
	 * Tests that exercise read paths push the rows they expect the code under test to see; the
	 * double does no SQL parsing, so ordering is the test's responsibility.
	 *
	 * @var array<int,mixed>
	 */
	public array $next_results = [];

	/** @var array<int,int> Queued affected-row counts for query(). */
	public array $next_affected = [];

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;
		$next            = array_shift( $this->next_results );

		if ( is_array( $next ) && $next && isset( $next[0] ) && is_array( $next[0] ) ) {
			return $next[0];
		}

		return is_array( $next ) ? $next : null;
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;
		$next            = array_shift( $this->next_results );

		return is_array( $next ) ? $next : [];
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		return array_shift( $this->next_results );
	}

	public function get_col( string $sql ) {
		$this->queries[] = $sql;
		$next            = array_shift( $this->next_results );

		return is_array( $next ) ? $next : [];
	}

	/**
	 * Mirrors wpdb::insert(), including the name-based format guessing applied when $format is
	 * omitted — that guess is exactly what silently cast ig_funnels.post_id to 0.
	 */
	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->last_write = [
			'table'   => $table,
			'data'    => $data,
			'formats' => $format ?? $this->guess_formats( $data ),
			'guessed' => null === $format,
		];
		$this->writes[]   = $this->last_write;

		$this->queries[] = 'INSERT INTO ' . $table;
		++$this->insert_id;

		return $this->fail_query ? false : 1;
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->last_write = [
			'table'   => $table,
			'data'    => $data,
			'formats' => $format ?? $this->guess_formats( $data ),
			'guessed' => null === $format,
		];
		$this->writes[]   = $this->last_write;

		$this->queries[] = 'UPDATE ' . $table;

		return $this->fail_query ? false : 1;
	}

	public function delete( string $table, array $where, $where_format = null ): int|bool {
		$this->last_write = [
			'table'   => $table,
			'data'    => $where,
			'formats' => $where_format ?? $this->guess_formats( $where ),
			'guessed' => null === $where_format,
		];
		$this->writes[]   = $this->last_write;

		$this->queries[] = 'DELETE FROM ' . $table;

		return $this->fail_query ? false : 1;
	}

	/** @return string[] */
	private function guess_formats( array $data ): array {
		$out = [];

		foreach ( $data as $column => $value ) {
			$out[] = $this->field_types[ $column ] ?? '%s';
		}

		return $out;
	}
}

$GLOBALS['wpdb'] = new wpdb();

require_once dirname( __DIR__ ) . '/src/Support/Autoloader.php';
\IGBZ\Suite\Support\Autoloader::register( 'IGBZ\\Suite\\', dirname( __DIR__ ) . '/src' );

// Doubles that must be declared inside a plugin namespace to win over a PHP built-in.
require_once __DIR__ . '/doubles-namespaced.php';

function get_bloginfo( string $show = '' ): string {
	return 'IGBZ Test Store';
}

function wp_timezone_string(): string {
	return 'Asia/Tehran';
}

function wp_timezone(): DateTimeZone {
	return new DateTimeZone( wp_timezone_string() );
}

function number_format_i18n( $number, int $decimals = 0 ): string {
	return number_format( (float) $number, $decimals );
}

function wp_date( string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null ) {
	$date = new DateTimeImmutable( '@' . ( $timestamp ?? time() ) );
	return $date->setTimezone( $timezone ?? wp_timezone() )->format( $format );
}

function is_admin(): bool {
	return false;
}

/** @return array<string,array<string,mixed>> slug => theme header */
function wp_get_themes(): array {
	return $GLOBALS['igbz_test_themes'] ?? [];
}

function get_stylesheet(): string {
	return (string) ( $GLOBALS['igbz_test_stylesheet'] ?? 'twentytwentysix' );
}

function wp_next_scheduled( string $hook ) {
	return false;
}

function igbz(): \IGBZ\Suite\Support\Plugin {
	return \IGBZ\Suite\Support\Plugin::instance();
}

// Boot the container without the WordPress hook side effects that boot() would add.
( function (): void {
	$plugin     = \IGBZ\Suite\Support\Plugin::instance();
	$reflection = new ReflectionMethod( $plugin, 'register_core_services' );
	$reflection->invoke( $plugin );
} )();

/**
 * Wipe the stored options and hand back a clean Settings instance that is also the one the
 * container (and therefore every service reached through igbz()) will use.
 */
function igbz_test_reset_settings(): \IGBZ\Suite\Support\Settings {
	$GLOBALS['igbz_test_options']       = [];
	$GLOBALS['igbz_test_http']          = [];
	$GLOBALS['igbz_test_http_requests'] = [];
	$GLOBALS['igbz_test_scheduled']     = [];
	$GLOBALS['igbz_test_users']         = [];
	$GLOBALS['igbz_test_user_roles']    = [];
	$GLOBALS['igbz_test_terms']          = [];
	$GLOBALS['igbz_test_user_id']        = 0;
	$GLOBALS['igbz_test_sideload_fails'] = false;
	$GLOBALS['igbz_test_attachments']    = [];
	$GLOBALS['igbz_test_capabilities']   = [];
	$GLOBALS['igbz_test_headers']        = [];
	$GLOBALS['igbz_test_cache']          = [];
	igbz_test_reset_actions();
	igbz()->bind( 'settings', static fn () => new \IGBZ\Suite\Support\Settings() );
	igbz()->bind( 'logger', static fn ( $c ) => new \IGBZ\Suite\Support\Logger( $c->get( 'settings' ) ) );
	return igbz()->settings();
	$GLOBALS['igbz_test_cache'] = [];
}

// Phase 10: the sandbox resolver returns private-range addresses for every name, which
// would trip the SSRF guard on all fake endpoints. The guard's own logic is covered by
// UrlGuardTest with literal IPs; live resolution stays a production concern.
add_filter(
	'igbz_url_guard_resolve',
	static fn () => false,
	10,
	0
);
