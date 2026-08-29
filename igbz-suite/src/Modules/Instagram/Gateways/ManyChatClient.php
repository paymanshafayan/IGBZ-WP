<?php
namespace IGBZ\Suite\Modules\Instagram\Gateways;

use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Thin client over the ManyChat public API (https://api.manychat.com/fb/).
 *
 * Auth is a page-scoped API key sent as a Bearer token. Endpoints are grouped as
 *   /fb/page/*        page metadata (tags, custom fields, flows)
 *   /fb/subscriber/*  subscriber lookup and mutation
 *   /fb/sending/*     content and flow delivery
 *
 * Rate limits enforced by ManyChat: 100 RPS per page (getFlows 10 RPS),
 * subscriber getInfo/findByName/findByCustomField 10 RPS.
 */
final class ManyChatClient {

	private const BASE = 'https://api.manychat.com/fb/';

	public const ERROR_SUBSCRIBER_NOT_FOUND = 2011;
	public const ERROR_USER_REF_NOT_FOUND   = 2012;
	public const ERROR_INVALID_CONTENT      = 3011;
	public const ERROR_TAG_REQUIRED         = 3021;

	/**
	 * Page-scoped key this instance authenticates with. ManyChat issues one key per page, so this
	 * is necessarily per-account rather than per-install.
	 */
	private string $api_key = '';

	public function __construct( private Http $http, private Logger $logger ) {}

	/**
	 * A copy of this client bound to one account's key. See ManusClient::for_key() for why this
	 * clones instead of mutating.
	 */
	public function for_key( string $api_key ): self {
		$clone          = clone $this;
		$clone->api_key = trim( $api_key );
		return $clone;
	}

	public function is_configured(): bool {
		return '' !== $this->api_key;
	}

	/** @return array<string,string> */
	private function headers(): array {
		return [
			'Authorization' => 'Bearer ' . $this->api_key,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		];
	}

	/**
	 * @param array<string,mixed> $query
	 * @return array{ok:bool,data:array<string,mixed>,error:string,code:int}
	 */
	private function get( string $path, array $query = [] ): array {
		if ( ! $this->is_configured() ) {
			return $this->fail( __( 'The ManyChat API key is not configured.', 'igbz-suite' ) );
		}
		$url = self::BASE . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( array_map( 'strval', $query ), $url );
		}
		return $this->unwrap( $this->http->get( $url, [ 'headers' => $this->headers(), 'channel' => 'manychat', 'timeout' => 15, 'retries' => 1 ] ) );
	}

	/**
	 * @param array<string,mixed> $body
	 * @return array{ok:bool,data:array<string,mixed>,error:string,code:int}
	 */
	private function post( string $path, array $body ): array {
		if ( ! $this->is_configured() ) {
			return $this->fail( __( 'The ManyChat API key is not configured.', 'igbz-suite' ) );
		}
		return $this->unwrap(
			$this->http->post(
				self::BASE . ltrim( $path, '/' ),
				[ 'json' => $body, 'headers' => $this->headers(), 'channel' => 'manychat', 'timeout' => 15, 'retries' => 1 ]
			)
		);
	}

	/** @return array{ok:bool,data:array<string,mixed>,error:string,code:int} */
	private function fail( string $message, int $code = 0 ): array {
		return [ 'ok' => false, 'data' => [], 'error' => $message, 'code' => $code ];
	}

	/** @return array{ok:bool,data:array<string,mixed>,error:string,code:int} */
	private function unwrap( \IGBZ\Suite\Support\HttpResponse $response ): array {
		if ( ! $response->ok() ) {
			$body    = $response->json();
			$message = (string) ( $body['message'] ?? $response->error_message() );
			$code    = (int) ( $body['code'] ?? $response->status );
			$this->logger->error( 'manychat', 'Request failed', [ 'status' => $response->status, 'message' => $message, 'code' => $code ] );
			return $this->fail( $message, $code );
		}

		$body = $response->json();
		if ( 'success' !== ( $body['status'] ?? '' ) ) {
			$message = (string) ( $body['message'] ?? __( 'Unknown ManyChat error.', 'igbz-suite' ) );
			$code    = (int) ( $body['code'] ?? 0 );
			$this->logger->warning( 'manychat', 'API error', [ 'message' => $message, 'code' => $code ] );
			return $this->fail( $message, $code );
		}

		$data = $body['data'] ?? [];
		return [ 'ok' => true, 'data' => is_array( $data ) ? $data : [ 'value' => $data ], 'error' => '', 'code' => 0 ];
	}

	// ----------------------------------------------------------------- page

	/** @return array{ok:bool,data:array<string,mixed>,error:string,code:int} */
	public function page_info(): array {
		return $this->get( 'page/getInfo' );
	}

	/** @return array{ok:bool,data:array<string,mixed>,error:string,code:int} */
	public function tags(): array {
		return $this->get( 'page/getTags' );
	}

	/** @return array{ok:bool,data:array<string,mixed>,error:string,code:int} */
	public function custom_fields(): array {
		return $this->get( 'page/getCustomFields' );
	}

	/** @return array{ok:bool,data:array<string,mixed>,error:string,code:int} */
	public function bot_fields(): array {
		return $this->get( 'page/getBotFields' );
	}

	/**
	 * Create a subscriber custom field.
	 *
	 * ManyChat rejects setCustomFieldByName for a field that does not exist, and it does so at
	 * the worst possible moment — while a customer is waiting for the DM the funnel promised. So
	 * the fields are created up front, when a product is registered and nobody is waiting.
	 *
	 * @return array{ok:bool,data:array<string,mixed>,error:string,code:int}
	 */
	public function create_custom_field( string $caption, string $type = 'text', string $description = '' ): array {
		return $this->post(
			'page/createCustomField',
			array_filter(
				[
					'caption'     => $caption,
					'type'        => $type,
					'description' => $description,
				]
			)
		);
	}

	/** @return array{ok:bool,data:array<string,mixed>,error:string,code:int} */
	public function create_bot_field( string $caption, string $type = 'text', string $description = '' ): array {
		return $this->post(
			'page/createBotField',
			array_filter(
				[
					'caption'     => $caption,
					'type'        => $type,
					'description' => $description,
				]
			)
		);
	}

	/**
	 * Set a page-level bot field by name.
	 *
	 * Bot fields are per page rather than per subscriber, which makes them the right place for
	 * "the newest product's code and link": a store can build one flow in the ManyChat UI that
	 * references them and never edit it again.
	 *
	 * @return array{ok:bool,data:array<string,mixed>,error:string,code:int}
	 */
	public function set_bot_field_by_name( string $field_name, mixed $value ): array {
		return $this->post( 'page/setBotFieldByName', [ 'field_name' => $field_name, 'field_value' => $value ] );
	}

	/**
	 * Flow list. Rate limited to 10 RPS, so the result is cached for 10 minutes.
	 *
	 * @return array{ok:bool,data:array<string,mixed>,error:string,code:int}
	 */
	public function flows( bool $force = false ): array {
		// Phase 15: the client is bound per account key, so the flow list belongs to that
		// account — keying globally would serve one store's flows to every other store.
		$key    = 'igbz_manychat_flows_' . md5( $this->api_key );
		$cached = $force ? false : get_transient( $key );
		if ( is_array( $cached ) ) {
			return [ 'ok' => true, 'data' => $cached, 'error' => '', 'code' => 0 ];
		}
		$result = $this->get( 'page/getFlows' );
		if ( $result['ok'] ) {
			set_transient( $key, $result['data'], 10 * MINUTE_IN_SECONDS );
		}
		return $result;
	}

	// ----------------------------------------------------------- subscriber

	/**
	 * Full subscriber profile: name, gender, locale, timezone, tags, custom fields, last interaction.
	 * This is integration path #2 from the brief - pull the profile once a comment has dragged the
	 * user into a Flow.
	 *
	 * @return array{ok:bool,data:array<string,mixed>,error:string,code:int}
	 */
	public function get_info( string $subscriber_id ): array {
		return $this->get( 'subscriber/getInfo', [ 'subscriber_id' => $subscriber_id ] );
	}

	/** @return array{ok:bool,data:array<string,mixed>,error:string,code:int} */
	public function get_info_by_user_ref( string $user_ref ): array {
		return $this->get( 'subscriber/getInfoByUserRef', [ 'user_ref' => $user_ref ] );
	}

	/** @return array{ok:bool,data:array<string,mixed>,error:string,code:int} */
	public function find_by_name( string $name ): array {
		return $this->get( 'subscriber/findByName', [ 'name' => $name ] );
	}

	/** @return array{ok:bool,data:array<string,mixed>,error:string,code:int} */
	public function find_by_custom_field( int $field_id, string $value ): array {
		return $this->get( 'subscriber/findByCustomField', [ 'field_id' => $field_id, 'field_value' => $value ] );
	}

	/** @return array{ok:bool,data:array<string,mixed>,error:string,code:int} */
	public function find_by_system_field( string $field, string $value ): array {
		return $this->get( 'subscriber/findBySystemField', [ $field => $value ] );
	}

	/** @param array<string,mixed> $fields */
	public function update_subscriber( string $subscriber_id, array $fields ): array {
		return $this->post( 'subscriber/updateSubscriber', [ 'subscriber_id' => $subscriber_id ] + $fields );
	}

	public function add_tag( string $subscriber_id, int $tag_id ): array {
		return $this->post( 'subscriber/addTag', [ 'subscriber_id' => $subscriber_id, 'tag_id' => $tag_id ] );
	}

	public function add_tag_by_name( string $subscriber_id, string $tag_name ): array {
		return $this->post( 'subscriber/addTagByName', [ 'subscriber_id' => $subscriber_id, 'tag_name' => $tag_name ] );
	}

	public function remove_tag( string $subscriber_id, int $tag_id ): array {
		return $this->post( 'subscriber/removeTag', [ 'subscriber_id' => $subscriber_id, 'tag_id' => $tag_id ] );
	}

	public function set_custom_field( string $subscriber_id, int $field_id, mixed $value ): array {
		return $this->post(
			'subscriber/setCustomField',
			[ 'subscriber_id' => $subscriber_id, 'field_id' => $field_id, 'field_value' => $value ]
		);
	}

	public function set_custom_field_by_name( string $subscriber_id, string $field_name, mixed $value ): array {
		return $this->post(
			'subscriber/setCustomFieldByName',
			[ 'subscriber_id' => $subscriber_id, 'field_name' => $field_name, 'field_value' => $value ]
		);
	}

	/**
	 * Bulk field write - handy when the External Request handler has to answer fast and then push
	 * several values back at once.
	 *
	 * @param array<string,mixed> $fields field_name => value
	 */
	public function set_custom_fields( string $subscriber_id, array $fields ): bool {
		$ok = true;
		foreach ( $fields as $name => $value ) {
			$result = $this->set_custom_field_by_name( $subscriber_id, (string) $name, $value );
			$ok     = $ok && $result['ok'];
		}
		return $ok;
	}

	// -------------------------------------------------------------- sending

	/**
	 * Trigger an existing ManyChat automation for a subscriber. flow_ns looks like
	 * "content20180221085508_278589" and is taken from the automation's edit URL.
	 */
	public function send_flow( string $subscriber_id, string $flow_ns ): array {
		return $this->post( 'sending/sendFlow', [ 'subscriber_id' => $subscriber_id, 'flow_ns' => $flow_ns ] );
	}

	/**
	 * Send ad-hoc content built with the v2 message envelope.
	 *
	 * @param array<int,array<string,mixed>> $messages
	 * @param array<int,array<string,mixed>> $actions
	 * @param array<int,array<string,mixed>> $quick_replies
	 */
	public function send_content( string $subscriber_id, array $messages, string $message_tag = '', array $actions = [], array $quick_replies = [] ): array {
		$body = [
			'subscriber_id' => $subscriber_id,
			'data'          => [
				'version' => 'v2',
				'content' => [
					'messages'      => $messages,
					'actions'       => $actions,
					'quick_replies' => $quick_replies,
				],
			],
		];
		if ( '' !== $message_tag ) {
			$body['message_tag'] = $message_tag;
		}
		return $this->post( 'sending/sendContent', $body );
	}

	/** @param array<int,array<string,mixed>> $messages */
	public function send_content_by_user_ref( string $user_ref, array $messages, string $message_tag = '' ): array {
		$body = [
			'user_ref' => $user_ref,
			'data'     => [ 'version' => 'v2', 'content' => [ 'messages' => $messages, 'actions' => [], 'quick_replies' => [] ] ],
		];
		if ( '' !== $message_tag ) {
			$body['message_tag'] = $message_tag;
		}
		return $this->post( 'sending/sendContentByUserRef', $body );
	}

	/**
	 * Convenience: a plain text DM, optionally with one URL button.
	 */
	public function send_text( string $subscriber_id, string $text, string $button_label = '', string $button_url = '' ): array {
		$message = [ 'type' => 'text', 'text' => mb_substr( $text, 0, 2000 ) ];
		if ( '' !== $button_url ) {
			$message['buttons'] = [
				[
					'type'    => 'url',
					'caption' => '' !== $button_label ? mb_substr( $button_label, 0, 20 ) : __( 'Open', 'igbz-suite' ),
					'url'     => $button_url,
				],
			];
		}
		return $this->send_content( $subscriber_id, [ $message ] );
	}

	// ------------------------------------------------- dynamic content help

	/**
	 * Build a Dynamic Content response envelope for ManyChat to render inline.
	 *
	 * @param array<int,array<string,mixed>> $messages
	 * @param array<int,array<string,mixed>> $actions
	 * @param array<int,array<string,mixed>> $quick_replies
	 * @return array<string,mixed>
	 */
	public static function dynamic_content( array $messages, array $actions = [], array $quick_replies = [] ): array {
		return [
			'version' => 'v2',
			'content' => [
				'messages'      => $messages,
				'actions'       => $actions,
				'quick_replies' => $quick_replies,
			],
		];
	}

	/**
	 * A message the bot will fill in later (async work): ManyChat waits up to $timeout seconds for
	 * an external_message_callback push instead of blocking the External Request.
	 *
	 * @return array<string,mixed>
	 */
	public static function external_message_callback( int $timeout = 600 ): array {
		return [ 'external_message_callback' => [ 'timeout' => $timeout ] ];
	}
}
