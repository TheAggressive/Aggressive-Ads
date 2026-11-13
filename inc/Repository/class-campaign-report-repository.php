<?php
/**
 * Bounded current delivery-plan settings and native projection reads.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Domain\Report_Zone;
use Aggressive\Ads\Install\Schema;

/**
 * Each query bounds input rows before aggregation or presentation.
 *
 * @phpstan-type Totals array{impressions: int, clicks: int, viewables: int|null, conversions: int|null}
 */
final class Campaign_Report_Repository {

	public const PAGE_SIZE     = 10;
	public const SCAN_ROWS     = 65536;
	private const EMPTY_TOTALS = array(
		'impressions' => 0,
		'clicks'      => 0,
		'viewables'   => null,
		'conversions' => null,
	);

	/**
	 * Verify the access paths before an installer stamps migration completion.
	 *
	 * @throws \RuntimeException When a required index is unavailable or incomplete.
	 */
	public function verify_indexes(): void {
		global $wpdb;
		$indexes = array(
			array( Schema::LINE_ITEMS_TABLE, 'organization_report', array( 'organization_id', 'id' ) ),
			array( Schema::ROLLUPS_TABLE, 'org_campaign_line_day', array( 'org_id', 'campaign_id', 'line_item_id', 'day_utc' ) ),
			array( Schema::REPORT_BUCKETS_TABLE, 'org_campaign_line_bucket', array( 'org_id', 'campaign_id', 'line_item_id', 'bucket_utc' ) ),
		);
		foreach ( $indexes as [ $table, $name, $expected ] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Three fixed indexes inspected only during install/upgrade.
			$rows   = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name=%s', $wpdb->prefix . $table, $name ), ARRAY_A );
			$actual = array();
			foreach ( $rows as $row ) {
				$actual[ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
			}
			ksort( $actual );
			if ( array_values( $actual ) !== $expected ) {
				throw new \RuntimeException( 'Could not install campaign report indexes.' );
			}
		}
	}

	/**
	 * Newest settings, with a keyset cursor; reads never create missing plans.
	 *
	 * @param int $org_id Server-resolved organization.
	 * @param int $before Exclusive line-item id, or zero for the first page.
	 * @return array{rows: list<array<string, mixed>>, more: bool, next: int}|\WP_Error
	 */
	public function page( int $org_id, int $before = 0 ): array|\WP_Error {
		if ( $org_id <= 0 || $before < 0 ) {
			return $this->unavailable();
		}
		global $wpdb;
		$cursor = $before > 0 ? ' AND id < %d' : '';
		$args   = array( $wpdb->prefix . Schema::LINE_ITEMS_TABLE, $org_id );
		if ( $before > 0 ) {
			$args[] = $before;
		}
		$args[] = self::PAGE_SIZE + 1;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Variadic arguments match the fixed fields and optional cursor. Cursor SQL is fixed; indexed org/id range, eleven rows, bounded names, no large JSON fields.
		$rows = $this->read(
			$wpdb->prepare(
				"SELECT id,campaign_id,organization_id,LEFT(name,127) AS name,CHAR_LENGTH(name)>127 AS name_clipped,
			name_is_derived,status,start_at_ts,end_at_ts,goal_type,goal_amount,pacing_mode,updated_at_ts
			FROM %i FORCE INDEX (organization_report) WHERE organization_id=%d{$cursor} ORDER BY id DESC LIMIT %d",
				...$args
			)
		);
		// phpcs:enable
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$more = count( $rows ) > self::PAGE_SIZE;
		$rows = array_slice( $rows, 0, self::PAGE_SIZE );
		if ( array() === $rows ) {
			return array(
				'rows' => array(),
				'more' => false,
				'next' => 0,
			);
		}
		$next  = $more ? (int) $rows[ count( $rows ) - 1 ]['id'] : 0;
		$ids   = array_values( array_unique( array_map( static fn ( array $row ): int => (int) $row['campaign_id'], $rows ) ) );
		$slots = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// Parent ownership is authoritative too: a stale or forged organization_id cannot disclose another campaign.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Variadic arguments match the bounded id list and fixed fields. At most ten exact post ids; correlated ownership lookup uses post_id and cannot duplicate a row.
		$parents = $this->read(
			$wpdb->prepare(
				"SELECT p.ID,LEFT(p.post_title,127) AS title,CHAR_LENGTH(p.post_title)>127 AS title_clipped
			FROM {$wpdb->posts} p WHERE p.ID IN ({$slots}) AND p.post_type=%s AND p.post_status NOT IN ('trash','auto-draft')
			AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key=%s AND m.meta_value=%s)",
				...array_merge( $ids, array( Post_Types::CAMPAIGN, Campaign_Repository::META_ORG_ID, (string) $org_id ) )
			)
		);
		// phpcs:enable
		if ( is_wp_error( $parents ) ) {
			return $parents;
		}
		$titles = array();
		foreach ( $parents as $parent ) {
			$titles[ (int) $parent['ID'] ] = (string) $parent['title'] . ( $parent['title_clipped'] ? '…' : '' );
		}
		$authorized = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $titles[ (int) $row['campaign_id'] ] ) ) {
				continue;
			}
			foreach ( array( 'id', 'campaign_id', 'organization_id', 'start_at_ts', 'end_at_ts', 'goal_amount', 'updated_at_ts' ) as $field ) {
				$row[ $field ] = (int) $row[ $field ];
			}
			$row['campaign_name'] = $titles[ $row['campaign_id'] ];
			$row['name']         .= $row['name_clipped'] ? '…' : '';
			$authorized[]         = $row;
		}
		return array(
			'rows' => $authorized,
			'more' => $more,
			'next' => $next,
		);
	}

	/**
	 * Sum only a bounded set of projection rows. Overflow is unavailable, never a partial total.
	 *
	 * @param int                        $org_id Authorized organization.
	 * @param list<array<string, mixed>> $plans Authorized settings page.
	 * @param Report_Period|null         $period Selected range, or null for current retained UTC history.
	 * @param int                        $now Current snapshot clock.
	 * @return array{totals: array<int, array{impressions: int, clicks: int, viewables: int|null, conversions: int|null}|null>, limited: bool, legacy: list<int>}|\WP_Error
	 */
	public function totals( int $org_id, array $plans, ?Report_Period $period, int $now ): array|\WP_Error {
		if ( $org_id <= 0 || count( $plans ) > self::PAGE_SIZE ) {
			return $this->unavailable();
		}
		$totals    = array();
		$campaigns = array();
		$owners    = array();
		foreach ( $plans as $plan ) {
			$id = (int) $plan['id'];
			if ( $id <= 0 || (int) $plan['organization_id'] !== $org_id ) {
				return $this->unavailable();
			}
			$totals[ $id ]                           = self::EMPTY_TOTALS;
			$owners[ $id ]                           = (int) $plan['campaign_id'];
			$campaigns[ (int) $plan['campaign_id'] ] = (int) $plan['campaign_id'];
		}
		if ( array() === $plans ) {
			return array(
				'totals'  => array(),
				'limited' => false,
				'legacy'  => array(),
			);
		}
		global $wpdb;
		$local          = null !== $period && Report_Zone::UTC !== $period->timezone;
		$table          = $wpdb->prefix . ( $local ? Schema::REPORT_BUCKETS_TABLE : Schema::ROLLUPS_TABLE );
		$date           = $local ? 'bucket_utc' : 'day_utc';
		$index          = $local ? 'org_campaign_line_bucket' : 'org_campaign_line_day';
		$start          = null === $period ? '1970-01-01' : ( $local ? $period->bucket_start() : $period->start );
		$end            = null === $period ? gmdate( 'Y-m-d', $now + DAY_IN_SECONDS ) : ( $local ? $period->bucket_end() : $period->utc_end()->format( 'Y-m-d' ) );
		$campaign_slots = implode( ',', array_fill( 0, count( $campaigns ), '%d' ) );
		$line_slots     = implode( ',', array_fill( 0, count( $owners ) + 1, '%d' ) );
		$args           = array_merge( array( $table, $index, $org_id ), array_values( $campaigns ), array( 0 ), array_keys( $owners ), array( $date, $start, $date, $end, $date, self::SCAN_ROWS + 1 ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Variadic arguments match the bounded IN lists and fixed fields. Fixed index/column allowlist, bounded prepared IN lists, index-order LIMIT materializes bounded input before SQL aggregation.
		$rows = $this->read(
			$wpdb->prepare(
				"SELECT campaign_id,line_item_id,COUNT(*) AS input_rows,SUM(impressions) AS impressions,
			SUM(clicks) AS clicks,SUM(viewables) AS viewables,SUM(conversions) AS conversions FROM (
			SELECT campaign_id,line_item_id,impressions,clicks,viewables,conversions FROM %i FORCE INDEX (%i)
			WHERE org_id=%d AND campaign_id IN ({$campaign_slots}) AND line_item_id IN ({$line_slots})
			AND %i >= %s AND %i < %s ORDER BY campaign_id,line_item_id,%i LIMIT %d
			) AS report_input GROUP BY campaign_id,line_item_id",
				...$args
			)
		);
		// phpcs:enable
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( array_sum( array_column( $rows, 'input_rows' ) ) > self::SCAN_ROWS ) {
			return array(
				'totals'  => array_fill_keys( array_keys( $owners ), null ),
				'limited' => true,
				'legacy'  => array(),
			);
		}
		$legacy = array();
		foreach ( $rows as $row ) {
			$id       = (int) $row['line_item_id'];
			$campaign = (int) $row['campaign_id'];
			if ( 0 !== $id && ( ! isset( $owners[ $id ] ) || $owners[ $id ] !== $campaign ) ) {
				return $this->unavailable();
			}
			$sum = $this->normalize_totals( $row );
			if ( is_wp_error( $sum ) ) {
				return $sum;
			}
			if ( 0 === $id ) {
				if ( array_sum( $sum ) > 0 ) {
					$legacy[ $campaign ] = $campaign;
				}
			} else {
				$totals[ $id ] = $sum;
			}
		}
		foreach ( $owners as $id => $campaign ) {
			if ( isset( $legacy[ $campaign ] ) ) {
				$totals[ $id ] = null;
			}
		}
		return array(
			'totals'  => $totals,
			'limited' => false,
			'legacy'  => array_values( $legacy ),
		);
	}

	/**
	 * Preserve measured zero/null semantics and reject sums outside PHP's integer range.
	 *
	 * @param array<string, mixed> $row One bounded SQL aggregate.
	 * @return Totals|\WP_Error
	 */
	private function normalize_totals( array $row ): array|\WP_Error {
		$impressions = $this->count_value( $row['impressions'] );
		$clicks      = $this->count_value( $row['clicks'] );
		$viewables   = null === $row['viewables'] ? null : $this->count_value( $row['viewables'] );
		$conversions = null === $row['conversions'] ? null : $this->count_value( $row['conversions'] );
		if ( is_wp_error( $impressions ) || is_wp_error( $clicks ) || is_wp_error( $viewables ) || is_wp_error( $conversions ) ) {
			return $this->unavailable();
		}
		return array(
			'impressions' => $impressions,
			'clicks'      => $clicks,
			'viewables'   => $viewables,
			'conversions' => $conversions,
		);
	}

	/**
	 * Native counters and their bounded sums must fit a non-negative PHP integer.
	 *
	 * @param mixed $raw Database sum.
	 * @return int|\WP_Error
	 */
	private function count_value( mixed $raw ): int|\WP_Error {
		$value = filter_var( $raw, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		return false === $value ? $this->unavailable() : $value;
	}

	/**
	 * A failed SELECT is never an empty success result.
	 *
	 * @param string $sql Prepared internal SQL.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private function read( string $sql ): array|\WP_Error {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Every caller supplies prepared internal SQL, and fresh error-aware reads are required.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return '' !== $wpdb->last_error ? $this->unavailable() : $rows;
	}

	/** SQL details stay out of public errors and mail/audit content. */
	private function unavailable(): \WP_Error {
		return new \WP_Error( 'aggr_campaign_report_unavailable', __( 'Campaign summaries could not be loaded. Please try again.', 'aggressive-ads' ) );
	}
}
