<?php
/**
 * Native fill events.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Install\Schema;

/**
 * The only code that touches aggr_events.
 */
final class Event_Repository {

	public const TYPE_IMPRESSION = 'impression';
	public const TYPE_CLICK      = 'click';

	/**
	 * Fully prefixed table name.
	 */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . Schema::EVENTS_TABLE;
	}

	/**
	 * Creates or updates the events table.
	 */
	public function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( Schema::events_table_ddl( $this->table_name(), $wpdb->get_charset_collate() ) );
		$this->drop_legacy_token_hash_index();
	}

	/**
	 * Whether the events table exists.
	 */
	public function table_exists(): bool {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection on a custom table; caching existence is how a failed install looks healthy.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Drops the events table. Uninstall only.
	 */
	public function drop_table(): void {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping this plugin's own table on uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * One fill may impression and click; the same event may not replay.
	 *
	 * WordPress dbDelta will add token_event but will not drop the v4 token_hash unique.
	 */
	public function migrate_token_event_unique(): void {
		$this->install_table();
	}

	/**
	 * WordPress dbDelta will add token_event but will not drop the v4 token_hash unique.
	 */
	private function drop_legacy_token_hash_index(): void {
		global $wpdb;

		$table = $this->table_name();

		if ( ! $this->table_exists() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection on this plugin's table.
		$rows  = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		$names = is_array( $rows ) ? array_values( array_unique( array_column( $rows, 'Key_name' ) ) ) : array();

		if ( in_array( 'token_hash', $names, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping the v4 unique so (token_hash, event) can be unique instead.
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX token_hash" );
		}
	}

	/**
	 * Records one impression or click. Duplicate (token_hash, event) returns false.
	 *
	 * @param string $type         impression|click.
	 * @param int    $placement_id Placement id.
	 * @param int    $campaign_id  Campaign id, or 0 for house.
	 * @param int    $creative_id  Creative id, or 0 for house.
	 * @param string $token_hash   64-char hex digest.
	 * @param string $ip_hash      64-char hex digest.
	 */
	public function insert( string $type, int $placement_id, int $campaign_id, int $creative_id, string $token_hash, string $ip_hash ): bool {
		global $wpdb;

		if ( ! in_array( $type, array( self::TYPE_IMPRESSION, self::TYPE_CLICK ), true ) ) {
			return false;
		}

		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $token_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $ip_hash ) ) {
			return false;
		}

		$was_suppressing = $wpdb->suppress_errors( true );

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; $wpdb->insert with a format array is the write path. Duplicate (token_hash, event) is the replay refusal.
			$written = $wpdb->insert(
				$this->table_name(),
				array(
					'created_at_ts' => time(),
					'event'         => $type,
					'placement_id'  => $placement_id,
					'campaign_id'   => $campaign_id,
					'creative_id'   => $creative_id,
					'token_hash'    => $token_hash,
					'ip_hash'       => $ip_hash,
				),
				array( '%d', '%s', '%d', '%d', '%d', '%s', '%s' )
			);
		} finally {
			$wpdb->suppress_errors( $was_suppressing );
		}

		return false !== $written;
	}

	/**
	 * Whether an exact event digest already exists.
	 *
	 * Used only to distinguish an expected replay from an infrastructure write
	 * failure after insert() returns false.
	 *
	 * @param string $type       impression|click.
	 * @param string $token_hash 64-char digest.
	 */
	public function exists( string $type, string $token_hash ): bool {
		global $wpdb;

		if ( ! in_array( $type, array( self::TYPE_IMPRESSION, self::TYPE_CLICK ), true ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $token_hash ) ) {
			return false;
		}

		$table = $this->table_name();

		$was_suppressing = $wpdb->suppress_errors( true );

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact unique-key diagnostic after a failed ledger insert.
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE token_hash = %s AND event = %s LIMIT 1", $token_hash, $type ) );
		} finally {
			$wpdb->suppress_errors( $was_suppressing );
		}

		return is_numeric( $id ) && (int) $id > 0;
	}

	/**
	 * Oldest event day in a closed UTC range.
	 *
	 * @param int $after_ts  Inclusive lower Unix timestamp.
	 * @param int $before_ts Exclusive upper Unix timestamp.
	 */
	public function earliest_day_between( int $after_ts, int $before_ts ): ?string {
		global $wpdb;

		if ( $after_ts < 0 || $before_ts <= $after_ts ) {
			return null;
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Indexed range lookup for the rollup reconciliation watermark.
		$timestamp = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(created_at_ts) FROM {$table} WHERE created_at_ts >= %d AND created_at_ts < %d", $after_ts, $before_ts ) );

		return is_numeric( $timestamp ) && (int) $timestamp > 0 ? gmdate( 'Y-m-d', (int) $timestamp ) : null;
	}

	/**
	 * Deletes events older than the retention cutoff.
	 *
	 * @param int $before_ts UTC Unix seconds.
	 * @param int $limit     Maximum rows removed by one statement.
	 * @return int Rows deleted.
	 */
	public function purge_before( int $before_ts, int $limit = 10_000 ): int {
		global $wpdb;

		if ( $before_ts <= 0 || $limit < 1 || $limit > 100_000 ) {
			return 0;
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bounded retention delete on this plugin's table; the name is prefix + constant.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at_ts < %d ORDER BY id ASC LIMIT %d", $before_ts, $limit ) );

		return is_int( $deleted ) ? $deleted : 0;
	}
}
