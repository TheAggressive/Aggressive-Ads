<?php
/**
 * Role capability matrix tests.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Unit\Security;

use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Security\Capabilities;
use LAAO_Advertiser_Portal\Security\Roles;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The declared matrix. That the matrix is actually applied to real WP_User
 * objects — and that a co-member of the same organization is allowed where a
 * stranger is denied — belongs to the integration suite, which needs a real
 * capability pipeline to mean anything.
 */
final class RolesMatrixTest extends TestCase {

	/**
	 * Two roles, named as documented.
	 *
	 * @return void
	 */
	public function test_declares_two_roles(): void {
		$this->assertSame(
			array( 'laao_ads_advertiser', 'laao_ads_reviewer' ),
			array_keys( Roles::definitions() )
		);
	}

	/**
	 * An advertiser holds none of the three capabilities that would undo the
	 * portal's containment.
	 *
	 * Holding upload_files would hand them the whole Media Library, which is
	 * the point of two-stage creative storage. Holding edit_posts is site
	 * content. And unfiltered_html is decisive, because code and html5
	 * creatives are arbitrary HTML on a public page.
	 *
	 * @param string $forbidden A capability advertisers must never hold.
	 * @return void
	 *
	 * @dataProvider data_forbidden_advertiser_capabilities
	 */
	public function test_advertiser_lacks_dangerous_core_capabilities( string $forbidden ): void {
		$caps = Roles::definitions()['laao_ads_advertiser']['capabilities'];

		$this->assertArrayNotHasKey( $forbidden, $caps );
	}

	/**
	 * Capabilities an advertiser must never hold.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_forbidden_advertiser_capabilities(): array {
		return array(
			'upload_files'    => array( 'upload_files' ),
			'edit_posts'      => array( 'edit_posts' ),
			'unfiltered_html' => array( 'unfiltered_html' ),
			'manage_options'  => array( 'manage_options' ),
			'edit_pages'      => array( 'edit_pages' ),
		);
	}

	/**
	 * An advertiser can reach the portal and do their own work.
	 *
	 * @return void
	 */
	public function test_advertiser_holds_the_portal_primitives(): void {
		$caps = Roles::definitions()['laao_ads_advertiser']['capabilities'];

		$this->assertTrue( $caps['read'] );
		$this->assertTrue( $caps[ Capabilities::ACCESS_PORTAL ] );
		$this->assertTrue( $caps[ Capabilities::UPLOAD_CREATIVE ] );
		$this->assertTrue( $caps[ Capabilities::SUBMIT_CAMPAIGN ] );
		$this->assertTrue( $caps['create_laao_ads_campaigns'] );
		$this->assertTrue( $caps['edit_laao_ads_campaigns'] );
		$this->assertTrue( $caps['edit_laao_ads_creatives'] );
	}

	/**
	 * An advertiser holds no _others_ or _private_ variant on any post type.
	 *
	 * This is the single most important assertion in the file. Those variants
	 * are what would let Ownership::map() resolve one organization's request
	 * onto another organization's object.
	 *
	 * @return void
	 */
	public function test_advertiser_holds_no_cross_organization_variant(): void {
		$caps = Roles::definitions()['laao_ads_advertiser']['capabilities'];

		foreach ( array_keys( $caps ) as $cap ) {
			$this->assertStringNotContainsString(
				'_others_',
				$cap,
				"Advertiser holds {$cap}, which reaches another organization's objects."
			);
		}

		foreach ( Post_Types::all() as $post_type ) {
			$plural = Post_Types::capability_names()[ $post_type ]['plural'];

			$this->assertArrayNotHasKey( 'edit_private_' . $plural, $caps );
			$this->assertArrayNotHasKey( 'delete_private_' . $plural, $caps );
		}
	}

	/**
	 * An advertiser cannot approve, review, or configure anything.
	 *
	 * @return void
	 */
	public function test_advertiser_holds_no_staff_primitive(): void {
		$caps = Roles::definitions()['laao_ads_advertiser']['capabilities'];

		$staff = array(
			Capabilities::REVIEW_CAMPAIGNS,
			Capabilities::PUBLISH_TO_ADSANITY,
			Capabilities::MANAGE_PLACEMENTS,
			Capabilities::MANAGE_PACKAGES,
			Capabilities::MANAGE_ORGS,
			Capabilities::MANAGE_SETTINGS,
			Capabilities::VIEW_AUDIT_LOG,
		);

		foreach ( $staff as $cap ) {
			$this->assertArrayNotHasKey( $cap, $caps, "Advertiser holds staff capability {$cap}." );
		}
	}

