<?php
/**
 * Phase 0 identity rewrite against a real database.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Upgrade;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Identity_Maps;
use Aggressive\Ads\Install\Identity_Migration;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Install\Upgrader;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Identity_Rewrite;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Workflow\Campaign_Clock;
use WP_UnitTestCase;

/**
 * An existing LAAO-shaped site must become queryable by the new identifiers,
 * a second run must be a no-op, and a missing new option must not look like
 * a fresh install.
 */
final class IdentityRewriteTest extends WP_UnitTestCase {

	/**
	 * Migration under test.
	 *
	 * @var Identity_Migration
	 */
	private Identity_Migration $migration;

	/**
	 * Builds the subject.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->migration = new Identity_Migration( new Identity_Rewrite(), new Private_Storage() );
		delete_option( Installer::OPTION_UPGRADE_LOCK );
	}

	/**
	 * Posts, statuses and meta written under the old names are found under the new ones.
	 *
	 * @return void
	 */
	public function test_rewrites_posts_statuses_and_meta(): void {
		$campaign_id = $this->plant_legacy_campaign();

		update_post_meta( $campaign_id, '_laao_ads_org_id', 99 );

		$this->migration->to_3();
		$this->migration->to_3();

		clean_post_cache( $campaign_id );

		$post = get_post( $campaign_id );

		$this->assertNotNull( $post );
		$this->assertSame( Post_Types::CAMPAIGN, $post->post_type );
		$this->assertSame( Post_Statuses::DRAFT, $post->post_status );
		$this->assertSame( '99', get_post_meta( $campaign_id, '_aggr_org_id', true ) );
		$this->assertSame( '', get_post_meta( $campaign_id, '_laao_ads_org_id', true ) );
	}

	/**
	 * Custom tables are renamed, not copied, so rows survive.
	 *
	 * @return void
	 */
	public function test_renames_custom_tables(): void {
		global $wpdb;

		foreach ( Identity_Maps::tables() as $legacy => $current ) {
			$old = $wpdb->prefix . $legacy;
			$new = $wpdb->prefix . $current;

			$this->assertTrue( $this->table_exists( $new ), $current );
			$this->assert_safe_identifier( $old );
			$this->assert_safe_identifier( $new );
			$this->drop_real_table( $old );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "RENAME TABLE `{$new}` TO `{$old}`" );

			$this->assertFalse( $this->table_exists( $new ), $current );
			$this->assertTrue( $this->table_exists( $old ), $legacy );
		}

		$this->migration->to_3();

		foreach ( Identity_Maps::tables() as $legacy => $current ) {
			$this->assertTrue( $this->table_exists( $wpdb->prefix . $current ), $current );
			$this->assertFalse( $this->table_exists( $wpdb->prefix . $legacy ), $legacy );
		}

		$this->migration->to_3();

