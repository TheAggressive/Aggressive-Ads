<?php
/**
 * Placement size vocabulary.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Common IAB sizes plus the rule for a custom WxH.
 *
 * Storage is always `{width}x{height}` with ASCII `x`. The dropdown labels are
 * display only. Two placements may share a size; the placement post is the
 * slot identity.
 */
final class Ad_Sizes {

	/**
	 * Custom is a form value, never stored.
	 */
	public const CUSTOM = 'custom';

	/**
	 * Common sizes offered in Inventory, keyed by stored value.
	 *
	 * @return array<string, string>
	 */
	public static function catalogue(): array {
		return array(
			'728x90'  => 'Leaderboard (728×90)',
			'970x90'  => 'Super Leaderboard (970×90)',
			'970x250' => 'Billboard (970×250)',
			'300x250' => 'Medium Rectangle (300×250)',
			'336x280' => 'Large Rectangle (336×280)',
			'300x600' => 'Half Page (300×600)',
			'160x600' => 'Wide Skyscraper (160×600)',
			'120x600' => 'Skyscraper (120×600)',
			'320x50'  => 'Mobile Banner (320×50)',
			'320x100' => 'Large Mobile Banner (320×100)',
			'250x250' => 'Square (250×250)',
			'468x60'  => 'Full Banner (468×60)',
			'720x300' => 'Wide Content (720×300)',
		);
	}

	/**
	 * Whether a stored size string is an exact pixel pair we can serve.
	 *
	 * @param string $size Candidate `{width}x{height}`.
	 */
	public static function is_valid( string $size ): bool {
		$parsed = Campaign_Rules::parse_size( $size );

		if ( null === $parsed ) {
			return false;
		}

		return ! Upload_Rules::exceeds_pixels( $parsed[0], $parsed[1] );
	}

	/**
	 * Compose a stored size from integers.
	 *
	 * @param int $width  Pixels.
	 * @param int $height Pixels.
	 */
	public static function from_dimensions( int $width, int $height ): string {
		return $width . 'x' . $height;
	}

	/**
	 * Whether the size is in the common catalogue.
	 *
	 * @param string $size Stored size.
	 */
	public static function is_listed( string $size ): bool {
		return isset( self::catalogue()[ $size ] );
	}
}
