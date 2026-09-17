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
	 * Object cache group for the dashboard and campaign report reads.
	 */
	public const CACHE_GROUP = 'aggr_reports';

	/**
	 * Delivery totals for an organization, or one of its campaigns, over a range.
	 *
	 * @param int           $org_id      Owning organization.
	 * @param Report_Period $period      Bounded UTC range.
	 * @param int           $campaign_id One of the organization's campaigns, or 0 for all of them.
	 * @return array{impressions: int, clicks: int, viewables: int|null, conversions: int|null}
	 */
	public function totals_for_org( int $org_id, Report_Period $period, int $campaign_id = 0 ): array {
		return $this->remember(
			'totals',
			array( $org_id, $campaign_id, $period->start, $period->end ),
			fn (): array => $this->read_totals_for_org( $org_id, $period, $campaign_id )
		);
	}

	/**
	 * Daily totals for an organization, or one of its campaigns, zeros padded.
	 *
	 * @param int           $org_id      Owning organization.
	 * @param Report_Period $period      Bounded UTC range.
	 * @param int           $campaign_id One of the organization's campaigns, or 0 for all of them.
	 * @return list<array{day: string, impressions: int, clicks: int}>
	 */
	public function series_for_org( int $org_id, Report_Period $period, int $campaign_id = 0 ): array {
		return $this->remember(
			'series',
			array( $org_id, $campaign_id, $period->start, $period->end ),
			fn (): array => $this->read_series_for_org( $org_id, $period, $campaign_id )
		);
	}

	/**
	 * A report read, reused for five minutes where a persistent object cache exists.
	 *
	 * **Only with a persistent cache.** Without one, WordPress's cache lasts a
	 * single request, in which each of these reads already happens once, so
	 * caching would add work and save none. The organization and campaign are
	 * part of the key, so one tenant's figures can never answer another's.
	 * Exports are deliberately not routed through here: a download is the
	 * figure somebody files, and it should be the current one.
	 *
	 * @template T of array
	 *
	 * @param string            $name Which read.
	 * @param array<int, mixed> $args Everything the result depends on.
	 * @param callable(): T     $read The read itself.
	 * @return T
	 */
	private function remember( string $name, array $args, callable $read ): array {
		if ( ! wp_using_ext_object_cache() ) {
			return $read();
		}

		$key   = $name . ':' . md5( (string) wp_json_encode( $args ) );
		$found = false;

		/**
		 * What `$read` returned when this entry was stored: the key names the
		 * read and everything it depends on, so a hit has the same shape.
		 *
		 * @var T $hit
		 */
		$hit = wp_cache_get( $key, self::CACHE_GROUP, false, $found );

		if ( $found && is_array( $hit ) ) {
			return $hit;
		}

		$value = $read();

		/*
		 * Five minutes. The counters behind these reads move with every
		 * impression, and the card already says which days are still coming
		 * in, so a figure a few minutes old is one the reader has been told to
		 * expect. What it buys is that a dashboard reloaded, or opened by
		 * several people in one account, does not re-sum the same rows. Shorter
		 * lifetimes churn a shared cache for little gain, which is why WordPress
		 * VIP's rules set this as the floor.
		 */
		wp_cache_set( $key, $value, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );

		return $value;
	}

	/**
	 * The most rows one variant comparison may return.
	 *
	 * A campaign's placements times `Creative_Manager::MAX_CREATIVES_PER_PLACEMENT`
	 * (ten), plus one row per placement for delivery counted before the
	 * creative dimension existed. Far above any real campaign, and there so the
	 * read is bounded by construction rather than by the data being small —
	 * the P17 contract asks the comparison to bound its own creative count
	 * instead of inheriting an unbounded scan.
	 */
	public const MAX_VARIANT_ROWS = 500;

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
	 * `viewables` and `conversions` are `SUM`s over nullable columns and stay
	 * null when no day in range was measured: a range before P11 did not have
	 * nobody see the ads, and one before P12 did not have nobody convert — in
	 * both cases nobody was counting. Coalescing either to zero is the one edit
	 * that would turn an unmeasured period into an alarming one.
	 *
	 * House rows (`campaign_id = 0`) are excluded and never attributed.
	 *
	 * @param int           $org_id      Owning organization.
	 * @param Report_Period $period      Bounded UTC range.
	 * @param int           $campaign_id One of the organization's campaigns, or 0 for all of them.
	 * @return array{impressions: int, clicks: int, viewables: int|null, conversions: int|null}
	 */
	private function read_totals_for_org( int $org_id, Report_Period $period, int $campaign_id = 0 ): array {
		$empty = array(
			'impressions' => 0,
			'clicks'      => 0,
			'viewables'   => null,
			'conversions' => null,
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
					SUM(r.viewables) AS viewables, SUM(r.conversions) AS conversions
				FROM {$table} r
				WHERE r.org_id = %d
					AND r.campaign_id > 0
					AND ( %d = 0 OR r.campaign_id = %d )
					AND r.day_utc >= %s
					AND r.day_utc <= %s",
				$org_id,
				$campaign_id,
				$campaign_id,
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
			'conversions' => null === $row['conversions'] ? null : (int) $row['conversions'],
		);
	}

	/**
	 * Org-scoped daily totals over the range, oldest day first, zeros padded.
	 *
	 * Padding happens here rather than in a template because a missing day and
	 * a zero day are the same picture in a chart and different facts, and the
	 * only place that knows which days were asked for is the period.
	 *
	 * @param int           $org_id      Owning organization.
	 * @param Report_Period $period      Bounded UTC range.
	 * @param int           $campaign_id One of the organization's campaigns, or 0 for all of them.
	 * @return list<array{day: string, impressions: int, clicks: int}>
	 */
	private function read_series_for_org( int $org_id, Report_Period $period, int $campaign_id = 0 ): array {
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
					AND ( %d = 0 OR r.campaign_id = %d )
					AND r.day_utc >= %s
					AND r.day_utc <= %s
				GROUP BY r.day_utc",
				$org_id,
				$campaign_id,
				$campaign_id,
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
	 * @param int           $org_id      Owning organization.
	 * @param Report_Period $period      Bounded UTC range.
	 * @param int           $campaign_id One of the organization's campaigns, or 0 for all of them.
	 * @return list<array{day: string, campaign_id: int, campaign: string, impressions: int, clicks: int, conversions: int|null}>
	 */
	public function daily_rows_for_org( int $org_id, Report_Period $period, int $campaign_id = 0 ): array {
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
					COALESCE(SUM(r.clicks), 0) AS clicks,
					SUM(r.conversions) AS conversions
				FROM {$table} r
				INNER JOIN {$posts} p
					ON p.ID = r.campaign_id
				WHERE r.org_id = %d
					AND r.campaign_id > 0
					AND ( %d = 0 OR r.campaign_id = %d )
					AND r.day_utc >= %s
					AND r.day_utc <= %s
				GROUP BY r.day_utc, r.campaign_id, p.post_title
				ORDER BY r.day_utc ASC, p.post_title ASC",
				$org_id,
				$campaign_id,
				$campaign_id,
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
				'conversions' => null === $row['conversions'] ? null : (int) $row['conversions'],
			);
		}

		return $out;
	}

	/**
	 * One campaign's delivery per placement and creative, over a bounded range.
	 *
	 * The first reader of the creative dimension P17 slice 1 added. Until this,
	 * every counter was written per creative and read per campaign, so the
	 * split existed only in the table.
	 *
	 * **Creative id 0 is returned, not filtered.** It is delivery counted before
	 * per-creative measurement existed, and dropping it would make a
	 * placement's variants sum to less than the placement — the defect P15
	 * shipped and caught one dimension higher. The caller decides how to label
	 * it; this read only refuses to lose it.
	 *
	 * Tenancy is the frozen `org_id`, as every read in this class: a campaign id
	 * alone authorizes nothing, and a campaign that changed hands keeps its
	 * history with the organization that ran it.
	 *
	 * @param int           $org_id      Organization the delivery is attributed to.
	 * @param int           $campaign_id Campaign post id.
	 * @param Report_Period $period      Bounded UTC range.
	 * @return array<int, array<int, array{impressions: int, clicks: int, viewables: int|null, conversions: int|null}>> Placement id, then creative id.
	 */
	public function creative_totals_for_campaign( int $org_id, int $campaign_id, Report_Period $period ): array {
		if ( $org_id <= 0 || $campaign_id <= 0 ) {
			return array();
		}

		global $wpdb;

		$table = $this->table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefix+constant; ids, bounds and the row cap are prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.placement_id, r.creative_id,
					COALESCE(SUM(r.impressions), 0) AS impressions, COALESCE(SUM(r.clicks), 0) AS clicks,
					SUM(r.viewables) AS viewables, SUM(r.conversions) AS conversions
				FROM {$table} r
				WHERE r.org_id = %d
					AND r.campaign_id = %d
					AND r.day_utc >= %s
					AND r.day_utc <= %s
				GROUP BY r.placement_id, r.creative_id
				ORDER BY r.placement_id, r.creative_id
				LIMIT %d",
				$org_id,
				$campaign_id,
				$period->start,
				$period->end,
				self::MAX_VARIANT_ROWS
			),
			ARRAY_A
		);
		// phpcs:enable

		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$out[ (int) $row['placement_id'] ][ (int) $row['creative_id'] ] = array(
				'impressions' => (int) $row['impressions'],
				'clicks'      => (int) $row['clicks'],
				'viewables'   => null === $row['viewables'] ? null : (int) $row['viewables'],
				'conversions' => null === $row['conversions'] ? null : (int) $row['conversions'],
			);
		}

		return $out;
	}
}
