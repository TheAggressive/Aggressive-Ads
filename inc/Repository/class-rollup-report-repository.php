<?php
/**
 * Organization-scoped reads of the delivery projection, all bounded by a range.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Install\Schema;

/**
 * What a tenant may read out of `aggr_rollups`, split from the class that
 * writes and reconciles it.
 *
 * **Why a separate class.** `Rollup_Repository` owns the table: its schema, the
 * live counter increment, the reconciler's rebuild and the pacing reads the
 * decision engine makes. Those are writes and hot-path reads reviewed for
 * contention and query budget. These are org-scoped aggregates reviewed for
 * tenant isolation and range bounds. Same table, two different review
 * standards, so they are two files — the reason the registrars are split the
 * same way.
 *
 * **Every read here takes a `Report_Period`.** Not a day count, not a pair of
 * strings: a value object that cannot exist unbounded. Before P14 the
 * organization total had no date predicate at all, so the first tile on the
 * advertiser dashboard summed every row the organization had ever produced —
 * measured at 12,775 rows examined against a 30-day read's 1,500 on one year of
 * a modest advertiser's history, and the gap grows linearly for as long as
 * retention keeps days. Making the bound a type rather than a convention is
 * what stops the next read being added without one.
 *
 * **Tenancy is filtered in SQL, against the frozen `org_id`.** Never a join to
 * current campaign metadata — P13 froze the column precisely so a campaign
 * changing hands does not move its history — and never a filter applied to rows
 * that have already been summed.
 */
final class Rollup_Report_Repository {

	/**
	 * Fully prefixed table name.
	 *
	 * Derived the same way `Rollup_Repository` derives it. Both read one
	 * constant from `Schema`, so there is one name and two accessors rather
	 * than a second source of truth.
	 */
	private function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . Schema::ROLLUPS_TABLE;
	}

	/**
	 * Delivery totals for one organization over a bounded range.
	 *
	 * `viewables` is a `SUM` over a nullable column and stays null when no day
	 * in range was measured: a range before P11 did not have nobody see the
	 * ads, it had nobody counting. Coalescing it to zero here is the one edit
	 * that would turn an unmeasured period into an alarming one.
	 *
	 * House rows (`campaign_id = 0`) are excluded and never attributed.
	 *
	 * @param int           $org_id Owning organization.
	 * @param Report_Period $period Bounded UTC range.
	 * @return array{impressions: int, clicks: int, viewables: int|null}
	 */
	public function totals_for_org( int $org_id, Report_Period $period ): array {
		$empty = array(
			'impressions' => 0,
			'clicks'      => 0,
			'viewables'   => null,
		);

		if ( $org_id <= 0 ) {
			return $empty;
		}

		global $wpdb;

		$table = $this->table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefix+constant; org id and bounds are prepared.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(r.impressions), 0) AS impressions, COALESCE(SUM(r.clicks), 0) AS clicks,
					SUM(r.viewables) AS viewables
				FROM {$table} r
				WHERE r.org_id = %d
					AND r.campaign_id > 0
					AND r.day_utc >= %s
					AND r.day_utc <= %s",
				$org_id,
				$period->start,
				$period->end
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $row ) ) {
			return $empty;
		}

		return array(
			'impressions' => (int) $row['impressions'],
			'clicks'      => (int) $row['clicks'],
			'viewables'   => null === $row['viewables'] ? null : (int) $row['viewables'],
		);
	}

	/**
	 * Org-scoped daily totals over the range, oldest day first, zeros padded.
	 *
	 * Padding happens here rather than in a template because a missing day and
	 * a zero day are the same picture in a chart and different facts, and the
	 * only place that knows which days were asked for is the period.
	 *
	 * @param int           $org_id Owning organization.
	 * @param Report_Period $period Bounded UTC range.
	 * @return list<array{day: string, impressions: int, clicks: int}>
	 */
	public function series_for_org( int $org_id, Report_Period $period ): array {
		$padded = array();

		foreach ( $period->keys() as $day ) {
			$padded[ $day ] = array(
				'day'         => $day,
				'impressions' => 0,
				'clicks'      => 0,
			);
		}

		if ( $org_id <= 0 ) {
			return array_values( $padded );
		}

		global $wpdb;

		$table = $this->table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefix+constant; org id and bounds are prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.day_utc AS day, COALESCE(SUM(r.impressions), 0) AS impressions, COALESCE(SUM(r.clicks), 0) AS clicks
				FROM {$table} r
				WHERE r.org_id = %d
					AND r.campaign_id > 0
					AND r.day_utc >= %s
					AND r.day_utc <= %s
				GROUP BY r.day_utc",
				$org_id,
				$period->start,
				$period->end
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$day = (string) $row['day'];

				if ( ! isset( $padded[ $day ] ) ) {
					continue;
				}

				$padded[ $day ]['impressions'] = (int) $row['impressions'];
				$padded[ $day ]['clicks']      = (int) $row['clicks'];
			}
		}

		return array_values( $padded );
	}

	/**
	 * Per-campaign, per-day rows over the range, for an export.
	 *
	 * The campaign title is joined rather than stored: a name is a
	 * re-resolvable dimension and a report shows today's name. Tenancy is not,
	 * which is why the `org_id` predicate reads the frozen column and not the
	 * campaign's current meta.
	 *
	 * @param int           $org_id Owning organization.
	 * @param Report_Period $period Bounded UTC range.
	 * @return list<array{day: string, campaign_id: int, campaign: string, impressions: int, clicks: int}>
	 */
	public function daily_rows_for_org( int $org_id, Report_Period $period ): array {
		if ( $org_id <= 0 ) {
			return array();
		}

		global $wpdb;

		$table = $this->table_name();
		$posts = $wpdb->posts;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are prefix+constant / core posts; bounds and org id are prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.day_utc AS day,
					r.campaign_id AS campaign_id,
					p.post_title AS campaign,
					COALESCE(SUM(r.impressions), 0) AS impressions,
					COALESCE(SUM(r.clicks), 0) AS clicks
				FROM {$table} r
				INNER JOIN {$posts} p
					ON p.ID = r.campaign_id
				WHERE r.org_id = %d
					AND r.campaign_id > 0
					AND r.day_utc >= %s
					AND r.day_utc <= %s
				GROUP BY r.day_utc, r.campaign_id, p.post_title
				ORDER BY r.day_utc ASC, p.post_title ASC",
				$org_id,
				$period->start,
				$period->end
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$out[] = array(
				'day'         => (string) $row['day'],
				'campaign_id' => (int) $row['campaign_id'],
				'campaign'    => (string) $row['campaign'],
				'impressions' => (int) $row['impressions'],
				'clicks'      => (int) $row['clicks'],
			);
		}

		return $out;
	}
}
