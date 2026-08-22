<?php
/**
 * The db-9 widening of the audit object index.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Upgrade;

use Aggressive\Ads\Repository\Audit_Repository;
use WP_UnitTestCase;

/**
 * The for_object() read filters on org_id as well as the object, so the index
 * has to carry it. Without that the optimizer index-merges and filesorts, which is
 * invisible until the table is large.
 */
final class AuditObjectIndexTest extends WP_UnitTestCase {

	/**
	 * Audit persistence under test.
	 *
	 * @var Audit_Repository
	 */
	private Audit_Repository $audit;

	/**
	 * Starts from a genuinely new table.
	 *
	 * Dropped rather than merely installed: the suite's database keeps whatever
	 * shape earlier runs left, and dbDelta will not change the columns of an
	 * index that already exists. Without the drop, "a fresh install" asserted
	 * against a table that was not fresh — and failed, which is how this
	 * comment came to exist.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->audit = new Audit_Repository();
		$this->audit->drop_table();
		$this->audit->install_table();
	}

	/**
	 * Columns of one index, in order.
	 *
	 * @param string $name Index name.
	 * @return array<int, string>
	 */
	private function index_columns( string $name ): array {
		global $wpdb;

		$table = $this->audit->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection in a schema test.
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );

		$columns = array();

		foreach ( (array) $rows as $row ) {
			if ( isset( $row['Key_name'] ) && $name === $row['Key_name'] ) {
				$columns[ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
			}
		}

		ksort( $columns );

		return array_values( $columns );
	}

	/**
	 * A freshly installed table already carries the widened index.
	 *
	 * @return void
	 */
	public function test_a_fresh_install_indexes_org_id(): void {
		$this->assertSame(
			array( 'object_type', 'object_id', 'org_id', 'id' ),
			$this->index_columns( 'object' )
		);
	}

	/**
	 * A pre-9 table is rebuilt.
	 *
	 * The old index is recreated by hand first, because dbDelta adds indexes
	 * but never changes the columns of one that already exists — which is the
	 * whole reason the migration drops it explicitly.
	 *
	 * @return void
	 */
	public function test_a_pre_nine_index_is_widened(): void {
		global $wpdb;

		$table = $this->audit->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Recreating the pre-9 shape this migration exists to replace.
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX object" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Recreating the pre-9 shape this migration exists to replace.
		$wpdb->query( "ALTER TABLE {$table} ADD KEY object (object_type,object_id,id)" );

		$this->assertSame(
			array( 'object_type', 'object_id', 'id' ),
			$this->index_columns( 'object' ),
			'Fixture must really be the old shape before the migration runs.'
		);

		$this->audit->migrate_object_index();

		$this->assertSame(
			array( 'object_type', 'object_id', 'org_id', 'id' ),
			$this->index_columns( 'object' )
		);
	}

	/**
	 * Running it again changes nothing.
	 *
	 * @return void
	 */
	public function test_the_migration_is_idempotent(): void {
		$this->audit->migrate_object_index();
		$this->audit->migrate_object_index();

		$this->assertSame(
			array( 'object_type', 'object_id', 'org_id', 'id' ),
			$this->index_columns( 'object' )
		);
	}
}
