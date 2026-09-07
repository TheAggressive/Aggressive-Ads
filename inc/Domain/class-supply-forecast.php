<?php
/**
 * Conservative supply estimates from observed daily opportunity history.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * How many opportunities a placement is likely to produce over a window.
 *
 * P16 opens by answering one question — *how much inventory is there* — and the
 * inventory-commerce contract requires this phase to declare the terms rather
 * than let them be inferred from the code. They are declared here, beside the
 * arithmetic they govern:
 *
 * **Source history.** Observed `request` counts per placement per UTC day, at
 * the opportunity grain P15 established: page opportunities and refresh
 * opportunities are separate series, never summed into one. Refresh supply is
 * forecast from refreshes that actually happened, never from the refresh timer
 * — a timer describes an upper bound and would forecast infinite supply from a
 * page nobody visits.
 *
 * **Exclusions.** None are applied here. Which days are real observations is
 * the caller's judgement, because only the caller knows whether a day with no
 * row means the placement produced nothing or did not yet exist. Every day
 * supplied is treated as a real observation, an explicit zero included; a day
 * omitted is treated as unknown and does not drag the estimate down.
 *
 * **Seasonality.** Day of week, when there is enough history for it. Ad supply
 * follows a weekly cycle, and pooling a Sunday with a Tuesday produces a figure
 * that is wrong on both. Below that threshold the days are pooled, because a
 * weekday quantile drawn from one observation is not seasonality — it is the
 * one number dressed up as a pattern.
 *
 * **Minimum data.** {@see self::MIN_POOLED_DAYS} days before a quantile is
 * taken at all, {@see self::MIN_WEEKDAY_DAYS} observations of every weekday in
 * the window before the weekly cycle is used.
 *
 * **Confidence.** A label naming which of those methods produced the figure,
 * so a reader can tell a well-supported estimate from a guess. Volatility is
 * *not* in the label; it is in the interval, and the two are different
 * questions — see {@see self::for_window()}.
 *
 * **Conservative fallback.** Sparse history falls back to the lowest day ever
 * observed, and no history produces no forecast rather than a zero.
 *
 * Pure domain: no WordPress, no storage, no ambient timezone. Which placement,
 * which days exist, and who may see the answer are all decided above this
 * class; a forecast is staff-only, and nothing here is safe to hand an
 * advertiser.
 */
final class Supply_Forecast {

	/**
	 * The quantile taken as the estimate.
	 *
	 * **A quantile, not a mean.** The mean is exceeded on about half of days
	 * and missed on the other half, so a publisher who sells to it
	 * under-delivers half of what they sell — the failure this phase exists to
	 * prevent. The 20th percentile says something a seller can stand behind:
	 * on four days in five, this placement produced at least this much.
	 *
	 * It is also robust in the direction that matters. One viral day lifts a
	 * mean and does not move a low quantile, so an outlier cannot talk the
	 * publisher into a commitment.
	 */
	public const ESTIMATE_QUANTILE = 0.2;

	/** The upper bound reported beside the estimate. */
	public const UPPER_QUANTILE = 0.8;

	/** Observed days below which no quantile is taken. */
	public const MIN_POOLED_DAYS = 7;

	/** Observations of each weekday below which the weekly cycle is not used. */
	public const MIN_WEEKDAY_DAYS = 4;

	/** Nothing was observed, so there is no estimate. */
	public const CONFIDENCE_NONE = 'none';

	/** Too little history for a quantile; the estimate is the lowest day seen. */
	public const CONFIDENCE_LOW = 'low';

	/** A quantile over every observed day, pooled without seasonality. */
	public const CONFIDENCE_MEDIUM = 'medium';

	/** A quantile per weekday, so the weekly cycle is carried. */
	public const CONFIDENCE_HIGH = 'high';

	/**
	 * Longest window this will forecast.
	 *
	 * A year and a day. Beyond it the history a conservative estimate rests on
	 * is older than the seasonality it claims to model, and the request is more
	 * likely a bad date than a real question.
	 */
	public const MAX_WINDOW_DAYS = 366;

