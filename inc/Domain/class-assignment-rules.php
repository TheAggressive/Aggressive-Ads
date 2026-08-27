<?php
/**
 * What a creative assignment may say about itself.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * The assignment's own rules, with no WordPress in sight.
 *
 * `inc/Domain/` calls no WordPress function at all, which is what makes these
 * assertable exhaustively in milliseconds — and exhaustive is what the window
 * rule needs, because it is the one an advertiser could otherwise use to run an
 * ad outside the period a publisher sold them.
 */
final class Assignment_Rules {

	public const DRAFT     = 'draft';
	public const READY     = 'ready';
	public const LIVE      = 'live';
	public const PAUSED    = 'paused';
	public const COMPLETED = 'completed';
	public const CANCELLED = 'cancelled';

	/** Weight is a share, not a percentage: any positive whole number. */
	public const MIN_WEIGHT = 1;

	/**
	 * An upper bound so one assignment cannot swamp arithmetic later.
	 *
	 * P3 divides by the sum of weights in a rotation. A weight of PHP_INT_MAX
	 * beside a weight of one is not a preference, it is an overflow waiting for
	 * a second competitor.
	 */
	public const MAX_WEIGHT = 10000;

	/**
	 * Every status an assignment may hold.
	 *
	 * @return array<int, string>
	 */
	public static function statuses(): array {
		return array(
			self::DRAFT,
			self::READY,
			self::LIVE,
			self::PAUSED,
			self::COMPLETED,
			self::CANCELLED,
		);
	}

	/**
	 * Whether a status string is one of ours.
	 *
	 * @param string $status Candidate status.
	 * @return bool
	 */
	public static function is_status( string $status ): bool {
		return in_array( $status, self::statuses(), true );
	}

	/**
	 * The edges a person may move an assignment along.
	 *
	 * Declared as a table rather than as conditions at call sites, for the
	 * reason `Transition_Table` already records for campaigns: a rule spread
	 * across call sites is a rule with no single answer, and the first
	 * disagreement is silent.
	 *
	 * Pause and resume are the pair this exists for. `completed` and
	 * `cancelled` are terminal: an assignment that finished its run or was
	 * withdrawn does not come back, because the thing that would come back is
	 * not the thing that was approved.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function transitions(): array {
		return array(
			self::DRAFT     => array( self::READY, self::CANCELLED ),
			self::READY     => array( self::LIVE, self::PAUSED, self::CANCELLED ),
			self::LIVE      => array( self::PAUSED, self::COMPLETED, self::CANCELLED ),
			self::PAUSED    => array( self::LIVE, self::COMPLETED, self::CANCELLED ),
			self::COMPLETED => array(),
			self::CANCELLED => array(),
		);
	}

	/**
	 * Whether one status may become another.
	 *
	 * A status may always stay itself: a write that changes weight and leaves
	 * status alone must not be refused for not being a transition.
	 *
	 * @param string $from Current status.
	 * @param string $to   Requested status.
	 * @return bool
	 */
	public static function can_transition( string $from, string $to ): bool {
		if ( $from === $to ) {
			return self::is_status( $from );
		}

		$edges = self::transitions();

		return isset( $edges[ $from ] ) && in_array( $to, $edges[ $from ], true );
	}

	/**
	 * Whether a weight is a usable share.
	 *
	 * @param int $weight Candidate weight.
	 * @return bool
	 */
	public static function is_weight( int $weight ): bool {
		return $weight >= self::MIN_WEIGHT && $weight <= self::MAX_WEIGHT;
	}

	/**
	 * Whether a delivery window is valid inside its parent's.
	 *
	 * **An assignment may only narrow.** This is the rule that matters most
	 * here: a campaign sold for June must not carry a creative that runs into
	 * July because somebody typed a later end date. Widening is refused rather
	 * than clamped, so the answer a person gets is "that is outside the
	 * campaign" rather than a silently different date they did not ask for.
	 *
	 * Zero means "inherit the parent", on both ends independently — a creative
	 * may start late and run to the end of the campaign without restating the
	 * end date, which is the common case for a mid-flight addition.
	 *
	 * @param int $start        Requested start, or 0 to inherit.
	 * @param int $end          Requested end, or 0 to inherit.
	 * @param int $parent_start Parent window start, 0 when unbounded.
	 * @param int $parent_end   Parent window end, 0 when unbounded.
	 * @return bool
	 */
	public static function window_fits( int $start, int $end, int $parent_start, int $parent_end ): bool {
		if ( $start < 0 || $end < 0 ) {
			return false;
		}

		if ( 0 !== $start && 0 !== $end && $end <= $start ) {
			return false;
		}

		if ( 0 !== $start && 0 !== $parent_start && $start < $parent_start ) {
			return false;
		}

		if ( 0 !== $end && 0 !== $parent_end && $end > $parent_end ) {
			return false;
		}

		/*
		 * A start after the parent ends, or an end before it begins, is a
		 * window that can never deliver. Refusing it is kinder than storing a
		 * creative that will simply never appear and reports nothing wrong.
		 */
		if ( 0 !== $start && 0 !== $parent_end && $start >= $parent_end ) {
			return false;
		}

		if ( 0 !== $end && 0 !== $parent_start && $end <= $parent_start ) {
			return false;
		}

		return true;
	}
}
