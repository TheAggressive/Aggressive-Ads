<?php
/**
 * Post type registration against real WordPress.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Integration;

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Post_Types;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * The unit suite asserts the declared arguments. This asserts what WordPress
 * did with them — in particular that no REST route was generated, which is a
 * structural mitigation rather than a guarded one and therefore has to be
 * verified against a real WP_REST_Server.
 */
final class PostTypeRegistrationTest extends WP_UnitTestCase {

	/**
	 * All five post types are registered.
	 *
	 * @return void
	 */
	public function test_every_post_type_is_registered(): void {
		foreach ( Post_Types::all() as $slug ) {
			$this->assertTrue( post_type_exists( $slug ), "{$slug} is not registered" );
		}
	}

	/**
	 * No wp/v2 route exists for any of the five.
	 *
	 * This is the assertion behind "REST meta leakage is removed by design,
	 * not defended against". A generic CRUD route over campaigns would serve
	 * one organization's budgets and schedules to another's user, and no
	 * permission callback of ours would ever run.
	 *
	 * @return void
	 */
	public function test_no_rest_route_exists_for_any_post_type(): void {
		/*
		 * The server has to be the *global* one.
		 *
		 * register_rest_route() resolves its target through rest_get_server(),
		 * which returns the global — so routes registered during rest_api_init
		 * never reach a locally constructed WP_REST_Server. An earlier version
		 * of this test did exactly that and scanned an empty list, which meant
		 * it passed just as happily with show_in_rest => true on all five post
		 * types. It was asserting nothing.
		 */
		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();

		do_action( 'rest_api_init', $wp_rest_server );

		$routes = array_keys( $wp_rest_server->get_routes() );

		$this->assertNotEmpty( $routes, 'No routes were collected, so this test would prove nothing.' );
		$this->assertContains( '/wp/v2/posts', $routes, 'Core routes are missing; the scan is not seeing the real registry.' );

		foreach ( Post_Types::all() as $slug ) {
			// The route scan comes first deliberately: it is the assertion that
			// describes the actual risk, so it is the one that must bite. With
			// the show_in_rest check ahead of it, a regression fails on the
			// declaration and this loop is never proven to work.
			foreach ( $routes as $route ) {
				$this->assertStringNotContainsString(
					$slug,
					$route,
					"A REST route mentions {$slug}: {$route}"
				);
			}

			$object = get_post_type_object( $slug );

			$this->assertNotNull( $object );
			$this->assertFalse( $object->show_in_rest, "{$slug} declares show_in_rest" );
		}
	}

	/**
	 * No post type is publicly queryable, and none has a permalink to guess.
	 *
	 * @return void
	 */
	public function test_no_post_type_is_reachable_from_the_front_end(): void {
		foreach ( Post_Types::all() as $slug ) {
			$object = get_post_type_object( $slug );

			$this->assertNotNull( $object );
			$this->assertFalse( $object->public, "{$slug}: public" );
			$this->assertFalse( $object->publicly_queryable, "{$slug}: publicly_queryable" );
			$this->assertFalse( $object->rewrite, "{$slug}: rewrite" );
			$this->assertFalse( $object->query_var, "{$slug}: query_var" );
		}
	}

	/**
	 * WordPress generated the per-post-type capabilities from our pair, which
	 * is what the whole ownership model is built on.
	 *
	 * @return void
	 */
	public function test_capabilities_were_generated_per_post_type(): void {
		foreach ( Post_Types::capability_names() as $slug => $names ) {
			$object = get_post_type_object( $slug );

			$this->assertNotNull( $object );
			$this->assertSame( 'edit_' . $names['plural'], $object->cap->edit_posts, "{$slug}: edit_posts" );
			$this->assertSame( 'edit_others_' . $names['plural'], $object->cap->edit_others_posts, "{$slug}: edit_others_posts" );
			$this->assertSame( 'read_private_' . $names['plural'], $object->cap->read_private_posts, "{$slug}: read_private_posts" );
		}
	}

	/**
	 * Meta-cap mapping is on, so our ownership filter has something to filter.
	 *
	 * @return void
	 */
	public function test_meta_cap_mapping_is_enabled(): void {
		foreach ( Post_Types::all() as $slug ) {
			$object = get_post_type_object( $slug );

			$this->assertNotNull( $object );
			$this->assertTrue( $object->map_meta_cap, "{$slug}: map_meta_cap" );
		}
	}

	/**
	 * All eleven campaign statuses are registered with WordPress.
	 *
	 * @return void
	 */
	public function test_every_campaign_status_is_registered(): void {
		foreach ( Post_Statuses::all() as $slug ) {
			$this->assertNotNull( get_post_status_object( $slug ), "{$slug} is not registered" );
		}
	}

	/**
	 * No campaign status is public or internal.
	 *
	 * Public would put campaigns on the front end. Internal would hide them
	 * from admin list filters, which is where the review queue lives.
	 *
	 * @return void
	 */
	public function test_campaign_statuses_are_protected_but_not_internal(): void {
		foreach ( Post_Statuses::all() as $slug ) {
			$status = get_post_status_object( $slug );

			$this->assertNotNull( $status );
			$this->assertFalse( $status->public, "{$slug}: public" );
			$this->assertFalse( $status->internal, "{$slug}: internal" );
			$this->assertTrue( $status->protected, "{$slug}: protected" );
		}
	}

	/**
	 * A campaign persists and round-trips through its own status.
	 *
	 * Proves the varchar(20) constraint is actually satisfied: an over-long
	 * status does not error on write, it truncates and then never matches on
	 * read — so a stored-and-refetched status is the only real check.
	 *
	 * @return void
	 */
	public function test_a_campaign_round_trips_through_every_status(): void {
		foreach ( Post_Statuses::all() as $slug ) {
			$id = wp_insert_post(
				array(
					'post_type'   => Post_Types::CAMPAIGN,
					'post_title'  => 'Round trip',
					'post_status' => $slug,
				)
			);

			$this->assertIsInt( $id );
			$this->assertGreaterThan( 0, $id );
			$this->assertSame( $slug, get_post_status( $id ), "{$slug} did not survive the round trip" );
		}
	}

	/**
	 * `post_status => 'any'` does not mean "any campaign status".
	 *
	 * Every campaign status is excluded from search by design, and 'any'
	 * expands to exactly the statuses that are not. A query written with 'any'
	 * therefore matches no campaign at all, silently and with no error — it
	 * simply returns nothing and looks like an empty site.
	 *
	 * This has already cost two defects: the dev seeder created a duplicate of
	 * every object on its second run, and uninstall.php deleted the
	 * organizations, reported success, and left every campaign row in the
	 * database on a site that had asked for its content to be removed.
	 *
	 * Pinned here rather than in either caller, because the trap belongs to the
	 * status registration and the next caller has not been written yet.
	 *
	 * @return void
	 */
	public function test_campaign_statuses_are_invisible_to_post_status_any(): void {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
			)
		);

		$any = get_posts(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => 'any',
				'numberposts' => 20,
				'fields'      => 'ids',
			)
		);

		$named = get_posts(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::all(),
				'numberposts' => 20,
				'fields'      => 'ids',
			)
		);

		$this->assertNotContains( $campaign_id, array_map( 'intval', $any ), "'any' must not be trusted to find campaigns." );
		$this->assertContains( $campaign_id, array_map( 'intval', $named ), 'Naming the statuses must find them.' );
	}
}
