<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

defined( 'ABSPATH' ) || exit;

/**
 * The shortcode of a published Instagram post, derived from its permalink.
 *
 * Why this exists: a funnel can be scoped to a single post, and until now the only way to fill in
 * that scope was for the operator to type an opaque id into a free-text box. There was nothing to
 * type it *from* -- ig_content stored the permalink and nothing else, so the id had to come out of
 * the URL by hand, and a single wrong character produced a funnel that silently never matched.
 *
 * The permalink is the one durable identifier we are allowed to have. We do not call the Graph API
 * (the founding rule of this project), so we never receive a numeric media id from Meta; and we do
 * not scrape instagram.com to discover one. What the publisher hands back when it publishes is the post
 * URL, and the shortcode embedded in that URL is stable for the life of the post. It is what
 * appears in a share link, so it is also the thing an operator can recognise and paste.
 *
 * This class is deliberately pure -- no database, no HTTP, no WordPress state -- so the parsing
 * rules can be tested exhaustively and reused by anything that holds a URL.
 */
final class PostIdentity {

	/**
	 * URL path segments that introduce a shortcode.
	 *
	 * Instagram serves the same post under several prefixes depending on how it was created and
	 * where the link was copied from: /p/ for a feed post or carousel, /reel/ (and the older
	 * /reels/) for a reel, /tv/ for the retired IGTV format. They all resolve to one post and one
	 * shortcode, so a funnel scoped to a reel must match whichever spelling the operator pasted.
	 */
	private const PREFIXES = [ 'p', 'reel', 'reels', 'tv' ];

	/**
	 * Instagram shortcodes are base64url-ish: letters, digits, hyphen and underscore. Eleven
	 * characters is the long-standing length, but the alphabet has been extended before and the
	 * length is not contractual, so the bound is generous rather than exact. Anything outside this
	 * shape is not a shortcode and we would rather store nothing than store a wrong id.
	 */
	private const SHORTCODE = '/^[A-Za-z0-9_-]{5,64}$/';

	/**
	 * Pull the shortcode out of a post URL.
	 *
	 * Accepts anything an operator is likely to paste: with or without a scheme, with or without
	 * www, with a trailing slash, with query parameters (?igsh=... is appended by the app's own
	 * share sheet), or a bare shortcode on its own. Returns '' when the input is not recognisable,
	 * which callers must treat as "unknown", never as a match-all.
	 */
	public static function from_permalink( string $permalink ): string {
		$permalink = trim( $permalink );

		if ( '' === $permalink ) {
			return '';
		}

		// A bare shortcode, pasted without the surrounding URL. Checked first: it has no slash, so
		// the path walk below would never see it.
		if ( ! str_contains( $permalink, '/' ) ) {
			return preg_match( self::SHORTCODE, $permalink ) ? $permalink : '';
		}

		// parse_url() wants a scheme to find a host; without one it reads "instagram.com/p/ABC"
		// as a path. We only care about the path either way, so normalise instead of branching.
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $permalink ) ) {
			$permalink = 'https://' . ltrim( $permalink, '/' );
		}

		$path = (string) wp_parse_url( $permalink, PHP_URL_PATH );
		if ( '' === $path ) {
			return '';
		}

		$segments = array_values( array_filter( explode( '/', $path ), static fn ( $s ) => '' !== $s ) );

		// Walk to the prefix rather than assuming its position: a post can be linked either as
		// /p/ABC or as /username/p/ABC, and the second form is what the mobile app copies.
		foreach ( $segments as $i => $segment ) {
			if ( ! in_array( strtolower( $segment ), self::PREFIXES, true ) ) {
				continue;
			}

			$candidate = $segments[ $i + 1 ] ?? '';

			return preg_match( self::SHORTCODE, $candidate ) ? $candidate : '';
		}

		return '';
	}

	/**
	 * The canonical URL for a shortcode.
	 *
	 * Always /p/, even for a reel: Instagram redirects the prefixes to whichever one the post
	 * actually is, and storing one spelling keeps links comparable.
	 */
	public static function permalink( string $shortcode ): string {
		$shortcode = trim( $shortcode );

		return preg_match( self::SHORTCODE, $shortcode )
			? 'https://www.instagram.com/p/' . $shortcode . '/'
			: '';
	}

	/**
	 * Do two post references point at the same post?
	 *
	 * Either side may be a full URL or a bare shortcode, because one side is typically what an
	 * operator configured on a funnel and the other is what the provider sent on a comment event, and
	 * those two arrive in different shapes. A reference we cannot parse never matches -- an
	 * unmatched funnel is recoverable, a funnel that fires on every post is not.
	 */
	public static function same_post( string $a, string $b ): bool {
		$a = self::from_permalink( $a );
		$b = self::from_permalink( $b );

		return '' !== $a && $a === $b;
	}
}
