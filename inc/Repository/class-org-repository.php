<?php
/**
 * Organization and ownership lookups.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Repository;

use LAAO_Advertiser_Portal\Core\Post_Types;
use WP_Error;

/**
 * Resolves who belongs to which organization, and which organization owns an
 * object.
 *
 * Every method here is on the hottest authorization path in the system:
 * map_meta_cap fires dozens of times per page render, and each call needs the
 * caller's memberships. The memoization is therefore not a micro-optimization
 * — without it, drawing a list of five campaigns issues sixty queries.
 */
final class Org_Repository {

	/**
	 * The most organizations one user is ever resolved into.
	 *
	 * This runs inside map_meta_cap, dozens of times per render, so an
	 * unbounded query here is a genuine hazard rather than a theoretical one.
	 * A person belonging to more than this many advertisers is not a scenario
	 * the product has; a query returning that many rows is a symptom.
	 */
	public const MAX_MEMBERSHIPS = 100;

	public const STATE_ACTIVE    = 'active';
	public const STATE_SUSPENDED = 'suspended';

	public const META_ORG_STATE      = '_laao_ads_org_state';
	public const META_ORG_ID         = '_laao_ads_org_id';
	public const META_OWNER_USER     = '_laao_ads_owner_user_id';
	public const META_MEMBER_USER    = '_laao_ads_member_user_id';
	public const META_CANONICAL_NAME = '_laao_ads_canonical_name';

	/**
	 * Organization identity registry.
	 *
	 * @var Org_Access_Repository
	 */
	private Org_Access_Repository $access;

	/**
	 * Memoized memberships, keyed by user id.
	 *
	 * @var array<int, array<int, int>>
	 */
	private array $memberships = array();

	/**
	 * Memoized ownership contexts, keyed by post id.
	 *
	 * @var array<int, array{post_type: string, org_id: int, status: string}|null>
	 */
	private array $contexts = array();

	/**
	 * Constructor.
	 *
	 * @param Org_Access_Repository|null $access Canonical identity registry.
	 */
	public function __construct( ?Org_Access_Repository $access = null ) {
		$this->access = $access ?? new Org_Access_Repository();
	}

	/**
	 * Canonical identity used only for duplicate detection.
	 *
	 * Display names remain the uppercase, human-readable value. This key removes
	 * accents and punctuation and collapses whitespace so `A.C.M.E., LLC` and
	 * `ACME LLC` compare equally without deleting meaningful words such as LLC.
	 *
	 * @param string $name Organization name.
	 */
	public static function canonical_name( string $name ): string {
		$name = remove_accents( sanitize_text_field( $name ) );
		$name = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $name, 'UTF-8' ) : strtoupper( $name );
		$name = (string) preg_replace( '/[^\p{L}\p{N}\s]+/u', '', $name );
		$name = (string) preg_replace( '/\s+/u', ' ', trim( $name ) );

