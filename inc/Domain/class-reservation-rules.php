<?php
/**
 * What a reservation is, and which ones consume inventory.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * The reservation vocabulary and its lifecycle.
 *
 * Everything depends on **which states consume capacity**: too generous and a
 * publisher oversells unwarned, too strict and released inventory is never
 * sellable again. The repository asks this class which states to sum rather
 * than restating them.
 */
final class Reservation_Rules {

	/** Claimed but not yet committed. Consumes capacity. */
	public const HELD = 'held';

	/** Committed against a campaign. Consumes capacity. */
	public const CONFIRMED = 'confirmed';

	/** Given back deliberately. Consumes nothing. */
	public const RELEASED = 'released';

	/** Its window passed without being confirmed. Consumes nothing. */
	public const EXPIRED = 'expired';

	/**
	 * Longest status this column must hold.
	 *
	 * `wp_posts.post_status` is `varchar(20)`, where a longer slug truncates on
	 * write and never matches on read. This is our own table, but the next
	 * status may not be.
	 */
	public const MAX_LENGTH = 20;

	/**
	 * Every status a reservation may hold.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::HELD, self::CONFIRMED, self::RELEASED, self::EXPIRED );
	}

	/**
	 * The statuses that count against forecast capacity.
	 *
	 * **Held counts**, or the same inventory is promised to everybody who asks
	 * and the shortfall appears only when the window runs. **Released and
	 * expired do not**, or capacity ratchets downward with every cancellation
	 * until the placement refuses everything.
	 *
	 * @return list<string>
	 */
	public static function consuming(): array {
		return array( self::HELD, self::CONFIRMED );
	}

	/**
	 * Whether a status consumes capacity.
	 *
	 * @param string $status Candidate status.
	 */
	public static function consumes( string $status ): bool {
		return in_array( $status, self::consuming(), true );
	}

	/**
	 * Whether this code may be stored.
	 *
	 * @param string $status Candidate status.
	 */
	public static function is_status( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	/**
	 * Statuses a reservation may move to from where it is.
	 *
	 * **Released and expired are terminal**: re-holding would restore a claim
	 * without re-checking capacity, so wanting it back is a new reservation.
	 *
	 * A confirmed reservation may still be released — a campaign can be
	 * cancelled — but never returned to held. A hold is the weaker claim, and a
	 * commitment decaying into one silently leaves a publisher believing
	 * inventory is spoken for when nobody has committed.
	 *
	 * @param string $from Current status.
	 * @return list<string>
	 */
	public static function next_from( string $from ): array {
		return match ( $from ) {
			self::HELD      => array( self::CONFIRMED, self::RELEASED, self::EXPIRED ),
			self::CONFIRMED => array( self::RELEASED ),
			default         => array(),
		};
	}

	/**
	 * Whether one status change is allowed.
	 *
	 * @param string $from Current status.
	 * @param string $to   Requested status.
	 */
	public static function may_move( string $from, string $to ): bool {
		return in_array( $to, self::next_from( $from ), true );
	}

	/**
	 * Whether a quantity is a claim somebody could make.
	 *
	 * Zero is refused rather than treated as a release. A reservation for
	 * nothing occupies a row, a version and a place in an audit trail while
	 * claiming no inventory, which reads to every later reader as a booking
	 * that was somehow lost.
	 *
	 * @param int $quantity Requested opportunities.
	 */
	public static function is_quantity( int $quantity ): bool {
		return $quantity > 0;
	}
}
