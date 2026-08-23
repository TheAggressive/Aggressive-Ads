<?php
/**
 * Capability vocabulary tests.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Security;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Security\Capabilities;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The capability names are the vocabulary every permission check speaks. A
 * typo here grants or denies silently, so the names are pinned.
 */
final class CapabilitiesTest extends TestCase {

	/**
	 * The ten primitives, by literal name.
	 *
	 * Written out rather than derived, deliberately: this test exists to fail
	 * when a capability is renamed, and deriving the expectation from the same
	 * source would make it agree with any rename.
	 *
	 * @return void
	 */
	public function test_primitive_names_are_pinned(): void {
		$this->assertSame(
			array(
				'aggr_access_portal',
				'aggr_upload_creative',
				'aggr_submit_campaign',
				'aggr_review_campaigns',
				'aggr_publish',
				'aggr_manage_placements',
				'aggr_manage_packages',
				'aggr_manage_orgs',
				'aggr_view_audit_log',
				'aggr_manage_settings',
			),
			Capabilities::primitives()
		);
	}

	/**
	 * Reviewing and publishing are separate capabilities.
	 *
	 * Reviewing is a judgement; publishing writes to a public website and can
	 * bill a customer. Collapsing them would make a triage-only role
	 * impossible without a redesign.
	 *
	 * @return void
	 */
	public function test_review_and_publish_are_distinct(): void {
		$this->assertNotSame( Capabilities::REVIEW_CAMPAIGNS, Capabilities::PUBLISH_TO_ADSANITY );
		$this->assertContains( Capabilities::REVIEW_CAMPAIGNS, Capabilities::primitives() );
		$this->assertContains( Capabilities::PUBLISH_TO_ADSANITY, Capabilities::primitives() );
	}

	/**
	 * Every primitive carries the plugin prefix, so nothing collides with
	 * core's or another plugin's vocabulary.
	 *
	 * @return void
	 */
	public function test_every_capability_is_prefixed(): void {
		foreach ( Capabilities::all() as $cap ) {
			$this->assertMatchesRegularExpression( '/aggr_/', $cap, "Unprefixed capability: {$cap}" );
		}
	}

	/**
	 * Each post type generates the eleven primitives map_meta_cap expects.
	 *
	 * @param string $post_type Post type slug.
	 * @return void
	 */
	#[DataProvider( 'data_post_types' )]
	public function test_generated_primitives_cover_the_full_set( string $post_type ): void {
		$plural   = Post_Types::capability_names()[ $post_type ]['plural'];
		$expected = array(
			'edit_' . $plural,
			'edit_others_' . $plural,
			'edit_private_' . $plural,
			'edit_published_' . $plural,
			'publish_' . $plural,
			'read_private_' . $plural,
			'delete_' . $plural,
			'delete_others_' . $plural,
			'delete_private_' . $plural,
			'delete_published_' . $plural,
			'create_' . $plural,
		);

		$this->assertSame( $expected, Capabilities::generated_for( $post_type ) );
	}

	/**
	 * Meta capabilities are built from the singular name and are never part of
	 * the grantable set — they are resolved per-object by Ownership::map().
	 *
	 * @param string $post_type Post type slug.
	 * @return void
	 */
	#[DataProvider( 'data_post_types' )]
	public function test_meta_capabilities_are_never_grantable( string $post_type ): void {
		$meta = Capabilities::meta_for( $post_type );

		$this->assertCount( 3, $meta );

		foreach ( $meta as $cap ) {
			$this->assertNotContains(
				$cap,
				Capabilities::all(),
				"Meta capability {$cap} appears in the grantable set; it must be resolved per-object instead."
			);
		}
	}

	/**
	 * An unknown post type yields nothing rather than a malformed capability
	 * name like `edit_` that would silently never match.
	 *
	 * @return void
	 */
	public function test_an_unknown_post_type_generates_nothing(): void {
		$this->assertSame( array(), Capabilities::generated_for( 'post' ) );
		$this->assertSame( array(), Capabilities::meta_for( 'post' ) );
		$this->assertSame( array(), Capabilities::subset_for( 'post', array( 'edit_' ) ) );
	}

	/**
	 * Only real generated primitives are emitted, so a mistyped prefix cannot
	 * invent a capability that is granted and never checked.
	 *
	 * @return void
	 */
	public function test_subset_ignores_prefixes_that_are_not_generated(): void {
		$subset = Capabilities::subset_for(
			Post_Types::CAMPAIGN,
			array( 'edit_', 'moderate_', 'promote_' )
		);

		$this->assertSame( array( 'edit_aggr_campaigns' ), $subset );
	}

	/**
	 * The full set is primitives plus generated, with no duplicates.
	 *
	 * @return void
	 */
	public function test_the_full_set_has_no_duplicates(): void {
		$all = Capabilities::all();

		$this->assertSame( $all, array_unique( $all ) );
		$this->assertCount( 10 + ( 5 * 11 ), $all );
	}

	/**
	 * The staff shell cap is derived at user_has_cap, never granted on a role.
	 *
	 * Adding it to primitives() would stamp it onto administrator at install
	 * and make a placements-only role need a second grant just to see the menu.
	 *
	 * @return void
	 */
	public function test_access_staff_is_derived_not_a_primitive(): void {
		$this->assertSame( 'aggr_access_staff', Capabilities::ACCESS_STAFF );
		$this->assertNotContains( Capabilities::ACCESS_STAFF, Capabilities::primitives() );
		$this->assertSame(
			array(
				Capabilities::REVIEW_CAMPAIGNS,
				Capabilities::MANAGE_ORGS,
				Capabilities::MANAGE_PLACEMENTS,
				Capabilities::MANAGE_PACKAGES,
				Capabilities::MANAGE_SETTINGS,
			),
			Capabilities::staff_menu_caps()
		);
	}

	/**
	 * Post type slugs.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_post_types(): array {
		$cases = array();

		foreach ( Post_Types::all() as $slug ) {
			$cases[ $slug ] = array( $slug );
		}

		return $cases;
	}
}