	/**
	 * The estimate for one placement over one window.
	 *
	 * **`estimate` is null when nothing was observed, not zero.** A placement
	 * with no history has not been measured as empty; reporting zero would tell
	 * a publisher they have no inventory when what they have is no data, and
	 * would refuse a booking on the strength of it. This is the same
	 * distinction {@see Fill_Figures} draws for a rate with no denominator.
	 *
	 * **`estimate` is the bottom of the interval, not its middle.** There is no
	 * separate lower bound because the conservative bound *is* the answer — a
	 * figure a publisher may sell against — and `optimistic` is named for what
	 * it is so that nobody mistakes it for a second forecast. Reporting the
	 * pair as `low` and `high` invited exactly that, and left two fields
	 * carrying one number.
	 *
	 * **`optimistic` is a quantile, not a ceiling** — except in the sparse
	 * fallback, where it is the best day observed. Both are honest for the data
	 * behind them and `confidence` says which is in force, but the difference
	 * matters: above the sparse threshold a single spike moves neither bound,
	 * so no screen can present one lucky day as headroom a buyer might book
	 * against. It is not the maximum, and must not be relabelled as one.
	 *
	 * **The spread carries volatility; the confidence carries sufficiency.**
	 * They answer different questions and must not be collapsed. Thirty days of
	 * wildly swinging traffic is well-observed — high confidence — and the wide
	 * gap up to `optimistic` is the honest way to say the swing is real. The
	 * estimate is already protected from that swing, because volatility drags a
	 * low quantile down on its own.
	 *
	 * @param array<string, int|numeric-string> $observed Y-m-d in UTC to opportunities on that day.
	 * @param array<int, string>                $window   Y-m-d in UTC, the days being forecast.
	 * @return array{estimate: int|null, optimistic: int|null, confidence: string, days_observed: int, days_forecast: int}
	 */
	public static function for_window( array $observed, array $window ): array {
		$samples       = self::samples( $observed );
		$days_observed = count( $samples );
		$days_forecast = count( $window );

		$none = array(
			'estimate'      => null,
			'optimistic'    => null,
			'confidence'    => self::CONFIDENCE_NONE,
			'days_observed' => $days_observed,
			'days_forecast' => $days_forecast,
		);

		if ( 0 === $days_observed || 0 === $days_forecast ) {
			return $none;
		}

		$by_weekday = self::by_weekday( $samples );

		if ( self::weekly_cycle_supported( $by_weekday, $window ) ) {
			return self::totals( self::weekday_bounds( $by_weekday, $window ), self::CONFIDENCE_HIGH, $days_observed, $days_forecast );
		}

		$daily = array_values( $samples );

		if ( $days_observed < self::MIN_POOLED_DAYS ) {
			/*
			 * The conservative fallback. With a handful of days there is no
			 * distribution to take a quantile from, and the lowest day observed
			 * is the only figure that cannot be an overstatement.
			 */
			$floor = min( $daily );

			return self::totals(
				array( $floor * $days_forecast, max( $daily ) * $days_forecast ),
				self::CONFIDENCE_LOW,
				$days_observed,
				$days_forecast
			);
		}

		return self::totals(
			array(
				self::quantile( $daily, self::ESTIMATE_QUANTILE ) * $days_forecast,
				self::quantile( $daily, self::UPPER_QUANTILE ) * $days_forecast,
			),
			self::CONFIDENCE_MEDIUM,
			$days_observed,
			$days_forecast
		);
	}

	/**
	 * Every UTC day from `$from` to `$to`, inclusive.
	 *
	 * Bounded rather than trusting, because the window reaches this class from
	 * a form. A reversed or oversized range is a caller defect — the layer that
	 * accepted the dates is the one that can answer a person with an error
	 * message, and it must not pass the problem down here to be silently
	 * truncated into a plausible-looking forecast.
	 *
	 * @param string $from Y-m-d in UTC.
	 * @param string $to   Y-m-d in UTC, on or after `$from`.
	 * @return list<string>
	 * @throws InvalidArgumentException When either date is unparseable, reversed, or the span is too long.
	 */
	public static function days_in_window( string $from, string $to ): array {
		$start = Utc_Day::parse( $from );
		$end   = Utc_Day::parse( $to );

		if ( null === $start || null === $end ) {
			throw new InvalidArgumentException( 'A forecast window needs two Y-m-d dates.' );
		}

		if ( $end < $start ) {
			throw new InvalidArgumentException( 'A forecast window cannot end before it starts.' );
		}

		$days = array();

		for ( $day = $start; $day <= $end; $day = $day->modify( '+1 day' ) ) {
			if ( count( $days ) >= self::MAX_WINDOW_DAYS ) {
				throw new InvalidArgumentException( 'A forecast window cannot exceed ' . self::MAX_WINDOW_DAYS . ' days.' );
			}

			$days[] = $day->format( 'Y-m-d' );
		}

		return $days;
	}

