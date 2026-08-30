<?php
/**
 * Campaign collection queries.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;

/**
 * Owns paged and set-valued campaign reads; single-record reads stay separate.
 */
final class Campaign_Query_Repository {

	/**
	 * Staff review queue across organizations.
	 *
	 * @param array<int, string> $statuses Campaign statuses.
	 * @param int                $page Page number.
	 * @param bool               $pending_updates_only Pending creative updates only.
	 * @param bool               $pending_requests_only Advertiser requests awaiting a decision only.
	 * @return array{ids: array<int, int>, total: int, pages: int}
	 */
	public function for_review( array $statuses, int $page, bool $pending_updates_only, bool $pending_requests_only = false ): array {
		$wanted = array_values(
			array_filter( $statuses, static fn ( $status ): bool => is_string( $status ) && Post_Statuses::is_valid( $status ) )
		);

		if ( array() === $wanted ) {
			return array(
				'ids'   => array(),
				'total' => 0,
				'pages' => 0,
			);
		}

		$args = array(
			'post_type'              => Post_Types::CAMPAIGN,
			'post_status'            => $wanted,
			'posts_per_page'         => Campaign_Repository::PAGE_SIZE,
			'paged'                  => max( 1, $page ),
			'fields'                 => 'ids',
			'orderby'                => array(
				'modified' => 'ASC',
				'ID'       => 'ASC',
			),
			'update_post_term_cache' => false,
		);

		if ( $pending_updates_only ) {
			/*
			 * Two kinds of creative work waiting on one tab, ORed rather than
			 * summed into a single counter.
			 *
			 * A replacement swaps artwork on a running campaign; a new creative
			 * added to one has never been published at all. Both need the same
			 * decision from the same person, and both were invisible before —
			 * the second had no counter, no tab and no route, so a creative
			 * added after a campaign went live could never be approved and
			 * therefore could never serve.
			 */
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded staff queue against two denormalized counts.
				'relation' => 'OR',
				array(
					'key'     => Campaign_Repository::META_PENDING_UPDATES,
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => Campaign_Repository::META_PENDING_CREATIVES,
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			);
		}

		/*
		 * Advertiser asks that need a staff decision: a submitted edit to a
		 * running campaign, or a request for a transition only staff can make.
		 *
		 * Both are stored as meta on the campaign rather than in a queue table,
		 * so this is the query that makes them findable. Until it existed there
		 * was no screen, no count and no notification carrying them, and an
		 * advertiser could submit a change that nobody was ever told about.
		 */
		if ( $pending_requests_only ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded staff queue; both keys are written only by the change workflow.
				'relation' => 'OR',
				array(
					'key'     => Campaign_Repository::META_PENDING_EDITS_SENT,
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => Campaign_Repository::META_ACTION_REQUEST,
					'compare' => 'EXISTS',
				),
			);
		}

		return $this->page_result( new \WP_Query( $args ) );
	}

	/**
	 * Counts campaigns in validated statuses.
	 *
	 * @param array<int, string> $statuses Campaign statuses.
	 * @return array<string, int>
	 */
	public function count_by_status( array $statuses ): array {
		$counts = array();

		foreach ( $statuses as $status ) {
			if ( is_string( $status ) && Post_Statuses::is_valid( $status ) ) {
				$counts[ $status ] = 0;
			}
		}

		if ( array() === $counts ) {
			return array();
		}

		$totals = (array) wp_count_posts( Post_Types::CAMPAIGN );

		foreach ( array_keys( $counts ) as $status ) {
			$counts[ $status ] = isset( $totals[ $status ] ) ? (int) $totals[ $status ] : 0;
		}

		return $counts;
	}

	/**
	 * One organization's newest campaigns.
	 *
	 * @param int $org_id Organization id.
	 * @param int $page Page number.
	 * @return array{ids: array<int, int>, total: int, pages: int}
	 */
	public function for_org( int $org_id, int $page ): array {
		if ( $org_id <= 0 ) {
			return array(
				'ids'   => array(),
				'total' => 0,
				'pages' => 0,
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'              => Post_Types::CAMPAIGN,
				'post_status'            => Post_Statuses::all(),
				'posts_per_page'         => Campaign_Repository::PAGE_SIZE,
				'paged'                  => max( 1, $page ),
				'fields'                 => 'ids',
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- This indexed meta predicate is the tenant scope.
					array(
						'key'   => Campaign_Repository::META_ORG_ID,
						'value' => (string) $org_id,
					),
				),
			)
		);

		return $this->page_result( $query );
	}

	/**
	 * Complete live set for one placement.
	 *
	 * @param int $placement_id Placement id.
	 * @return array<int, int>
	 */
	public function live_ids_for_placement( int $placement_id ): array {
		if ( $placement_id <= 0 ) {
			return array();
		}

		$ids       = array();
		$offset    = 0;
		$page_size = 100;

		do {
			$page = get_posts(
				array(
					'post_type'              => Post_Types::CAMPAIGN,
					'post_status'            => Post_Statuses::LIVE,
					'numberposts'            => $page_size,
					'offset'                 => $offset,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded live set for one placement.
						array(
							'key'     => Campaign_Repository::META_PLACEMENT_ID,
							'value'   => $placement_id,
							'compare' => '=',
							'type'    => 'NUMERIC',
						),
					),
				)
			);

			$page       = array_map( 'intval', $page );
			$page_count = count( $page );
			$ids        = array_merge( $ids, $page );
			$offset    += $page_count;
		} while ( $page_size === $page_count );

		return array_values( $ids );
	}

	/**
	 * Normalizes an ids-only WP_Query page.
	 *
	 * @param \WP_Query $query Completed query.
	 * @return array{ids: array<int, int>, total: int, pages: int}
	 */
	private function page_result( \WP_Query $query ): array {
		$ids = array();

		foreach ( $query->posts as $post ) {
			$ids[] = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;
		}

		return array(
			'ids'   => $ids,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}
}
