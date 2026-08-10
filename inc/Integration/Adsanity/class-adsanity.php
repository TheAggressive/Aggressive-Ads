<?php
/**
 * What AdSanity is, from this plugin's point of view.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Integration\Adsanity;

/**
 * The names AdSanity uses, and whether it is here.
 *
 * **This directory is the only place these strings appear.** AdSanity is a
 * third-party plugin whose meta keys are undocumented implementation detail
 * read out of its source; when it changes, the blast radius has to be one
 * directory. `bin/ci/check-boundaries.php` fails the build on any of these
 * identifiers appearing elsewhere in inc/.
 *
 * Every value here is verified against the installed 2.0.1 source, with file
 * and line recorded in docs/adsanity-integration.md.
 */
final class Adsanity {

	/**
	 * The ad post type.
	 */
	public const POST_TYPE = 'ads';

	/**
	 * The taxonomy that determines where an ad renders.
	 */
	public const TAXONOMY = 'ad-group';

	public const META_URL        = '_url';
	public const META_TARGET     = '_target';
	public const META_SIZE       = '_size';
	public const META_NOTES      = '_notes';
	public const META_START_DATE = '_start_date';
	public const META_END_DATE   = '_end_date';

	/**
	 * The filter through which the size map must always be read.
	 *
	 * Reading the raw option instead would miss the sizes the
	 * adsanity-custom-ad-sizes add-on injects, and reject valid ones.
	 */
	public const FILTER_AD_SIZES = 'adsanity_ad_sizes';

	/**
	 * Whether AdSanity is present and usable.
	 *
	 * Tested by the shapes we actually need rather than by a version constant,
	 * because that is what the publisher depends on — and because the contract
	 * stub provides exactly those shapes and nothing else.
	 *
	 * AdSanity being absent is a supported state, not an error state: the
	 * portal keeps accepting and reviewing campaigns, and only approval
	 * refuses. See docs/architecture.md.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return post_type_exists( self::POST_TYPE ) && taxonomy_exists( self::TAXONOMY );
	}

	/**
	 * "No end date", as an integer.
	 *
	 * AdSanity defines ADSANITY_EOL as the **string** '2082672000'. Every
	 * consumer compares numerically so the string form works, but storing an
	 * int keeps our own meta_query and comparisons honest.
	 *
	 * @return int
	 */
	public static function end_of_life(): int {
		return defined( 'ADSANITY_EOL' ) ? (int) constant( 'ADSANITY_EOL' ) : 2082672000;
	}

	/**
	 * The current size map, read through the filter.
	 *
	 * @return array<string, string>
	 */
	public static function sizes(): array {
		/**
		 * Filters the AdSanity size map. Owned by AdSanity, not by us.
		 *
		 * @param array<string, string> $sizes Size key to label.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- AdSanity's own filter, applied here to read its size map. Prefixing it with ours would invent a hook nobody listens to.
		$sizes = apply_filters( self::FILTER_AD_SIZES, array() );

		if ( ! is_array( $sizes ) ) {
			return array();
		}

		$clean = array();

		foreach ( $sizes as $key => $label ) {
			if ( is_string( $key ) && is_string( $label ) ) {
				$clean[ $key ] = $label;
			}
		}

		return $clean;
	}

	/**
	 * Whether a size string is one AdSanity currently knows.
	 *
	 * AdSanity does not validate `_size` at save: any string is accepted and
	 * stored, then rendered with an empty CSS class and shown as
	 * "- invalid size -" in the admin list. So it will not catch our typos,
	 * and we check before publishing. See docs/known-issues.md.
	 *
	 * @param string $size Size key, e.g. `728x90`.
	 * @return bool
	 */
	public static function knows_size( string $size ): bool {
		return array_key_exists( $size, self::sizes() );
	}

	/**
	 * Whether an ad-group term exists.
	 *
	 * @param int $term_id Term id.
	 * @return bool
	 */
	public static function group_exists( int $term_id ): bool {
		if ( $term_id <= 0 || ! taxonomy_exists( self::TAXONOMY ) ) {
			return false;
		}

		$term = get_term( $term_id, self::TAXONOMY );

		return $term instanceof \WP_Term;
	}
}
