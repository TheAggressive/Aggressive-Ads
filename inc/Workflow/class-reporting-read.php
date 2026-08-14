<?php
/**
 * Whether advertiser reporting surfaces may render.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Reporting_Rules;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Repository\Rollup_Repository;

/**
 * The reporting gate and rollup reads in one place so View_Data and REST
 * cannot disagree about when zeros would be a lie.
 */
final class Reporting_Read {

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Settings document.
	 * @param Rollup_Repository $rollups  Native delivery counters.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Rollup_Repository $rollups
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
	 * Organization totals, or zeros when the surface is off.
	 *
	 * @param int $org_id Owning organization.
	 * @return array{impressions: int, clicks: int}
	 */
	public function totals_for_org( int $org_id ): array {
		if ( ! $this->surfaces() ) {
			return array(
				'impressions' => 0,
				'clicks'      => 0,
			);
		}

		return $this->rollups->totals_for_org( $org_id );
	}

	/**
	 * Adds impressions, clicks, and CTR to authorized campaign rows.
	 *
	 * Off leaves the keys absent so a client cannot treat 0 as "nobody saw this."
	 * CTR is null when impressions are not positive (not a fake 0%).
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
	 * Last seven UTC days of org-scoped impressions, or empty when the surface is off.
	 *
	 * @param int $org_id Owning organization.
	 * @return list<array{day: string, impressions: int, clicks: int}>
	 */
	public function series_for_org( int $org_id ): array {
		if ( ! $this->surfaces() ) {
			return array();
		}

		return $this->rollups->series_for_org( $org_id, 7 );
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
