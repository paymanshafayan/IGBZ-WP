<?php
namespace IGBZ\Suite\Modules\Instagram\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * The Zernio social gateway contract (phase 50, ADR-0004 §5).
 *
 * Zernio is the project's only social provider. The interface is split in two
 * halves, mirroring the key model:
 *
 *   - profile-plane operations (profile, keys, OAuth, accounts, deletion) are
 *     authenticated with the one central IGBZ key and are called only from the
 *     backend;
 *   - social-plane operations (publish, inbox, DM, analytics, audio, health)
 *     are authenticated with the store's own profile-scoped key, which the
 *     caller resolves through ZernioConnectionService and passes explicitly —
 *     this adapter never stores or resolves keys itself.
 *
 * Every endpoint path is config-driven (zernio.* settings) with the official
 * docs.zernio.com defaults; nothing here invents endpoint semantics beyond the
 * documented shapes. Live endpoint verification happens in the dedicated
 * `PV-ZERNIO-*` phase; until then the adapter is exercised against scripted
 * doubles.
 */
interface ZernioAdapterInterface {

	// ------------------------------------------------------- profile plane

	public function is_configured(): bool;

	/** Create the store's profile under the central account. @return array{ok:bool,profile_id:string,error:string} */
	public function create_profile( string $store_slug ): array;

	/** Issue a profile-scoped key. @return array{ok:bool,key:string,key_id:string,error:string} */
	public function issue_profile_key( string $profile_id ): array;

	/** Revoke a profile-scoped key by its key id. @return array{ok:bool,error:string} */
	public function revoke_profile_key( string $key_id ): array;

	/** Start the official OAuth connect for the profile. @return array{ok:bool,auth_url:string,error:string} */
	public function start_connect( string $profile_id ): array;

	/**
	 * List the accounts currently attached to the profile.
	 *
	 * @return array{ok:bool,accounts:array<int,array{account_id:string,platform:string,username:string}>,error:string}
	 */
	public function list_accounts( string $profile_id ): array;

	/** Offboarding: delete the profile and everything under it. @return array{ok:bool,error:string} */
	public function delete_profile( string $profile_id ): array;

	// ------------------------------------------------------- social plane

	/**
	 * Publish (now or scheduled) to the store's Instagram account.
	 *
	 * @param string $key          The store's profile-scoped key.
	 * @param string $account_id   The Zernio account id from the profile mapping.
	 * @param array{caption?:string,media?:array<int,string>,publish_now?:bool,scheduled_at?:string,idempotency_key?:string} $content
	 * @return array{ok:bool,post_id:string,error:string}
	 */
	public function publish_content( string $key, string $account_id, array $content ): array;

	/** Reconciliation: read back a post's real state. @return array{ok:bool,status:string,permalink:string,media_id:string,error:string} */
	public function get_post( string $key, string $post_id ): array;

	/** Provider-side retry of a failed post (its own idempotency applies). @return array{ok:bool,error:string} */
	public function retry_post( string $key, string $post_id ): array;

	/**
	 * Send a direct message inside an existing conversation (no cold messaging —
	 * the recipient must have initiated, per the platform window).
	 *
	 * @param array{content?:string,media?:array<int,string>,idempotency_key?:string} $message
	 * @return array{ok:bool,message_id:string,error:string}
	 */
	public function send_direct_message( string $key, string $account_id, string $recipient_id, array $message ): array;

	/** Reply to a story. @return array{ok:bool,message_id:string,error:string} */
	public function send_story_reply( string $key, string $account_id, string $story_id, string $recipient_id, string $text ): array;

	/**
	 * Read the unified inbox (conversations, comments, mentions, reviews).
	 *
	 * @return array{ok:bool,items:array<int,array<string,mixed>>,next_cursor:string,error:string}
	 */
	public function get_inbox( string $key, string $kind, string $cursor = '', int $limit = 50 ): array;

	/** Account/post analytics. @return array{ok:bool,metrics:array<string,mixed>,error:string} */
	public function get_analytics( string $key, string $account_id, string $period = '30d' ): array;

	/** Trending / catalog audio (ADR-0004 §7; availability gated in PV-ZERNIO-*). @return array{ok:bool,audios:array<int,array<string,mixed>>,error:string} */
	public function get_trending_audio( string $key, int $limit = 20 ): array;

	/** Connected-account health. @return array{ok:bool,healthy:bool,error:string} */
	public function account_health( string $key, string $account_id ): array;
}
