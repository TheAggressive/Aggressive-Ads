<?php
/**
 * A bounded, validated calendar-day range and what can be said about its freshness.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * The date arithmetic every report shares, with no WordPress underneath it.
 *
 * **Why a value object rather than two integers passed around.** Reporting's
 * defects live in date arithmetic — a range off by a day at a month boundary, a
 * comparison window of a different length, a partial day summed beside settled
 * ones — and every one of those is testable in milliseconds if the arithmetic
 * is somewhere with no bootstrap. It is also the seam a scheduled report would
 * render through, having no request and no session to read a range from.
 *
 * **Every period is bounded on construction.** A report read is only bounded if
 * its range is, and a bound enforced by each caller clamping its own input is a
 * bound that one caller forgets. There is no way to hold an unbounded instance
 * of this class.
 *
 * **The days are calendar dates in a named timezone.** The default is UTC, which
 * is how every period was constructed before reporting buckets existed and how a
 * staff export still asks to read. A site-local period still stores `Y-m-d`
 * keys — the sparkline and the CSV keep one row per day — and converts those
 * days into a UTC instant range at the moment a query needs one. Daily pacing
 * counters stay on UTC days; only the reporting projection is re-bucketed.
 */
final class Report_Period {

	/**
	 * Longest range a report may cover.
	 *
	 * A quarter, because it is the longest span a person reads on a screen
	 * before they want a different tool, and because a 92-day range and its
	 * 92-day comparison window are then two bounded reads rather than one
	 * unbounded one.
	 *
	 * This is not the export bound. An export is bounded by the memory it
	 * assembles a document in, which is a different constraint that produces a
	 * different — tighter — number, and collapsing the two would mean one of
	 * them was chosen for the wrong reason.
	 */
	public const MAX_DAYS = 92;

	/**
	 * Every day in the range is at or before the reconciliation watermark.
	 *
	 * Rebuilt from the ledger, so the same report run next quarter returns the
	 * same numbers.
	 */
	public const RECONCILED = 'reconciled';

	/**
	 * The range extends past the watermark into closed days not yet rebuilt.
	 *
	 * Correct in ordinary operation — the live projection is written by the
	 * same code the reconciler re-runs — but not yet proven against the ledger.
	 */
	public const PROVISIONAL = 'provisional';

	/**
	 * The range includes today, which is still accumulating.
	 */
	public const PARTIAL = 'partial';

	/**
	 * Not constructible without going through a validating factory.
	 *
	 * @param string $start     Inclusive first calendar day, `Y-m-d`.
	 * @param string $end       Inclusive last calendar day, `Y-m-d`.
	 * @param int    $days      Length in days, 1–92.
	 * @param string $timezone  Zone the calendar days are in.
	 */
	private function __construct(
		public readonly string $start,
		public readonly string $end,
		public readonly int $days,
		public readonly string $timezone
	) {
	}

	/**
	 * A period from an inclusive pair of calendar days, or null.
	 *
	 * Null rather than an exception or a clamp. A malformed or over-long range
	 * is a caller's bad input, and clamping it silently would answer a question
	 * nobody asked — the report would render, look authoritative, and cover a
	 * different period than the one requested.
	 *
	 * @param string $start     Inclusive first calendar day, `Y-m-d`.
	 * @param string $end       Inclusive last calendar day, `Y-m-d`.
	 * @param string $timezone  Zone the days are in; invalid names become UTC.
	 */
	public static function between( string $start, string $end, string $timezone = Report_Zone::UTC ): ?self {
		$first = self::parse( $start );
		$last  = self::parse( $end );

		if ( null === $first || null === $last || $first > $last ) {
			return null;
		}

		$days = (int) $first->diff( $last )->days + 1;

		if ( $days > self::MAX_DAYS ) {
			return null;
		}

		return new self( $first->format( 'Y-m-d' ), $last->format( 'Y-m-d' ), $days, Report_Zone::normalize( $timezone ) );
	}

