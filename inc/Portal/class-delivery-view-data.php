<?php
/**
 * Advertiser-facing delivery numbers for the portal dashboard.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Domain\Reporting_Rules;
use Aggressive\Ads\Workflow\Reporting_Read;

/**
 * Keeps delivery reporting out of the multi-screen `View_Data` coordinator,
 * the way `Catalogue_View_Data` already keeps the catalogue out of it.
 *
 * These four methods share one job — turning org-scoped rollups into something
 * a tile can print — and one rule that the rest of the portal does not have to
 * care about: **an absent number and a zero are different answers**, and this
 * is the only place that decides which is which.
 */
final class Delivery_View_Data {

	/**
	 * Constructor.
	 *
	 * @param Reporting_Read $reporting Org-scoped rollup reads and the surface gate.
	 */
	public function __construct( private readonly Reporting_Read $reporting ) {
	}

	/**
	 * The window every delivery tile on the dashboard covers.
	 *
	 * Read once and passed to both the numbers and the caption, so a tile
	 * cannot end up describing a different period than the one it summed.
	 */
	public function period(): Report_Period {
		return $this->reporting->default_period();
	}

	/**
	 * The range in words, timezone included.
	 *
	 * **UTC is stated, not implied.** Both projections are keyed by a UTC day
	 * and neither carries an hour, so a publisher or advertiser east or west of
	 * UTC is reading days that do not line up with their own. A report that
	 * left that unsaid would be quietly wrong for most of the world for the
	 * hours that matter most.
	 */
	public function range_label(): string {
		return sprintf(
			/* translators: %d: number of days the delivery figures cover. */
			_n( 'Last %d day (UTC)', 'Last %d days (UTC)', Reporting_Read::DEFAULT_DAYS, 'aggressive-ads' ),
			Reporting_Read::DEFAULT_DAYS
		);
	}

	/**
	 * A sentence naming the first day whose numbers may still move, or ''.
	 *
	 * Empty means every day in range has been rebuilt from the event ledger and
	 * will not change. Anything else names the boundary rather than disclaiming
	 * the whole table, because "these numbers might be wrong" tells a reader
	 * nothing they can act on and a date tells them which rows to re-check.
	 *
	 * @param Report_Period|null $period Range being described.
	 */
	public function freshness_note( ?Report_Period $period = null ): string {
		$freshness = $this->reporting->freshness( $period ?? $this->period() );
		$from      = $freshness['unreconciled_from'];

		if ( Report_Period::RECONCILED === $freshness['state'] || null === $from ) {
			return '';
		}

		$timestamp = strtotime( $from . ' UTC' );

		return sprintf(
			/* translators: %s: a date, e.g. 30 August 2026. */
			__( 'Figures from %s onward are still being counted.', 'aggressive-ads' ),
			false === $timestamp ? $from : (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $timestamp )
		);
	}

	/**
	 * Native delivery totals for one organization over the reporting window.
	 *
	 * Empty when the reporting surface is off, so the template does not render
	 * a row of zeros that look like traffic.
	 *
	 * @param int                $org_id Organization to report on.
	 * @param Report_Period|null $period Range, or the dashboard's own window.
	 * @return array<int, array{label: string, value: string}>
	 */
	public function counts( int $org_id, ?Report_Period $period = null ): array {
		if ( ! $this->reporting->surfaces() ) {
			return array();
		}

		$read     = $this->reporting->totals_with_comparison( $org_id, $period ?? $this->period() );
		$totals   = $read['current'];
		$was      = $read['previous'];
		$ctr      = Reporting_Rules::ctr( $totals['impressions'], $totals['clicks'] );
		$ctr_was  = Reporting_Rules::ctr( $was['impressions'], $was['clicks'] );
		$view     = Reporting_Rules::viewability( $totals['impressions'], $totals['viewables'] );
		$view_was = Reporting_Rules::viewability( $was['impressions'], $was['viewables'] );

		return array(
			$this->tile(
				__( 'Impressions', 'aggressive-ads' ),
				(string) number_format_i18n( $totals['impressions'] ),
				Reporting_Rules::change( $totals['impressions'], $was['impressions'] )
			),
			$this->tile(
				__( 'Clicks', 'aggressive-ads' ),
				(string) number_format_i18n( $totals['clicks'] ),
				Reporting_Rules::change( $totals['clicks'], $was['clicks'] )
			),
			$this->tile(
				__( 'CTR', 'aggressive-ads' ),
				$this->format_ctr( $ctr ),
				Reporting_Rules::point_change( $ctr, $ctr_was ),
				true
			),
			$this->tile(
				__( 'Viewable', 'aggressive-ads' ),
				$this->format_viewability( $totals['impressions'], $totals['viewables'] ),
				Reporting_Rules::point_change( $view, $view_was ),
				true
			),
			$this->tile(
				__( 'Conversions', 'aggressive-ads' ),
				$this->format_count( $totals['conversions'] ),
				Reporting_Rules::change( $totals['conversions'], $was['conversions'] )
			),
		);
	}

	/**
	 * One tile, with its comparison already rendered.
	 *
	 * `direction` exists to hang a colour on and carries no meaning of its own:
	 * the sign is in `change` as text, so a reader in high contrast, in
	 * monochrome, or with a colour vision deficiency loses nothing. Empty
	 * `change` means there was no comparison to draw, which is a real state and
	 * not a zero.
	 *
	 * @param string     $label  Tile label.
	 * @param string     $value  Formatted figure.
	 * @param float|null $delta  Signed change, or null when incomparable.
	 * @param bool       $points Whether $delta is percentage points rather than a proportion.
	 * @return array{label: string, value: string, change: string, direction: string}
	 */
	private function tile( string $label, string $value, ?float $delta, bool $points = false ): array {
		return array(
			'label'     => $label,
			'value'     => $value,
			'change'    => $this->format_change( $delta, $points ),
			'direction' => $this->direction( $delta ),
		);
	}

