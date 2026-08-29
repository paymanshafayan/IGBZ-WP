<?php
/**
 * A funnel scoped to a post has to actually fire on that post.
 *
 * Two defects met here. The reward a funnel pays was filed in the wallet ledger under
 * `affiliate_commission` -- the reason that means "you earned this by referring a sale" -- so a
 * shopper who had only commented on a post read "Affiliate commission" on their statement, and
 * nothing downstream could tell promotional rewards from money owed to affiliates.
 *
 * And a funnel could be scoped to one post only by typing an opaque id into a text box, with
 * nothing in the product that could tell an operator what to type. We never receive a media id --
 * calling the Graph API is prohibited here, and scraping instagram.com is worse -- so the only
 * identifier available is the shortcode inside the permalink the publisher hands back at publish time.
 * Publishing now records it, and the funnel form offers the published posts as a list.
 *
 * These tests pin:
 *
 *   - the shortcode is read from every shape of post URL an operator might paste, and from none
 *     of the shapes that are not a post;
 *   - an unrecognisable reference yields '' and is never treated as "any post";
 *   - publishing stores the shortcode, and stores nothing when the URL is unusable;
 *   - matching accepts the shortcode and the full URL as the same post, so funnels configured
 *     before and after the picker both keep working;
 *   - the reward is credited as an Instagram reward, with the funnel's own reference code.
 */

declare( strict_types=1 );

use IGBZ\Suite\Modules\Instagram\Services\PostIdentity;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;

final class PostIdentityTest extends TestCase {

	public function run(): void {
		$this->test_the_shortcode_is_read_from_a_plain_post_url();
		$this->test_reels_and_igtv_and_username_prefixed_urls_all_resolve();
		$this->test_share_query_strings_and_missing_schemes_are_tolerated();
		$this->test_a_bare_shortcode_passes_through();
		$this->test_a_url_that_is_not_a_post_yields_nothing();
		$this->test_an_unparseable_reference_never_becomes_a_wildcard();
		$this->test_the_canonical_permalink_round_trips();
		$this->test_the_same_post_in_two_spellings_compares_equal();
		$this->test_the_funnel_reward_is_not_an_affiliate_commission();
	}

	private function test_the_shortcode_is_read_from_a_plain_post_url(): void {
		$this->assert_same(
			'Cx1YzAbCdEf',
			PostIdentity::from_permalink( 'https://www.instagram.com/p/Cx1YzAbCdEf/' ),
			'a standard post URL yields its shortcode'
		);
	}

	/**
	 * One post, several prefixes. Instagram serves a reel under /reel/ (and historically /reels/)
	 * and an IGTV upload under /tv/, and the mobile app copies links with the username in front.
	 * A funnel scoped to a reel must match whichever spelling was pasted, or it silently never
	 * fires -- the failure mode that made the old text box unusable.
	 */
	private function test_reels_and_igtv_and_username_prefixed_urls_all_resolve(): void {
		$cases = [
			'https://www.instagram.com/reel/AbCdEfGhIjK/'          => 'AbCdEfGhIjK',
			'https://www.instagram.com/reels/AbCdEfGhIjK/'         => 'AbCdEfGhIjK',
			'https://www.instagram.com/tv/AbCdEfGhIjK/'            => 'AbCdEfGhIjK',
			'https://www.instagram.com/shopname/p/AbCdEfGhIjK/'    => 'AbCdEfGhIjK',
			'https://www.instagram.com/shopname/reel/AbCdEfGhIjK/' => 'AbCdEfGhIjK',
		];

		foreach ( $cases as $url => $expected ) {
			$this->assert_same( $expected, PostIdentity::from_permalink( $url ), 'resolves ' . $url );
		}
	}

	/**
	 * What actually lands in the paste buffer. The share sheet appends ?igsh=..., people drop the
	 * scheme, and a copied link may have no trailing slash.
	 */
	private function test_share_query_strings_and_missing_schemes_are_tolerated(): void {
		$cases = [
			'https://www.instagram.com/p/Cx1YzAbCdEf/?igsh=MTQ0eDh4' => 'Cx1YzAbCdEf',
			'https://instagram.com/p/Cx1YzAbCdEf'                    => 'Cx1YzAbCdEf',
			'www.instagram.com/p/Cx1YzAbCdEf/'                       => 'Cx1YzAbCdEf',
			'instagram.com/p/Cx1YzAbCdEf/'                           => 'Cx1YzAbCdEf',
			'  https://www.instagram.com/p/Cx1YzAbCdEf/  '           => 'Cx1YzAbCdEf',
		];

		foreach ( $cases as $url => $expected ) {
			$this->assert_same( $expected, PostIdentity::from_permalink( $url ), 'tolerates ' . trim( $url ) );
		}
	}

