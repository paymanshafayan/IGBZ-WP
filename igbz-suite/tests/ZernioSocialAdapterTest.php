<?php
/**
 * Phase 50 — the formal Zernio adapter (ADR-0004 §5).
 *
 * The client is config-driven (base URL + paths from settings, docs defaults),
 * the profile plane authenticates with the central key, the social plane with
 * the store's own profile-scoped key plus an idempotency key on creates, and
 * the tenant facade enforces guard → backend mapping → key in that order.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Instagram\Gateways\SocialProviders;
use IGBZ\Suite\Modules\Instagram\Gateways\ZernioAdapterInterface;
use IGBZ\Suite\Modules\Instagram\Gateways\ZernioClient;
use IGBZ\Suite\Modules\Instagram\Services\ZernioConnectionService;
use IGBZ\Suite\Modules\Instagram\Services\ZernioSocialService;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;

/** Records the social-plane calls the facade forwards. */
final class SocialPlaneRecorder implements ZernioAdapterInterface {

	/** @var array<int,array{key:string,account_id:string,content:array}> */
	public array $published = [];

	/** @var array<int,string> Keys seen on any social call. */
	public array $keys = [];

	public function __construct( public bool $configured = true ) {}

	public function is_configured(): bool {
		return $this->configured;
	}

	public function create_profile( string $store_slug ): array {
		return [ 'ok' => true, 'profile_id' => 'prof-' . $store_slug, 'error' => '' ];
	}

	public function issue_profile_key( string $profile_id ): array {
		return [ 'ok' => true, 'key' => 'key-' . $profile_id, 'key_id' => 'kid-' . $profile_id, 'error' => '' ];
	}

	public function revoke_profile_key( string $key_id ): array {
		return [ 'ok' => true, 'error' => '' ];
	}

	public function start_connect( string $profile_id ): array {
		return [ 'ok' => true, 'auth_url' => 'https://connect.zernio.test/' . $profile_id, 'error' => '' ];
	}

	public function list_accounts( string $profile_id ): array {
		return [ 'ok' => true, 'accounts' => [ [ 'account_id' => 'acct-1', 'platform' => 'instagram', 'username' => 'x' ] ], 'error' => '' ];
	}

	public function delete_profile( string $profile_id ): array {
		return [ 'ok' => true, 'error' => '' ];
	}

	public function publish_content( string $key, string $account_id, array $content ): array {
		$this->keys[] = $key;
		$this->published[] = [ 'key' => $key, 'account_id' => $account_id, 'content' => $content ];

		return [ 'ok' => true, 'post_id' => 'post-1', 'error' => '' ];
	}

	public function get_post( string $key, string $post_id ): array {
		$this->keys[] = $key;

		return [ 'ok' => true, 'status' => 'published', 'permalink' => 'https://instagram.com/p/x', 'media_id' => 'm1', 'error' => '' ];
	}

	public function retry_post( string $key, string $post_id ): array {
		$this->keys[] = $key;

		return [ 'ok' => true, 'error' => '' ];
	}

	public function send_direct_message( string $key, string $account_id, string $recipient_id, array $message ): array {
		$this->keys[] = $key;

		return [ 'ok' => true, 'message_id' => 'msg-1', 'error' => '' ];
	}

	public function send_story_reply( string $key, string $account_id, string $story_id, string $recipient_id, string $text ): array {
		$this->keys[] = $key;

		return [ 'ok' => true, 'message_id' => 'msg-2', 'error' => '' ];
	}

	public function get_inbox( string $key, string $kind, string $cursor = '', int $limit = 50 ): array {
		$this->keys[] = $key;

		return [ 'ok' => true, 'items' => [], 'next_cursor' => '', 'error' => '' ];
	}

	public function get_analytics( string $key, string $account_id, string $period = '30d' ): array {
		$this->keys[] = $key;

		return [ 'ok' => true, 'metrics' => [], 'error' => '' ];
	}

	public function get_trending_audio( string $key, int $limit = 20 ): array {
		$this->keys[] = $key;

		return [ 'ok' => true, 'audios' => [], 'error' => '' ];
	}

	public function account_health( string $key, string $account_id ): array {
		$this->keys[] = $key;

		return [ 'ok' => true, 'healthy' => true, 'error' => '' ];
	}
}

/** In-memory profile registry for the facade tests. */
final class SocialAdapterDb extends wpdb {

	/** @var array<string,array<string,mixed>> tenant_id => profile row */
	public array $profiles = [];

