<?php
/**
 * Where each ad is drawn in the Review step's page sketch.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

/**
 * Sorts a campaign's placements into the sketch's two areas.
 *
 * A placement stores a name, a size, its groups and its limits — nothing about
 * where on a page it renders — so the sketch cannot show where an ad really
 * sits. It draws by shape instead: a size at least twice as wide as it is tall
 * runs across the article, anything else sits in the side column. That is a
 * convention for an illustration, kept in one place so the template does not
 * carry it and a test can pin it. **Revisit if a placement gains a position**
 * (see `docs/portal-routing-and-ui.md`): this should then read it rather than
 * guess, and the shape rule becomes the fallback.
 *
 * No WordPress function is called here.
 */
final class Page_Preview {

	/**
	 * A size at least this many times wider than tall is a banner.
	 *
	 * Two puts 728×90 and 970×250 across the article and 300×250, 160×600 and
	 * 300×600 beside it, which is where those sizes are sold to be.
	 */
	public const BANNER_RATIO = 2;

	/**
	 * Splits placements into the article column and the side column.
	 *
	 * A placement whose size is not `WIDTHxHEIGHT` is left out rather than drawn
	 * as a guess: a tile with an invented shape is worse than a missing one,
	 * because the sketch is meant to be believed.
	 *
	 * Only the first creative of a placement is drawn. A rotation has several,
	 * and the sketch shows one at rest; the list under it names the rest.
	 *
	 * @param array<int, mixed> $slots Placements as `Creative_View_Data::creative_slots()` returns them.
	 * @return array{column: list<array{width: int, height: int, preview: string}>, rail: list<array{width: int, height: int, preview: string}>}
	 */
	public static function arrange( array $slots ): array {
		$arranged = array(
			'column' => array(),
			'rail'   => array(),
		);

		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$dimensions = array();

			if ( 1 !== preg_match( '/^(\d+)x(\d+)$/', (string) ( $slot['size'] ?? '' ), $dimensions ) ) {
				continue;
			}

			$width  = (int) $dimensions[1];
			$height = (int) $dimensions[2];

			if ( $width < 1 || $height < 1 ) {
				continue;
			}

			$first   = $slot['creatives'][0] ?? null;
			$preview = is_array( $first ) ? (string) ( $first['preview'] ?? '' ) : '';

			$arranged[ $width >= self::BANNER_RATIO * $height ? 'column' : 'rail' ][] = array(
				'width'   => $width,
				'height'  => $height,
				'preview' => $preview,
			);
		}

		return $arranged;
	}
}
