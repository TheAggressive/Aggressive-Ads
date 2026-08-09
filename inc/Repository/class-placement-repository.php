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
	 * Every active placement, in sort order.
	 *
	 * @return array<int, int>
	 */
	public function active_ids(): array {
		$ids = get_posts(
			array(
				'post_type'              => Post_Types::PLACEMENT,
				'post_status'            => 'any',
				'numberposts'            => self::MAX_PLACEMENTS,
				'fields'                 => 'ids',
				'orderby'                => 'meta_value_num',
				'meta_key'               => self::META_SORT_ORDER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Ordering a small, bounded configuration set.
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

		return array_map( 'intval', $ids );
	}
}
