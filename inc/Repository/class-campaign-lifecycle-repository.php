<?php
/**
 * Batch campaign queries for clock, reminder and retention sweeps.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Cron keyset queries must see current rows and use generated placeholders only for validated statuses.

/**
 * Cron-facing campaign id lookups.
 *
 * Kept out of Campaign_Repository so single-object persistence and lifecycle
 * sweeps do not share one class that trips the file-length gate. Callers are
 * cron workflows, never request handlers that already know a campaign id.
 */
final class Campaign_Lifecycle_Repository {

	private const CURSOR_CLOCK       = 'aggr_lifecycle_cursor_clock';
	private const CURSOR_ENDING_SOON = 'aggr_lifecycle_cursor_ending_soon';
	private const CURSOR_RETENTION   = 'aggr_lifecycle_cursor_retention';

	/**
	 * Campaign ids in the given statuses, advancing by stable id.
	 *
	 * For the reconciler, which sweeps every organization: there is no org
	 * clause here and there should not be, because the clock belongs to nobody.
	 * A durable keyset cursor ensures a full first batch cannot permanently
	 * starve later campaigns. The cursor wraps after the final page.
	 *
	 * @param array<int, string> $statuses Statuses to include.
	 * @param int                $limit    Maximum ids to return.
	 * @return array<int, int>
	 */
	public function ids_in_status( array $statuses, int $limit ): array {
		$wanted = array();

		foreach ( $statuses as $status ) {
			if ( is_string( $status ) && Post_Statuses::is_valid( $status ) ) {
				$wanted[] = $status;
			}
		}

		if ( array() === $wanted || $limit <= 0 ) {
			return array();
		}

		global $wpdb;

		$cursor       = (int) get_option( self::CURSOR_CLOCK, 0 );
		$placeholders = implode( ', ', array_fill( 0, count( $wanted ), '%s' ) );
		$sql          = "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ({$placeholders}) AND ID > %d ORDER BY ID ASC LIMIT %d";
		$args         = array_merge( array( Post_Types::CAMPAIGN ), $wanted, array( $cursor, $limit ) );
		$ids          = $wpdb->get_col( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders are generated for validated status values.

		return $this->finish_page( self::CURSOR_CLOCK, $ids, $limit );
	}

	/**
	 * Live or paused campaigns whose end timestamp falls in an inclusive window.
	 *
	 * Open-ended campaigns (`end_ts` of 0) never match.
	 *
	 * @param int $from_ts Inclusive lower bound (UTC Unix seconds).
	 * @param int $to_ts   Inclusive upper bound (UTC Unix seconds).
	 * @param int $limit   Maximum ids to return.
	 * @return array<int, int>
	 */
	public function ids_ending_between( int $from_ts, int $to_ts, int $limit ): array {
		if ( $from_ts <= 0 || $to_ts < $from_ts || $limit <= 0 ) {
			return array();
		}

		global $wpdb;

		$cursor = (int) get_option( self::CURSOR_ENDING_SOON, 0 );
		$sql    = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} ends ON ends.post_id = p.ID AND ends.meta_key = %s WHERE p.post_type = %s AND p.post_status IN (%s, %s) AND p.ID > %d AND CAST(ends.meta_value AS UNSIGNED) BETWEEN %d AND %d ORDER BY p.ID ASC LIMIT %d";
		$ids    = $wpdb->get_col(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is a fixed repository statement with prepared values.
				$sql,
				Campaign_Repository::META_END_TS,
				Post_Types::CAMPAIGN,
				Post_Statuses::LIVE,
				Post_Statuses::PAUSED,
				$cursor,
				$from_ts,
				$to_ts,
				$limit
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cron-only bounded keyset query.

		return $this->finish_page( self::CURSOR_ENDING_SOON, $ids, $limit );
	}

	/**
	 * Terminal campaigns whose relevance timestamp is at or before a cutoff.
	 *
	 * Relevance is `end_ts` when set, otherwise last modified. Two queries keep
	 * the retention sweep off a full-table PHP filter.
	 *
	 * @param int $cutoff_ts Inclusive upper bound (UTC Unix seconds).
	 * @param int $limit     Maximum ids to return.
	 * @return array<int, int>
	 */
	public function ids_terminal_before( int $cutoff_ts, int $limit ): array {
		if ( $cutoff_ts <= 0 || $limit <= 0 ) {
			return array();
		}

		global $wpdb;

		$cursor       = (int) get_option( self::CURSOR_RETENTION, 0 );
		$terminal     = Post_Statuses::terminal();
		$placeholders = implode( ', ', array_fill( 0, count( $terminal ), '%s' ) );
		$sql          = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} ends ON ends.post_id = p.ID AND ends.meta_key = %s WHERE p.post_type = %s AND p.post_status IN ({$placeholders}) AND p.ID > %d AND ((CAST(ends.meta_value AS UNSIGNED) BETWEEN 1 AND %d) OR ((ends.meta_id IS NULL OR CAST(ends.meta_value AS UNSIGNED) = 0) AND p.post_modified_gmt <= %s)) ORDER BY p.ID ASC LIMIT %d";
		$args         = array_merge(
			array( Campaign_Repository::META_END_TS, Post_Types::CAMPAIGN ),
			$terminal,
			array( $cursor, $cutoff_ts, gmdate( 'Y-m-d H:i:s', $cutoff_ts ), $limit )
		);
		$ids          = $wpdb->get_col( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders are generated for known terminal statuses.

		return $this->finish_page( self::CURSOR_RETENTION, $ids, $limit );
	}

	/**
	 * Advances a durable keyset cursor so bounded sweeps cannot starve later ids.
	 *
	 * @param string            $cursor_key Cursor option name.
	 * @param array<int, mixed> $raw_ids    Database result ids.
	 * @param int               $limit      Maximum ids.
	 * @return array<int, int>
	 */
	private function finish_page( string $cursor_key, array $raw_ids, int $limit ): array {
		$ids  = array_values( array_map( 'intval', $raw_ids ) );
		$next = count( $ids ) < $limit ? 0 : (int) end( $ids );

		update_option( $cursor_key, $next, false );

		return $ids;
	}
}
