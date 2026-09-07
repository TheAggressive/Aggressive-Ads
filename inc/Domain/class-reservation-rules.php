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
 * A reservation is a time-bounded claim on a placement's forecast supply. The
 * one question everything else depends on is **which states consume capacity**,
 * because that is what a booking is checked against — get it wrong in the
 * generous direction and a publisher oversells without ever being warned; get
 * it wrong in the strict direction and released inventory is never sellable
 * again.
 *
 * Pure domain: no WordPress and no storage, so the rules can be exercised
 * exhaustively in milliseconds. Storage decides nothing here — the repository
 * asks this class which states to sum.
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
	 * Nine characters. Stated because `wp_posts.post_status` is `varchar(20)`
	 * and a longer slug there truncates on write and never matches on read —
	 * this is our own table and not subject to that, but the habit of checking
	 * is what stops the next status from being invented at eleven characters
	 * and stored somewhere that cares.
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
	 * **Held counts.** A hold that did not consume capacity would let the same
	 * inventory be promised to every advertiser who asked for it, which is the
	 * entire failure a reservation exists to prevent — the check would pass for
	 * all of them and the shortfall would only appear when the window ran.
	 *
	 * **Released and expired do not.** Inventory given back has to become
	 * sellable again, or a publisher's capacity ratchets downward with every
	 * cancelled booking and the placement eventually refuses everything.
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
	 * **Released and expired are terminal.** Re-holding a released reservation
	 * would recreate the original claim's position in the queue without
	 * re-checking capacity, so giving inventory back and wanting it again is a
	 * new reservation — which is checked, dated and audited as one.
	 *
	 * A confirmed reservation may still be released, because a campaign can be
	 * cancelled after it is booked and the inventory has to return to the pool.
	 * It may not go back to held: a hold is the weaker claim, and letting a
	 * commitment decay into one silently would let a publisher believe
	 * inventory was still spoken for when nobody had committed to it.
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
