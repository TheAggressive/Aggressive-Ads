<?php
/**
 * Organization and ownership lookups.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Repository;

use LAAO_Advertiser_Portal\Core\Post_Types;

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

	public const META_ORG_STATE   = '_laao_ads_org_state';
	public const META_ORG_ID      = '_laao_ads_org_id';
	public const META_OWNER_USER  = '_laao_ads_owner_user_id';
	public const META_MEMBER_USER = '_laao_ads_member_user_id';

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
