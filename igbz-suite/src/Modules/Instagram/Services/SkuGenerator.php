<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Mints the two codes a registration needs, which are deliberately not the same string.
 *
 * The *public code* is what a shopper reads off the post and types into a comment. It is the
 * WooCommerce product id, zero-padded to at least four digits — digits only, because the audience
 * is on a Persian keyboard and an alphanumeric token like IGBZ-P6R4 means switching layouts,
 * hunting for letters and usually mistyping at least once. Digits are the one character class that
 * is trivial to type in every layout, and the canonical form folds Persian and Arabic
 * digits to ASCII, so a shopper who comments ۰۰۴۷ matches a funnel stored as 0047.
 *
 * The padding is not cosmetic. WooCommerce ids start low, and a bare "12" under a post is
 * something people type by accident — a reply, a quantity, half a phone number — which would fire
 * the funnel and DM a purchase link to somebody who never asked. Four digits makes an accidental
 * match implausible while staying comfortable to type.
 *
 * The *SKU* stays the alphanumeric IGBZ-P6R4 form. It is an inventory identifier: it appears on
 * invoices and packing lists, it has to be unique across a catalogue that also contains
 * hand-entered products, and it must not be confusable with an order number or a quantity. Its
 * alphabet drops everything ambiguous in a condensed font (0/O, 1/I/L, 5/S, 8/B, 2/Z), which is
 * still the right call for something a warehouse reads aloud.
 *
 * Uniqueness is checked against both the intake table and the WooCommerce SKU index, because a
 * code could have been typed by hand into a product created before this flow existed.
 */
final class SkuGenerator {

	/** Unambiguous characters only: no 0/O, 1/I/L, 2/Z, 5/S, 8/B. */
	private const ALPHABET = '34679ACDEFGHJKMNPQRTUVWXY';

	private const LENGTH = 4;

	/**
	 * Shortest public code we will show a shopper.
	 *
	 * Below four digits the code stops being a deliberate token: "12" or "470" get typed under a
	 * post for a dozen innocent reasons and each one would trigger a DM.
	 */
	public const PUBLIC_MIN_DIGITS = 4;

	/** Give up after this many collisions and lengthen the code instead of looping forever. */
	private const MAX_TRIES = 12;

	public function __construct( private Db $db ) {}

	public function prefix(): string {
		$prefix = igbz()->settings()->string( 'intake.sku_prefix', 'IGBZ' );
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $prefix ) ?? '' );

		return '' === $prefix ? 'IGBZ' : substr( $prefix, 0, 8 );
	}

	/**
	 * A code that is free right now.
	 *
	 * Not reserved: the caller writes it onto the intake row, whose UNIQUE index is the real
	 * arbiter. Two concurrent registrations that draw the same code will have one insert fail,
	 * and the caller retries — cheaper than holding a lock across a user-facing request.
	 */
	public function generate(): string {
		$prefix = $this->prefix();

		for ( $attempt = 0; $attempt < self::MAX_TRIES; $attempt++ ) {
			// Widen the code once collisions suggest the space is getting crowded.
			$length    = self::LENGTH + (int) floor( $attempt / 4 );
			$candidate = $prefix . '-' . $this->random( $length );

			if ( $this->is_free( $candidate ) ) {
				return $candidate;
			}
		}

		// Fall back to something that cannot realistically collide.
		return $prefix . '-' . $this->random( 8 );
	}

	public function is_free( string $code ): bool {
		$taken = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_intake' ) . ' WHERE sku = %s',
			$code
		);
		if ( $taken > 0 ) {
			return false;
		}

		return ! ( function_exists( 'wc_get_product_id_by_sku' ) && wc_get_product_id_by_sku( $code ) > 0 );
	}

	/**
	 * Whether some other funnel already answers this public code.
	 *
	 * The product id cannot collide with another *registration*, but it can collide with a
	 * funnel somebody built by hand — a store that made a "1247" keyword campaign last year and
	 * then registers product 1247 would have two funnels racing for the same comment. This is
	 * rare enough to report rather than design around, and the caller logs it.
	 */
	public function public_code_conflicts( string $code, int $ignore_funnel_id = 0 ): bool {
		if ( '' === $code ) {
			return false;
		}

		$sql  = 'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_funnels' ) . ' WHERE keyword = %s';
		$args = [ $this->keyword( $code ) ];

		if ( $ignore_funnel_id > 0 ) {
			$sql   .= ' AND id != %d';
			$args[] = $ignore_funnel_id;
		}

		return (int) $this->db->scalar( $sql, ...$args ) > 0;
	}

	/**
	 * The shopper-facing code for a product: its WooCommerce id, zero-padded.
	 *
	 * Uniqueness is free — the id is a primary key — which is the main reason this beats the
	 * random token it replaced. There is no collision loop, nothing to reserve, and no way for
	 * two products to end up sharing a code.
	 *
	 * The padding only ever grows: product 1247 is "1247", not "01247". Once a store passes
	 * 9999 products the codes simply get longer, and older four-digit codes stay valid because
	 * they are still that product's id.
	 */
	public function public_code( int $product_id ): string {
		if ( $product_id <= 0 ) {
			return '';
		}

		$digits = (int) igbz()->settings()->int( 'intake.code_digits', self::PUBLIC_MIN_DIGITS );
		$digits = max( self::PUBLIC_MIN_DIGITS, min( 12, $digits ) );

		return str_pad( (string) $product_id, $digits, '0', STR_PAD_LEFT );
	}

	/**
	 * The comment keyword for a public code.
	 *
	 * canonical() lower-cases and folds Persian/Arabic digits before matching, so
	 * an all-digit code is already in canonical form. This stays a named method rather than an
	 * inlined strtolower because the funnel and the caption must agree on one definition — when
	 * the code was alphanumeric, disagreeing here silently produced funnels that never fired.
	 */
	public function keyword( string $code ): string {
		return mb_strtolower( trim( $code ) );
	}

	private function random( int $length ): string {
		$alphabet = self::ALPHABET;
		$max      = strlen( $alphabet ) - 1;
		$out      = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $alphabet[ random_int( 0, $max ) ];
		}

		return $out;
	}
}