	/**
	 * A period of `$days` ending on `$end_day` inclusive, or null.
	 *
	 * "Last 30 days" includes today, so it starts 29 days back. Off-by-one here
	 * is the reporting bug that looks like a rounding error for a week.
	 *
	 * @param int    $days      Length, 1–92.
	 * @param string $end_day   Inclusive last calendar day, `Y-m-d`.
	 * @param string $timezone  Zone the days are in.
	 */
	public static function ending( int $days, string $end_day, string $timezone = Report_Zone::UTC ): ?self {
		if ( $days < 1 || $days > self::MAX_DAYS ) {
			return null;
		}

		$last = self::parse( $end_day );

		if ( null === $last ) {
			return null;
		}

		// Delegated rather than constructed here, so there is one place a
		// period comes into existence and one place its bound is enforced. Two
		// factories each doing their own arithmetic is how they end up
		// disagreeing about whether a range is inclusive.
		return self::between( $last->modify( '-' . (string) ( $days - 1 ) . ' days' )->format( 'Y-m-d' ), $end_day, $timezone );
	}

	/**
	 * A period of `$days` ending on `$end_day`, with the length clamped into range.
	 *
	 * **Deliberately different from `ending()`, and the difference is who
	 * supplied the number.** A range that came from a request must be refused
	 * when it is out of bounds, because clamping it answers a question nobody
	 * asked. A length chosen by a constant in this codebase cannot usefully be
	 * refused at runtime — a dashboard tile is not worth a fatal, and returning
	 * null would only push the same problem to a caller with less context — so
	 * it is clamped here and caught in review instead.
	 *
	 * @param int    $days      Requested length; clamped to 1–92.
	 * @param string $end_day   Inclusive last calendar day, `Y-m-d`. A malformed value
	 *                          yields the epoch rather than null, for the same reason.
	 * @param string $timezone  Zone the days are in.
	 */
	public static function trailing( int $days, string $end_day, string $timezone = Report_Zone::UTC ): self {
		$days   = max( 1, min( self::MAX_DAYS, $days ) );
		$period = self::ending( $days, $end_day, $timezone );

		return $period ?? new self( '1970-01-01', '1970-01-01', 1, Report_Zone::normalize( $timezone ) );
	}

	/**
	 * Every calendar day in the range, oldest first.
	 *
	 * Callers pad a sparse result against this so a day with no delivery is a
	 * zero in the series rather than a missing point the chart closes over.
	 *
	 * @return list<string>
	 */
	public function keys(): array {
		$keys = array();
		$day  = self::parse( $this->start );

		if ( null === $day ) {
			return $keys;
		}

		for ( $offset = 0; $offset < $this->days; $offset++ ) {
			$keys[] = $day->modify( '+' . (string) $offset . ' days' )->format( 'Y-m-d' );
		}

		return $keys;
	}

	/**
	 * Inclusive UTC instant of the first local midnight in range.
	 */
	public function utc_start(): \DateTimeImmutable {
		$bounds = Report_Zone::utc_bounds( $this->start, $this->end, $this->timezone );

		return $bounds['start'] ?? new \DateTimeImmutable( $this->start . ' 00:00:00', new \DateTimeZone( Report_Zone::UTC ) );
	}

	/**
	 * Exclusive UTC instant of midnight after the last local day in range.
	 */
	public function utc_end(): \DateTimeImmutable {
		$bounds = Report_Zone::utc_bounds( $this->start, $this->end, $this->timezone );

		return $bounds['end'] ?? new \DateTimeImmutable( $this->end . ' 00:00:00', new \DateTimeZone( Report_Zone::UTC ) )->modify( '+1 day' );
	}

	/**
	 * `bucket_utc >=` bound, as MySQL datetime.
	 */
	public function bucket_start(): string {
		return $this->utc_start()->format( 'Y-m-d H:i:s' );
	}

	/**
	 * `bucket_utc <` bound, as MySQL datetime.
	 */
	public function bucket_end(): string {
		return $this->utc_end()->format( 'Y-m-d H:i:s' );
	}

	/**
	 * The equal-length window immediately before this one.
	 *
	 * **Equal length and immediately preceding, with no calendar special
	 * cases.** A comparison window of a different length produces a percentage
	 * that looks like performance and is arithmetic about the calendar: a
	 * 31-day month against a 28-day one is reported as a 10% gain by a report
	 * that did nothing but count days.
	 */
	public function previous(): self {
		$start = self::parse( $this->start );

		if ( null === $start ) {
			return $this;
		}

		$end      = $start->modify( '-1 day' );
		$previous = self::ending( $this->days, $end->format( 'Y-m-d' ), $this->timezone );

		// A period's own length is already inside the bound, so the window
		// before it is too; the fallback exists only because the type says so.
		return $previous ?? $this;
	}

