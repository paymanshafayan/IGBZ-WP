<?php
namespace IGBZ\Suite\Modules\Hub\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Editable marketing blocks for the mother site ("store", "mobile app", "Instagram assistant",
 * …). The nop version kept these in a dedicated table; here they live in one autoloaded option
 * because they are a handful of rows that the hub reads on every landing request.
 *
 * Each block: page_key, menu_title, title, summary, bullets[], image_url, cta_text, cta_url,
 * content (HTML), images[], is_active, sort_order.
 */
final class ContentBlockService {

	public const OPTION = 'igbz_hub_blocks';

	/** @return array<int,array<string,mixed>> */
	public function all( bool $only_active = false ): array {
		$blocks = get_option( self::OPTION, null );
		if ( ! is_array( $blocks ) ) {
			$blocks = $this->defaults();
			update_option( self::OPTION, $blocks, false );
		}

		$blocks = array_map( [ $this, 'normalize' ], array_values( $blocks ) );

		if ( $only_active ) {
			$blocks = array_values( array_filter( $blocks, static fn ( array $b ): bool => (bool) $b['is_active'] ) );
		}

		usort( $blocks, static fn ( array $a, array $b ): int => $a['sort_order'] <=> $b['sort_order'] );

		return $blocks;
	}

	/** @return array<string,mixed>|null */
	public function get( string $page_key ): ?array {
		foreach ( $this->all() as $block ) {
			if ( $block['page_key'] === $page_key ) {
				return $block;
			}
		}
		return null;
	}

	/** @param array<string,mixed> $data */
	public function save( array $data ): string {
		$block  = $this->normalize( $data );
		$blocks = $this->all();

		$replaced = false;
		foreach ( $blocks as $index => $existing ) {
			if ( $existing['page_key'] === $block['page_key'] ) {
				$blocks[ $index ] = $block;
				$replaced         = true;
				break;
			}
		}
		if ( ! $replaced ) {
			$blocks[] = $block;
		}

		update_option( self::OPTION, array_values( $blocks ), false );

		return $block['page_key'];
	}

	public function delete( string $page_key ): bool {
		$blocks = array_values(
			array_filter( $this->all(), static fn ( array $b ): bool => $b['page_key'] !== $page_key )
		);
		update_option( self::OPTION, $blocks, false );
		return true;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public function normalize( array $data ): array {
		$to_lines = static function ( mixed $value ): array {
			if ( is_array( $value ) ) {
				return array_values( array_filter( array_map( 'sanitize_text_field', $value ) ) );
			}
			$lines = preg_split( '/\r\n|\r|\n/', (string) $value ) ?: [];
			return array_values( array_filter( array_map( 'sanitize_text_field', $lines ) ) );
		};

		return [
			'page_key'   => sanitize_key( (string) ( $data['page_key'] ?? 'block' ) ),
			'menu_title' => sanitize_text_field( (string) ( $data['menu_title'] ?? '' ) ),
			'title'      => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'summary'    => sanitize_textarea_field( (string) ( $data['summary'] ?? '' ) ),
			'bullets'    => $to_lines( $data['bullets'] ?? [] ),
			'image_url'  => esc_url_raw( (string) ( $data['image_url'] ?? '' ) ),
			'images'     => array_values( array_filter( array_map( 'esc_url_raw', is_array( $data['images'] ?? null ) ? $data['images'] : $to_lines( $data['images'] ?? [] ) ) ) ),
			'cta_text'   => sanitize_text_field( (string) ( $data['cta_text'] ?? '' ) ),
			'cta_url'    => esc_url_raw( (string) ( $data['cta_url'] ?? '' ) ),
			'content'    => wp_kses_post( (string) ( $data['content'] ?? '' ) ),
			'is_active'  => ! empty( $data['is_active'] ),
			'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
		];
	}

	/** @return array<int,array<string,mixed>> */
	public function defaults(): array {
		return [
			$this->normalize(
				[
					'page_key'   => 'store',
					'menu_title' => __( 'Online store', 'igbz-suite' ),
					'title'      => __( 'Launch a full online store in minutes', 'igbz-suite' ),
					'summary'    => __( 'Your own domain, your own theme, wallet, instalments and Iranian payment gateways out of the box.', 'igbz-suite' ),
					'bullets'    => [
						__( 'Custom domain with automatic verification', 'igbz-suite' ),
						__( 'Wallet, cashback and instalment payments', 'igbz-suite' ),
						__( 'Affiliate programme and marketplace feeds', 'igbz-suite' ),
					],
					'cta_text'   => __( 'Create my store', 'igbz-suite' ),
					'is_active'  => true,
					'sort_order' => 10,
				]
			),
			$this->normalize(
				[
					'page_key'   => 'app',
					'menu_title' => __( 'Mobile app', 'igbz-suite' ),
					'title'      => __( 'A branded mobile app for your customers', 'igbz-suite' ),
					'summary'    => __( 'The REST module powers a Flutter client with JWT login, push notifications and deep links straight into your catalogue.', 'igbz-suite' ),
					'bullets'    => [
						__( 'Phone OTP login', 'igbz-suite' ),
						__( 'Firebase push notifications', 'igbz-suite' ),
						__( 'Deep links from Instagram into a product page', 'igbz-suite' ),
					],
					'cta_text'   => __( 'See the app', 'igbz-suite' ),
					'is_active'  => true,
					'sort_order' => 20,
				]
			),
			$this->normalize(
				[
					'page_key'   => 'instagram',
					'menu_title' => __( 'Instagram assistant', 'igbz-suite' ),
					'title'      => __( 'Content and DM funnels on autopilot', 'igbz-suite' ),
					'summary'    => __( 'The store publishes through its single social provider (Zernio official OAuth): posts, inbox and analytics from one place. Comment-to-DM answers land in the rebuilt inbox flow.', 'igbz-suite' ),
					'bullets'    => [
						__( 'Trend research and graphics', 'igbz-suite' ),
						__( 'Reels with captions and hashtags', 'igbz-suite' ),
						__( 'Comment a keyword, get the link in your DMs', 'igbz-suite' ),
					],
					'cta_text'   => __( 'Try the assistant', 'igbz-suite' ),
					'is_active'  => true,
					'sort_order' => 30,
				]
			),
		];
	}
}
