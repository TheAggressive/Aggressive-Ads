<?php
/**
 * The capability vocabulary.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Security;

use Aggressive\Ads\Core\Post_Types;

/**
 * Declares every capability this plugin defines, once.
 *
 * This class is the source of truth consumed by Roles, by the REST permission
 * callbacks, and by the tests. A capability string typed literally anywhere
 * else is a typo waiting to silently grant or silently deny.
 *
 * Pure data — no WordPress calls — so the matrix is testable without a
 * bootstrap. See docs/roles-and-capabilities.md.
 */
final class Capabilities {

	public const ACCESS_PORTAL       = 'aggr_access_portal';
	public const UPLOAD_CREATIVE     = 'aggr_upload_creative';
	public const SUBMIT_CAMPAIGN     = 'aggr_submit_campaign';
	public const REVIEW_CAMPAIGNS    = 'aggr_review_campaigns';
	public const PUBLISH_TO_ADSANITY = 'aggr_publish';
	public const MANAGE_PLACEMENTS   = 'aggr_manage_placements';
	public const MANAGE_PACKAGES     = 'aggr_manage_packages';
	public const MANAGE_ORGS         = 'aggr_manage_orgs';
	public const VIEW_AUDIT_LOG      = 'aggr_view_audit_log';
	public const MANAGE_SETTINGS     = 'aggr_manage_settings';

	/**
	 * Derived shell cap for the unified admin parent. Not granted on a role.
	 */
	public const ACCESS_STAFF = 'aggr_access_staff';

	/**
	 * The primitive-capability suffixes `map_meta_cap => true` generates from a
	 * post type's plural capability name.
	 *
	 * @var array<int, string>
	 */
	private const GENERATED_PREFIXES = array(
		'edit_',
		'edit_others_',
		'edit_private_',
		'edit_published_',
		'publish_',
		'read_private_',
		'delete_',
		'delete_others_',
		'delete_private_',
		'delete_published_',
		'create_',
	);

	/**
	 * The meta-capability prefixes, which are resolved per-object and never
	 * granted to a role.
	 *
	 * @var array<int, string>
	 */
	private const META_PREFIXES = array( 'edit_', 'read_', 'delete_' );

	/**
	 * The capabilities this plugin invents.
	 *
	 * REVIEW_CAMPAIGNS and PUBLISH_TO_ADSANITY are separate on purpose:
	 * reviewing is a judgement, publishing writes to a public website and can
	 * bill a customer. Keeping them apart allows a triage-only role later
	 * without redesigning anything.
	 *
	 * @return array<int, string>
	 */
	public static function primitives(): array {
		return array(
			self::ACCESS_PORTAL,
			self::UPLOAD_CREATIVE,
			self::SUBMIT_CAMPAIGN,
			self::REVIEW_CAMPAIGNS,
			self::PUBLISH_TO_ADSANITY,
			self::MANAGE_PLACEMENTS,
			self::MANAGE_PACKAGES,
			self::MANAGE_ORGS,
			self::VIEW_AUDIT_LOG,
			self::MANAGE_SETTINGS,
		);
	}

	/**
	 * Capabilities that reveal a submenu under the Advertising parent.
	 *
	 * `ACCESS_STAFF` is derived from these at user_has_cap. It is not a
	 * primitive and is never granted on a role.
	 *
	 * @return list<string>
	 */
	public static function staff_menu_caps(): array {
		return array(
			self::REVIEW_CAMPAIGNS,
			self::MANAGE_ORGS,
			self::MANAGE_PLACEMENTS,
			self::MANAGE_PACKAGES,
			self::MANAGE_SETTINGS,
		);
	}

	/**
	 * The primitives WordPress generates for one post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return array<int, string>
	 */
	public static function generated_for( string $post_type ): array {
		$names = Post_Types::capability_names();

		if ( ! isset( $names[ $post_type ] ) ) {
			return array();
		}

		$plural = $names[ $post_type ]['plural'];
		$caps   = array();

		foreach ( self::GENERATED_PREFIXES as $prefix ) {
			$caps[] = $prefix . $plural;
		}

		return $caps;
	}

	/**
	 * A subset of one post type's generated primitives.
	 *
	 * @param string            $post_type Post type slug.
	 * @param array<int,string> $prefixes  Prefixes to include, e.g. array( 'edit_', 'create_' ).
	 * @return array<int, string>
	 */
	public static function subset_for( string $post_type, array $prefixes ): array {
		$names = Post_Types::capability_names();

		if ( ! isset( $names[ $post_type ] ) ) {
			return array();
		}

		$plural = $names[ $post_type ]['plural'];
		$caps   = array();

		foreach ( $prefixes as $prefix ) {
			if ( ! in_array( $prefix, self::GENERATED_PREFIXES, true ) ) {
				continue;
			}

			$caps[] = $prefix . $plural;
		}

		return $caps;
	}

	/**
	 * Every generated primitive across all five post types.
	 *
	 * @return array<int, string>
	 */
	public static function all_generated(): array {
		$caps = array();

		foreach ( Post_Types::all() as $post_type ) {
			foreach ( self::generated_for( $post_type ) as $cap ) {
				$caps[] = $cap;
			}
		}

		return $caps;
	}

	/**
	 * The meta capabilities for one post type.
	 *
	 * Listed for the tests and for Ownership::map(); never granted to a role.
	 * current_user_can( 'edit_aggr_campaign', 42 ) is translated by
	 * map_meta_cap into whichever primitive applies to that object, and the
	 * primitive is what the role holds.
	 *
	 * @param string $post_type Post type slug.
	 * @return array<int, string>
	 */
	public static function meta_for( string $post_type ): array {
		$names = Post_Types::capability_names();

		if ( ! isset( $names[ $post_type ] ) ) {
			return array();
		}

		$singular = $names[ $post_type ]['singular'];
		$caps     = array();

		foreach ( self::META_PREFIXES as $prefix ) {
			$caps[] = $prefix . $singular;
		}

		return $caps;
	}

	/**
	 * One meta capability, by post type and action.
	 *
	 * Named rather than positional so a caller cannot quietly depend on the
	 * order meta_for() happens to return, which is how `delete_` ends up being
	 * checked where `read_` was meant.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $action    One of edit, read, delete.
	 * @return string Empty when either argument is unknown.
	 */
	public static function meta_cap( string $post_type, string $action ): string {
		$names = Post_Types::capability_names();

		if ( ! isset( $names[ $post_type ] ) || ! in_array( $action . '_', self::META_PREFIXES, true ) ) {
			return '';
		}

		return $action . '_' . $names[ $post_type ]['singular'];
	}

	/**
	 * Every capability this plugin grants: primitives plus generated.
	 *
	 * This is what an administrator receives on install.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array_merge( self::primitives(), self::all_generated() );
	}
}