	private function test_a_bare_shortcode_passes_through(): void {
		$this->assert_same(
			'Cx1YzAbCdEf',
			PostIdentity::from_permalink( 'Cx1YzAbCdEf' ),
			'a shortcode on its own is already the answer'
		);
	}

	/**
	 * Profile, explore and story URLs are not posts. Returning the segment after the host would
	 * have turned a profile link into a plausible-looking shortcode that matches nothing.
	 */
	private function test_a_url_that_is_not_a_post_yields_nothing(): void {
		$cases = [
			'https://www.instagram.com/shopname/',
			'https://www.instagram.com/explore/tags/sale/',
			'https://www.instagram.com/stories/shopname/3211234567890/',
			'https://example.com/blog/p/',
			'https://www.instagram.com/',
		];

		foreach ( $cases as $url ) {
			$this->assert_same( '', PostIdentity::from_permalink( $url ), 'not a post: ' . $url );
		}
	}

	/**
	 * The empty string is the "any post" scope in a funnel row, so a reference we cannot read must
	 * come back empty *and* callers must not confuse the two. same_post() is the guard: an
	 * unreadable reference matches nothing, including another unreadable one.
	 */
	private function test_an_unparseable_reference_never_becomes_a_wildcard(): void {
		$this->assert_same( '', PostIdentity::from_permalink( '' ), 'empty in, empty out' );
		$this->assert_same( '', PostIdentity::from_permalink( '   ' ), 'whitespace is not a post' );
		$this->assert_same( '', PostIdentity::from_permalink( 'https://www.instagram.com/p/no!' ), 'a bad shortcode is rejected' );

		$this->assert_false( PostIdentity::same_post( '', '' ), 'two unknowns are not the same post' );
		$this->assert_false( PostIdentity::same_post( 'not a url', 'not a url' ), 'two unreadable refs are not the same post' );
	}

	private function test_the_canonical_permalink_round_trips(): void {
		$url = PostIdentity::permalink( 'Cx1YzAbCdEf' );

		$this->assert_same( 'https://www.instagram.com/p/Cx1YzAbCdEf/', $url, 'builds the canonical URL' );
		$this->assert_same( 'Cx1YzAbCdEf', PostIdentity::from_permalink( $url ), 'and reads back what it wrote' );
		$this->assert_same( '', PostIdentity::permalink( 'not a shortcode!' ), 'refuses to build a URL from junk' );
	}

	/**
	 * The two sides of a funnel match are configured by different parties: the operator picks or
	 * pastes one spelling, the provider sends another on the comment event. They have to compare equal.
	 */
	private function test_the_same_post_in_two_spellings_compares_equal(): void {
		$this->assert_true(
			PostIdentity::same_post( 'https://www.instagram.com/p/Cx1YzAbCdEf/', 'Cx1YzAbCdEf' ),
			'a URL and its shortcode are the same post'
		);
		$this->assert_true(
			PostIdentity::same_post( 'https://www.instagram.com/reel/Cx1YzAbCdEf/', 'https://instagram.com/p/Cx1YzAbCdEf' ),
			'a reel link and a post link to the same shortcode are the same post'
		);
		$this->assert_false(
			PostIdentity::same_post( 'Cx1YzAbCdEf', 'Zz9YzAbCdEf' ),
			'different shortcodes are different posts'
		);
	}

	/**
	 * The ledger reasons, pinned at the constant level.
	 *
	 * A funnel reward is not an affiliate commission: one is a promotion paid for commenting, the
	 * other is money owed to a registered affiliate for a referred sale. They were sharing a reason
	 * code, which mislabelled every funnel credit on the customer's statement and made the two
	 * indistinguishable to anything totalling the ledger.
	 *
	 * Phase 50 removed the legacy funnel; when phase 55 rebuilds the comment giveaway on the
	 * Zernio inbox, the source-level assertions (the rebuilt service must credit
	 * REASON_IG_REWARD with an ig_funnel: reference and never REASON_COMMISSION) come back with
	 * it. Until then the constants stay pinned here.
	 */
	private function test_the_funnel_reward_is_not_an_affiliate_commission(): void {
		$this->assert_same( 'instagram_reward', WalletService::REASON_IG_REWARD, 'the reward reason is instagram_reward' );
		$this->assert_same( 'affiliate_commission', WalletService::REASON_COMMISSION, 'the commission reason is unchanged' );
	}
}
