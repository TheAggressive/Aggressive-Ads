<?php
/**
 * Package persistence.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Core\Post_Types;
use WP_Error;

/**
 * Reads the shared package catalogue a campaign may select from.
 */
final class Package_Repository {

	public const META_PLACEMENT_ID    = '_aggr_placement_id';
	public const META_DURATION_DAYS   = '_aggr_duration_days';
	public const META_PRICE_CENTS     = '_aggr_price_cents';
	public const META_CURRENCY        = '_aggr_currency';
	public const META_IS_ACTIVE       = '_aggr_is_active';
	public const META_CUSTOM_DURATION = '_aggr_custom_duration';
	public const META_IS_DEFAULT      = '_aggr_is_default';

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
		$title = get_post_field( 'post_title', $package_id, 'raw' );

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
	 * Whether advertisers choose this package's schedule rather than receiving
	 * a fixed contract duration.
	 *
	 * @param int $package_id Package post id.
	 * @return bool
	 */
	public function has_custom_duration( int $package_id ): bool {
		return $this->exists( $package_id ) && 1 === (int) get_post_meta( $package_id, self::META_CUSTOM_DURATION, true );
	}

	/**
	 * The first active package explicitly designated as the catalogue default.
	 *
	 * A deterministic first match contains accidental duplicate flags without
	 * making the entire catalogue disappear. save() clears every other flag
	 * when assigning a new default.
	 *
	 * @return int
	 */
	public function default_id(): int {
		foreach ( $this->active_ids() as $package_id ) {
			if ( 1 === (int) get_post_meta( $package_id, self::META_IS_DEFAULT, true ) ) {
				return $package_id;
			}
		}

		return 0;
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

	/**
	 * Every package, including inactive catalogue rows.
	 *
	 * @return array<int, int>
	 */
	public function all_ids(): array {
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
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Creates a catalogue row. Meta is applied by save().
	 *
	 * @param string $name Display title.
	 * @return int|WP_Error
	 */
	public function create( string $name ) {
		if ( count( $this->all_ids() ) >= self::MAX_PACKAGES ) {
			return new WP_Error( 'aggr_package_limit', 'The package catalogue is full.' );
		}

		$package_id = wp_insert_post(
			array(
				'post_type'   => Post_Types::PACKAGE,
				'post_status' => 'publish',
				'post_title'  => $name,
			),
			true
		);

		return is_wp_error( $package_id ) ? $package_id : (int) $package_id;
	}

	/**
	 * Hard-deletes a package. Compensating rollback for a failed create only.
	 *
	 * @param int $package_id Package post id.
	 */
	public function delete( int $package_id ): bool {
		if ( ! $this->exists( $package_id ) ) {
			return false;
		}

		return null !== wp_delete_post( $package_id, true );
	}

	/**
	 * Writes one package and read-backs every field.
	 *
	 * @param int                  $package_id Package post id.
	 * @param array<string, mixed> $fields     Validated catalogue fields.
	 *
	 * @phpstan-param array{name: string, placement_ids: array<int, int>, duration_days: int, custom_duration: bool, price_cents: int, currency: string, is_active: bool, is_default: bool} $fields
	 *
	 * @return bool
	 */
	public function save( int $package_id, array $fields ): bool {
		if ( ! $this->exists( $package_id ) ) {
			return false;
		}

		$updated = wp_update_post(
			array(
				'ID'         => $package_id,
				'post_title' => $fields['name'],
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return false;
		}

		delete_post_meta( $package_id, self::META_PLACEMENT_ID );

		foreach ( $fields['placement_ids'] as $placement_id ) {
			add_post_meta( $package_id, self::META_PLACEMENT_ID, $placement_id, false );
		}

		update_post_meta( $package_id, self::META_DURATION_DAYS, $fields['custom_duration'] ? 0 : $fields['duration_days'] );
		update_post_meta( $package_id, self::META_CUSTOM_DURATION, $fields['custom_duration'] ? 1 : 0 );
		update_post_meta( $package_id, self::META_PRICE_CENTS, $fields['price_cents'] );
		update_post_meta( $package_id, self::META_CURRENCY, $fields['currency'] );
		update_post_meta( $package_id, self::META_IS_ACTIVE, $fields['is_active'] ? 1 : 0 );

		if ( $fields['is_default'] ) {
			$this->clear_default_flags( $package_id );
			update_post_meta( $package_id, self::META_IS_DEFAULT, 1 );
		} else {
			update_post_meta( $package_id, self::META_IS_DEFAULT, 0 );
		}

		clean_post_cache( $package_id );

		$expected_duration = $fields['custom_duration'] ? 0 : $fields['duration_days'];

		return $this->name( $package_id ) === $fields['name']
			&& $this->placement_ids( $package_id ) === $fields['placement_ids']
			&& $this->duration_days( $package_id ) === $expected_duration
			&& $this->has_custom_duration( $package_id ) === $fields['custom_duration']
			&& $this->price_cents( $package_id ) === $fields['price_cents']
			&& $this->currency( $package_id ) === $fields['currency']
			&& $this->is_active( $package_id ) === $fields['is_active']
			&& ( $fields['is_default'] ? $package_id === $this->default_id() : $package_id !== $this->default_id() );
	}

	/**
	 * One catalogue default. Clears every other flag so duplicates cannot accumulate.
	 *
	 * @param int $keep_id Package that will become the default.
	 */
	private function clear_default_flags( int $keep_id ): void {
		foreach ( $this->all_ids() as $package_id ) {
			if ( $keep_id !== $package_id && 1 === (int) get_post_meta( $package_id, self::META_IS_DEFAULT, true ) ) {
				update_post_meta( $package_id, self::META_IS_DEFAULT, 0 );
			}
		}
	}
}
