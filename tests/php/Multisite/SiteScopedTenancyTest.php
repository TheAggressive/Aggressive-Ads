<?php
/**
 * One WordPress site is one publisher tenant.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Multisite;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Site_Lifecycle;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Asset_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Repository\Org_Access_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Workflow\Fill_Cache;
use Aggressive\Ads\Workflow\Fill_Token;
use WP_UnitTestCase;

/**
 * These assertions are inexpressible on single-site PHPUnit: post ids restart
 * per blog, and factory()->blog does not exist. They must not skip — this
 * file is loaded only when WP_TESTS_MULTISITE is defined.
 *
 * See docs/data-schema.md.
 */
final class SiteScopedTenancyTest extends WP_UnitTestCase {

	/**
	 * The config, not a runtime skip, is what makes this suite exist.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->fail( 'phpunit-multisite.xml.dist must define WP_TESTS_MULTISITE. This suite does not skip.' );
		}
	}

	/**
	 * The hooks exist before any site is created.
	 *
	 * @return void
	 */
	public function test_site_lifecycle_hooks_are_registered(): void {
		$lifecycle = Plugin::instance()->container()->get( Site_Lifecycle::class );

		$this->assertNotFalse( has_action( 'wp_initialize_site', array( $lifecycle, 'initialize_site' ) ) );
		$this->assertNotFalse( has_action( 'wp_uninitialize_site', array( $lifecycle, 'uninitialize_site' ) ) );
	}

	/**
	 * A new site does not inherit schema from a per-site install elsewhere.
	 *
	 * @return void
	 */
	public function test_a_new_site_is_not_installed_unless_the_plugin_is_network_active(): void {
		$this->network_deactivate();

		$blog_id = (int) self::factory()->blog->create();

		$this->assertSame(
			$this->all_tables( false ),
			$this->plugin_tables_on( $blog_id ),
			'A site created while the plugin was not network-active received plugin tables.'
		);
	}

	/**
	 * Network-active install must finish before public fill can run.
	 *
	 * @return void
	 */
	public function test_network_active_install_creates_schema_on_a_new_site(): void {
		$this->network_activate();

		$blog_id = (int) self::factory()->blog->create();

		$this->assertSame(
			$this->all_tables( true ),
			$this->plugin_tables_on( $blog_id ),
			'A network-active install did not create every plugin table on the new site.'
		);
		$this->assertNotSame( get_current_blog_id(), $blog_id );
	}

	/**
	 * A valid token from site A must not parse on site B, even when post ids collide.
	 *
	 * @return void
	 */
	public function test_a_fill_token_from_another_site_does_not_parse(): void {
		$this->network_activate();

		$blog_b = (int) self::factory()->blog->create();
		$tokens = new Fill_Token();
		$minted = $tokens->mint( 5, 10, 12 );

		$this->assertIsArray( $tokens->parse( $minted['token'] ) );

		$this->on_site(
			$blog_b,
			function () use ( $tokens, $minted ): void {
				$this->assertNull( $tokens->parse( $minted['token'] ) );

				$local = $tokens->mint( 5, 10, 12 );
				$this->assertIsArray( $tokens->parse( $local['token'] ) );
				$this->assertSame( get_current_blog_id(), $local['blog_id'] );
			}
		);
	}

	/**
	 * Candidate sets for the same placement id must not leak across sites.
	 *
	 * @return void
	 */
	public function test_fill_cache_keys_do_not_collide_across_sites(): void {
		$this->network_activate();

		$blog_b = (int) self::factory()->blog->create();
		$cache  = Plugin::instance()->container()->get( Fill_Cache::class );

		// Core's in-memory cache already prefixes non-global groups by blog id,
		// which would make this assertion pass with a key of aggr_fill_{id}
		// alone. Some drop-ins treat groups as global. Force that so the key
		// itself has to carry the blog id.
		wp_cache_add_global_groups( Fill_Cache::GROUP );

		$cache->put( 42, array( 'site' => 'one' ) );

		$this->on_site(
			$blog_b,
			static function () use ( $cache ): void {
				$cache->put( 42, array( 'site' => 'two' ) );
			}
		);

		$from_a = $cache->get( 42 );
		$this->assertIsArray( $from_a );
		$this->assertSame( 'one', $from_a['site'] );

		$this->on_site(
			$blog_b,
			function () use ( $cache ): void {
				$from_b = $cache->get( 42 );
				$this->assertIsArray( $from_b );
				$this->assertSame( 'two', $from_b['site'] );
			}
		);
	}

