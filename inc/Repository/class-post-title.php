<?php
/**
 * How a post title survives a round trip through WordPress.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

/**
 * The two conversions every repository that stores a name needs.
 *
 * WordPress does not store a title verbatim, and four repositories here each
 * compared a typed name with the stored one and got it wrong in a different
 * way:
 *
 * - `wp_insert_post()` and `wp_update_post()` unslash their input, so a name
 *   passed unslashed loses every backslash on the way in.
 * - For an account without `unfiltered_html`, `title_save_pre` runs kses,
 *   which stores `&` as `&amp;`.
 * - `get_the_title()` is a display filter. It curls quotes and writes `&` as
 *   `&#038;`, so reading a name through it never matches what was typed.
 *
 * A campaign name containing `&`, `'`, `"` or a backslash therefore could not
 * be saved, and an organization with one could not be renamed. Callers slash
 * what they write, compare what they read back against `as_stored()`, and give
 * people `plain()`.
 */
final class Post_Title {

	/**
	 * The title WordPress will store for this input.
	 *
	 * Built from the filters `wp_insert_post()` applies rather than a list of
	 * characters, so it stays exact for any account and any filter a site
	 * adds. A read-back that compares against this cannot fail on a title
	 * that was, in fact, saved.
	 *
	 * @param string $title Title as submitted, unslashed.
	 * @return string Title as it will be stored.
	 */
	public static function as_stored( string $title ): string {
		$stored = wp_unslash( sanitize_post_field( 'post_title', wp_slash( $title ), 0, 'db' ) );

		return is_string( $stored ) ? $stored : '';
	}

	/**
	 * A stored title as plain text, for anything that escapes on its way out.
	 *
	 * Every consumer here — templates, mailers, REST responses, file names —
	 * escapes or sanitizes for its own context. Handing them the stored form
	 * showed "Arts &amp;amp; Culture"; handing them `get_the_title()` showed
	 * "Arts &#038; Culture". The stored value has already been made safe by
	 * kses, so decoding it is what gives each consumer the text a person typed.
	 *
	 * @param string $stored Title exactly as stored.
	 * @return string
	 */
	public static function plain( string $stored ): string {
		return wp_specialchars_decode( $stored, ENT_QUOTES );
	}
}
