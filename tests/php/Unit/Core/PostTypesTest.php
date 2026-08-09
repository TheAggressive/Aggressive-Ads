<?php
/**
 * Post type registration argument tests.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Unit\Core;

use LAAO_Advertiser_Portal\Core\Post_Types;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The privacy baseline is the security boundary for every business entity in
 * the system, so it is asserted argument by argument rather than trusted.
 */
final class PostTypesTest extends TestCase {

	/**
	 * Every slug fits wp_posts.post_type.
	 *
	 * A longer slug does not error — it truncates on write and then fails to
	 * match on read, producing posts that exist and cannot be queried. Nobody
	 * should have to discover that the slow way.
	 *
	 * @return void
	 */
	public function test_every_slug_fits_the_post_type_column(): void {
		foreach ( Post_Types::all() as $slug ) {
			$this->assertLessThanOrEqual(
				Post_Types::MAX_SLUG_LENGTH,
				strlen( $slug ),
				sprintf( 'Post type "%s" is %d characters; the column holds %d.', $slug, strlen( $slug ), Post_Types::MAX_SLUG_LENGTH )
			);
		}
	}

	/**
	 * All five post types are declared, and nothing else is.
	 *
	 * @return void
	 */
	public function test_declares_exactly_five_post_types(): void {
		$this->assertSame(
			array(
				'laao_ads_org',
				'laao_ads_placement',
				'laao_ads_package',
				'laao_ads_campaign',
				'laao_ads_creative',
			),
			Post_Types::all()
		);

		$this->assertCount( 5, Post_Types::registration_args() );
	}

	/**
	 * Every argument that makes a post type unreachable is asserted by name.
	 *
	 * Setting show_ui => false alone hides a post type from the admin and
	 * leaves the REST route, the permalink and the ?post_type= query all open.
	 * It is the combination that closes them, so the combination is tested.
	 *
	 * @param string $slug Post type slug.
	 * @return void
	 *
	 * @dataProvider data_post_types
	 */
	public function test_privacy_baseline_holds( string $slug ): void {
		$args = Post_Types::registration_args()[ $slug ];

		$this->assertFalse( $args['public'], "{$slug}: public" );
		$this->assertFalse( $args['publicly_queryable'], "{$slug}: publicly_queryable" );
		$this->assertTrue( $args['exclude_from_search'], "{$slug}: exclude_from_search" );
		$this->assertFalse( $args['show_in_rest'], "{$slug}: show_in_rest — this is what stops wp/v2 leaking another org's data" );
		$this->assertFalse( $args['rewrite'], "{$slug}: rewrite" );
		$this->assertFalse( $args['query_var'], "{$slug}: query_var" );
		$this->assertFalse( $args['has_archive'], "{$slug}: has_archive" );
		$this->assertFalse( $args['show_in_nav_menus'], "{$slug}: show_in_nav_menus" );
	}

	/**
	 * Deleting a WordPress user must not cascade-delete an organization's
	 * campaigns. An account going away does not erase what it ran.
	 *
	 * @param string $slug Post type slug.
	 * @return void
	 *
	 * @dataProvider data_post_types
	 */
	public function test_content_survives_user_deletion( string $slug ): void {
		$this->assertFalse( Post_Types::registration_args()[ $slug ]['delete_with_user'] );
	}

	/**
	 * Meta-cap mapping must be on, or the org-scoped ownership filter has
	 * nothing to filter and every object check falls back to core's author
	 * comparison.
	 *
	 * @param string $slug Post type slug.
	 * @return void
	 *
	 * @dataProvider data_post_types
	 */
	public function test_meta_cap_mapping_is_enabled( string $slug ): void {
		$this->assertTrue( Post_Types::registration_args()[ $slug ]['map_meta_cap'] );
	}

	/**
	 * Each post type gets its own capability pair, so a role can hold rights
	 * over campaigns without holding them over placements.
	 *
	 * @return void
	 */
	public function test_capability_types_are_distinct_per_post_type(): void {
		$plurals = array();

		foreach ( Post_Types::registration_args() as $slug => $args ) {
			$this->assertIsArray( $args['capability_type'], "{$slug}: capability_type must be a singular/plural pair" );
			$this->assertCount( 2, $args['capability_type'] );

			$plurals[] = $args['capability_type'][1];
		}

		$this->assertSame( $plurals, array_unique( $plurals ), 'Two post types share a plural capability name.' );
	}

	/**
	 * Placements and packages are configuration, not someone's work, so they
	 * carry no author. Everything else does.
	 *
	 * @return void
	 */
	public function test_only_advertiser_owned_types_support_author(): void {
		$args = Post_Types::registration_args();

		$this->assertNotContains( 'author', $args['laao_ads_placement']['supports'] );
		$this->assertNotContains( 'author', $args['laao_ads_package']['supports'] );

		$this->assertContains( 'author', $args['laao_ads_org']['supports'] );
		$this->assertContains( 'author', $args['laao_ads_campaign']['supports'] );
		$this->assertContains( 'author', $args['laao_ads_creative']['supports'] );
	}

	/**
	 * Capability names cover every post type and are prefixed.
	 *
	 * @return void
	 */
	public function test_capability_names_are_declared_for_every_post_type(): void {
		$names = Post_Types::capability_names();

		$this->assertSame( Post_Types::all(), array_keys( $names ) );

		foreach ( $names as $slug => $pair ) {
			$this->assertStringStartsWith( 'laao_ads_', $pair['singular'], "{$slug}: singular" );
			$this->assertStringStartsWith( 'laao_ads_', $pair['plural'], "{$slug}: plural" );
			$this->assertNotSame( $pair['singular'], $pair['plural'], "{$slug}: singular and plural must differ" );
		}
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