	/**
	 * Membership is org post meta. Those posts live on one site.
	 *
	 * @return void
	 */
	public function test_an_organization_on_site_a_is_invisible_on_site_b(): void {
		$this->network_activate();

		$user_id = (int) self::factory()->user->create();
		$orgs    = Plugin::instance()->container()->get( Org_Repository::class );
		$org_id  = $orgs->create_for_owner( 'Tenant A', $user_id );

		$this->assertIsInt( $org_id );
		$orgs->flush_cache();
		$this->assertSame( array( $org_id ), $orgs->org_ids_for_user( $user_id ) );

		$blog_b = (int) self::factory()->blog->create();

		$this->on_site(
			$blog_b,
			function () use ( $orgs, $user_id, $org_id ): void {
				$orgs->flush_cache();
				$this->assertSame( array(), $orgs->org_ids_for_user( $user_id ) );
				$this->assertNotSame( Post_Types::ORGANIZATION, get_post_type( $org_id ) );
			}
		);
	}

	/**
	 * Site deletion must drop plugin tables core will not drop.
	 *
	 * @return void
	 */
	public function test_uninitialize_site_drops_plugin_tables(): void {
		$this->network_activate();

		$blog_id = (int) self::factory()->blog->create();
		$this->assertSame(
			$this->all_tables( true ),
			$this->plugin_tables_on( $blog_id ),
			'A network-active install did not create every plugin table on the new site.'
		);

		$site = get_site( $blog_id );
		$this->assertInstanceOf( \WP_Site::class, $site );

		Plugin::instance()->container()->get( Site_Lifecycle::class )->uninitialize_site( $site );

		$this->assertSame(
			$this->all_tables( false ),
			$this->plugin_tables_on( $blog_id ),
			'Deleting a site left plugin tables — and the tenant rows in them — behind.'
		);
	}

	/**
	 * Network-activates this plugin. The test bootstrap loads it as an
	 * mu-plugin, which is not the same as active_sitewide_plugins.
	 *
	 * @return void
	 */
	private function network_activate(): void {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$basename = plugin_basename( AGGR_PLUGIN_FILE );
		$result   = activate_plugin( $basename, '', true );

		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( is_plugin_active_for_network( $basename ) );
	}

	/**
	 * Ensures the plugin is not network-active.
	 *
	 * @return void
	 */
	private function network_deactivate(): void {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( plugin_basename( AGGR_PLUGIN_FILE ), true, true );
		$this->assertFalse( is_plugin_active_for_network( plugin_basename( AGGR_PLUGIN_FILE ) ) );
	}

	/**
	 * Which of this plugin's tables exist on a blog, keyed by a readable name.
	 *
	 * This used to ask about `aggr_events` alone and stand in for the rest. That
	 * is a proxy, and a proxy only reports on the thing it proxies: a table
	 * added to `Installer::install()` and forgotten in the uninstaller would
	 * leave one tenant's rows behind on a deleted site with this suite green.
	 * The line-item table was exactly that shape — installed per site, dropped
	 * per site, and asserted by nothing.
	 *
	 * So every table is named, and the assertions compare whole sets. A new
	 * table is one line here, and then it has to satisfy both directions.
	 *
	 * @param int $blog_id Site id.
	 * @return array<string, bool> Table label to existence.
	 */
	private function plugin_tables_on( int $blog_id ): array {
		$found = array();

		$this->on_site(
			$blog_id,
			static function () use ( &$found ): void {
				global $wpdb;

				$tables = array(
					'audit'                => ( new Audit_Repository() )->table_name(),
					'org_access'           => ( new Org_Access_Repository() )->table_name(),
					'events'               => ( new Event_Repository() )->table_name(),
					'rollups'              => ( new Rollup_Repository() )->table_name(),
					'line_items'           => ( new Line_Item_Repository( new Campaign_Repository() ) )->table_name(),
					'creative_assets'      => ( new Creative_Asset_Repository() )->table_name(),
					'creative_assignments' => ( new Creative_Assignment_Repository() )->table_name(),
				);

				$suppress = $wpdb->suppress_errors();

				foreach ( $tables as $label => $table ) {
					// Core makes per-test tables temporary; MySQL omits those from SHOW TABLES.
					$found[ $label ] = (bool) $wpdb->get_results( "DESCRIBE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion must see Core's temporary table.
				}

				$wpdb->suppress_errors( $suppress );
			}
		);

		return $found;
	}

	/**
	 * Every plugin table, mapped to whether it should exist.
	 *
	 * @param bool $expected Expected existence for all of them.
	 * @return array<string, bool>
	 */
	private function all_tables( bool $expected ): array {
		return array(
			'audit'                => $expected,
			'org_access'           => $expected,
			'events'               => $expected,
			'rollups'              => $expected,
			'line_items'           => $expected,
			'creative_assets'      => $expected,
			'creative_assignments' => $expected,
		);
	}

	/**
	 * Runs a callback against another blog's prefix.
	 *
	 * @param int      $blog_id  Target site.
	 * @param callable $callback Work.
	 */
	private function on_site( int $blog_id, callable $callback ): void {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- This is the suite that proves fill never has to.
		switch_to_blog( $blog_id );

		try {
			$callback();
		} finally {
			restore_current_blog();
		}
	}
}
