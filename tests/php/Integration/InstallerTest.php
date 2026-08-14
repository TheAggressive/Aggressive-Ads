<?php
/**
 * Installation against a real database.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Org_Access_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * These assertions need real MySQL: dbDelta's idempotence depends on the
 * server's own type normalization, and "roles survived" is by definition about
 * real wp_options state. Neither is expressible against a mock, which is why
 * this suite exists at all.
 */
final class InstallerTest extends WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var Audit_Repository
	 */
	private Audit_Repository $audit;

	/**
	 * Organization identity and access persistence.
	 *
	 * @var Org_Access_Repository
	 */
	private Org_Access_Repository $org_access;

	/**
	 * Installer under test.
	 *
	 * @var Installer
	 */
	private Installer $installer;

	/**
	 * Builds the subjects.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->audit      = new Audit_Repository();
		$this->org_access = new Org_Access_Repository();
		$this->installer  = new Installer( $this->audit, new Roles() );
	}

	/**
	 * The bootstrap installs, so the table is there.
	 *
	 * @return void
	 */
	public function test_the_audit_table_exists(): void {
		$this->assertTrue( $this->audit->table_exists() );
		$this->assertTrue( $this->org_access->table_exists() );
		$this->assertTrue( ( new Event_Repository() )->table_exists() );
		$this->assertTrue( ( new Rollup_Repository() )->table_exists() );
	}

	/** The organization access table has every declared column and index. */
	public function test_the_organization_access_table_matches_the_schema(): void {
		global $wpdb;

		$table = $this->org_access->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration schema assertion.
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		sort( $columns );

		$declared_columns = Schema::org_access_columns();
		sort( $declared_columns );
		$this->assertSame( $declared_columns, $columns );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration schema assertion.
		$rows    = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		$indexes = array_values( array_unique( array_column( $rows, 'Key_name' ) ) );

		$this->assertSame( Schema::org_access_index_names(), $indexes );
	}

	/** Native fill tables match the declared schema. */
	public function test_the_delivery_tables_match_the_schema(): void {
		global $wpdb;

		$events  = new Event_Repository();
		$rollups = new Rollup_Repository();

		$this->installer->install_delivery_tables();

		foreach (
			array(
				array( $events->table_name(), Schema::events_columns(), Schema::events_index_names() ),
				array( $rollups->table_name(), Schema::rollups_columns(), Schema::rollups_index_names() ),
			) as $spec
		) {
			[ $table, $declared_columns, $declared_indexes ] = $spec;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration schema assertion.
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
			sort( $columns );
			sort( $declared_columns );
			$this->assertSame( $declared_columns, $columns );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration schema assertion.
			$rows    = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
			$indexes = array_values( array_unique( array_column( $rows, 'Key_name' ) ) );
			$this->assertSame( $declared_indexes, $indexes );
		}
	}

	/**
	 * WordPress dbDelta will not drop the v4 unique; the walker must.
	 *
	 * @return void
	 */
	public function test_event_token_uniqueness_migration_replaces_the_v4_index(): void {
		global $wpdb;

		$events = new Event_Repository();
		$table  = $events->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rebuilding this plugin's table as the v4 shape.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Installing the v4 unique so the migration has something to drop.
		$wpdb->query(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at_ts bigint(20) unsigned NOT NULL DEFAULT 0,
				event varchar(16) NOT NULL DEFAULT '',
				placement_id bigint(20) unsigned NOT NULL DEFAULT 0,
				campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
				creative_id bigint(20) unsigned NOT NULL DEFAULT 0,
				token_hash char(64) NOT NULL DEFAULT '',
				ip_hash char(64) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY created (created_at_ts,id),
				KEY campaign_day (campaign_id,created_at_ts,id)
			) {$wpdb->get_charset_collate()}"
		);

		$this->installer->migrate_event_token_uniqueness();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration schema assertion.
		$rows    = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		$indexes = array_values( array_unique( array_column( $rows, 'Key_name' ) ) );

		$this->assertContains( 'token_event', $indexes );
		$this->assertNotContains( 'token_hash', $indexes );
	}

	/**
	 * Every declared column exists on the real table, with no extras.
	 *
	 * @return void
	 */
	public function test_the_table_carries_exactly_the_declared_columns(): void {
		global $wpdb;

		$table = $this->audit->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

		sort( $columns );

		$declared = Schema::audit_columns();
		sort( $declared );

		$this->assertSame( $declared, $columns );
	}

	/**
	 * Every declared index exists on the real table.
	 *
	 * A missing index does not break a query, it makes it slow — invisible
	 * until the table is large, and by then nobody connects the two.
	 *
	 * @return void
	 */
	public function test_every_declared_index_exists(): void {
		$this->assertSame( Schema::audit_index_names(), $this->live_index_names() );
	}

	/**
	 * Installing twice changes nothing.
	 *
	 * This is the assertion dbDelta formatting exists to satisfy, and the one
	 * that cannot be faked: if a field type does not match what MySQL reports
	 * back, dbDelta re-applies an ALTER on every request forever, and the only
	 * way to see it is to run it twice against a real server.
	 *
	 * @return void
	 */
	public function test_installing_twice_is_idempotent(): void {
		$before = $this->live_index_names();

		$this->installer->install();
		$this->installer->install();

		$this->assertSame( $before, $this->live_index_names() );
		$this->assertTrue( $this->audit->table_exists() );
	}

	/**
	 * Both custom roles exist after install.
	 *
	 * @return void
	 */
	public function test_both_roles_are_created(): void {
		$this->installer->install_roles();

		$this->assertNotNull( get_role( Roles::ADVERTISER ) );
		$this->assertNotNull( get_role( Roles::REVIEWER ) );
	}

	/**
	 * The advertiser role carries exactly the declared matrix — no more.
	 *
	 * @return void
	 */
	public function test_the_advertiser_role_matches_the_declared_matrix(): void {
		$this->installer->install_roles();

		$role = get_role( Roles::ADVERTISER );

		$this->assertNotNull( $role );

		$granted  = array_keys( array_filter( $role->capabilities ) );
		$declared = array_keys( Roles::definitions()[ Roles::ADVERTISER ]['capabilities'] );

		sort( $granted );
		sort( $declared );

		$this->assertSame( $declared, $granted );
	}

	/**
	 * A real advertiser user holds none of the dangerous core capabilities.
	 *
	 * The unit suite asserts the declared matrix; this asserts what WordPress
	 * actually resolves for a user, which is the thing that matters.
	 *
	 * @return void
	 */
	public function test_a_real_advertiser_cannot_upload_or_edit_content(): void {
		$this->installer->install_roles();

		$user_id = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		wp_set_current_user( $user_id );

		$this->assertFalse( current_user_can( 'upload_files' ) );
		$this->assertFalse( current_user_can( 'edit_posts' ) );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$this->assertFalse( current_user_can( 'manage_options' ) );

		$this->assertTrue( current_user_can( Capabilities::ACCESS_PORTAL ) );
		$this->assertTrue( current_user_can( Capabilities::SUBMIT_CAMPAIGN ) );
	}

	/**
	 * An advertiser cannot review or publish. Both are staff decisions, and
	 * publishing writes to a public site.
	 *
	 * @return void
	 */
	public function test_a_real_advertiser_cannot_review_or_publish(): void {
		$this->installer->install_roles();

		$user_id = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		wp_set_current_user( $user_id );

		$this->assertFalse( current_user_can( Capabilities::REVIEW_CAMPAIGNS ) );
		$this->assertFalse( current_user_can( Capabilities::PUBLISH_TO_ADSANITY ) );
	}

	/**
	 * Administrators receive every capability the plugin defines.
	 *
	 * @return void
	 */
	public function test_administrators_receive_every_capability(): void {
		$this->installer->install_roles();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		foreach ( Capabilities::all() as $cap ) {
			$this->assertTrue( current_user_can( $cap ), "Administrator lacks {$cap}" );
		}
	}

	/**
	 * Editors receive nothing. The plugin adds capabilities to administrators
	 * only, and a site's existing roles are not quietly widened.
	 *
	 * @return void
	 */
	public function test_editors_receive_nothing(): void {
		$this->installer->install_roles();

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		foreach ( Capabilities::primitives() as $cap ) {
			$this->assertFalse( current_user_can( $cap ), "Editor unexpectedly holds {$cap}" );
		}
	}

	/**
	 * Re-installing revokes a capability that was removed from the matrix.
	 *
	 * Calling add_role() on an existing role is a no-op, so an implementation
	 * that does not remove first can add capabilities but never take one away —
	 * and a
	 * revoked capability that stays granted is a security regression that no
	 * amount of matrix editing fixes.
	 *
	 * @return void
	 */
	public function test_reinstalling_revokes_a_capability_added_out_of_band(): void {
		$this->installer->install_roles();

		$role = get_role( Roles::ADVERTISER );
		$this->assertNotNull( $role );

		$role->add_cap( 'manage_options' );
		$this->assertTrue( get_role( Roles::ADVERTISER )?->has_cap( 'manage_options' ) ?? false );

		$this->installer->install_roles();

		$this->assertFalse( get_role( Roles::ADVERTISER )?->has_cap( 'manage_options' ) ?? true );
	}

	/**
	 * Version options are stamped, since the upgrader reads them on every
	 * request to decide whether to do anything.
	 *
	 * @return void
	 */
	public function test_version_options_are_stamped(): void {
		$this->installer->install();

		$this->assertSame( Schema::DB_VERSION, (int) get_option( Installer::OPTION_DB_VERSION ) );
		$this->assertSame( Roles::VERSION, (int) get_option( Installer::OPTION_ROLES_VERSION ) );
		$this->assertSame( AGGR_VERSION, get_option( Installer::OPTION_PLUGIN_VERSION ) );
	}

	/**
	 * Installing writes an audit row, so the history starts at install rather
	 * than at first use.
	 *
	 * @return void
	 */
	public function test_installing_records_an_audit_row(): void {
		global $wpdb;

		$table = $this->audit->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE event = 'plugin.installed'" );

		$this->installer->install();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE event = 'plugin.installed'" );

		$this->assertSame( $before + 1, $after );
	}

	/**
	 * The index names actually present on the table, ordered as declared.
	 *
	 * @return array<int, string>
	 */
	private function live_index_names(): array {
		global $wpdb;

		$table = $this->audit->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );

		$names = array();

		foreach ( $rows as $row ) {
			$name = (string) $row['Key_name'];

			if ( ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}

		return $names;
	}
}