	private int $next_id = 1;

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_zernio_profiles' ) && preg_match( "/tenant_id = '(\d+)'/", $sql, $m ) ) {
			return $this->profiles[ $m[1] ] ?? null;
		}

		return parent::get_row( $sql, $output );
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->queries[] = 'INSERT ' . $table;

		if ( str_ends_with( $table, 'ig_zernio_profiles' ) ) {
			$id                = $this->next_id++;
			$data['id']        = $id;
			$tenant            = (string) ( $data['tenant_id'] ?? '' );
			$this->profiles[ $tenant ] = $data;
			$this->insert_id   = $id;

			return $id;
		}

		return parent::insert( $table, $data, $format );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->queries[] = 'UPDATE ' . $table;

		if ( str_ends_with( $table, 'ig_zernio_profiles' ) ) {
			$changed = 0;
			foreach ( $this->profiles as $tenant => $row ) {
				if ( (string) $row['id'] === (string) ( $where['id'] ?? '' ) ) {
					$this->profiles[ $tenant ] = array_merge( $row, $data );
					++$changed;
				}
			}
			return $changed;
		}

		return parent::update( $table, $data, $where, $format, $where_format );
	}
}

final class ZernioSocialAdapterTest extends TestCase {

	private ZernioClient $client;

	private function boot(): void {
		igbz_test_reset_settings();
		igbz()->settings()->set( 'zernio.central_api_key', 'zk-central' );

		$logger = igbz()->get( 'logger' );
		$this->client = new ZernioClient( new Http( $logger ), $logger );
	}

	/** @return array{url:string,method:string,body:string,headers:array<string,string>} */
	private function last_request(): array {
		$requests = $GLOBALS['igbz_test_http_requests'];
		return $requests[ count( $requests ) - 1 ];
	}

	public function run(): void {
		$this->guard_allows_only_zernio();
		$this->profile_plane_uses_the_central_key();
		$this->social_plane_uses_the_store_key_and_idempotency();
		$this->unconfigured_store_never_leaves_the_building();
		$this->paths_are_config_driven();
		$this->json_extraction_is_tolerant_of_documented_shapes();
		$this->facade_resolves_guard_mapping_and_key_in_order();
		$this->facade_refuses_a_store_without_a_connection();
	}

	/** ADR-0004 §7: the provider guard is the single door, and only Zernio passes. */
	private function guard_allows_only_zernio(): void {
		$this->assert_true( SocialProviders::is_allowed( SocialProviders::ZERNIO ), 'zernio is the allowed provider' );

		foreach ( [ 'manus', 'manychat', 'chatplace', 'agent_reach', 'instagram_session' ] as $legacy ) {
			$threw = false;
			try {
				SocialProviders::assert_allowed( $legacy );
			} catch ( \DomainException $e ) {
				$threw = true;
			}
			$this->assert_true( $threw, "the provider guard rejects '$legacy'" );
		}
	}

	private function profile_plane_uses_the_central_key(): void {
		$this->boot();
		igbz_test_queue_http( [ 'body' => '{"profile":{"_id":"prof-9"}}' ] );

		$result = $this->client->create_profile( 'store-seven' );

		$this->assert_true( $result['ok'], 'the profile is created' );
		$this->assert_same( 'prof-9', $result['profile_id'], 'the profile id comes out of the documented envelope' );

		$last = $this->last_request();
		$this->assert_same( 'https://zernio.com/api/v1/profiles', $last['url'], 'the documented default base and path' );
		$this->assert_same( 'POST', $last['method'], 'profile creation is a POST' );
		$this->assert_same( 'Bearer zk-central', (string) $last['headers']['Authorization'], 'the central key authenticates the profile plane' );
		$this->assert_true( str_contains( $last['body'], 'store-seven' ), 'the store slug is the profile name' );
	}

	private function social_plane_uses_the_store_key_and_idempotency(): void {
		$this->boot();
		igbz_test_queue_http( [ 'body' => '{"post":{"_id":"post-77"}}' ] );

		$result = $this->client->publish_content( 'zk-store-key', 'acct-42', [
			'caption'         => 'hello',
			'idempotency_key' => 'idem-1',
		] );

		$this->assert_true( $result['ok'], 'the post is accepted' );
		$this->assert_same( 'post-77', $result['post_id'], 'the post id is extracted' );

		$last = $this->last_request();
		$this->assert_same( 'https://zernio.com/api/v1/posts', $last['url'], 'publish targets the posts path' );
		$this->assert_same( 'Bearer zk-store-key', (string) $last['headers']['Authorization'], 'the store key — not the central one — authenticates the social plane' );
		$this->assert_same( 'idem-1', (string) $last['headers']['Idempotency-Key'], 'the idempotency key rides the create' );
		$this->assert_true( str_contains( $last['body'], 'acct-42' ), 'the target account id is in the body' );
	}