	/**
	 * How settled this period's numbers are: the most cautious state that applies.
	 *
	 * Caution wins because the failure this prevents is one-directional. A
	 * reconciled range wrongly labelled provisional costs a reader nothing but
	 * a second look; a partial day presented as settled is a number somebody
	 * plans against and is wrong about, and it looks identical to a real
	 * decline in traffic.
	 *
	 * The watermark is still a closed UTC day — that is what the reconciler
	 * seals — and seals everything before midnight of the following day. A local Tuesday in
	 * Los Angeles that still contains Wednesday 00:00–06:00 UTC is therefore
	 * provisional until UTC Wednesday is sealed, which is conservative and
	 * the honest reading of a day-grained watermark against an bucket-grained
	 * period.
	 *
	 * @param string $watermark Last UTC day reconciled from the ledger, or '' when never.
	 * @param string $today     Current calendar day in this period's timezone, `Y-m-d`.
	 */
	public function freshness( string $watermark, string $today ): string {
		if ( null !== self::parse( $today ) && $this->end >= $today ) {
			return self::PARTIAL;
		}

		$sealed = self::sealed_through( $watermark );
		$last   = $this->utc_end();

		if ( null === $sealed || $sealed < $last ) {
			return self::PROVISIONAL;
		}

		return self::RECONCILED;
	}

	/**
	 * First day in the range whose numbers are not reconciled, or null.
	 *
	 * The boundary a report names, so "these numbers may still move" points at
	 * a date rather than being a disclaimer over the whole table. Null means
	 * every day in range is settled.
	 *
	 * @param string $watermark Last UTC day reconciled from the ledger, or '' when never.
	 * @param string $today     Current calendar day in this period's timezone, `Y-m-d`.
	 */
	public function unreconciled_from( string $watermark, string $today ): ?string {
		if ( self::RECONCILED === $this->freshness( $watermark, $today ) ) {
			return null;
		}

		$sealed = self::sealed_through( $watermark );

		if ( null === $sealed ) {
			// Nothing has ever been reconciled, so nothing in range is settled.
			return $this->start;
		}

		$first = Report_Zone::local_day( $sealed->format( 'Y-m-d H:i:s' ), $this->timezone );

		if ( '' === $first ) {
			return $this->start;
		}

		// A watermark older than the range must not point outside it.
		return $first > $this->start ? $first : $this->start;
	}

	/**
	 * Exclusive UTC end of a sealed UTC day, or null.
	 *
	 * @param string $watermark Last reconciled UTC day, `Y-m-d`.
	 */
	private static function sealed_through( string $watermark ): ?\DateTimeImmutable {
		if ( null === self::parse( $watermark ) ) {
			return null;
		}

		return new \DateTimeImmutable( $watermark . ' 00:00:00', new \DateTimeZone( Report_Zone::UTC ) )->modify( '+1 day' );
	}

	/**
	 * A `Y-m-d` string as a calendar date, or null.
	 *
	 * The format is checked before parsing because `DateTimeImmutable` is
	 * generous: it reads `2026-02-30` as 2 March and `13-08-2026` as something
	 * else again, and a report that silently reinterprets a date is worse than
	 * one that refuses it.
	 *
	 * Parsed in UTC so the calendar date itself is what is validated, not the
	 * instant that date begins in some other zone.
	 *
	 * @param string $day Candidate day.
	 */
	private static function parse( string $day ): ?\DateTimeImmutable {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			return null;
		}

		try {
			$date = new \DateTimeImmutable( $day . ' 00:00:00', new \DateTimeZone( Report_Zone::UTC ) );
		} catch ( \Exception $e ) {
			return null;
		}

		// Round-trip check: a date the parser accepted but rewrote (30 February
		// becomes 2 March) is not the day the caller asked for.
		return $date->format( 'Y-m-d' ) === $day ? $date : null;
	}
}
