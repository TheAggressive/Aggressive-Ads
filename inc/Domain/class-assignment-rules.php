<?php
/**
 * What a creative assignment may say about itself.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

use Aggressive\Ads\Core\Post_Statuses;

/** The assignment's own rules. See docs/architecture.md for why Domain is pure. */
final class Assignment_Rules {

	public const DRAFT     = 'draft';
	public const READY     = 'ready';
	public const LIVE      = 'live';
	public const PAUSED    = 'paused';
	public const COMPLETED = 'completed';
	public const CANCELLED = 'cancelled';

	/** Weight is a share, not a percentage: any positive whole number. */
	public const MIN_WEIGHT = 1;

	/** P3 divides by the sum of weights; PHP_INT_MAX beside 1 is an overflow. */
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
	 * The assignment status a campaign status implies.
	 *
	 * An assignment has its own vocabulary. Writing a campaign status straight
	 * into the column produced values like `aggr_draft`, which no transition
	 * accepts — every pause, resume and withdrawal was refused, and the fixture
	 * looked fine. Found by a test, not by inspection.
	 *
	 * There is no `scheduled` here: an assignment inside a campaign that has not
	 * started is simply `ready`.
	 *
	 * @param string $status Campaign post status.
	 * @return string
	 */
	public static function status_for_campaign( string $status ): string {
		return match ( $status ) {
			Post_Statuses::APPROVED, Post_Statuses::SCHEDULED => self::READY,
			Post_Statuses::LIVE      => self::LIVE,
			Post_Statuses::PAUSED    => self::PAUSED,
			Post_Statuses::COMPLETE  => self::COMPLETED,
			Post_Statuses::CANCELLED => self::CANCELLED,
			default                  => self::DRAFT,
		};
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
	 * A table rather than conditions at call sites, for the reason
	 * `Transition_Table` records for campaigns. Pause and resume are the pair
	 * this exists for; `completed` and `cancelled` are terminal.
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
	 * Whether one status may become another. A status may stay itself, so a
	 * weight-only write is not refused for not being a transition.
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
	 * **An assignment may only narrow.** A campaign sold for June must not carry
	 * a creative running into July. Widening is refused rather than clamped, so
	 * nobody gets a silently different date than they submitted.
	 *
	 * Zero inherits the parent, on each end independently.
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

		// A window that can never deliver is refused rather than stored silently.
		if ( 0 !== $start && 0 !== $parent_end && $start >= $parent_end ) {
			return false;
		}

		if ( 0 !== $end && 0 !== $parent_start && $end <= $parent_start ) {
			return false;
		}

		return true;
	}
}