	/**
	 * A signed change against the previous window, or '' when there is none.
	 *
	 * Rates read in percentage points and counts in percent, because they are
	 * different quantities: CTR going from 1.0% to 1.5% is half a point and a
	 * 50% rise, and only one of those is what a reader takes "CTR up 50%" to
	 * mean. See `Reporting_Rules::point_change()`.
	 *
	 * @param float|null $delta  Signed change.
	 * @param bool       $points Whether $delta is percentage points.
	 */
	public function format_change( ?float $delta, bool $points = false ): string {
		if ( null === $delta ) {
			return '';
		}

		// Both a proportion and a point difference are stored as fractions, so
		// both render by the same factor; only the unit differs.
		$rounded = number_format_i18n( abs( $delta ) * 100, 1 );

		if ( $points ) {
			if ( $delta < 0 ) {
				/* translators: %s: change in percentage points, e.g. 0.5. */
				return sprintf( __( '−%s pp vs previous period', 'aggressive-ads' ), $rounded );
			}

			/* translators: %s: change in percentage points, e.g. 0.5. */
			return sprintf( __( '+%s pp vs previous period', 'aggressive-ads' ), $rounded );
		}

		if ( $delta < 0 ) {
			/* translators: %s: percentage change, e.g. 12.4. */
			return sprintf( __( '−%s%% vs previous period', 'aggressive-ads' ), $rounded );
		}

		/* translators: %s: percentage change, e.g. 12.4. */
		return sprintf( __( '+%s%% vs previous period', 'aggressive-ads' ), $rounded );
	}

	/**
	 * Styling hook for a change, never the change's meaning.
	 *
	 * @param float|null $delta Signed change.
	 */
	private function direction( ?float $delta ): string {
		if ( null === $delta ) {
			return '';
		}

		if ( $delta > 0 ) {
			return 'up';
		}

		return $delta < 0 ? 'down' : 'flat';
	}

	/**
	 * Seven-day impression series for the dashboard sparkline.
	 *
	 * Empty when Reporting is off, so the template omits the chart rather than
	 * drawing a flat line that looks like traffic.
	 *
	 * @param int $org_id Organization to report on.
	 * @return list<array{day: string, label: string, impressions: int, height: int}>
	 */
	public function series( int $org_id ): array {
		if ( ! $this->reporting->surfaces() ) {
			return array();
		}

		$raw = $this->reporting->series_for_org( $org_id );
		$max = 0;

		foreach ( $raw as $row ) {
			$max = max( $max, $row['impressions'] );
		}

		$series = array();

		foreach ( $raw as $row ) {
			$timestamp = strtotime( $row['day'] . ' UTC' );

			$series[] = array(
				'day'         => $row['day'],
				'label'       => false === $timestamp ? $row['day'] : (string) wp_date( 'D', $timestamp ),
				'impressions' => $row['impressions'],
				'height'      => Reporting_Rules::bar_height( $row['impressions'], $max ),
			);
		}

		return $series;
	}

	/**
	 * CTR as a percentage, or an em dash when there were no impressions.
	 *
	 * @param float|null $ratio Clicks per impression.
	 */
	public function format_ctr( ?float $ratio ): string {
		if ( null === $ratio ) {
			return __( '—', 'aggressive-ads' );
		}

		return sprintf(
			/* translators: %s: click-through rate as a percentage, e.g. 1.2. */
			__( '%s%%', 'aggressive-ads' ),
			number_format_i18n( $ratio * 100, 1 )
		);
	}

	/**
	 * A measured count, or the statement that nothing measured it.
	 *
	 * **Two answers here where viewability has three, and the missing one is
	 * the em dash.** A rate needs a denominator, so "nothing to divide by" is a
	 * distinct state; a count does not, so a measured zero is simply zero and
	 * is a real answer — the campaign delivered and nobody converted, which is
	 * worth knowing. Only "nobody was counting" has to be said in words.
	 *
	 * @param int|null $value Counted outcomes, or null when unmeasured.
	 */
	public function format_count( ?int $value ): string {
		if ( null === $value ) {
			return __( 'Not measured', 'aggressive-ads' );
		}

		return (string) number_format_i18n( $value );
	}

	/**
	 * Viewability as a percentage, or which kind of absence it is.
	 *
	 * Three answers, and collapsing any two of them would mislead. **Not
	 * measured** is a day before viewability shipped, or one where the script
	 * never ran — reporting it as `0%` claims nobody saw the ads, which is the
	 * alarming reading and the false one. An em dash is the ordinary "nothing
	 * delivered yet". `0.0%` is a real measurement of nothing being seen, and
	 * is the one worth investigating.
	 *
	 * @param int      $impressions Delivered impressions.
	 * @param int|null $viewables   Views recorded, or null when unmeasured.
	 * @return string
	 */
	public function format_viewability( int $impressions, ?int $viewables ): string {
		if ( null === $viewables ) {
			return __( 'Not measured', 'aggressive-ads' );
		}

		$rate = Reporting_Rules::viewability( $impressions, $viewables );

		if ( null === $rate ) {
			return __( '—', 'aggressive-ads' );
		}

		return sprintf(
			/* translators: %s: share of impressions that were viewable, e.g. 62.5. */
			__( '%s%%', 'aggressive-ads' ),
			number_format_i18n( $rate * 100, 1 )
		);
	}
}
