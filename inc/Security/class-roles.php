<?php
/**
 * The two custom roles.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Security;

use Aggressive\Ads\Core\Post_Types;

/**
 * Declares and installs the advertiser and reviewer roles.
 *
 * The whole capability matrix lives in definitions(), which is pure data and
 * therefore testable without WordPress. install() and remove() are the only
 * parts that touch it, and they are driven by the installer's role-version
 * check rather than by activation alone — see
 * docs/adr/0014-version-driven-idempotent-installer.md.
 */
final class Roles {

	public const ADVERTISER = 'aggr_advertiser';
	public const REVIEWER   = 'aggr_reviewer';

	/**
	 * Bumped whenever the capability matrix below changes, so an update
	 * re-applies roles on sites that were installed under the old matrix.
	 */
	public const VERSION = 3;

	/**
	 * The role capability matrix.
	 *
	 * Display names are stored untranslated, as core does — WordPress
	 * translates them at display time through translate_user_role().
	 *
	 * @return array<string, array{display_name: string, capabilities: array<string, bool>}>
	 */
	public static function definitions(): array {
		return array(
			self::ADVERTISER => array(
				'display_name' => 'Advertiser',
				'capabilities' => self::advertiser_capabilities(),
			),
			self::REVIEWER   => array(
				'display_name' => 'Ad Reviewer',
				'capabilities' => self::reviewer_capabilities(),
			),
		);
	}

	/**
	 * What an advertiser holds.
	 *
	 * Note what is absent, which matters more than what is present:
	 *
	 * - `upload_files` — advertisers never touch the Media Library. Creative
	 *   goes to private storage and only becomes an attachment at approval.
	 * - `edit_posts` — no access to site content of any kind.
	 * - `unfiltered_html` — decisive, given that code and html5 creatives are
	 *   arbitrary HTML on a public page.
	 *
	 * @return array<string, bool>
	 */
	private static function advertiser_capabilities(): array {
		$caps = array(
			'read'                        => true,
			Capabilities::ACCESS_PORTAL   => true,
			Capabilities::UPLOAD_CREATIVE => true,
			Capabilities::SUBMIT_CAMPAIGN => true,
		);

		// Their own campaigns and creatives: create, edit, delete. Never the
		// _others_ or _private_ variants — those are what would let one
		// organization reach another's work.
		$owner_prefixes = array( 'create_', 'edit_', 'edit_published_', 'delete_', 'delete_published_' );

		foreach ( array( Post_Types::CAMPAIGN, Post_Types::CREATIVE ) as $post_type ) {
			foreach ( Capabilities::subset_for( $post_type, $owner_prefixes ) as $cap ) {
				$caps[ $cap ] = true;
			}
		}

		/*
		 * Placements and packages are shared configuration, readable by anyone
		 * building a campaign. read_private_ is required rather than optional:
		 * these post types are registered private, so a plain read is not
		 * enough to see one.
		 *
		 * Organizations are deliberately NOT in this list. An advertiser reads
		 * their own organization through membership, which Ownership::map()
		 * resolves to plain `read` — so granting read_private_aggr_orgs
		 * would do nothing for their own org and everything for everyone
		 * else's, leaving one dropped guard between an advertiser and every
		 * other customer's contact and billing details.
		 */
		foreach ( array( Post_Types::PLACEMENT, Post_Types::PACKAGE ) as $post_type ) {
			foreach ( Capabilities::subset_for( $post_type, array( 'read_private_' ) ) as $cap ) {
				$caps[ $cap ] = true;
			}
		}

		return $caps;
	}

	/**
	 * What a reviewer holds: everything an advertiser has, plus the review and
	 * publish primitives and the cross-organization variants on all five post
	 * types.
	 *
	 * Deliberately not included: MANAGE_PLACEMENTS, MANAGE_PACKAGES,
	 * MANAGE_ORGS and MANAGE_SETTINGS. Reviewing campaigns is a daily job;
	 * changing the placement-to-ad-group mapping is a configuration change
	 * that publishes ads into different slots on a public site.
	 *
	 * @return array<string, bool>
	 */
	private static function reviewer_capabilities(): array {
		$caps = self::advertiser_capabilities();

		$caps[ Capabilities::REVIEW_CAMPAIGNS ]    = true;
		$caps[ Capabilities::PUBLISH_TO_ADSANITY ] = true;
		$caps[ Capabilities::VIEW_AUDIT_LOG ]      = true;

		foreach ( Post_Types::all() as $post_type ) {
			foreach ( Capabilities::generated_for( $post_type ) as $cap ) {
				$caps[ $cap ] = true;
			}
		}

		return $caps;
	}

	/**
	 * Roles that receive the full capability set on install.
	 *
	 * `editor` receives nothing. The filter is the supported way to grant the
	 * full set to another role without editing this file.
	 *
	 * @return array<int, string>
	 */
	public static function roles_receiving_all_capabilities(): array {
		/**
		 * Filters which existing roles receive every portal capability.
		 *
		 * @param array<int, string> $roles Role slugs.
		 */
		$roles = apply_filters( 'aggr_roles_receiving_caps', array( 'administrator' ) );

		if ( ! is_array( $roles ) ) {
			return array( 'administrator' );
		}

		return array_values( array_filter( $roles, 'is_string' ) );
	}

	/**
	 * Creates the custom roles and grants existing roles their capabilities.
	 *
	 * Idempotent: a role that already exists is removed and re-added, so a
	 * capability added in an update reaches sites installed under the old
	 * matrix. Removing first is what makes a *revoked* capability actually go
	 * away — add_role() on an existing role is a no-op, which is the trap.
	 *
	 * @return void
	 */
	public function install(): void {
		foreach ( self::definitions() as $slug => $definition ) {
			remove_role( $slug );

			add_role( $slug, $definition['display_name'], $definition['capabilities'] );
		}

		foreach ( self::roles_receiving_all_capabilities() as $slug ) {
			$role = get_role( $slug );

			if ( null === $role ) {
				continue;
			}

			foreach ( Capabilities::all() as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Removes the custom roles and revokes granted capabilities.
	 *
	 * Called on uninstall, never on deactivation — a deactivated plugin that
	 * silently stripped every advertiser's role would look like data loss.
	 *
	 * @return void
	 */
	public function remove(): void {
		foreach ( array_keys( self::definitions() ) as $slug ) {
			remove_role( $slug );
		}

		foreach ( self::roles_receiving_all_capabilities() as $slug ) {
			$role = get_role( $slug );

			if ( null === $role ) {
				continue;
			}

			foreach ( Capabilities::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
