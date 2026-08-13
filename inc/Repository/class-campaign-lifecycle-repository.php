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

/**
 * Cron-facing campaign id lookups.
 *
 * Kept out of Campaign_Repository so single-object persistence and lifecycle
 * sweeps do not share one class that trips the file-length gate. Callers are
 * cron workflows, never request handlers that already know a campaign id.
 */
final class Campaign_Lifecycle_Repository {

	/**
	 * Campaign ids in the given statuses, oldest-modified first.
	 *
	 * For the reconciler, which sweeps every organization: there is no org
	 * clause here and there should not be, because the clock belongs to nobody.
	 * Bounded by $limit rather than paged — a sweep that falls behind catches
	 * up on the next run.
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

		return $this->query_ids(
			array(
				'post_status' => $wanted,
				'orderby'     => 'modified',
				'order'       => 'ASC',
			),
			$limit
		);
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

		return $this->query_ids(
			array(
				'post_status' => array( Post_Statuses::LIVE, Post_Statuses::PAUSED ),
				'orderby'     => 'meta_value_num',
				'meta_key'    => Campaign_Repository::META_END_TS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed end-window lookup for the ending-soon sweep.
				'order'       => 'ASC',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded lifecycle sweep; not a page-read path.
					array(
						'key'     => Campaign_Repository::META_END_TS,
						'value'   => array( $from_ts, $to_ts ),
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					),
				),
			),
			$limit
		);
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

		$ids = $this->query_ids(
			array(
				'post_status' => Post_Statuses::terminal(),
				'orderby'     => 'meta_value_num',
				'meta_key'    => Campaign_Repository::META_END_TS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Retention sweep keyed on end date.
				'order'       => 'ASC',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded retention sweep.
					array(
						'key'     => Campaign_Repository::META_END_TS,
						'value'   => array( 1, $cutoff_ts ),
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					),
				),
			),
			$limit
		);

		if ( count( $ids ) >= $limit ) {
			return $ids;
		}

		return array_values(
			array_unique(
				array_merge(
					$ids,
					$this->query_ids(
						array(
							'post_status' => Post_Statuses::terminal(),
							'orderby'     => 'modified',
							'order'       => 'ASC',
							'date_query'  => array(
								array(
									'column'    => 'post_modified_gmt',
									'before'    => gmdate( 'Y-m-d H:i:s', $cutoff_ts ),
									'inclusive' => true,
								),
							),
							'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Open-ended terminal retention.
								'relation' => 'OR',
								array(
									'key'     => Campaign_Repository::META_END_TS,
									'compare' => 'NOT EXISTS',
								),
								array(
									'key'     => Campaign_Repository::META_END_TS,
									'value'   => 0,
									'compare' => '=',
									'type'    => 'NUMERIC',
								),
							),
						),
						$limit - count( $ids )
					)
				)
			)
		);
	}

	/**
	 * Runs a campaign id query with shared defaults.
	 *
	 * @param array<string, mixed> $args  Extra WP_Query args.
	 * @param int                  $limit Maximum ids.
	 * @return array<int, int>
	 */
	private function query_ids( array $args, int $limit ): array {
		$query = new \WP_Query(
			array_merge(
				array(
					'post_type'              => Post_Types::CAMPAIGN,
					'posts_per_page'         => $limit,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				),
				$args
			)
		);

		$ids = array();

		foreach ( $query->posts as $post ) {
			$ids[] = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;
		}

		return $ids;
	}
}
