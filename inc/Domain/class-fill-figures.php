<?php
/**
 * Fill arithmetic for one set of outcome totals.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Turns raw outcome counters into requests, fills, a rate and the leftovers.
 *
 * Extracted from `Admin\Report_Data` when the utilisation view needed the same
 * arithmetic per placement that the site-wide report already did once. Two
 * implementations of a fill rate would have been two chances to disagree about
 * what a denominator is, and the disagreement would surface as one screen
 * saying a placement is fully sold while another says it is half empty.
 *
 * Pure domain: no WordPress, so the arithmetic is testable without a bootstrap
 * and without a database. Reason *labels* stay in the admin layer, because they
 * are translated; only codes appear here.
 */
final class Fill_Figures {

	/**
	 * Figures for one set of totals.
	 *
	 * **`fill_rate` is null when nothing was asked for, not zero.** A placement
	 * nobody requested did not fail to fill, and a screen that renders 0% for
	 * it tells a publisher they have a problem where they have no data. This is
	 * the same distinction the advertiser tiles draw with an em dash.
	 *
	 * **The rate is not clamped.** Fills cannot exceed requests — the engine
	 * records the request first, on the one path that records either — so a
	 * rate above 1 means the ledger is wrong. Capping it at 100% would turn a
	 * defect worth finding into a number that looks healthy, which is the same
	 * reason `unaccounted` is reported rather than normalised away.
	 *
	 * The casts are load-bearing. PHP's `/` returns an *int* when the division
	 * is exact, so an unfilled placement produced `0` where the declared type
	 * says `float`, and a fully sold one produced `1`. Nothing had noticed,
	 * because both encode to the same JSON — but a strict comparison against
	 * `0.0` does not, and the type was simply untrue.
	 *
	 * @param array<string, int|numeric-string> $totals Outcome code to event count.
	 * @return array{requests: int, fills: int, fill_rate: float|null, unaccounted: int, reasons: list<array{code: string, events: int, share: float|null}>}
	 */
	public static function from_totals( array $totals ): array {
		$requests = (int) ( $totals[ Decision_Outcome::REQUEST ] ?? 0 );
		$fills    = (int) ( $totals[ Decision_Outcome::FILL ] ?? 0 );
		$reasons  = array();
		$counted  = 0;

		foreach ( $totals as $code => $events ) {
			if ( Decision_Outcome::REQUEST === $code || Decision_Outcome::FILL === $code ) {
				continue;
			}

			$events   = (int) $events;
			$counted += $events;

			$reasons[] = array(
				'code'   => (string) $code,
				'events' => $events,
				'share'  => $requests > 0 ? (float) $events / $requests : null,
			);
		}

		return array(
			'requests'    => $requests,
			'fills'       => $fills,
			'fill_rate'   => $requests > 0 ? (float) $fills / $requests : null,

			/*
			 * P13's invariant — requests equal fills plus every no-fill reason
			 * — is a property of the engine, not of the table. A screen that
			 * quietly normalised a discrepancy away would hide exactly the
			 * defect worth finding. Zero on a healthy site.
			 */
			'unaccounted' => max( 0, $requests - $fills - $counted ),
			'reasons'     => $reasons,
		);
	}
}
