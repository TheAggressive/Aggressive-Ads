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
	 * The status a campaign transition may write onto one assignment.
	 *
	 * A campaign pause pauses every assignment under it, and a resume brings
	 * them all back. That is right for the ones the campaign paused, and wrong
	 * for one a person paused on its own: the publisher who stopped a single
	 * advertisement finds it serving again after an unrelated pause and resume
	 * of its campaign, with nothing in the interface saying it moved.
	 *
	 * The reason this needed a stored flag rather than a cleverer rule is that
	 * `paused` alone cannot answer it. Both kinds of pause produce the identical
	 * row, so protecting every paused assignment would strand the ones the
	 * campaign paused — which is the worse failure, and is why this was left
	 * open rather than guessed at.
	 *
	 * **A terminal projection still wins.** A campaign that completes or is
	 * cancelled takes its assignments with it whoever paused them; an operator's
	 * pause says "not now", not "never mind what happens to the campaign", and a
	 * row left `paused` under a cancelled campaign would be a candidate the
	 * engine keeps considering for a campaign that has ended.
	 *
	 * @param string $projected       Status derived from the campaign.
	 * @param bool   $operator_paused Whether a person paused this assignment itself.
	 * @return string The status to write.
	 */
	public static function project_status( string $projected, bool $operator_paused ): string {
		if ( ! $operator_paused || self::is_terminal( $projected ) ) {
			return $projected;
		}

		return self::PAUSED;
	}

	/**
	 * Whether a status change is a person pausing this assignment on its own.
	 *
	 * Asked of the *edit* path only. A campaign transition reaches assignments
	 * through `project_status()` and never through here, which is what keeps the
	 * two kinds of pause distinguishable at all.
	 *
	 * @param string $to Status being written.
	 * @return bool
	 */
	public static function is_operator_pause( string $to ): bool {
		return self::PAUSED === $to;
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
	 * Whether a status is one nothing may move an assignment out of.
	 *
	 * `transitions()` already says so — both have no outgoing edges — but the
	 * projection needs to ask the question directly rather than infer it from
	 * an empty array, and a caller reading `array() === transitions()[$status]`
	 * would be one refactor away from meaning something else.
	 *
	 * This is what protects a withdrawal. An assignment retired while its
	 * campaign is live must not be resurrected the next time the campaign
	 * transitions, and a completed one must not restart.
	 *
	 * @param string $status Current status.
	 * @return bool
	 */
	public static function is_terminal( string $status ): bool {
		return in_array( $status, array( self::COMPLETED, self::CANCELLED ), true );
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
	 * What every creative on one placement adds up to, as a percentage.
	 */
	public const SHARE_TOTAL = 100;

	/**
	 * The placement's weights after one creative is given a share of it.
	 *
	 * **A share is a share of something.** The stored weight is relative —
	 * 3 is only three times something — and setting one creative's number
	 * without touching the others meant the advertiser typed 70 and got 41%,
	 * with no way to say "seventy per cent" at all. Here the number is the
	 * percentage: the creative takes it, and what is left is divided between
	 * the others **in the proportions they already had**, so a rotation set
	 * to 60/30/10 and then changed at the top keeps 3:1 underneath.
	 *
	 * Whole numbers that sum to exactly `SHARE_TOTAL`, by largest remainder —
	 * rounding each independently leaves 99 or 101 and a rotation that never
	 * quite matches what the screen says. Every creative keeps at least one
	 * per cent, because a weight below `MIN_WEIGHT` is not a valid assignment
	 * and "0%" is `Remove`, not a share.
	 *
	 * @param array<int, int> $weights Current weight by assignment id, the target included.
	 * @param int             $target  The assignment being set.
	 * @param int             $percent The share it should take.
	 * @return array<int, int> New weight by assignment id, summing to SHARE_TOTAL.
	 */
	public static function rebalance( array $weights, int $target, int $percent ): array {
		if ( ! isset( $weights[ $target ] ) ) {
			return $weights;
		}

		$others = array_diff_key( $weights, array( $target => 0 ) );

		if ( array() === $others ) {
			return array( $target => self::SHARE_TOTAL );
		}

		// One per cent each for the others, and one for this one.
		$percent = max( self::MIN_WEIGHT, min( self::SHARE_TOTAL - count( $others ), $percent ) );
		$rest    = self::SHARE_TOTAL - $percent;
		$sum     = array_sum( array_map( static fn ( int $weight ): int => max( 0, $weight ), $others ) );
		$shares  = array();
		$exact   = array();

		foreach ( $others as $id => $weight ) {
			$share         = $sum > 0 ? ( max( 0, $weight ) / $sum ) * $rest : $rest / count( $others );
			$shares[ $id ] = max( self::MIN_WEIGHT, (int) floor( $share ) );
			$exact[ $id ]  = $share - floor( $share );
		}

		/*
		 * The floors lose up to one per cent each; largest remainder first
		 * gives those back, so the column adds to a hundred rather than to
		 * ninety-seven.
		 */
		arsort( $exact );

		$left = $rest - array_sum( $shares );

		foreach ( array_keys( $exact ) as $id ) {
			if ( $left <= 0 ) {
				break;
			}

			++$shares[ $id ];
			--$left;
		}

		// Over a hundred only when every other was floored up to its minimum.
		foreach ( array_keys( $shares ) as $id ) {
			if ( $left >= 0 ) {
				break;
			}

			if ( $shares[ $id ] > self::MIN_WEIGHT ) {
				--$shares[ $id ];
				++$left;
			}
		}

		$shares[ $target ] = self::SHARE_TOTAL - array_sum( $shares );

		/*
		 * In the order they arrived, which is the order the placement's cards
		 * are drawn in. A caller reading this as a list would otherwise find
		 * the creative it just set at the end.
		 */
		$ordered = array();

		foreach ( array_keys( $weights ) as $id ) {
			$ordered[ $id ] = $shares[ $id ];
		}

		return $ordered;
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
