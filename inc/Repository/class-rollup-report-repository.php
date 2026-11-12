<?php
/**
 * Organization-scoped reads of the delivery projection, all bounded by a range.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Domain\Report_Export_Limits;
use Aggressive\Ads\Domain\Report_Zone;
use Aggressive\Ads\Install\Schema;

/**
 * What a tenant may read out of `aggr_report_buckets`, split from the class
 * that writes and reconciles the daily table.
 *
 * **Why a separate class.** `Rollup_Repository` owns the table: its schema, the
 * live counter increment, the reconciler's rebuild and the pacing reads the
 * decision engine makes. Those are writes and hot-path reads reviewed for
 * contention and query budget. These are org-scoped aggregates reviewed for
 * tenant isolation and range bounds. UTC reads retain the daily table;
 * local-day reads use the finer reporting projection.
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

	private const EMPTY_TOTALS = array(
		'impressions' => 0,
		'clicks'      => 0,
		'viewables'   => null,
		'conversions' => null,
	);

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
			array( $org_id, $campaign_id, $period->start, $period->end, $period->timezone ),
			fn (): array => $this->read_totals_for_org( $org_id, $period, $campaign_id ) ?? self::EMPTY_TOTALS
		);
	}

	/**
	 * Uncached delivery snapshot; failed SQL cannot be mailed as measured zeros.
	 *
	 * @param int           $org_id Authorized organization id.
	 * @param Report_Period $period Bounded report period.
	 * @return array{impressions: int, clicks: int, viewables: int|null, conversions: int|null}|\WP_Error
	 */
	public function checked_totals_for_org( int $org_id, Report_Period $period ): array|\WP_Error {
		if ( $org_id <= 0 ) {
			return new \WP_Error( 'aggr_report_scope_invalid' );
		}

		return $this->read_totals_for_org( $org_id, $period ) ?? new \WP_Error( 'aggr_report_read_unavailable' );
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
			array( $org_id, $campaign_id, $period->start, $period->end, $period->timezone ),
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

		// Advancing the historical backfill must not reuse a partially rebuilt total.
		$args[] = (int) get_option( Report_Bucket_Repository::OPTION_SINCE, 0 );
		$args[] = (int) get_option( Report_Bucket_Repository::OPTION_NEXT, 0 );

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
	 * @param Report_Period $period UTC uses retained daily history; local days use buckets.
	 *
	 * Derived the same way `Rollup_Repository` derives it. Both read one
	 * constant from `Schema`, so there is one name and two accessors rather
	 * than a second source of truth.
	 */
	private function table_name( Report_Period $period ): string {
		global $wpdb;

		return $wpdb->prefix . ( Report_Zone::UTC === $period->timezone ? Schema::ROLLUPS_TABLE : Schema::REPORT_BUCKETS_TABLE );
	}

	/**
	 * Calendar-day SQL without depending on MySQL's optional timezone tables.
	 * Only offset transitions inside the bounded period need branches. SQL then
	 * returns one row per local day rather than shipping every bucket to PHP.
	 *
	 * @param Report_Period $period Bounded, validated reporting period.
	 */
	private function day_sql( Report_Period $period ): string {
		if ( Report_Zone::UTC === $period->timezone ) {
			return 'r.day_utc';
		}

		global $wpdb;
		$zone        = new \DateTimeZone( $period->timezone );
		$transitions = $zone->getTransitions( $period->utc_start()->getTimestamp(), $period->utc_end()->getTimestamp() );
		$offset      = $zone->getOffset( $period->utc_start() );
		$sql         = 'CASE ';

		foreach ( false === $transitions ? array() : array_slice( $transitions, 1 ) as $transition ) {
			$sql   .= $wpdb->prepare(
				'WHEN r.bucket_utc < %s THEN DATE(DATE_ADD(r.bucket_utc, INTERVAL %d SECOND)) ',
				gmdate( 'Y-m-d H:i:s', $transition['ts'] ),
				$offset
			);
			$offset = $transition['offset'];
		}

		$day = $wpdb->prepare( 'DATE(DATE_ADD(r.bucket_utc, INTERVAL %d SECOND))', $offset );

		return 'CASE ' === $sql ? $day : $sql . 'ELSE ' . $day . ' END';
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
	 * @return array{impressions: int, clicks: int, viewables: int|null, conversions: int|null}|null Null means failed SQL.
	 */
	private function read_totals_for_org( int $org_id, Report_Period $period, int $campaign_id = 0 ): ?array {
		if ( $org_id <= 0 ) {
			return self::EMPTY_TOTALS;
		}

		global $wpdb;

		$table  = $this->table_name( $period );
		$bucket = Report_Zone::UTC === $period->timezone ? 'r.day_utc' : 'r.bucket_utc';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefix+constant; org id and bounds are prepared.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(r.impressions), 0) AS impressions, COALESCE(SUM(r.clicks), 0) AS clicks,
					SUM(r.viewables) AS viewables, SUM(r.conversions) AS conversions
				FROM {$table} r
				WHERE r.org_id = %d
					AND r.campaign_id > 0
					AND ( %d = 0 OR r.campaign_id = %d )
					AND {$bucket} >= %s
					AND {$bucket} < %s",
				$org_id,
				$campaign_id,
				$campaign_id,
				$period->bucket_start(),
				$period->bucket_end()
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $row ) ) {
			return null;
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
	 * @param Report_Period $period      Bounded calendar range in a named timezone.
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

		$table   = $this->table_name( $period );
		$bucket  = Report_Zone::UTC === $period->timezone ? 'r.day_utc' : 'r.bucket_utc';
		$day_sql = $this->day_sql( $period );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefix+constant; org id and bounds are prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$day_sql} AS day, COALESCE(SUM(r.impressions), 0) AS impressions, COALESCE(SUM(r.clicks), 0) AS clicks
				FROM {$table} r
				WHERE r.org_id = %d
					AND r.campaign_id > 0
					AND ( %d = 0 OR r.campaign_id = %d )
					AND {$bucket} >= %s
					AND {$bucket} < %s
				GROUP BY day",
				$org_id,
				$campaign_id,
				$campaign_id,
				$period->bucket_start(),
				$period->bucket_end()
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

				$padded[ $day ]['impressions'] += (int) $row['impressions'];
				$padded[ $day ]['clicks']      += (int) $row['clicks'];
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
	 * @return list<array{day: string, campaign_id: int, campaign: string, impressions: int, clicks: int, conversions: int|null}>|\WP_Error
	 */
	public function daily_rows_for_org( int $org_id, Report_Period $period, int $campaign_id = 0 ): array|\WP_Error {
		if ( $org_id <= 0 ) {
			return array();
		}

		global $wpdb;

		$table   = $this->table_name( $period );
		$bucket  = Report_Zone::UTC === $period->timezone ? 'r.day_utc' : 'r.bucket_utc';
		$day_sql = $this->day_sql( $period );
		$posts   = $wpdb->posts;
		$index   = Report_Zone::UTC === $period->timezone ? 'org_day' : 'org_bucket';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are prefix+constant / core posts; the tenant/date index is allowlisted and all values are prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT q.*, CASE WHEN OCTET_LENGTH(p.post_title) <= %d THEN p.post_title ELSE NULL END AS campaign
				FROM (
					SELECT {$day_sql} AS day, r.campaign_id,
						COALESCE(SUM(r.impressions), 0) AS impressions,
						COALESCE(SUM(r.clicks), 0) AS clicks,
						SUM(r.conversions) AS conversions
					FROM {$table} r FORCE INDEX ({$index})
					INNER JOIN {$posts} existing ON existing.ID = r.campaign_id
					WHERE r.org_id = %d
						AND r.campaign_id > 0
						AND ( %d = 0 OR r.campaign_id = %d )
						AND {$bucket} >= %s AND {$bucket} < %s
					GROUP BY day, r.campaign_id
					ORDER BY day, r.campaign_id
					LIMIT %d
				) q
				INNER JOIN {$posts} p ON p.ID = q.campaign_id
				ORDER BY q.day ASC, campaign ASC, q.campaign_id ASC",
				Report_Export_Limits::MAX_NAME_BYTES,
				$org_id,
				$campaign_id,
				$campaign_id,
				$period->bucket_start(),
				$period->bucket_end(),
				Report_Export_Limits::MAX_ROWS + 1
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return new \WP_Error( 'aggr_report_export_unavailable', __( 'The report could not be loaded. Please try again.', 'aggressive-ads' ), array( 'status' => 503 ) );
		}
		if ( count( $rows ) > Report_Export_Limits::MAX_ROWS ) {
			return new \WP_Error( 'aggr_report_export_too_large', __( 'This report is too large to download. Choose a shorter date range or export a single campaign.', 'aggressive-ads' ), array( 'status' => 422 ) );
		}
		foreach ( $rows as $row ) {
			if ( null === $row['campaign'] ) {
				return new \WP_Error( 'aggr_report_export_name_too_large', __( 'A campaign name is too long to export. Shorten the name and try again.', 'aggressive-ads' ), array( 'status' => 422 ) );
			}
		}

		return array_map(
			static fn ( array $row ): array => array(
				'day'         => (string) $row['day'],
				'campaign_id' => (int) $row['campaign_id'],
				'campaign'    => (string) $row['campaign'],
				'impressions' => (int) $row['impressions'],
				'clicks'      => (int) $row['clicks'],
				'conversions' => null === $row['conversions'] ? null : (int) $row['conversions'],
			),
			array_values( $rows )
		);
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

		$table  = $this->table_name( $period );
		$bucket = Report_Zone::UTC === $period->timezone ? 'r.day_utc' : 'r.bucket_utc';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefix+constant; ids, bounds and the row cap are prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.placement_id, r.creative_id,
					COALESCE(SUM(r.impressions), 0) AS impressions, COALESCE(SUM(r.clicks), 0) AS clicks,
					SUM(r.viewables) AS viewables, SUM(r.conversions) AS conversions
				FROM {$table} r
				WHERE r.org_id = %d
					AND r.campaign_id = %d
					AND {$bucket} >= %s
					AND {$bucket} < %s
				GROUP BY r.placement_id, r.creative_id
				ORDER BY r.placement_id, r.creative_id
				LIMIT %d",
				$org_id,
				$campaign_id,
				$period->bucket_start(),
				$period->bucket_end(),
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
