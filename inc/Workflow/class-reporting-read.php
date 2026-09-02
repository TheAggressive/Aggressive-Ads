<?php
/**
 * Whether advertiser reporting surfaces may render, and over what range.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Domain\Reporting_Rules;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Repository\Rollup_Report_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;

/**
 * The reporting gate and the org-scoped rollup reads in one place, so
 * `View_Data` and REST cannot disagree about when zeros would be a lie.
 *
 * **Every org-scoped read takes a `Report_Period`.** The gate and the range
 * live together because they answer the same question in two halves — may this
 * number be shown, and what does it cover — and a caller that had to ask one
 * here and decide the other itself is a caller that will eventually pick a
 * different range from its neighbour.
 */
final class Reporting_Read {

	/**
	 * Days a surface covers when it does not name its own range.
	 *
	 * The dashboard tiles used to be all-time, which read as a lifetime figure
	 * and cost a scan of the organization's whole history on every page load.
	 * Thirty days is what the export already defaulted to and what the
	 * sparkline beside it implies, so the two surfaces now agree.
	 */
	public const DEFAULT_DAYS = 30;

	/**
	 * Days the dashboard sparkline covers.
	 */
	public const SERIES_DAYS = 7;

	/**
	 * Constructor.
	 *
	 * @param Settings                 $settings   Settings document.
	 * @param Rollup_Repository        $rollups    Native delivery counters.
	 * @param Rollup_Report_Repository $reports    Org-scoped, range-bounded reads.
	 * @param Rollup_Reconciler        $reconciler Projection watermark.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Rollup_Repository $rollups,
		private readonly Rollup_Report_Repository $reports,
		private readonly Rollup_Reconciler $reconciler
	) {
	}

	/**
	 * Impression tiles and fields exist only while Reporting is on.
	 *
	 * Native delivery is always recording. Reporting-off omits the
	 * tiles so zeros are not invented as business figures.
	 */
	public function surfaces(): bool {
		return $this->settings->module_enabled( Settings_Schema::MODULE_REPORTING );
	}

	/**
	 * The default reporting window, ending today in UTC.
	 *
	 * Built here rather than by each caller so every surface on a page covers
	 * the same days.
	 *
	 * @param int $days Window length, 1–92.
	 */
	public function default_period( int $days = self::DEFAULT_DAYS ): Report_Period {
		return Report_Period::trailing( $days, gmdate( 'Y-m-d' ) );
	}

	/**
	 * How settled a period's numbers are.
	 *
	 * Reported by every surface that shows a number, because a partial day
	 * summed beside reconciled ones looks exactly like a decline in traffic.
	 *
	 * @param Report_Period $period Range being reported.
	 * @return array{state: string, unreconciled_from: string|null, reconciled_through: string}
	 */
	public function freshness( Report_Period $period ): array {
		$watermark = $this->reconciler->reconciled_through();
		$today     = gmdate( 'Y-m-d' );

		return array(
			'state'              => $period->freshness( $watermark, $today ),
			'unreconciled_from'  => $period->unreconciled_from( $watermark, $today ),
			'reconciled_through' => $watermark,
		);
	}

	/**
	 * Organization totals over a range, or zeros when the surface is off.
	 *
	 * @param int                $org_id Owning organization.
	 * @param Report_Period|null $period Range, or the default window.
	 * @return array{impressions: int, clicks: int, viewables: int|null}
	 */
	public function totals_for_org( int $org_id, ?Report_Period $period = null ): array {
		if ( ! $this->surfaces() ) {
			// Null rather than 0, matching the rest of the contract: Reporting
			// being off is not a claim that nothing was seen.
			return array(
				'impressions' => 0,
				'clicks'      => 0,
				'viewables'   => null,
			);
		}

		return $this->reports->totals_for_org( $org_id, $period ?? $this->default_period() );
	}

	/**
	 * Adds impressions, clicks, and CTR to authorized campaign rows.
	 *
	 * Off leaves the keys absent so a client cannot treat 0 as "nobody saw this."
	 * CTR is null when impressions are not positive (not a fake 0%).
	 *
	 * These are lifetime figures for a named campaign, deliberately: the row is
	 * about the campaign rather than about a period, and the read is bounded by
	 * that campaign's own schedule rather than by the organization's history.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows that already include `id`.
	 * @return array<int, array<string, mixed>>
	 */
	public function attach( array $rows ): array {
		if ( ! $this->surfaces() || array() === $rows ) {
			return $rows;
		}

		$ids = array();

		foreach ( $rows as $row ) {
			$ids[] = (int) $row['id'];
		}

		$totals = $this->rollups->totals_for_campaigns( $ids );

		foreach ( $rows as $index => $row ) {
			$id          = (int) $row['id'];
			$impressions = $totals[ $id ]['impressions'] ?? 0;
			$clicks      = $totals[ $id ]['clicks'] ?? 0;

			$rows[ $index ]['impressions'] = $impressions;
			$rows[ $index ]['clicks']      = $clicks;
			$rows[ $index ]['ctr']         = Reporting_Rules::ctr( $impressions, $clicks );
		}

		return $rows;
	}

	/**
	 * Org-scoped daily impressions over a range, or empty when the surface is off.
	 *
	 * @param int                $org_id Owning organization.
	 * @param Report_Period|null $period Range, or the default sparkline window.
	 * @return list<array{day: string, impressions: int, clicks: int}>
	 */
	public function series_for_org( int $org_id, ?Report_Period $period = null ): array {
		if ( ! $this->surfaces() ) {
			return array();
		}

		return $this->reports->series_for_org( $org_id, $period ?? $this->default_period( self::SERIES_DAYS ) );
	}

	/**
	 * Per-campaign, per-day rows for a CSV export, or empty when off.
	 *
	 * Gated identically to every other surface. An export that still returned
	 * rows with Reporting switched off would be the one place a site owner's
	 * decision to hide the numbers could be walked around, and it would be the
	 * place that hands them over in bulk.
	 *
	 * @param int           $org_id Owning organization.
	 * @param Report_Period $period Bounded UTC range.
	 * @return list<array{day: string, campaign_id: int, campaign: string, impressions: int, clicks: int}>
	 */
	public function daily_rows_for_org( int $org_id, Report_Period $period ): array {
		if ( ! $this->surfaces() ) {
			return array();
		}

		return $this->reports->daily_rows_for_org( $org_id, $period );
	}

	/**
	 * One authorized campaign row with metrics attached when the surface is on.
	 *
	 * @param array<string, mixed> $row A row that already includes `id`.
	 * @return array<string, mixed>
	 */
	public function attach_one( array $row ): array {
		$attached = $this->attach( array( $row ) );
		$first    = reset( $attached );

		return is_array( $first ) ? $first : $row;
	}
}
