<?php
/**
 * Rules for the group slugs a placement is filed under.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Normalises a set of placement group slugs.
 *
 * Deliberately not `sanitize_title()`. This is domain code, so it calls no
 * WordPress function at all — but the stronger reason is that the same input
 * has to produce the same slug in the browser, in the REST layer and in a
 * roll-up, and core's filter chain is something a site can change. A group
 * whose slug depends on what other plugins are installed cannot be compared
 * across two reads.
 *
 * Lowercasing is ASCII-only on purpose. Case-folding the rest of Unicode needs
 * mbstring, which WordPress does not require and does not polyfill for
 * `mb_strtolower`, so a group named in one language would file differently on
 * two servers. Non-ASCII letters are preserved unchanged instead: a group named
 * "Café" stays "café" if it was typed that way and "Café" if it was not, and
 * both are stable everywhere.
 */
final class Placement_Groups {

	/**
	 * `wp_terms.slug` is varchar(200).
	 *
	 * Counted in characters rather than bytes, because that is what the column
	 * holds under utf8mb4 and because cutting a multi-byte character in half
	 * produces a slug no read will ever match.
	 */
	public const MAX_SLUG_LENGTH = 200;

	/**
	 * Upper bound on groups per placement.
	 *
	 * A placement filed under more than this is not filed, and the cost of the
	 * bound is that somebody has to choose. The number matters less than that
	 * there is one: an unbounded set here becomes an unbounded `IN` clause in
	 * slice 4's roll-up.
	 */
	public const MAX_GROUPS = 20;

	/**
	 * Cleans, de-duplicates, sorts and bounds a set of group slugs.
	 *
	 * Idempotent by construction — normalising an already-normalised set
	 * returns it unchanged — which is what lets a read-back comparison verify a
	 * write instead of merely re-running the same transformation.
	 *
	 * @param mixed $slugs Anything a caller offered.
	 * @return array<int, string>
	 */
	public static function normalise( mixed $slugs ): array {
		if ( ! is_array( $slugs ) ) {
			return array();
		}

		$clean = array();

		foreach ( $slugs as $slug ) {
			if ( ! is_string( $slug ) && ! is_int( $slug ) ) {
				continue;
			}

			$one = self::slug( (string) $slug );

			if ( '' !== $one ) {
				$clean[] = $one;
			}
		}

		$clean = array_values( array_unique( $clean ) );
		sort( $clean );

		return array_slice( $clean, 0, self::MAX_GROUPS );
	}

	/**
	 * Reduces one label to a slug.
	 *
	 * @param string $label Raw label.
	 * @return string
	 */
	public static function slug( string $label ): string {
		$slug = preg_replace_callback(
			'/[A-Z]+/',
			static fn ( array $found ): string => strtolower( $found[0] ),
			$label
		);

		if ( ! is_string( $slug ) ) {
			return '';
		}

		// Any run of characters that is neither a letter nor a digit becomes a
		// single separator, whatever it was — space, punctuation or emoji.
		$slug = preg_replace( '/[^\p{L}\p{N}]+/u', '-', $slug );

		if ( ! is_string( $slug ) ) {
			return '';
		}

		$slug = trim( $slug, '-' );

		// Character-counted, not byte-counted. Trimmed again afterwards
		// because the cut can land immediately after a separator.
		if ( 1 === preg_match( '/^.{0,' . self::MAX_SLUG_LENGTH . '}/u', $slug, $captured ) ) {
			$slug = $captured[0];
		}

		return trim( $slug, '-' );
	}
}
