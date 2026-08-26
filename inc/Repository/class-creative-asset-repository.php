<?php
/**
 * Persistence for creative assets.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Install\Schema;

/**
 * Identity only. Bytes, click URL and alternative text belong to the revision — see docs/platform-p2-creative-model.md.
 */
final class Creative_Asset_Repository {

	/**
	 * Cached table-existence answer, or null when not yet asked.
	 *
	 * @var bool|null
	 */
	private ?bool $table_exists = null;

	/** Prefixed table name, scoped to the current site. */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aggr_creative_assets';
	}

	/** Creates or repairs the table. */
	public function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( Schema::creative_assets_table_ddl( $this->table_name(), $wpdb->get_charset_collate() ) );
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
	 * Finds or creates the asset for a chain root.
	 *
	 * Keyed on the root creative id so that every revision of one piece of
	 * artwork resolves to the same asset however many times this runs, and
	 * whichever revision asks. That is what makes the backfill and lazy
	 * self-healing safe to run concurrently.
	 *
	 * @param int    $root_creative_id Oldest creative in the replacement chain.
	 * @param int    $organization_id  Owning tenant.
	 * @param string $name             Advertiser-facing label.
	 * @return int Asset id, or 0 when it could not be created.
	 */
	public function ensure_for_root( int $root_creative_id, int $organization_id, string $name ): int {
		if ( $root_creative_id <= 0 || ! $this->table_exists() ) {
			return 0;
		}

		global $wpdb;
		$table   = $this->table_name();
		$blog_id = get_current_blog_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read owned by this repository.
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE root_creative_id = %d AND blog_id = %d LIMIT 1', $table, $root_creative_id, $blog_id )
		);

		if ( $existing > 0 ) {
			return $existing;
		}

		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom-table persistence with an explicit format list.
		$wpdb->insert(
			$table,
			array(
				'root_creative_id' => $root_creative_id,
				'organization_id'  => $organization_id,
				'blog_id'          => $blog_id,
				'name'             => mb_substr( $name, 0, 191 ),
				'created_at_ts'    => $now,
				'updated_at_ts'    => $now,
			),
			array( '%d', '%d', '%d', '%s', '%d', '%d' )
		);

		if ( $wpdb->insert_id > 0 ) {
			return (int) $wpdb->insert_id;
		}

		/*
		 * The unique key refused it, which means another request won the race.
		 * Read the winner rather than reporting failure — a duplicate-key
		 * refusal here is the concurrency control working, not an error.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read owned by this repository.
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE root_creative_id = %d AND blog_id = %d LIMIT 1', $table, $root_creative_id, $blog_id )
		);
	}

	/**
	 * The columns this table declares.
	 *
	 * @return array<int, string>
	 */
	public function columns(): array {
		return Schema::creative_assets_columns();
	}
}
