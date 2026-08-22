<?php
/**
 * Organization and ownership lookups.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Core\Post_Types;
use WP_Error;
use WP_Post;
use WP_Query;

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

	/** Upper bound shared by signup and rename display names. */
	public const MAX_NAME_LENGTH = 150;

	/** Bounded staff organization catalogue size. */
	private const MAX_ADMIN_ORGS = 500;

	/**
	 * Ceiling on a single page, so a crafted per_page cannot ask for the table.
	 */
	public const MAX_PER_PAGE = 100;

	public const STATE_ACTIVE    = 'active';
	public const STATE_SUSPENDED = 'suspended';

	public const META_ORG_STATE      = '_aggr_org_state';
	public const META_ORG_ID         = '_aggr_org_id';
	public const META_OWNER_USER     = '_aggr_owner_user_id';
	public const META_MEMBER_USER    = '_aggr_member_user_id';
	public const META_CANONICAL_NAME = '_aggr_canonical_name';

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
			return new WP_Error( 'aggr_invalid_org_identity', __( 'The organization name is not valid.', 'aggressive-ads' ) );
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

			return new WP_Error( 'aggr_org_write_failed', __( 'The organization could not be created.', 'aggressive-ads' ) );
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
	 * Rename an organization display title and its reserved canonical identity.
	 *
	 * Collision handling is the unique `active_key` insert: another tenant's
	 * exact canonical name cannot be taken. Display-only punctuation changes
	 * that leave the canonical key unchanged skip the identity swap.
	 *
	 * @param int    $org_id Organization id.
	 * @param string $name   Requested display name.
	 * @return true|WP_Error
	 */
	public function rename( int $org_id, string $name ): bool|WP_Error {
		if ( $org_id <= 0 || Post_Types::ORGANIZATION !== get_post_type( $org_id ) ) {
			return new WP_Error( 'aggr_org_missing', __( 'The organization could not be found.', 'aggressive-ads' ) );
		}

		$display   = self::display_name( $name );
		$canonical = self::canonical_name( $display );

		if ( '' === $canonical || strlen( $display ) > self::MAX_NAME_LENGTH ) {
			return new WP_Error( 'aggr_invalid_org_identity', __( 'Enter a valid organization name.', 'aggressive-ads' ) );
		}

		$old_display   = $this->name( $org_id );
		$old_canonical = (string) get_post_meta( $org_id, self::META_CANONICAL_NAME, true );

		if ( '' === $old_canonical ) {
			$old_canonical = self::canonical_name( $old_display );
		}

		if ( $display === $old_display && $canonical === $old_canonical ) {
			return true;
		}

		$identity_moved = false;
		if ( $canonical !== $old_canonical ) {
			$identity = $this->access->rename_identity( $org_id, $old_canonical, $canonical );
			if ( is_wp_error( $identity ) ) {
				if ( 'aggr_duplicate_org_identity' === $identity->get_error_code() ) {
					return new WP_Error(
						'aggr_duplicate_org_identity',
						__( 'That organization name is already in use.', 'aggressive-ads' )
					);
				}

				return $identity;
			}
			$identity_moved = true;
		}

		$updated = wp_update_post(
			array(
				'ID'         => $org_id,
				'post_title' => $display,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			if ( $identity_moved ) {
				$this->access->rename_identity( $org_id, $canonical, $old_canonical );
			}

			return new WP_Error( 'aggr_org_write_failed', __( 'The organization could not be renamed.', 'aggressive-ads' ) );
		}

		update_post_meta( $org_id, self::META_CANONICAL_NAME, $canonical );
		$this->flush_cache();

		$verified = $this->name( $org_id ) === $display
			&& (string) get_post_meta( $org_id, self::META_CANONICAL_NAME, true ) === $canonical
			&& ( ! $identity_moved || $org_id === $this->access->org_id_for_canonical( $canonical ) );

		if ( ! $verified ) {
			wp_update_post(
				array(
					'ID'         => $org_id,
					'post_title' => $old_display,
				),
				true
			);
			update_post_meta( $org_id, self::META_CANONICAL_NAME, $old_canonical );
			if ( $identity_moved ) {
				$this->access->rename_identity( $org_id, $canonical, $old_canonical );
			}
			$this->flush_cache();

			return new WP_Error( 'aggr_org_write_failed', __( 'The organization could not be renamed.', 'aggressive-ads' ) );
		}

		return true;
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
	 * Move ownership from the current owner to an existing member.
	 *
	 * The former owner becomes a repeated member. The new owner is removed from
	 * the member list so the two roles never share one user id. Suspended orgs
	 * remain transferable so staff can recover an account without reactivating
	 * it first.
	 *
	 * @param int $org_id       Organization id.
	 * @param int $new_owner_id Existing member who becomes owner.
	 */
	public function transfer_ownership( int $org_id, int $new_owner_id ): bool {
		if ( $org_id <= 0 || $new_owner_id <= 0 || Post_Types::ORGANIZATION !== get_post_type( $org_id ) ) {
			return false;
		}

		$current_owner = (int) get_post_meta( $org_id, self::META_OWNER_USER, true );
		if ( $current_owner <= 0 || $current_owner === $new_owner_id || $this->is_owner( $org_id, $new_owner_id ) ) {
			return false;
		}

		$members = array_map( 'intval', get_post_meta( $org_id, self::META_MEMBER_USER, false ) );
		if ( ! in_array( $new_owner_id, $members, true ) ) {
			return false;
		}

		$owner_updated = update_post_meta( $org_id, self::META_OWNER_USER, $new_owner_id );
		if ( false === $owner_updated && (int) get_post_meta( $org_id, self::META_OWNER_USER, true ) !== $new_owner_id ) {
			return false;
		}

		delete_post_meta( $org_id, self::META_MEMBER_USER, $new_owner_id );

		if ( ! in_array( $current_owner, array_map( 'intval', get_post_meta( $org_id, self::META_MEMBER_USER, false ) ), true ) ) {
			add_post_meta( $org_id, self::META_MEMBER_USER, $current_owner, false );
		}

		$this->flush_cache();

		$verified = $this->is_owner( $org_id, $new_owner_id )
			&& ! $this->is_owner( $org_id, $current_owner )
			&& in_array( $current_owner, $this->user_ids_for_org( $org_id ), true )
			&& in_array( $new_owner_id, $this->user_ids_for_org( $org_id ), true )
			&& ! in_array( $new_owner_id, array_map( 'intval', get_post_meta( $org_id, self::META_MEMBER_USER, false ) ), true );

		if ( $verified ) {
			return true;
		}

		update_post_meta( $org_id, self::META_OWNER_USER, $current_owner );
		delete_post_meta( $org_id, self::META_MEMBER_USER, $current_owner );
		if ( ! in_array( $new_owner_id, array_map( 'intval', get_post_meta( $org_id, self::META_MEMBER_USER, false ) ), true ) ) {
			add_post_meta( $org_id, self::META_MEMBER_USER, $new_owner_id, false );
		}
		$this->flush_cache();

		return false;
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
	 * Remove one non-owner member.
	 *
	 * The owner lives in a separate meta key and is never cleared here. Product
	 * removal and compensation both call this path, so refusing the owner at
	 * the repository keeps a mistaken workflow from stranding the tenant.
	 *
	 * @param int $org_id  Organization id.
	 * @param int $user_id User id.
	 */
	public function remove_member( int $org_id, int $user_id ): bool {
		if ( $org_id <= 0 || $user_id <= 0 || $this->is_owner( $org_id, $user_id ) ) {
			return false;
		}

		$deleted = delete_post_meta( $org_id, self::META_MEMBER_USER, $user_id );
		$this->flush_cache();

		return $deleted && ! in_array( $user_id, $this->user_ids_for_org( $org_id ), true );
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
		return self::STATE_ACTIVE === $this->state( $org_id );
	}

	/**
	 * Normalized organization lifecycle state.
	 *
	 * Empty meta is treated as active so legacy rows stay usable until staff
	 * deliberately suspend them.
	 *
	 * @param int $org_id Organization post id.
	 * @return string Empty when the id is not an organization; otherwise active or suspended.
	 */
	public function state( int $org_id ): string {
		if ( $org_id <= 0 || Post_Types::ORGANIZATION !== get_post_type( $org_id ) ) {
			return '';
		}

		$state = (string) get_post_meta( $org_id, self::META_ORG_STATE, true );

		if ( self::STATE_SUSPENDED === $state ) {
			return self::STATE_SUSPENDED;
		}

		return self::STATE_ACTIVE;
	}

	/**
	 * Persist an explicit active or suspended state with read-back.
	 *
	 * @param int    $org_id Organization post id.
	 * @param string $state  self::STATE_ACTIVE or self::STATE_SUSPENDED.
	 */
	public function set_state( int $org_id, string $state ): bool {
		if ( $org_id <= 0 || Post_Types::ORGANIZATION !== get_post_type( $org_id ) ) {
			return false;
		}

		if ( self::STATE_ACTIVE !== $state && self::STATE_SUSPENDED !== $state ) {
			return false;
		}

		if ( $this->state( $org_id ) === $state ) {
			return true;
		}

		update_post_meta( $org_id, self::META_ORG_STATE, $state );
		$this->flush_cache();

		return $this->state( $org_id ) === $state;
	}

	/**
	 * Whether the id is an organization post.
	 *
	 * @param int $org_id Organization post id.
	 */
	public function exists( int $org_id ): bool {
		return $org_id > 0 && Post_Types::ORGANIZATION === get_post_type( $org_id );
	}

	/**
	 * One page of organization ids, with the total the filter actually matched.
	 *
	 * `all_ids()` returns a bounded catalogue, and the bound is invisible to
	 * whoever reads it: past MAX_ADMIN_ORGS the extra organizations simply are
	 * not there, with nothing in the payload saying so. That was survivable
	 * while the screen rendered an obvious dump, and stopped being survivable
	 * when it became a table with a search box — searching for the 501st
	 * organization returns nothing, which reads as "no such organization"
	 * rather than "not loaded". So paging happens in the query, and the caller
	 * is told the real total.
	 *
	 * @param int    $page     One-based page number.
	 * @param int    $per_page Rows per page.
	 * @param string $search   Free-text match against the organization name.
	 * @param string $state    self::STATE_ACTIVE, self::STATE_SUSPENDED, or ''.
	 * @return array{ids: array<int, int>, total: int}
	 */
	public function page( int $page, int $per_page, string $search = '', string $state = '' ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );

		$args = array(
			'post_type'              => Post_Types::ORGANIZATION,
			'post_status'            => 'any',
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'fields'                 => 'ids',
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( self::STATE_SUSPENDED === $state ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One indexed meta key, on a paged staff query.
				array(
					'key'   => self::META_ORG_STATE,
					'value' => self::STATE_SUSPENDED,
				),
			);
		} elseif ( self::STATE_ACTIVE === $state ) {
			/*
			 * Active is everything that is not suspended, including rows whose
			 * state meta was never written. `state()` already reads it that
			 * way, and a filter that tested `= 'active'` instead would agree
			 * with it on every organization except the ones created before the
			 * meta existed — which would then vanish from the active list while
			 * still reporting themselves active everywhere else.
			 */
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One indexed meta key, on a paged staff query.
				'relation' => 'OR',
				array(
					'key'     => self::META_ORG_STATE,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => self::META_ORG_STATE,
					'value'   => self::STATE_SUSPENDED,
					'compare' => '!=',
				),
			);
		}

		$query = new WP_Query( $args );
		$ids   = array();

		// `fields => 'ids'` yields integers, but WP_Query's own type is the
		// union, so the cast covers both rather than trusting the argument.
		foreach ( $query->posts as $post ) {
			$ids[] = $post instanceof WP_Post ? (int) $post->ID : (int) $post;
		}

		return array(
			'ids'   => $ids,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Bounded catalogue of organization ids for staff administration.
	 *
	 * @return array<int, int>
	 */
	public function all_ids(): array {
		$ids = get_posts(
			array(
				'post_type'              => Post_Types::ORGANIZATION,
				'post_status'            => 'any',
				'numberposts'            => self::MAX_ADMIN_ORGS,
				'fields'                 => 'ids',
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			)
		);

		return array_values( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
	}

	/**
	 * Owner user id for one organization.
	 *
	 * @param int $org_id Organization post id.
	 */
	public function owner_user_id( int $org_id ): int {
		if ( ! $this->exists( $org_id ) ) {
			return 0;
		}

		return (int) get_post_meta( $org_id, self::META_OWNER_USER, true );
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