	/**
	 * Placements, packages and organizations are readable but not writable by
	 * an advertiser. read_private_ is required rather than optional, because
	 * these post types are registered private.
	 *
	 * @return void
	 */
	public function test_advertiser_reads_configuration_but_cannot_edit_it(): void {
		$caps = Roles::definitions()['laao_ads_advertiser']['capabilities'];

		foreach ( array( Post_Types::PLACEMENT, Post_Types::PACKAGE, Post_Types::ORGANIZATION ) as $post_type ) {
			$plural = Post_Types::capability_names()[ $post_type ]['plural'];

			$this->assertTrue( $caps[ 'read_private_' . $plural ], "advertiser cannot read {$plural}" );
			$this->assertArrayNotHasKey( 'edit_' . $plural, $caps, "advertiser can edit {$plural}" );
			$this->assertArrayNotHasKey( 'delete_' . $plural, $caps, "advertiser can delete {$plural}" );
		}
	}

	/**
	 * A reviewer holds everything an advertiser holds, and more. A reviewer
	 * who could not do what an advertiser can would be unable to reproduce a
	 * reported problem.
	 *
	 * @return void
	 */
	public function test_reviewer_is_a_superset_of_advertiser(): void {
		$definitions = Roles::definitions();
		$advertiser  = $definitions['laao_ads_advertiser']['capabilities'];
		$reviewer    = $definitions['laao_ads_reviewer']['capabilities'];

		foreach ( array_keys( $advertiser ) as $cap ) {
			$this->assertArrayHasKey( $cap, $reviewer, "Reviewer is missing advertiser capability {$cap}." );
		}

		$this->assertGreaterThan( count( $advertiser ), count( $reviewer ) );
	}

	/**
	 * A reviewer can review, publish and read the audit log, and holds the
	 * cross-organization variants that make a queue possible.
	 *
	 * @return void
	 */
	public function test_reviewer_holds_the_review_primitives(): void {
		$caps = Roles::definitions()['laao_ads_reviewer']['capabilities'];

		$this->assertTrue( $caps[ Capabilities::REVIEW_CAMPAIGNS ] );
		$this->assertTrue( $caps[ Capabilities::PUBLISH_TO_ADSANITY ] );
		$this->assertTrue( $caps[ Capabilities::VIEW_AUDIT_LOG ] );

		foreach ( Post_Types::all() as $post_type ) {
			$plural = Post_Types::capability_names()[ $post_type ]['plural'];

			$this->assertTrue( $caps[ 'edit_others_' . $plural ], "reviewer cannot edit others' {$plural}" );
			$this->assertTrue( $caps[ 'read_private_' . $plural ], "reviewer cannot read private {$plural}" );
		}
	}

	/**
	 * A reviewer cannot change configuration or settings.
	 *
	 * Reviewing campaigns is a daily job; remapping a placement to a different
	 * ad group publishes ads into different slots on a public site. Those are
	 * different levels of trust and stay separate.
	 *
	 * @return void
	 */
	public function test_reviewer_cannot_change_configuration(): void {
		$caps = Roles::definitions()['laao_ads_reviewer']['capabilities'];

		$this->assertArrayNotHasKey( Capabilities::MANAGE_PLACEMENTS, $caps );
		$this->assertArrayNotHasKey( Capabilities::MANAGE_PACKAGES, $caps );
		$this->assertArrayNotHasKey( Capabilities::MANAGE_SETTINGS, $caps );
		$this->assertArrayNotHasKey( Capabilities::MANAGE_ORGS, $caps );
	}

	/**
	 * No role is granted a meta capability. Meta caps are resolved per-object
	 * and holding one outright would bypass the ownership filter entirely.
	 *
	 * @return void
	 */
	public function test_no_role_is_granted_a_meta_capability(): void {
		$meta = array();

		foreach ( Post_Types::all() as $post_type ) {
			$meta = array_merge( $meta, Capabilities::meta_for( $post_type ) );
		}

		foreach ( Roles::definitions() as $slug => $definition ) {
			foreach ( $meta as $cap ) {
				$this->assertArrayNotHasKey(
					$cap,
					$definition['capabilities'],
					"Role {$slug} is granted meta capability {$cap} outright, bypassing Ownership::map()."
				);
			}
		}
	}

	/**
	 * Every granted capability maps to true. A capability present with false
	 * reads as "granted" to anyone skimming, and denies at runtime.
	 *
	 * @return void
	 */
	public function test_every_declared_capability_is_granted_not_denied(): void {
		foreach ( Roles::definitions() as $slug => $definition ) {
			foreach ( $definition['capabilities'] as $cap => $granted ) {
				$this->assertTrue( $granted, "Role {$slug} declares {$cap} as false; remove it instead." );
			}
		}
	}

	/**
	 * The role version exists so an updated matrix reaches sites installed
	 * under the old one. A matrix change without a bump ships silently.
	 *
	 * @return void
	 */
	public function test_a_role_version_is_declared(): void {
		$this->assertGreaterThanOrEqual( 1, Roles::VERSION );
	}
}
