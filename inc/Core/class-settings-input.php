<?php
/**
 * Shapes a raw settings request into the document `Settings::save()` expects.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Core;

use Aggressive\Ads\Domain\Settings_Schema;

/**
 * One shaper for both save paths.
 *
 * The Settings screen can be saved two ways: a form POST to admin-post.php, and
 * an autosave POST to the REST route. Both arrive as an untrusted array, and
 * both must end up as the same document, because `Settings_Schema::validate()`
 * is the only thing standing between a request and the option — a field that
 * one path shapes and the other drops is a field the contrast gate never sees.
 *
 * Shaping is deliberately separate from validating. Everything here is about
 * *type*: which keys exist, and what each value is. Whether the result is
 * allowed is `Settings_Schema`'s decision, and it is made once, downstream of
 * both callers.
 */
final class Settings_Input {

	/**
	 * Builds the full document from an unslashed request array.
	 *
	 * The key list comes from the schema rather than from the request. That is
	 * the difference between a settings form and an arbitrary option writer:
	 * an unexpected key in the payload is not shaped, not validated, and never
	 * reaches the option.
	 *
	 * @param array<string, mixed> $raw Unslashed request body.
	 * @return array<string, mixed>
	 */
	public static function shape( array $raw ): array {
		return array(
			'modules'    => self::flags( $raw['modules'] ?? null, Settings_Schema::module_keys() ),
			'live_edits' => self::flags( $raw['live_edits'] ?? null, Settings_Schema::edit_keys() ),
			'brand'      => self::brand( $raw['brand'] ?? null ),
			'delivery'   => self::delivery( $raw['delivery'] ?? null ),
			'tracking'   => self::tracking( $raw['tracking'] ?? null ),
		);
	}

	/**
	 * Reads an allowlisted set of switches.
	 *
	 * @param mixed $raw  Request section.
	 * @param array $keys Schema key list.
	 *
	 * @phpstan-param list<string> $keys
	 * @return array<string, bool>
	 */
	private static function flags( mixed $raw, array $keys ): array {
		$section = is_array( $raw ) ? $raw : array();
		$flags   = array();

		foreach ( $keys as $key ) {
			$flags[ $key ] = array_key_exists( $key, $section ) && self::truthy( $section[ $key ] );
		}

		return $flags;
	}

	/**
	 * Whether one switch value means on.
	 *
	 * The two callers disagree about what "on" looks like and neither is wrong.
	 * An unchecked checkbox does not post at all, so a form sends only the keys
	 * that are on, always as the string "1". JSON sends every key every time,
	 * as a real boolean. Testing presence alone would read `false` as on and
	 * silently invert every switch the autosave path touches.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function truthy( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return '' !== $value && '0' !== $value && 'false' !== strtolower( $value );
		}

		return is_scalar( $value ) && (bool) $value;
	}

	/**
	 * Brand fields, each sanitized to its own type.
	 *
	 * @param mixed $raw Request section.
	 * @return array<string, string>
	 */
	private static function brand( mixed $raw ): array {
		$section = is_array( $raw ) ? $raw : array();

		$text = static function ( string $key ) use ( $section ): string {
			return isset( $section[ $key ] ) && is_string( $section[ $key ] )
				? sanitize_text_field( $section[ $key ] )
				: '';
		};

		return array(
			'product_name'  => $text( 'product_name' ),
			'tagline'       => $text( 'tagline' ),

			/*
			 * Trimmed, not sanitized.
			 *
			 * sanitize_email() returns '' for anything malformed, and '' is a
			 * meaningful value here — it means "fall back to the site admin
			 * address". So sanitizing first turned a typo into a silent
			 * un-setting: the schema's support_email rule became unreachable,
			 * the autosave answered "Saved.", and the address the advertiser
			 * sees on the Help screen quietly changed. Settings_Schema::validate()
			 * decides whether it is an address; shaping only makes it a string.
			 */
			'support_email' => isset( $section['support_email'] ) && is_string( $section['support_email'] )
				? trim( wp_strip_all_tags( $section['support_email'] ) )
				: '',
			'logo_url'      => isset( $section['logo_url'] ) && is_string( $section['logo_url'] )
				? esc_url_raw( $section['logo_url'] )
				: '',
			'accent'        => $text( 'accent' ),
			'accent_strong' => $text( 'accent_strong' ),
			'canvas'        => $text( 'canvas' ),
			'surface'       => $text( 'surface' ),
			'text'          => $text( 'text' ),
		);
	}

	/**
	 * Delivery fields.
	 *
	 * @param mixed $raw Request section.
	 * @return array<string, int|string>
	 */
	private static function delivery( mixed $raw ): array {
		$section = is_array( $raw ) ? $raw : array();

		return array(
			'fill_ttl'     => isset( $section['fill_ttl'] ) && is_numeric( $section['fill_ttl'] )
				? (int) $section['fill_ttl']
				: 0,
			'house_policy' => isset( $section['house_policy'] ) && is_string( $section['house_policy'] )
				? sanitize_key( $section['house_policy'] )
				: '',
		);
	}

	/**
	 * Tracking fields.
	 *
	 * @param mixed $raw Request section.
	 * @return array<string, int>
	 */
	private static function tracking( mixed $raw ): array {
		$section = is_array( $raw ) ? $raw : array();

		return array(
			'retention_days' => isset( $section['retention_days'] ) && is_numeric( $section['retention_days'] )
				? (int) $section['retention_days']
				: 0,
		);
	}
}
