<?php
/**
 * Placement persistence.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Repository;

use LAAO_Advertiser_Portal\Core\Post_Types;

/**
 * Reads placements — the shared configuration a campaign is built from.
 *
 * The ad-group mapping lives here too, but only as a stored integer. Resolving
 * it against the provider is the publisher's job, and it happens in
 * inc/Integration/Adsanity/. See docs/adr/0007-placement-mapping-is-explicit-data.md.
 */
final class Placement_Repository {

	public const META_SIZE           = '_laao_ads_size';
	public const META_ADGROUP_TERM   = '_laao_ads_adgroup_term_id';
	public const META_IS_ACTIVE      = '_laao_ads_is_active';
	public const META_SORT_ORDER     = '_laao_ads_sort_order';
	public const META_POSITION_LABEL = '_laao_ads_position_label';

	/**
	 * Upper bound on placements returned in one query.
	 *
	 * A site with more distinct ad positions than this has a configuration
	 * problem, not a pagination problem.
	 */
	public const MAX_PLACEMENTS = 200;

	/**
	 * Whether a post exists and is a placement.
	 *
	 * @param int $placement_id Placement post id.
	 * @return bool
	 */
	public function exists( int $placement_id ): bool {
		return Post_Types::PLACEMENT === get_post_type( $placement_id );
	}

	/**
	 * Whether a placement is currently offered.
	 *
	 * Re-checked at approval as well as at submission, because a placement can
	 * be deactivated while a campaign waits in the queue.
	 *
	 * @param int $placement_id Placement post id.
	 * @return bool
	 */
	public function is_active( int $placement_id ): bool {
		if ( ! $this->exists( $placement_id ) ) {
			return false;
		}

		return 1 === (int) get_post_meta( $placement_id, self::META_IS_ACTIVE, true );
	}

	/**
	 * A placement's declared size, e.g. `728x90`.
	 *
	 * @param int $placement_id Placement post id.
	 * @return string
	 */
	public function size( int $placement_id ): string {
		return (string) get_post_meta( $placement_id, self::META_SIZE, true );
	}

	/**
	 * The ad-group term this placement publishes into, or 0 when unmapped.
	 *
	 * @param int $placement_id Placement post id.
	 * @return int
	 */
	public function adgroup_term_id( int $placement_id ): int {
		return (int) get_post_meta( $placement_id, self::META_ADGROUP_TERM, true );
	}

	/**
	 * Stores one placement's provider-group mapping and verifies the write.
	 *
	 * Zero deliberately means unmapped. The workflow layer decides whether a
	 * non-zero provider id is valid before it reaches this persistence method.
	 *
	 * @param int $placement_id Placement post id.
	 * @param int $term_id      Provider ad-group term id, or zero.
	 * @return bool
	 */
	public function set_adgroup_term_id( int $placement_id, int $term_id ): bool {
		if ( ! $this->exists( $placement_id ) || $term_id < 0 ) {
			return false;
		}

		if ( $this->adgroup_term_id( $placement_id ) === $term_id ) {
			return true;
		}

		update_post_meta( $placement_id, self::META_ADGROUP_TERM, $term_id );

		return $this->adgroup_term_id( $placement_id ) === $term_id;
	}

	/**
	 * A placement's display name.
	 *
	 * @param int $placement_id Placement post id.
	 * @return string
	 */
	public function name( int $placement_id ): string {
		$title = get_the_title( $placement_id );

		return is_string( $title ) ? $title : '';
	}

	/**
	 * A placement's display order, defaulting to 0 when unset.
	 *
	 * @param int $placement_id Placement post id.
	 * @return int
	 */
	public function sort_order( int $placement_id ): int {
		return (int) get_post_meta( $placement_id, self::META_SORT_ORDER, true );
	}

	/**
	 * Every placement, including inactive configuration rows.
	 *
	 * @return array<int, int>
	 */
	public function all_ids(): array {
		$ids = get_posts(
			array(
				'post_type'              => Post_Types::PLACEMENT,
				'post_status'            => 'any',
				'numberposts'            => self::MAX_PLACEMENTS,
				'fields'                 => 'ids',
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$ids = array_map( 'intval', $ids );

		usort(
			$ids,
			fn ( int $a, int $b ): int => $this->sort_order( $a ) <=> $this->sort_order( $b )
		);

		return $ids;
	}

	/**
	 * Every active placement, in sort order.
	 *
	 * @return array<int, int>
	 */
	public function active_ids(): array {
		/*
		 * Ordered in PHP, deliberately.
		 *
		 * `orderby => meta_value_num` with a meta_key requires the post to
		 * *have* that key: WP_Query joins on it, so a placement created without
		 * a sort order silently disappears from every advertiser's list. That
		 * is a configuration mistake presenting as a missing feature, which is
		 * the worst way for one to present.
		 *
		 * The set is bounded at MAX_PLACEMENTS and read once per screen, so
		 * sorting it here costs nothing and cannot hide a row.
		 */
		$ids = get_posts(
			array(
				'post_type'              => Post_Types::PLACEMENT,
				'post_status'            => 'any',
				'numberposts'            => self::MAX_PLACEMENTS,
				'fields'                 => 'ids',
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded configuration set, read once per screen.
					array(
						'key'   => self::META_IS_ACTIVE,
						'value' => 1,
					),
				),
			)
		);

		$ids = array_map( 'intval', $ids );

		usort(
			$ids,
			function ( int $a, int $b ): int {
				// Unset sorts as 0, so an unordered placement leads rather than
				// vanishing. Ties fall back to the title order above.
				return $this->sort_order( $a ) <=> $this->sort_order( $b );
			}
		);

		return $ids;
	}
}