	/**
	 * Observations keyed by day, with unparseable days dropped.
	 *
	 * @param array<string, int|numeric-string> $observed Raw history.
	 * @return array<string, int>
	 */
	private static function samples( array $observed ): array {
		$samples = array();

		foreach ( $observed as $day => $count ) {
			if ( null === Utc_Day::parse( (string) $day ) ) {
				continue;
			}

			/*
			 * **Not clamped at zero.** The counter column is unsigned, so a
			 * negative arriving here means the ledger or the query that read it
			 * is wrong. Clamping would turn a defect worth finding into a
			 * placement that looks merely unsold, and unsold is a thing a
			 * publisher acts on. {@see Fill_Figures} refuses to normalise a
			 * discrepancy for the same reason.
			 */
			$samples[ (string) $day ] = (int) $count;
		}

		return $samples;
	}

	/**
	 * Observations grouped by ISO weekday, 1 (Monday) through 7.
	 *
	 * @param array<string, int> $samples Observations by day.
	 * @return array<int, list<int>>
	 */
	private static function by_weekday( array $samples ): array {
		$buckets = array();

		foreach ( $samples as $day => $count ) {
			$date = Utc_Day::parse( $day );

			if ( null === $date ) {
				continue;
			}

			$buckets[ (int) $date->format( 'N' ) ][] = $count;
		}

		return $buckets;
	}

	/**
	 * Whether every weekday the window contains has enough observations.
	 *
	 * Only the weekdays actually being forecast have to clear the bar. A
	 * Monday-to-Friday window does not need Sunday history, and requiring it
	 * would drop a well-observed weekday window back to a pooled estimate that
	 * mixes in the weekend it deliberately excludes.
	 *
	 * @param array<int, list<int>> $by_weekday Observations grouped by ISO weekday.
	 * @param array<int, string>    $window     Days being forecast.
	 */
	private static function weekly_cycle_supported( array $by_weekday, array $window ): bool {
		foreach ( $window as $day ) {
			$date = Utc_Day::parse( $day );

			if ( null === $date ) {
				return false;
			}

			$weekday = (int) $date->format( 'N' );

			if ( count( $by_weekday[ $weekday ] ?? array() ) < self::MIN_WEEKDAY_DAYS ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Window totals summed from each day's own weekday distribution.
	 *
	 * @param array<int, list<int>> $by_weekday Observations grouped by ISO weekday.
	 * @param array<int, string>    $window     Days being forecast.
	 * @return array{0: int, 1: int} The conservative estimate and the optimistic bound.
	 */
	private static function weekday_bounds( array $by_weekday, array $window ): array {
		$estimate   = 0;
		$optimistic = 0;

		foreach ( $window as $day ) {
			$date = Utc_Day::parse( $day );

			if ( null === $date ) {
				continue;
			}

			$samples = $by_weekday[ (int) $date->format( 'N' ) ] ?? array();

			if ( array() === $samples ) {
				continue;
			}

			$estimate   += self::quantile( $samples, self::ESTIMATE_QUANTILE );
			$optimistic += self::quantile( $samples, self::UPPER_QUANTILE );
		}

		return array( $estimate, $optimistic );
	}

	/**
	 * One result, with the estimate pinned to the conservative bound.
	 *
	 * @param array{0: int, 1: int} $bounds        The conservative estimate and the optimistic bound.
	 * @param string                $confidence    Which method produced it.
	 * @param int                   $days_observed Observations behind it.
	 * @param int                   $days_forecast Days it covers.
	 * @return array{estimate: int|null, optimistic: int|null, confidence: string, days_observed: int, days_forecast: int}
	 */
	private static function totals( array $bounds, string $confidence, int $days_observed, int $days_forecast ): array {
		return array(
			'estimate'      => $bounds[0],
			'optimistic'    => $bounds[1],
			'confidence'    => $confidence,
			'days_observed' => $days_observed,
			'days_forecast' => $days_forecast,
		);
	}

	/**
	 * The value at a quantile, by nearest rank.
	 *
	 * **Nearest rank, not interpolation.** Every figure this returns is a day
	 * that actually happened, which is what lets the estimate be described to a
	 * buyer as a day the publisher has already had. An interpolated quantile
	 * would be a number between two real days and true of neither.
	 *
	 * @param array<int, int> $samples  At least one observation.
	 * @param float           $quantile Between 0 and 1.
	 */
	private static function quantile( array $samples, float $quantile ): int {
		sort( $samples, SORT_NUMERIC );

		$rank = (int) ceil( $quantile * count( $samples ) );

		return $samples[ max( 0, min( count( $samples ) - 1, $rank - 1 ) ) ];
	}
}
