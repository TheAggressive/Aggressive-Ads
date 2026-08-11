<?php
/**
 * Package persistence.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Repository;

use LAAO_Advertiser_Portal\Core\Post_Types;

/**
 * Reads the shared package catalogue a campaign may select from.
 */
final class Package_Repository {

	public const META_PLACEMENT_ID  = '_laao_ads_placement_id';
	public const META_DURATION_DAYS = '_laao_ads_duration_days';
	public const META_PRICE_CENTS   = '_laao_ads_price_cents';
	public const META_CURRENCY      = '_laao_ads_currency';
	public const META_IS_ACTIVE     = '_laao_ads_is_active';

	/**
	 * A bounded configuration catalogue, not an unbounded content query.
	 */
	public const MAX_PACKAGES = 100;

	/**
	 * Whether an id names a package.
	 *
	 * @param int $package_id Package post id.
	 * @return bool
	 */
	public function exists( int $package_id ): bool {
		return Post_Types::PACKAGE === get_post_type( $package_id );
	}

	/**
	 * Whether the package is currently offered.
	 *
	 * @param int $package_id Package post id.
	 * @return bool
	 */
	public function is_active( int $package_id ): bool {
		return $this->exists( $package_id ) && 1 === (int) get_post_meta( $package_id, self::META_IS_ACTIVE, true );
	}

	/**
	 * Active packages in deterministic title/id order.
	 *
	 * @return array<int, int>
	 */
	public function active_ids(): array {
		$ids = get_posts(
			array(
				'post_type'              => Post_Types::PACKAGE,
				'post_status'            => 'any',
				'numberposts'            => self::MAX_PACKAGES,
				'fields'                 => 'ids',
				'orderby'                => array(
					'title' => 'ASC',
					'ID'    => 'ASC',
				),
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded shared configuration set, read once per catalogue request.
					array(
						'key'   => self::META_IS_ACTIVE,
						'value' => 1,
					),
				),
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Package display name.
	 *
	 * @param int $package_id Package post id.
	 * @return string
	 */
	public function name( int $package_id ): string {
		$title = get_the_title( $package_id );

		return is_string( $title ) ? $title : '';
	}

	/**
	 * Placements included by the package.
	 *
	 * @param int $package_id Package post id.
	 * @return array<int, int>
	 */
	public function placement_ids( int $package_id ): array {
		$ids = get_post_meta( $package_id, self::META_PLACEMENT_ID, false );

		return is_array( $ids )
			? array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) )
			: array();
	}

	/**
	 * Contract duration in days.
	 *
	 * @param int $package_id Package post id.
	 * @return int
	 */
	public function duration_days( int $package_id ): int {
		return (int) get_post_meta( $package_id, self::META_DURATION_DAYS, true );
	}

	/**
	 * Package price in integer cents.
	 *
	 * @param int $package_id Package post id.
	 * @return int
	 */
	public function price_cents( int $package_id ): int {
		return (int) get_post_meta( $package_id, self::META_PRICE_CENTS, true );
	}

	/**
	 * ISO 4217 currency code.
	 *
	 * @param int $package_id Package post id.
	 * @return string
	 */
	public function currency( int $package_id ): string {
		return strtoupper( (string) get_post_meta( $package_id, self::META_CURRENCY, true ) );
	}
}
