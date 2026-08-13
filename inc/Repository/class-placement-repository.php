<?php
/**
 * Placement persistence.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Core\Post_Types;
use WP_Error;

/**
 * Reads placements — the shared configuration a campaign is built from.
 *
 * Size and slug live here. Orphan `_aggr_adgroup_term_id` is not read.
 */
final class Placement_Repository {

	public const META_SIZE             = '_aggr_size';
	public const META_ADGROUP_TERM     = '_aggr_adgroup_term_id';
	public const META_IS_ACTIVE        = '_aggr_is_active';
	public const META_SORT_ORDER       = '_aggr_sort_order';
	public const META_POSITION_LABEL   = '_aggr_position_label';
	public const META_HOUSE_ATTACHMENT = '_aggr_house_attachment_id';
	public const META_HOUSE_CLICK_URL  = '_aggr_house_click_url';
	public const META_HOUSE_ALT        = '_aggr_house_alt';

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
	 * A placement's display name.
	 *
	 * @param int $placement_id Placement post id.
	 * @return string
	 */
	public function name( int $placement_id ): string {
		$title = get_post_field( 'post_title', $placement_id, 'raw' );

		return is_string( $title ) ? $title : '';
	}

	/**
	 * Public slot id. Editors place this, never a campaign.
	 *
	 * @param int $placement_id Placement post id.
	 */
	public function slug( int $placement_id ): string {
		$name = get_post_field( 'post_name', $placement_id, 'raw' );

		return is_string( $name ) ? $name : '';
	}

	/**
	 * Placement id for a public slot slug, or 0.
	 *
	 * @param string $slug post_name.
	 */
	public function id_by_slug( string $slug ): int {
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'              => Post_Types::PLACEMENT,
				'post_status'            => 'any',
				'name'                   => $slug,
				'numberposts'            => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		return array() === $ids ? 0 : (int) $ids[0];
	}

	/**
	 * House creative attachment, or 0.
	 *
	 * @param int $placement_id Placement post id.
	 */
	public function house_attachment_id( int $placement_id ): int {
		return (int) get_post_meta( $placement_id, self::META_HOUSE_ATTACHMENT, true );
	}

	/**
	 * House click-through URL.
	 *
	 * @param int $placement_id Placement post id.
	 */
	public function house_click_url( int $placement_id ): string {
		return (string) get_post_meta( $placement_id, self::META_HOUSE_CLICK_URL, true );
	}

	/**
	 * House image alt text.
	 *
	 * @param int $placement_id Placement post id.
	 */
	public function house_alt( int $placement_id ): string {
		return (string) get_post_meta( $placement_id, self::META_HOUSE_ALT, true );
	}

	/**
	 * MIME and extension for a media library attachment.
	 *
	 * @param int $attachment_id Attachment post id.
	 * @return array{mime: string, extension: string}|null
	 */
	public function attachment_type( int $attachment_id ): ?array {
		if ( $attachment_id <= 0 ) {
			return null;
		}

		$post = get_post( $attachment_id );

		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return null;
		}

		$file = get_attached_file( $attachment_id );
		$ext  = is_string( $file ) && '' !== $file
			? strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) )
			: '';

		return array(
			'mime'      => (string) $post->post_mime_type,
			'extension' => $ext,
		);
	}

	/**
	 * Absolute path for a media library attachment, or empty.
	 *
	 * @param int $attachment_id Attachment post id.
	 */
	public function attachment_file( int $attachment_id ): string {
		$type = $this->attachment_type( $attachment_id );

		if ( null === $type ) {
			return '';
		}

		$file = get_attached_file( $attachment_id );

		return is_string( $file ) ? $file : '';
	}

	/**
	 * Stores house creative for native fill. Zero attachment clears it.
	 *
	 * @param int    $placement_id  Placement post id.
	 * @param int    $attachment_id Media attachment id, or zero.
	 * @param string $click_url     Destination URL.
	 * @param string $alt           Image alt text.
	 */
	public function set_house( int $placement_id, int $attachment_id, string $click_url, string $alt ): bool {
		if ( ! $this->exists( $placement_id ) || $attachment_id < 0 ) {
			return false;
		}

		update_post_meta( $placement_id, self::META_HOUSE_ATTACHMENT, $attachment_id );
		update_post_meta( $placement_id, self::META_HOUSE_CLICK_URL, $click_url );
		update_post_meta( $placement_id, self::META_HOUSE_ALT, $alt );

		return $this->house_attachment_id( $placement_id ) === $attachment_id
			&& $this->house_click_url( $placement_id ) === $click_url
			&& $this->house_alt( $placement_id ) === $alt;
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
	 * Creates a catalogue row. Meta is applied by save().
	 *
	 * @param string $name Display title.
	 * @param string $slug Public slot id.
	 * @return int|WP_Error
	 */
	public function create( string $name, string $slug ) {
		if ( count( $this->all_ids() ) >= self::MAX_PLACEMENTS ) {
			return new WP_Error( 'aggr_placement_limit', 'The placement catalogue is full.' );
		}

		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			return new WP_Error( 'aggr_invalid_placement_slug', 'Enter a slot slug.' );
		}

		if ( $this->id_by_slug( $slug ) > 0 ) {
			return new WP_Error( 'aggr_placement_slug_taken', 'That slot slug is already in use.' );
		}

		$placement_id = wp_insert_post(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => $name,
				'post_name'   => $slug,
			),
			true
		);

		return is_wp_error( $placement_id ) ? $placement_id : (int) $placement_id;
	}

	/**
	 * Hard-deletes a placement. Compensating rollback for a failed create only.
	 *
	 * @param int $placement_id Placement post id.
	 */
	public function delete( int $placement_id ): bool {
		if ( ! $this->exists( $placement_id ) ) {
			return false;
		}

		return null !== wp_delete_post( $placement_id, true );
	}

	/**
	 * Writes one placement and read-backs catalogue fields.
	 *
	 * @param int                  $placement_id Placement post id.
	 * @param array<string, mixed> $fields       Validated catalogue fields.
	 *
	 * @phpstan-param array{name: string, slug: string, size: string, is_active: bool, sort_order: int} $fields
	 */
	public function save( int $placement_id, array $fields ): bool {
		if ( ! $this->exists( $placement_id ) ) {
			return false;
		}

		$taken = $this->id_by_slug( $fields['slug'] );

		if ( $taken > 0 && $taken !== $placement_id ) {
			return false;
		}

		$updated = wp_update_post(
			array(
				'ID'         => $placement_id,
				'post_title' => $fields['name'],
				'post_name'  => $fields['slug'],
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return false;
		}

		update_post_meta( $placement_id, self::META_SIZE, $fields['size'] );
		update_post_meta( $placement_id, self::META_IS_ACTIVE, $fields['is_active'] ? 1 : 0 );
		update_post_meta( $placement_id, self::META_SORT_ORDER, $fields['sort_order'] );

		return $this->name( $placement_id ) === $fields['name']
			&& $this->slug( $placement_id ) === $fields['slug']
			&& $this->size( $placement_id ) === $fields['size']
			&& $this->is_active( $placement_id ) === $fields['is_active']
			&& $this->sort_order( $placement_id ) === $fields['sort_order'];
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
