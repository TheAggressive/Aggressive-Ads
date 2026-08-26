<?php
/**
 * Persistence for creative assignments.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Install\Schema;

/**
 * The serving-path table. Its delivery columns are denormalized from an immutable revision at approval, which is why they cannot drift.
 */
final class Creative_Assignment_Repository {

	/**
	 * Cached table-existence answer, or null when not yet asked.
	 *
	 * @var bool|null
	 */
	private ?bool $table_exists = null;

	/** Prefixed table name, scoped to the current site. */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aggr_creative_assignments';
	}

	/** Creates or repairs the table. */
	public function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( Schema::creative_assignments_table_ddl( $this->table_name(), $wpdb->get_charset_collate() ) );
		$this->table_exists = null;
	}

	/** Whether the table exists. */
	public function table_exists(): bool {
		if ( null !== $this->table_exists ) {
			return $this->table_exists;
		}

		global $wpdb;
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table schema introspection.
		$this->table_exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $this->table_exists;
	}

	/** Drops the table during a destructive uninstall. */
	public function drop_table(): void {
		global $wpdb;
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall of this repository's fixed table.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		$this->table_exists = false;
	}

	/**
	 * The columns this table declares.
	 *
	 * @return array<int, string>
	 */
	public function columns(): array {
		return Schema::creative_assignments_columns();
	}
}
