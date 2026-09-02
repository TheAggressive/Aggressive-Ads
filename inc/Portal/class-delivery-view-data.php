<?php
/**
 * Advertiser-facing delivery numbers for the portal dashboard.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

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
	 * Native delivery totals for one organization.
	 *
	 * Empty when the reporting surface is off, so the template does not render
	 * a row of zeros that look like traffic.
	 *
	 * @param int $org_id Organization to report on.
	 * @return array<int, array{label: string, value: string}>
	 */
	public function counts( int $org_id ): array {
		if ( ! $this->reporting->surfaces() ) {
			return array();
		}

		$totals = $this->reporting->totals_for_org( $org_id );
		$ctr    = Reporting_Rules::ctr( $totals['impressions'], $totals['clicks'] );

		return array(
			array(
				'label' => __( 'Impressions', 'aggressive-ads' ),
				'value' => (string) number_format_i18n( $totals['impressions'] ),
			),
			array(
				'label' => __( 'Clicks', 'aggressive-ads' ),
				'value' => (string) number_format_i18n( $totals['clicks'] ),
			),
			array(
				'label' => __( 'CTR', 'aggressive-ads' ),
				'value' => $this->format_ctr( $ctr ),
			),
			array(
				'label' => __( 'Viewable', 'aggressive-ads' ),
				'value' => $this->format_viewability( $totals['impressions'], $totals['viewables'] ),
			),
		);
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