		return $name;
	}

	/**
	 * Uppercase human-readable organization name persisted as the post title.
	 *
	 * @param string $name Organization name.
	 */
	public static function display_name( string $name ): string {
		$name = sanitize_text_field( $name );

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $name, 'UTF-8' ) : strtoupper( $name );
	}

	/**
	 * Exact or unambiguous fuzzy match, without exposing the catalogue.
	 *
	 * @param string $name Submitted organization name.
	 */
	public function matching_org_id( string $name ): int {
		$canonical = self::canonical_name( $name );
		$exact     = $this->access->org_id_for_canonical( $canonical );

		return $exact > 0 ? $exact : $this->access->similar_org_id( $canonical );
	}

	/**
	 * Whether this subscriber may retry the same expired access request.
	 *
	 * @param int $user_id User id.
	 * @param int $org_id  Organization id.
	 */
	public function has_expired_access_request( int $user_id, int $org_id ): bool {
		return $this->access->has_expired_request( $user_id, $org_id );
	}

	/**
	 * Creates and verifies the organization owned by a new advertiser.
	 *
	 * The post and both ownership fields are treated as one repository write.
	 * A partial record is removed before an error is returned, so the workflow
	 * never has to understand which meta write failed.
	 *
	 * @param string $name          Organization display name.
	 * @param int    $owner_user_id Owner user id.
	 * @return int|WP_Error
	 */
	public function create_for_owner( string $name, int $owner_user_id ): int|WP_Error {
		$name      = self::display_name( $name );
		$canonical = self::canonical_name( $name );

		if ( '' === $canonical ) {
			return new WP_Error( 'laao_ads_invalid_org_identity', __( 'The organization name is not valid.', 'laao-advertiser-portal' ) );
		}

		$org_id = wp_insert_post(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => $name,
				'post_author' => $owner_user_id,
			),
			true
		);

		if ( is_wp_error( $org_id ) ) {
			return $org_id;
		}

		$owner_written = add_post_meta( $org_id, self::META_OWNER_USER, $owner_user_id, true );
		$state_written = add_post_meta( $org_id, self::META_ORG_STATE, self::STATE_ACTIVE, true );
		$name_written  = add_post_meta( $org_id, self::META_CANONICAL_NAME, $canonical, true );
		$verified      = Post_Types::ORGANIZATION === get_post_type( $org_id )
			&& (int) get_post_meta( $org_id, self::META_OWNER_USER, true ) === $owner_user_id
			&& self::STATE_ACTIVE === (string) get_post_meta( $org_id, self::META_ORG_STATE, true )
			&& (string) get_post_meta( $org_id, self::META_CANONICAL_NAME, true ) === $canonical;

		if ( false === $owner_written || false === $state_written || false === $name_written || ! $verified ) {
			wp_delete_post( $org_id, true );

			return new WP_Error( 'laao_ads_org_write_failed', __( 'The organization could not be created.', 'laao-advertiser-portal' ) );
		}

		$identity = $this->access->register_identity( $org_id, $canonical );
		if ( is_wp_error( $identity ) ) {
			wp_delete_post( $org_id, true );

			return $identity;
		}

		$this->flush_cache();

		return $org_id;
	}

	/**
	 * Removes an organization created by a registration that could not finish.
	 *
	 * @param int $org_id Organization post id.
	 * @return bool
	 */
	public function delete_registration_org( int $org_id ): bool {
		$this->access->remove_identity( $org_id );

		$deleted = null !== wp_delete_post( $org_id, true );

		$this->flush_cache();

		return $deleted;
	}

	/**
	 * Register identities for organizations created before schema version 2.
	 *
	 * @return void
	 */
	public function backfill_identities(): void {
		$page = 1;

		do {
			$ids = get_posts(
				array(
					'post_type'              => Post_Types::ORGANIZATION,
					'post_status'            => 'any',
					'posts_per_page'         => 100,
					'paged'                  => $page,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'update_post_meta_cache' => false,
				)
			);

			foreach ( $ids as $org_id ) {
				$title        = get_the_title( (int) $org_id );
				$title        = is_string( $title ) ? $title : '';
				$display_name = self::display_name( $title );
				$canonical    = self::canonical_name( $display_name );

				if ( '' === $canonical ) {
					continue;
				}

				if ( $display_name !== $title ) {
					wp_update_post(
						array(
							'ID'         => (int) $org_id,
							'post_title' => $display_name,
						)
					);
				}

				update_post_meta( (int) $org_id, self::META_CANONICAL_NAME, $canonical );
				$this->access->register_identity( (int) $org_id, $canonical );
			}

			$count = count( $ids );
			++$page;
		} while ( 100 === $count );
	}

	/**
	 * Whether a user owns an organization.
	 *
	 * @param int $org_id  Organization id.
	 * @param int $user_id User id.
	 */
	public function is_owner( int $org_id, int $user_id ): bool {
		return $org_id > 0 && $user_id > 0
			&& (int) get_post_meta( $org_id, self::META_OWNER_USER, true ) === $user_id;
	}

	/**
	 * Add one member exactly once.
	 *
	 * @param int $org_id  Organization id.
	 * @param int $user_id User id.
	 */
	public function add_member( int $org_id, int $user_id ): bool {
		if ( ! $this->is_active( $org_id ) || $user_id <= 0 ) {
			return false;
		}

		if ( in_array( $user_id, $this->user_ids_for_org( $org_id ), true ) ) {
			return true;
		}

		$added = add_post_meta( $org_id, self::META_MEMBER_USER, $user_id, false );
		$this->flush_cache();

		return false !== $added && in_array( $user_id, $this->user_ids_for_org( $org_id ), true );
	}

	/**
	 * Remove one member during compensation or denial.
	 *
	 * @param int $org_id  Organization id.
	 * @param int $user_id User id.
	 */
	public function remove_member( int $org_id, int $user_id ): bool {
		$deleted = delete_post_meta( $org_id, self::META_MEMBER_USER, $user_id );
		$this->flush_cache();

		return $deleted;
	}

	/**
	 * The organizations a user belongs to, as owner or member.
	 *
	 * @param int $user_id User id.
	 * @return array<int, int> Organization post ids.
	 */
	public function org_ids_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		if ( isset( $this->memberships[ $user_id ] ) ) {
			return $this->memberships[ $user_id ];
		}

		$ids = get_posts(
			array(
				'post_type'              => Post_Types::ORGANIZATION,
				'post_status'            => 'any',
				'numberposts'            => self::MAX_MEMBERSHIPS,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed lookup on repeated meta rows; this is why memberships are not a serialized array.
					'relation' => 'OR',
					array(
						'key'   => self::META_OWNER_USER,
						'value' => $user_id,
					),
					array(
						'key'   => self::META_MEMBER_USER,
						'value' => $user_id,
					),
				),
			)
		);

		$this->memberships[ $user_id ] = array_map( 'intval', $ids );

		return $this->memberships[ $user_id ];
	}

	/**
	 * Everyone in an organization, owner first.
	 *
	 * The inverse of org_ids_for_user(), and read from the same two meta keys
	 * so the two cannot disagree about who is a member. Owner first because a
	 * notice about somebody's campaign should reach the person answerable for
	 * it even if the list is later truncated.
	 *
	 * @param int $org_id Organization post id.
	 * @return array<int, int> User ids, without duplicates.
	 */
	public function user_ids_for_org( int $org_id ): array {
		if ( $org_id <= 0 || Post_Types::ORGANIZATION !== get_post_type( $org_id ) ) {
			return array();
		}

		$ids = array();

		foreach ( array( self::META_OWNER_USER, self::META_MEMBER_USER ) as $key ) {
			foreach ( get_post_meta( $org_id, $key, false ) as $value ) {
				$user_id = (int) $value;

				if ( $user_id > 0 && ! in_array( $user_id, $ids, true ) ) {
					$ids[] = $user_id;
				}
			}
		}

		return $ids;
	}

	/**
	 * What a post is, and which organization owns it.
	 *
	 * Returns null when the post does not exist. That distinction matters:
	 * a caller that cannot tell "missing" from "org 0" ends up treating a
	 * deleted campaign as unowned, and unowned is not the same as denied.
	 *
	 * @param int $post_id Post id.
	 * @return array{post_type: string, org_id: int, status: string}|null
	 */
	public function ownership_context( int $post_id ): ?array {
		if ( $post_id <= 0 ) {
			return null;
		}

		if ( array_key_exists( $post_id, $this->contexts ) ) {
			return $this->contexts[ $post_id ];
		}

		$post = get_post( $post_id );

		if ( null === $post ) {
			$this->contexts[ $post_id ] = null;

			return null;
		}

		// An organization owns itself. Every other entity carries the org it
		// belongs to, denormalized on write so this lookup is one meta read.
		$org_id = Post_Types::ORGANIZATION === $post->post_type
			? $post->ID
			: (int) get_post_meta( $post_id, self::META_ORG_ID, true );

		$this->contexts[ $post_id ] = array(
			'post_type' => (string) $post->post_type,
			'org_id'    => $org_id,
			'status'    => (string) $post->post_status,
		);

		return $this->contexts[ $post_id ];
	}

	/**
	 * Whether an organization may transact.
	 *
	 * Defaults to active when unset, so an organization created before this
	 * field existed is not silently frozen out. Suspension has to be
	 * deliberate — it is a decision someone makes, not a default someone
	 * forgets to undo.
	 *
	 * @param int $org_id Organization post id.
	 * @return bool
	 */
	public function is_active( int $org_id ): bool {
		if ( $org_id <= 0 || Post_Types::ORGANIZATION !== get_post_type( $org_id ) ) {
			return false;
		}

		$state = (string) get_post_meta( $org_id, self::META_ORG_STATE, true );

		return '' === $state || self::STATE_ACTIVE === $state;
	}

	/**
	 * The organization's display name.
	 *
	 * @param int $org_id Organization post id.
	 * @return string Empty when the id is not an organization.
	 */
	public function name( int $org_id ): string {
		if ( $org_id <= 0 || Post_Types::ORGANIZATION !== get_post_type( $org_id ) ) {
			return '';
		}

		$title = get_the_title( $org_id );

		return is_string( $title ) ? $title : '';
	}

	/**
	 * Drops memoized state.
	 *
	 * Called whenever membership or ownership data changes within a request.
	 * Without it, a capability check that ran before an organization gained a
	 * member keeps answering with the old answer for the rest of the request —
	 * which in a test suite means the second test inherits the first one's
	 * memberships and passes for the wrong reason.
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		$this->memberships = array();
		$this->contexts    = array();
	}
}