	private function unconfigured_store_never_leaves_the_building(): void {
		igbz_test_reset_settings();
		$logger  = igbz()->get( 'logger' );
		$client  = new ZernioClient( new Http( $logger ), $logger );

		$result = $client->create_profile( 'store-seven' );
		$this->assert_false( $result['ok'], 'no central key, no profile' );
		$this->assert_same( 'zernio_not_configured', $result['error'], 'the reason is structured' );

		$this->assert_same( 0, count( $GLOBALS['igbz_test_http_requests'] ), 'nothing left the process' );
	}

	private function paths_are_config_driven(): void {
		$this->boot();
		igbz()->settings()->set( 'zernio.base_url', 'https://proxy.test/z' );
		igbz()->settings()->set( 'zernio.posts_path', 'custom/posts' );
		igbz_test_queue_http( [ 'body' => '{"post":{"_id":"p1"}}' ] );

		$this->client->publish_content( 'zk-store-key', 'acct-1', [ 'caption' => 'x' ] );

		$this->assert_same( 'https://proxy.test/z/custom/posts', $this->last_request()['url'], 'a proxy or staging host changes one field; a path change needs no code' );
	}

	private function json_extraction_is_tolerant_of_documented_shapes(): void {
		$this->boot();

		igbz_test_queue_http( [ 'body' => '{"id":"kid-a","apiKey":"sk-a"}' ] );
		$first = $this->client->issue_profile_key( 'prof-a' );
		$this->assert_true( $first['ok'], 'top-level id/apiKey shape is accepted' );
		$this->assert_same( 'sk-a', $first['key'], 'top-level apiKey is read' );
		$this->assert_same( 'kid-a', $first['key_id'], 'top-level id is read' );

		igbz_test_queue_http( [ 'body' => '{"_id":"kid-b","key":"sk-b"}' ] );
		$second = $this->client->issue_profile_key( 'prof-b' );
		$this->assert_true( $second['ok'], 'mongo _id shape is accepted' );
		$this->assert_same( 'sk-b', $second['key'], 'mongo key is read' );
		$this->assert_same( 'kid-b', $second['key_id'], 'mongo _id is read' );
	}

	private function facade_resolves_guard_mapping_and_key_in_order(): void {
		igbz_test_reset_settings();
		igbz()->settings()->set( 'zernio.central_api_key', 'zk-central' );

		$db   = new SocialAdapterDb();
		$GLOBALS['wpdb'] = $db;
		$db->insert(
			'wp_igbz_ig_zernio_profiles',
			[
				'tenant_id'          => 7,
				'profile_id'         => 'prof-7',
				'account_id'         => 'acct-7',
				'instagram_account_id' => 'acct-7',
				'status'             => ZernioConnectionService::STATUS_CONNECTED,
				'key_enc'            => Crypto::encrypt( 'zk-store-seven' ),
				'key_id'             => 'kid-7',
				'key_version'        => 1,
			]
		);

		$recorder = new SocialPlaneRecorder();
		$zernio   = new ZernioConnectionService( new Db(), igbz()->get( 'logger' ), $recorder );
		$facade   = new ZernioSocialService( new Db(), igbz()->get( 'logger' ), $zernio, $recorder );

		$result = $facade->publish( 7, [ 'caption' => 'go' ] );

		$this->assert_true( $result['ok'], 'a connected store publishes through the facade' );
		$this->assert_same( 1, count( $recorder->published ), 'exactly one adapter call' );
		$this->assert_same( 'zk-store-seven', $recorder->published[0]['key'], 'the decrypted store key reaches the adapter' );
		$this->assert_same( 'acct-7', $recorder->published[0]['account_id'], 'the account id comes from the backend mapping, not the caller' );
	}

	private function facade_refuses_a_store_without_a_connection(): void {
		igbz_test_reset_settings();
		igbz()->settings()->set( 'zernio.central_api_key', 'zk-central' );

		$db   = new SocialAdapterDb();
		$GLOBALS['wpdb'] = $db;
		$db->insert(
			'wp_igbz_ig_zernio_profiles',
			[
				'tenant_id'  => 9,
				'profile_id' => 'prof-9',
				'account_id' => '',
				'status'     => ZernioConnectionService::STATUS_PROVISIONED,
				'key_enc'    => Crypto::encrypt( 'zk-store-nine' ),
				'key_id'     => 'kid-9',
				'key_version' => 1,
			]
		);

		$recorder = new SocialPlaneRecorder();
		$zernio   = new ZernioConnectionService( new Db(), igbz()->get( 'logger' ), $recorder );
		$facade   = new ZernioSocialService( new Db(), igbz()->get( 'logger' ), $zernio, $recorder );

		$result = $facade->publish( 9, [ 'caption' => 'go' ] );

		$this->assert_false( $result['ok'], 'a provisioned-but-unconnected store cannot publish' );
		$this->assert_same( 'not_connected', $result['error'], 'the reason is structured, not an exception' );
		$this->assert_same( 0, count( $recorder->published ), 'no adapter call leaked past the mapping' );
	}
}
