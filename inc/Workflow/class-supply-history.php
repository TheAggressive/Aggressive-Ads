<?php
/**
 * Assembles a placement's observed supply and forecasts from it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Decision_Outcome;
use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Domain\Supply_Forecast;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use InvalidArgumentException;

/**
 * Turns stored counters into the history a forecast can be drawn from.
 *
 * `Domain\Supply_Forecast` states that exclusions are the caller's judgement,
 * because only a caller can tell a day that produced nothing from a day before
 * the placement existed. This is that caller. It makes three decisions the
 * arithmetic deliberately refuses to make:
 *
 * **A day the placement existed and produced nothing is a zero.** The counters
 * write no row for such a day, so history read from them alone contains only
 * the busy days — and a quantile over only the busy days describes a placement
 * that is busy every day. A slot that serves on weekdays and sits idle at the
 * weekend would be sold as though the weekend did not exist. Every day from the
 * placement's creation onward that has no row is filled in as an explicit zero.
 *
 * **A day before the placement existed supplies nothing.** Not a zero — those
 * days are not evidence of anything, and counting them would push a new
 * placement's estimate to zero for as long as the history window is wide.
 *
 * **History stops at the last sealed day.** {@see Rollup_Reconciler} decides
 * where today ends and this asks it rather than deciding again. Today is
 * partial by definition, and a half-day counted as a whole one is the lowest
 * number in the series — which is exactly the number a conservative estimate
 * reaches for.
 */
final class Supply_History {

	/**
	 * Days of history a forecast is drawn from by default.
	 *
	 * A quarter. Long enough for twelve observations of every weekday, which is
	 * three times what {@see Supply_Forecast::MIN_WEEKDAY_DAYS} needs before it
	 * will model a weekly cycle, and short enough that a placement whose
	 * traffic changed a season ago is not still being sold on the old figure.
	 */
	public const DEFAULT_HISTORY_DAYS = 90;

	/** Upper bound on history, so a caller cannot ask for the whole table. */
	public const MAX_HISTORY_DAYS = 366;

	/**
	 * Reads the counters, and the placement that produced them.
	 *
	 * @param Decision_Rollup_Repository $decisions  Stored opportunity counters.
	 * @param Placement_Repository       $placements Answers when a placement began.
	 */
	public function __construct(
		private readonly Decision_Rollup_Repository $decisions,
		private readonly Placement_Repository $placements
	) {}

	/**
	 * A placement's observed daily supply, zero-filled where it existed.
	 *
	 * @param int    $placement     Placement post id.
	 * @param string $opportunity   `Domain\Opportunity` kind.
	 * @param int    $history_days  Days of history ending at the last sealed day.
	 * @return array<string, int> UTC day to opportunities, ascending.
	 */
	public function observed( int $placement, string $opportunity, int $history_days = self::DEFAULT_HISTORY_DAYS ): array {
		$last = Rollup_Reconciler::latest_closed_day();

		if ( '' === $last || $placement <= 0 || ! Opportunity::is_valid( $opportunity ) ) {
			return array();
		}

		if ( ! $this->placements->exists( $placement ) ) {
			return array();
		}

		$days = max( 1, min( self::MAX_HISTORY_DAYS, $history_days ) );

		$first = gmdate( 'Y-m-d', strtotime( $last . ' 00:00:00 UTC' ) - ( $days - 1 ) * DAY_IN_SECONDS );

		/*
		 * A placement created inside the window shortens it. Reading further
		 * back would not find rows anyway; the reason to stop here is the
		 * zero-fill below, which would otherwise invent evidence of an empty
		 * placement for every day before it was made.
		 */
		$created = $this->placements->first_day_utc( $placement );

		if ( '' !== $created && $created > $first ) {
			$first = $created;
		}

		if ( $first > $last ) {
			return array();
		}

		$recorded = $this->decisions->daily_events_for_placement( $placement, Decision_Outcome::REQUEST, $opportunity, $first, $last );

		if ( '' === $created ) {
			/*
			 * **No creation date, no zero-fill.** A placement whose
			 * `post_date_gmt` is the zero sentinel cannot say which days it
			 * existed for, and filling those days with zeros would invent
			 * evidence of an empty placement rather than report the absence of
			 * evidence. The recorded days stand on their own, and the estimate
			 * drawn from them is the optimistic one a busy-days-only series
			 * gives — which is visible in `days_observed` rather than hidden.
			 */
			return $recorded;
		}

		$series = array();

		try {
			$window = Supply_Forecast::days_in_window( $first, $last );
		} catch ( InvalidArgumentException ) {
			return $recorded;
		}

		foreach ( $window as $day ) {
			$series[ $day ] = $recorded[ $day ] ?? 0;
		}

		return $series;
	}

	/**
	 * What a placement actually supplied between two days.
	 *
	 * The counterpart to a forecast, and what its error is measured against.
	 * No zero-filling and no creation date: this is a sum over a closed window
	 * rather than a distribution to draw from, and a day with no row
	 * contributes nothing to a sum either way.
	 *
	 * **Returns null for a window that has not closed.** A partial window
	 * summed as though it were complete produces an actual lower than the
	 * truth, and a forecast error computed from it would report every
	 * placement as over-forecast — an error that says the model is pessimistic
	 * when what happened is that nobody waited.
	 *
	 * @param int    $placement   Placement post id.
	 * @param string $opportunity `Domain\Opportunity` kind.
	 * @param string $from_utc    First day of the window, `Y-m-d`.
	 * @param string $to_utc      Last day of the window, `Y-m-d`.
	 * @return int|null Opportunities recorded, or null while the window is open.
	 */
	public function supplied( int $placement, string $opportunity, string $from_utc, string $to_utc ): ?int {
		$last = Rollup_Reconciler::latest_closed_day();

		if ( '' === $last || $placement <= 0 || ! Opportunity::is_valid( $opportunity ) ) {
			return null;
		}

		if ( $to_utc > $last || $to_utc < $from_utc ) {
			return null;
		}

		$recorded = $this->decisions->daily_events_for_placement( $placement, Decision_Outcome::REQUEST, $opportunity, $from_utc, $to_utc );

		return array_sum( $recorded );
	}

	/**
	 * What a placement is likely to supply between two days.
	 *
	 * @param int    $placement    Placement post id.
	 * @param string $opportunity  `Domain\Opportunity` kind.
	 * @param string $from_utc     First day of the window, `Y-m-d`.
	 * @param string $to_utc       Last day of the window, `Y-m-d`.
	 * @param int    $history_days Days of history to draw from.
	 * @return array{estimate: int|null, optimistic: int|null, confidence: string, days_observed: int, days_forecast: int}
	 */
	public function forecast( int $placement, string $opportunity, string $from_utc, string $to_utc, int $history_days = self::DEFAULT_HISTORY_DAYS ): array {
		try {
			$window = Supply_Forecast::days_in_window( $from_utc, $to_utc );
		} catch ( InvalidArgumentException ) {
			/*
			 * A window the domain refuses is a caller defect, and the caller is
			 * the layer that can answer a person. An empty window forecasts
			 * nothing, which reads as "no answer" rather than as zero supply.
			 */
			$window = array();
		}

		return Supply_Forecast::for_window( $this->observed( $placement, $opportunity, $history_days ), $window );
	}
}
