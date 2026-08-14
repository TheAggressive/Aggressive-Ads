<?php
/**
 * Organization-scoped object authorization.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Security;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Repository\Org_Repository;

/**
 * Answers "may this user touch this object?" for all five post types.
 *
 * Ownership here is the **organization**, not the author. Core's meta-cap
 * mapping compares $post->post_author against the user id, which is the wrong
 * question: an organization has several member users, and a campaign created
 * by member A must be editable by member B. Left to core, the portal would
 * silently become single-user-per-organization — and would appear to work,
 * right up until the second person at an agency tried to fix a typo.
 *
 * Controllers never compare ids. They ask
 * current_user_can( 'edit_aggr_campaign', $id ) and this filter answers,
 * so there is exactly one implementation of ownership behind every surface.
 *
 * See docs/adr/0009-org-scoped-map-meta-cap.md.
 */
final class Ownership implements Service {

	/**
	 * The generic meta capabilities core recurses into, and the action each means.
	 *
	 * @var array<string, string>
	 */
	private const GENERIC_META_CAPS = array(
		'edit_post'   => 'edit',
		'read_post'   => 'read',
		'delete_post' => 'delete',
	);

	/**
	 * Post types that belong to the site rather than to an organization.
	 *
	 * @var array<int, string>
	 */
	private const GLOBAL_POST_TYPES = array(
		Post_Types::PLACEMENT,
		Post_Types::PACKAGE,
	);

	/**
	 * Constructor.
	 *
	 * @param Org_Repository $orgs Organization lookups.
	 */
	public function __construct( private readonly Org_Repository $orgs ) {
	}

	/**
	 * Attaches the filter, and the cache invalidation it depends on.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'map_meta_cap', array( $this, 'map' ), 10, 4 );

		// Membership is memoized for the request. Anything that could change it
		// has to drop the memo, or a check made earlier in the request keeps
		// answering with a stale membership set.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'flush_on_meta_change' ), 10, 3 );
		}

		add_action( 'save_post_' . Post_Types::ORGANIZATION, array( $this, 'flush_cache' ) );
		add_action( 'deleted_post', array( $this, 'flush_cache' ) );
	}

	/**
	 * Maps a meta capability onto the primitive that actually applies.
	 *
	 * @param array<int, string> $caps    Primitive capabilities required.
	 * @param string             $cap     The capability being checked.
	 * @param int                $user_id The user being checked.
	 * @param array<int, mixed>  $args    Context; $args[0] is the object id.
	 * @return array<int, string>
	 */
	public function map( array $caps, string $cap, int $user_id, array $args ): array {
		$owned     = self::meta_capability_map();
		$post_type = null;

		if ( isset( $owned[ $cap ] ) ) {
			list( $post_type, $action ) = $owned[ $cap ];
		} elseif ( isset( self::GENERIC_META_CAPS[ $cap ] ) ) {
			/*
			 * The case that actually fires.
			 *
			 * core's map_meta_cap() does not pass our capability name to this
			 * filter. For a custom meta cap it looks the name up in the global
			 * $post_type_meta_caps, then *returns* a recursive call using the
			 * generic 'edit_post' / 'read_post' / 'delete_post' — so the outer
			 * apply_filters() never runs, and all this filter ever sees is the
			 * generic name.
			 *
			 * Handling only our own names looks correct and silently does
			 * nothing: every object check falls through to core's
			 * post_author comparison, which is precisely the single-user
			 * behaviour this class exists to prevent. It also *grants* reads,
			 * because core maps read_post on a published post to 'read'.
			 */
			$action = self::GENERIC_META_CAPS[ $cap ];
		} else {
			// This filter runs on every capability check in WordPress,
			// including core's own. It is inert for anything it does not own.
			return $caps;
		}

		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		$context = $this->orgs->ownership_context( $post_id );

		if ( null === $context ) {
			// Reached through a generic capability, a missing post is somebody
			// else's business — core's own, usually. Reached through one of our
			// names, it is ours, and it denies: falling through would compare
			// $post->post_author on a null post, and that comparison can grant.
			return null === $post_type ? $caps : array( 'do_not_allow' );
		}

		if ( null === $post_type ) {
			// A generic capability against somebody else's post type. Leaving
			// core's answer untouched here is what keeps the media library and
			// every other plugin working.
			if ( ! in_array( $context['post_type'], Post_Types::all(), true ) ) {
				return $caps;
			}

			$post_type = $context['post_type'];
		} elseif ( $context['post_type'] !== $post_type ) {
			// The capability names a post type; the object must actually be
			// one. Without this, edit_aggr_campaign against a creative's id
			// would be answered using the campaign rules.
			return array( 'do_not_allow' );
		}

		$plural = Post_Types::capability_names()[ $post_type ]['plural'];

		// Placements and packages are shared configuration, not anyone's
		// property: they carry no owning organization, so an org comparison
		// would deny every advertiser — including from the wizard screen whose
		// entire job is choosing among them.
		if ( in_array( $post_type, self::GLOBAL_POST_TYPES, true ) ) {
			return $this->primitives_for_global( $post_type, $action, $plural );
		}

		$is_member = in_array( $context['org_id'], $this->orgs->org_ids_for_user( $user_id ), true );
		$is_staff  = $this->holds_review_capability( $user_id );

		if ( ! $is_member && ! $is_staff ) {
			return array( 'do_not_allow' );
		}

		return $this->primitives_for( $action, $plural, $is_member );
	}

