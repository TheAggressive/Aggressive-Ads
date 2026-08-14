<?php
/**
 * Equal pick among fill candidates.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Request-time rotation. Weighted share is a later ADR.
 */
final class Fill_Rotation {

	/**
	 * One candidate at a wrap-safe index, or null when the set is empty.
	 *
	 * @param array<int, mixed> $candidates Servable fills.
	 * @param int               $draw       Non-negative draw, typically random_int().
	 */
	public static function at( array $candidates, int $draw ): mixed {
		$count = count( $candidates );

		if ( 0 === $count ) {
			return null;
		}

		$index = $draw % $count;

		if ( $index < 0 ) {
			$index += $count;
		}

		return $candidates[ $index ];
	}
}