		foreach ( Identity_Maps::tables() as $current ) {
			$this->assertTrue( $this->table_exists( $wpdb->prefix . $current ), $current );
		}
	}

	/**
	 * An empty table created under the new name does not trap rows in the old one.
	 *
	 * @return void
	 */
	public function test_renames_over_an_empty_new_table(): void {
		global $wpdb;

		$audit  = new Audit_Repository();
		$marker = 'identity-rewrite-row-survival-' . wp_generate_uuid4();
		$legacy = array_search( Schema::AUDIT_TABLE, Identity_Maps::tables(), true );
		$this->assertIsString( $legacy );

		$old = $wpdb->prefix . $legacy;
		$new = $audit->table_name();

		$audit->insert( new Audit_Event( event: 'plugin.migrated', message: $marker ) );

		$this->drop_real_table( $old );
		$this->assert_safe_identifier( $old );
		$this->assert_safe_identifier( $new );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "RENAME TABLE `{$new}` TO `{$old}`" );

		$audit->install_table();

		$this->assertTrue( $this->table_exists( $old ) );
		$this->assertTrue( $audit->table_exists() );

		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->migration->to_3();
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->assertTrue( $audit->table_exists() );
		$this->assertFalse( $this->table_exists( $old ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE message = %s',
				$new,
				$marker
			)
		);

		$this->assertSame( 1, $found );
	}

	/**
	 * Activating new code against a LAAO database must rewrite, not stamp and skip.
	 *
	 * @return void
	 */
	public function test_install_rewrites_legacy_posts_before_stamping_current_version(): void {
		$campaign_id = $this->plant_legacy_campaign();

		delete_option( Installer::OPTION_DB_VERSION );
		update_option( 'laao_ads_db_version', 2 );

		( new Installer( new Audit_Repository(), new Roles() ) )->install();

		clean_post_cache( $campaign_id );
		$post = get_post( $campaign_id );

		$this->assertNotNull( $post );
		$this->assertSame( Post_Types::CAMPAIGN, $post->post_type );
		$this->assertSame( Post_Statuses::DRAFT, $post->post_status );
		$this->assertSame( Schema::DB_VERSION, (int) get_option( Installer::OPTION_DB_VERSION ) );
	}

	/**
	 * A site that only has the old version option is behind, not uninstalled.
	 *
	 * @return void
	 */
	public function test_legacy_db_version_does_not_look_like_a_fresh_install(): void {
		delete_option( Installer::OPTION_DB_VERSION );
		update_option( 'laao_ads_db_version', 2 );
		update_option( 'laao_ads_plugin_version', '0.0.1' );
		update_option( 'laao_ads_roles_version', 2 );

		$this->assertSame( 2, Installer::stored_db_version() );

		$upgrader = new Upgrader(
			new Installer( new Audit_Repository(), new Roles() ),
			new Audit_Repository(),
			array(
				3 => function (): void {
					$this->migration->to_3();
				},
				4 => static function (): void {
					( new Installer( new Audit_Repository(), new Roles() ) )->install_delivery_tables();
				},
				5 => static function (): void {
					( new Installer( new Audit_Repository(), new Roles() ) )->migrate_event_token_uniqueness();
				},
			)
		);

		$this->assertTrue( $upgrader->needs_work() );

		$upgrader->maybe_upgrade();

		$this->assertSame( Schema::DB_VERSION, (int) get_option( Installer::OPTION_DB_VERSION ) );
		$this->assertFalse( get_option( 'laao_ads_db_version' ) );
		$this->assertSame( AGGR_VERSION, get_option( Installer::OPTION_PLUGIN_VERSION ) );
	}

	/**
	 * Users on the previous advertiser role land on the new slug.
	 *
	 * @return void
	 */
	public function test_rewrites_role_assignments(): void {
		add_role( 'laao_ads_advertiser', 'Advertiser', array( 'read' => true ) );

		$user_id = self::factory()->user->create( array( 'role' => 'laao_ads_advertiser' ) );

		$this->migration->to_3();
		( new Roles() )->install();

		$user = get_userdata( $user_id );

		$this->assertNotFalse( $user );
		$this->assertContains( Roles::ADVERTISER, $user->roles );
		$this->assertNotContains( 'laao_ads_advertiser', $user->roles );
		$this->assertNull( get_role( 'laao_ads_advertiser' ) );
	}

	/**
	 * User meta keys are rewritten with the post meta keys.
	 *
	 * @return void
	 */
	public function test_rewrites_user_meta_keys(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_laao_ads_email_change', 'pending' );

		$this->migration->to_3();

		$this->assertSame( 'pending', get_user_meta( $user_id, '_aggr_email_change', true ) );
		$this->assertSame( '', get_user_meta( $user_id, '_laao_ads_email_change', true ) );
	}

	/**
	 * Unmapped leftover options and transients follow the prefix.
	 *
	 * @return void
	 */
	public function test_rewrites_leftover_options_and_transients(): void {
		add_option( 'laao_ads_rate_limit_signup', '1' );
		set_transient( 'laao_ads_retry_batch', '1', HOUR_IN_SECONDS );

		$this->migration->to_3();

		$this->assertSame( '1', get_option( 'aggr_rate_limit_signup' ) );
		$this->assertFalse( get_option( 'laao_ads_rate_limit_signup' ) );
		$this->assertSame( '1', get_transient( 'aggr_retry_batch' ) );
		$this->assertFalse( get_transient( 'laao_ads_retry_batch' ) );
	}

	/**
	 * Capabilities stored on administrator are copied onto the new names.
	 *
	 * @return void
	 */
	public function test_copies_granted_capabilities_onto_the_new_names(): void {
		add_role( 'identity_cap_probe', 'Probe', array( 'read' => true ) );

		$role = get_role( 'identity_cap_probe' );
		$this->assertNotNull( $role );
		$role->add_cap( 'laao_ads_review_campaigns' );

		add_filter(
			'aggr_roles_receiving_caps',
			static function ( mixed $roles ): array {
				$roles   = is_array( $roles ) ? $roles : array();
				$roles[] = 'identity_cap_probe';

				return $roles;
			}
		);

		$this->migration->to_3();

		$role = get_role( 'identity_cap_probe' );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( Capabilities::REVIEW_CAMPAIGNS ) );
		$this->assertFalse( $role->has_cap( 'laao_ads_review_campaigns' ) );

		remove_role( 'identity_cap_probe' );
	}

	/**
	 * Cron events registered under the previous hook names are cleared.
	 *
	 * @return void
	 */
	public function test_clears_legacy_cron_events(): void {
		$legacy = array_search( Campaign_Clock::HOOK, Identity_Maps::cron_hooks(), true );
		$this->assertIsString( $legacy );

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', $legacy );

		$this->assertNotFalse( wp_next_scheduled( $legacy ) );

		$this->migration->to_3();

		$this->assertFalse( wp_next_scheduled( $legacy ) );
	}

	/**
	 * The private creative directory is renamed with the rest of the identity.
	 *
	 * @return void
	 */
	public function test_promotes_the_private_storage_directory(): void {
		$storage = new Private_Storage();
		$new     = $storage->root();
		$old     = dirname( $new ) . '/' . Private_Storage::LEGACY_DIRECTORY;
		$aside   = $new . '.aside-identity-test';

		if ( is_dir( $new ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename -- Aside of a wp_upload_dir() private root for the duration of this test.
			$this->assertTrue( rename( $new, $aside ) );
		}

		try {
			wp_mkdir_p( $old );
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Fixture file under the uploads-based private root this test creates.
			$this->assertNotFalse( file_put_contents( $old . '/keep.txt', 'keep' ) );

			$this->migration->to_3();

			$this->assertTrue( is_dir( $new ) );
			$this->assertFalse( is_dir( $old ) );
			$this->assertFileExists( $new . '/keep.txt' );
		} finally {
			if ( is_file( $new . '/keep.txt' ) ) {
				wp_delete_file( $new . '/keep.txt' );
			}

			if ( is_dir( $aside ) ) {
				if ( is_dir( $new ) ) {
					$this->remove_directory( $new );
				}

				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename -- Restores the uploads-based private root this test moved aside.
				rename( $aside, $new );
			} elseif ( is_dir( $new ) ) {
				$this->remove_directory( $new );
			}

			if ( is_dir( $old ) ) {
				$this->remove_directory( $old );
			}
		}
	}

	/**
	 * Fresh install never creates the previous post types.
	 *
	 * @return void
	 */
	public function test_fresh_install_does_not_create_legacy_post_types(): void {
		global $wpdb;

		foreach ( array_keys( Identity_Maps::post_types() ) as $legacy ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
					$legacy
				)
			);

			$this->assertSame( 0, $count, "Fresh install wrote {$legacy}." );
		}
	}

	/**
	 * Drops a real table, not a temporary one.
	 *
	 * The core test case rewrites DROP TABLE into DROP TEMPORARY TABLE so
	 * fixtures roll back with the transaction. That rewrite cannot touch a
	 * leftover identity-migration table, which is a real table created by
	 * RENAME — DDL that already committed.
	 *
	 * @param string $table Fully prefixed table name.
	 * @return void
	 */
	private function drop_real_table( string $table ): void {
		global $wpdb;

		$this->assert_safe_identifier( $table );

		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Writes a campaign, then forces the previous post type and status onto it.
	 *
	 * @return int
	 */
	private function plant_legacy_campaign(): int {
		global $wpdb;

		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
				'post_title'  => 'Identity rewrite',
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture must be old-shaped; wp_insert_post would write the new names.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_type'   => 'laao_ads_campaign',
				'post_status' => 'lap_draft',
			),
			array( 'ID' => $campaign_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return $campaign_id;
	}

	/**
	 * Recursively removes a directory created for a fixture.
	 *
	 * @param string $directory Absolute path.
	 * @return void
	 */
	private function remove_directory( string $directory ): void {
		$files = scandir( $directory );

		if ( is_array( $files ) ) {
			foreach ( $files as $base ) {
				if ( '.' === $base || '..' === $base ) {
					continue;
				}

				$path = $directory . '/' . $base;

				if ( is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
		}

		if ( is_dir( $directory ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir -- Test fixture teardown of a directory this test created.
			rmdir( $directory );
		}
	}

	/**
	 * Whether a fully prefixed table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Asserts a table name is safe to interpolate.
	 *
	 * @param string $identifier Table name.
	 * @return void
	 */
	private function assert_safe_identifier( string $identifier ): void {
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_]+$/', $identifier );
	}
}
