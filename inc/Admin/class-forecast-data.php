<?php
/**
 * The staff inventory outlook: what there is to sell, and what is spoken for.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Domain\Availability;
use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Domain\Supply_Forecast;
use Aggressive\Ads\Domain\Utc_Day;
use Aggressive\Ads\Repository\Forecast_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Reservation_Repository;

/**
 * Assembles one window's outlook for every active placement.
 *
 * Staff-only: the inventory-commerce contract keeps forecast value,
 * confidence, version and error away from advertisers, because a forecast is
 * the publisher's negotiating position.
 *
 * Two queries for the catalogue rather than two per placement — the forecast
 * and the committed total are both read in batch, because this screen lists
 * every active placement and the ceiling is two hundred.
 */
final class Forecast_Data {

	/** Days a window covers when nobody has chosen one. */
	public const DEFAULT_WINDOW_DAYS = 30;

	/** Longest window this screen will assemble. */
	public const MAX_WINDOW_DAYS = 92;

	/**
	 * Reads placements, forecasts and reservations.
	 *
	 * @param Placement_Repository   $placements   The catalogue.
	 * @param Forecast_Repository    $forecasts    What each window was forecast to supply.
	 * @param Reservation_Repository $reservations What has been claimed against it.
	 */
	public function __construct(
		private readonly Placement_Repository $placements,
		private readonly Forecast_Repository $forecasts,
		private readonly Reservation_Repository $reservations
	) {}

	/**
	 * The window shown when nobody has chosen one.
	 *
	 * Starts tomorrow. Today is half elapsed and cannot be sold whole, so a
	 * window that included it would offer inventory that has partly already
	 * gone — and mix a part-measured day into a forecast of unmeasured ones.
	 *
	 * @param int $days How many days the window covers.
	 * @return array{from: string, to: string}
	 */
	public function default_window( int $days = self::DEFAULT_WINDOW_DAYS ): array {
		$span  = max( 1, min( self::MAX_WINDOW_DAYS, $days ) );
		$start = (int) strtotime( gmdate( 'Y-m-d' ) . ' 00:00:00 UTC' ) + DAY_IN_SECONDS;

		return array(
			'from' => gmdate( 'Y-m-d', $start ),
			'to'   => gmdate( 'Y-m-d', $start + ( $span - 1 ) * DAY_IN_SECONDS ),
		);
	}

	/**
	 * The screen payload for one window and one kind of inventory.
	 *
	 * @param string $opportunity `Domain\Opportunity` kind.
	 * @param string $from_utc    First day of the window, `Y-m-d`.
	 * @param string $to_utc      Last day of the window, `Y-m-d`.
	 * @return array{window: array{from: string, to: string}, opportunity: string, rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
	 */
	public function view( string $opportunity, string $from_utc, string $to_utc ): array {
		$kind = Opportunity::is_valid( $opportunity ) ? $opportunity : Opportunity::PAGE;

		if ( ! Utc_Day::is_window( $from_utc, $to_utc ) ) {
			$window   = $this->default_window();
			$from_utc = $window['from'];
			$to_utc   = $window['to'];
		}

		$ids       = $this->placements->active_ids();
		$forecasts = $this->forecasts->latest_by_placement( $ids, $kind, $from_utc, $to_utc );
		$committed = $this->reservations->committed_by_placement( $ids, $kind, $from_utc, $to_utc );

		$rows = array();

		foreach ( $ids as $id ) {
			$rows[] = $this->row( (int) $id, $forecasts[ (int) $id ] ?? null, (int) ( $committed[ (int) $id ] ?? 0 ) );
		}

		return array(
			'window'      => array(
				'from' => $from_utc,
				'to'   => $to_utc,
			),
			'opportunity' => $kind,
			'rows'        => $rows,
			'totals'      => $this->totals( $rows ),
		);
	}

	/**
	 * One placement's outlook.
	 *
	 * `forecast` is null for a placement nobody has measured: a screen showing
	 * nought would say it is sold out.
	 *
	 * @param int                       $placement Placement post id.
	 * @param array<string, mixed>|null $forecast  Its newest snapshot, or null.
	 * @param int                       $committed What reservations claim.
	 * @return array<string, mixed>
	 */
	private function row( int $placement, ?array $forecast, int $committed ): array {
		$capacity = is_array( $forecast ) ? (int) $forecast['estimate'] : null;

		/*
		 * `holdings()`, not `decide( …, 0 )`. Asking whether a request of
		 * nothing fits is not the same question as whether the window is
		 * oversold, and it always answers yes — so every row read `available`,
		 * including the ones the summary above the table was counting as
		 * oversold in the same render.
		 */
		$decision = Availability::holdings( $capacity, $committed );

		return array(
			'id'         => $placement,
			'name'       => $this->placements->name( $placement ),
			'slug'       => $this->placements->slug( $placement ),
			'forecast'   => $capacity,
			'optimistic' => is_array( $forecast ) ? (int) $forecast['optimistic'] : null,
			'confidence' => is_array( $forecast ) ? (string) $forecast['confidence'] : Supply_Forecast::CONFIDENCE_NONE,
			'version'    => is_array( $forecast ) ? (int) $forecast['version'] : 0,
			'made_at'    => is_array( $forecast ) ? (string) $forecast['made_at'] : '',
			'committed'  => $committed,
			'remaining'  => $decision['remaining'],
			'verdict'    => $decision['verdict'],
		);
	}

	/**
	 * The figures a summary card shows.
	 *
	 * `unforecast` is counted rather than skipped: it says how much of the
	 * catalogue this screen cannot speak for, and a total that dropped those
	 * placements would present partial coverage as complete.
	 *
	 * @param array<int, array<string, mixed>> $rows Assembled placement rows.
	 * @return array{placements: int, forecast: int, committed: int, remaining: int, unforecast: int, oversold: int}
	 */
	private function totals( array $rows ): array {
		$forecast   = 0;
		$committed  = 0;
		$remaining  = 0;
		$unforecast = 0;
		$oversold   = 0;

		foreach ( $rows as $row ) {
			$committed += (int) $row['committed'];

			if ( null === $row['forecast'] ) {
				++$unforecast;

				continue;
			}

			$forecast  += (int) $row['forecast'];
			$remaining += (int) $row['remaining'];

			/*
			 * The row's own verdict rather than a second rule. This counted
			 * with an inline `committed > forecast` while the rows carried a
			 * verdict from the domain, which is two definitions of oversold on
			 * one screen — the arrangement where a tile and the rows beneath it
			 * can disagree and neither can be checked against the other.
			 */
			if ( Availability::OVERSELL === $row['verdict'] ) {
				++$oversold;
			}
		}

		return array(
			'placements' => count( $rows ),
			'forecast'   => $forecast,
			'committed'  => $committed,
			'remaining'  => $remaining,
			'unforecast' => $unforecast,
			'oversold'   => $oversold,
		);
	}
}