	/**
	 * Drops memoized membership when one of our ownership keys changes.
	 *
	 * The first argument is deliberately untyped beyond int|array, because the
	 * three hooks disagree: `added_post_meta` and `updated_post_meta` pass a
	 * single meta id, while **`deleted_post_meta` passes an array of them**.
	 * Declaring `int` here fatals the moment anyone removes an organization
	 * member — which is exactly how this was found.
	 *
	 * @param int|array<int, int> $meta_ids Meta row id, or ids on deletion. Unused.
	 * @param int                 $post_id  Ignored.
	 * @param string              $meta_key The key that changed.
	 * @return void
	 */
	public function flush_on_meta_change( int|array $meta_ids, int $post_id, string $meta_key ): void {
		$watched = array(
			Org_Repository::META_ORG_ID,
			Org_Repository::META_OWNER_USER,
			Org_Repository::META_MEMBER_USER,
		);

		if ( in_array( $meta_key, $watched, true ) ) {
			$this->flush_cache();
		}
	}

	/**
	 * Drops all memoized ownership state.
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		$this->orgs->flush_cache();
	}

	/**
	 * The primitives for shared configuration objects.
	 *
	 * Anyone who can reach the portal may read them. Changing one is a
	 * different level of trust: remapping a placement publishes ads into a
	 * different slot on a public site, so writes need the management
	 * capability, which neither advertisers nor reviewers hold.
	 *
	 * @param string $post_type The post type slug.
	 * @param string $action    One of edit, read, delete.
	 * @param string $plural    The post type's plural capability name.
	 * @return array<int, string>
	 */
	private function primitives_for_global( string $post_type, string $action, string $plural ): array {
		if ( 'read' === $action ) {
			return array( 'read_private_' . $plural );
		}

		return array(
			Post_Types::PLACEMENT === $post_type
				? Capabilities::MANAGE_PLACEMENTS
				: Capabilities::MANAGE_PACKAGES,
		);
	}

	/**
	 * The primitives a mapped capability resolves to.
	 *
	 * @param string $action    One of edit, read, delete.
	 * @param string $plural    The post type's plural capability name.
	 * @param bool   $is_member Whether the user belongs to the owning organization.
	 * @return array<int, string>
	 */
	private function primitives_for( string $action, string $plural, bool $is_member ): array {
		if ( 'read' === $action ) {
			// Membership is the authorization. Requiring read_private_ as well
			// would mean an advertiser could not read their own campaign, since
			// that primitive is the cross-organization one.
			return $is_member ? array( 'read' ) : array( 'read_private_' . $plural );
		}

		$scope = $is_member ? $action . '_' : $action . '_others_';

		return array( $scope . $plural );
	}

	/**
	 * Whether a user holds the reviewer capability.
	 *
	 * Reads allcaps directly and deliberately. Calling current_user_can() or
	 * map_meta_cap() here re-enters map_meta_cap, which this filter is already
	 * inside — and the recursion does not reliably blow the stack. Sometimes it
	 * just returns a wrong answer from a half-built capability array, which is
	 * considerably worse than a crash.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private function holds_review_capability( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$user = get_userdata( $user_id );

		if ( false === $user ) {
			return false;
		}

		return ! empty( $user->allcaps[ Capabilities::REVIEW_CAMPAIGNS ] );
	}

	/**
	 * Every meta capability this filter owns, mapped to its post type and action.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function meta_capability_map(): array {
		static $map = null;

		if ( null !== $map ) {
			return $map;
		}

		$map = array();

		foreach ( Post_Types::capability_names() as $post_type => $names ) {
			foreach ( array( 'edit', 'read', 'delete' ) as $action ) {
				$map[ $action . '_' . $names['singular'] ] = array( $post_type, $action );
			}
		}

		return $map;
	}
}
