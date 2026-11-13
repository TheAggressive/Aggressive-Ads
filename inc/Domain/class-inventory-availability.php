<?php
/**
 * Public inventory answers derived from the native availability calculation.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/** Buyer statuses are distinct from staff oversell and unknown verdicts. */
final class Inventory_Availability {

	public const AVAILABLE   = 'available';
	public const LIMITED     = 'limited';
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Every public status this projection can return.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array( self::AVAILABLE, self::LIMITED, self::UNAVAILABLE );
	}

	/**
	 * Whether some page inventory remains, without promising a delivery quantity.
	 *
	 * @param int|null $capacity Current matching forecast, or absent.
	 * @param int      $committed Exact-window consuming reservations.
	 * @param bool     $uncertain Incomplete assessment, stale snapshot or another overlapping window.
	 */
	public static function placement_state( ?int $capacity, int $committed, bool $uncertain ): string {
		if ( $uncertain || null === $capacity ) {
			return self::LIMITED;
		}
		return Availability::bookable( Availability::decide( $capacity, $committed, 1 )['verdict'] ) ? self::AVAILABLE : self::UNAVAILABLE;
	}

	/**
	 * Every placement is required by a selected package.
	 *
	 * @param array<int, string> $states Derived placement answers.
	 */
	public static function package_state( array $states ): string {
		if ( in_array( self::UNAVAILABLE, $states, true ) ) {
			return self::UNAVAILABLE;
		}
		return array() !== $states && array() === array_diff( $states, array( self::AVAILABLE ) ) ? self::AVAILABLE : self::LIMITED;
	}
}
