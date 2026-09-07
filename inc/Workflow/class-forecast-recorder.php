<?php
/**
 * Writes forecast snapshots, and the outcomes they are judged against.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Repository\Forecast_Repository;

/**
 * Turns a live forecast into a record, and matures the records that are due.
 *
 * `Supply_History` answers what a placement is likely to supply *now*.
 * Answering it again next week gives a different number, and nothing would
 * remember the first — so the phase's central requirement, that forecast error
 * is recorded when actuals mature, would have nothing to measure. This is the
 * half that remembers.
 *
 * **Recording is deliberately not automatic.** Nothing forecasts on a
 * schedule: a snapshot is a claim somebody made, so it is written when staff
 * ask for a figure rather than accumulated by a cron nobody reads. Maturing is
 * the opposite — it needs no judgement, only a closed window — so that half is
 * a batch a job can drive.
 */
final class Forecast_Recorder {

	/** Windows one maturing pass will close. */
	public const MAX_PER_RUN = 25;

	/**
	 * Reads history, and remembers what it was told.
	 *
	 * @param Supply_History      $history   Observed supply and the forecast drawn from it.
	 * @param Forecast_Repository $forecasts Snapshot storage.
	 */
	public function __construct(
		private readonly Supply_History $history,
		private readonly Forecast_Repository $forecasts
	) {}

	/**
	 * Forecasts a window and stores the answer as its next version.
	 *
	 * @param int    $placement    Placement post id.
	 * @param string $opportunity  `Domain\Opportunity` kind.
	 * @param string $from_utc     First day of the window, `Y-m-d`.
	 * @param string $to_utc       Last day of the window, `Y-m-d`.
	 * @param int    $history_days Days of history to draw from.
	 * @return array{forecast: array<string, mixed>, version: int} The figure and the version it was stored as, 0 when nothing was.
	 */
	public function snapshot( int $placement, string $opportunity, string $from_utc, string $to_utc, int $history_days = Supply_History::DEFAULT_HISTORY_DAYS ): array {
		$forecast = $this->history->forecast( $placement, $opportunity, $from_utc, $to_utc, $history_days );

		return array(
			'forecast' => $forecast,

			/*
			 * The forecast is returned whether or not it was stored. A caller
			 * asking for a figure wants the figure; whether it was worth
			 * keeping is a separate answer, and conflating them would make an
			 * unstorable forecast look like a failed one.
			 */
			'version'  => $this->forecasts->record( $placement, $opportunity, $from_utc, $to_utc, $forecast ),
		);
	}

	/**
	 * Records what closed windows actually supplied.
	 *
	 * **Recovery is the design, as it is for the campaign clock.** A missed run
	 * costs nothing: the window stays in `awaiting_actuals()` and the next pass
	 * finds it. Nothing tracks where the last run got to, so there is no
	 * watermark to be wrong.
	 *
	 * A window whose supply cannot be read yet is left alone rather than
	 * recorded as zero — `Supply_History::supplied()` answers null while a
	 * window is still open, and writing that as an outcome would report every
	 * placement as wildly over-forecast.
	 *
	 * @param string $through_utc Last day that counts as matured, `Y-m-d`.
	 * @param int    $limit       Windows to close in one pass.
	 * @return int Windows closed.
	 */
	public function mature( string $through_utc, int $limit = self::MAX_PER_RUN ): int {
		$due    = $this->forecasts->awaiting_actuals( $through_utc, min( self::MAX_PER_RUN, max( 1, $limit ) ) );
		$closed = 0;

		foreach ( $due as $window ) {
			$supplied = $this->history->supplied(
				$window['placement_id'],
				$window['opportunity'],
				$window['window_start'],
				$window['window_end']
			);

			if ( null === $supplied ) {
				continue;
			}

			$written = $this->forecasts->record_actual(
				$window['placement_id'],
				$window['opportunity'],
				$window['window_start'],
				$window['window_end'],
				$supplied
			);

			if ( $written > 0 ) {
				++$closed;
			}
		}

		return $closed;
	}

	/**
	 * Every recorded version of one window, oldest first.
	 *
	 * @param int    $placement   Placement post id.
	 * @param string $opportunity `Domain\Opportunity` kind.
	 * @param string $from_utc    First day of the window, `Y-m-d`.
	 * @param string $to_utc      Last day of the window, `Y-m-d`.
	 * @return array<int, array<string, mixed>>
	 */
	public function history_for( int $placement, string $opportunity, string $from_utc, string $to_utc ): array {
		if ( ! Opportunity::is_valid( $opportunity ) ) {
			return array();
		}

		return $this->forecasts->versions( $placement, $opportunity, $from_utc, $to_utc );
	}
}
