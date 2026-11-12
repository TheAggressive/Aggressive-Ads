<?php
/**
 * Per-site report preferences, delivery state, and serialization locks.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Install\Schema_Report_Subscriptions;

/** No recipient address or report body is stored in the scheduling table. */
final class Report_Subscription_Repository {

	/**
	 * Prevent reentrant acquisition on the same connection.
	 *
	 * @var array<string, true>
	 */
	private static array $held = array();

	/** Current site's table, including after a multisite switch. */
	public function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::REPORT_SUBSCRIPTIONS_TABLE;
	}

	/**
	 * Additive schema install and repair.
	 *
	 * @throws \RuntimeException When installation cannot establish the required schema.
	 */
	public function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( Schema_Report_Subscriptions::ddl( $this->table_name(), $wpdb->get_charset_collate() ) );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Could not install the report subscription schema.' );
		}
		// Do not let the upgrader stamp completion after a refused or partial CREATE.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded introspection verifies required columns before migration progress advances.
		$columns = $wpdb->get_col( $wpdb->prepare( 'DESCRIBE %i', $this->table_name() ) );
		if ( array_diff( Schema_Report_Subscriptions::columns(), $columns ) ) {
			throw new \RuntimeException( 'Could not install the report subscription schema.' );
		}
	}

	/** Schema existence check. */
	public function table_exists(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Installation introspection of this site's own table.
		return $this->table_name() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $this->table_name() ) ) );
	}

	/** Uninstall/site deletion only. */
	public function drop_table(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Only the repository-owned per-site table is dropped.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->table_name() ) );
	}

	/**
	 * Fresh subscription lookup; SQL errors are not an Off preference.
	 *
	 * @param int $org_id Organization id.
	 * @param int $user_id Current account id.
	 * @return array<string, mixed>|\WP_Error|null
	 */
	public function for_member( int $org_id, int $user_id ): array|\WP_Error|null {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh state is required for revocation and retry decisions.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE org_id=%d AND user_id=%d', $this->table_name(), $org_id, $user_id ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error( 'aggr_report_schedule_unavailable', __( 'Report preferences could not be loaded. Please try again.', 'aggressive-ads' ) );
		}

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Worker lookup; a missing/unavailable record cannot authorize a send.
	 *
	 * @param int $id Subscription id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uncached worker state under the member lock.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $this->table_name(), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Save an authorized preference under its member lock; invalidate old work.
	 *
	 * @param int    $org_id Organization id.
	 * @param int    $user_id Subscriber id.
	 * @param string $cadence Validated cadence, or off.
	 * @param string $zone Site timezone.
	 * @param int    $due Next future release.
	 */
	public function save( int $org_id, int $user_id, string $cadence, string $zone, int $due ): bool {
		global $wpdb;
		$enabled = 'off' !== $cadence;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic preference replacement; the unique tenant/account key prevents duplicate subscriptions.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (org_id,user_id,cadence,timezone,enabled,due_at,state)
			VALUES (%d,%d,%s,%s,%d,%d,%s)
			ON DUPLICATE KEY UPDATE cadence=VALUES(cadence),timezone=VALUES(timezone),enabled=VALUES(enabled),due_at=VALUES(due_at),
			state=VALUES(state),period_start='',period_end='',attempts=0,last_error='',revision=revision+1",
				$this->table_name(),
				$org_id,
				$user_id,
				$cadence,
				$zone,
				$enabled ? 1 : 0,
				$enabled ? $due : 0,
				$enabled ? 'idle' : 'disabled'
			)
		);

		return false !== $result;
	}

	/**
	 * Indexed, bounded candidates; the worker rechecks state under a lock.
	 *
	 * @param int $now Current UTC timestamp.
	 * @param int $limit Maximum candidates in this pass.
	 * @return list<int>
	 */
	public function due_ids( int $now, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed due cursor, fixed maximum batch, no report data selected.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE enabled=1 AND due_at<=%d ORDER BY due_at,id LIMIT %d', $this->table_name(), $now, max( 1, min( 20, $limit ) ) ) );
		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Compare-and-set one job transition. Zero affected rows never permits sending.
	 *
	 * @param array<string, mixed> $row Locked subscription snapshot.
	 * @param array<string, mixed> $fields Server-owned transition fields.
	 */
	public function transition( array $row, array $fields ): bool {
		global $wpdb;
		$allowed = array( 'due_at', 'state', 'period_start', 'period_end', 'attempts', 'last_queued_at', 'last_error', 'enabled', 'timezone' );
		if ( array_diff( array_keys( $fields ), $allowed ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic expected-state transition under the member advisory lock.
		return 1 === $wpdb->update(
			$this->table_name(),
			$fields,
			array(
				'id'       => (int) $row['id'],
				'revision' => (int) $row['revision'],
				'state'    => (string) $row['state'],
				'enabled'  => (int) $row['enabled'],
			)
		);
	}

	/**
	 * One worker per site; rows also lock independently against preferences.
	 *
	 * @param string $scope Worker or a server-derived member key.
	 */
	public function acquire( string $scope ): bool {
		global $wpdb;
		$name = $this->lock_name( $scope );
		if ( isset( self::$held[ $name ] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Nonblocking session lock; closed on process/connection exit.
		$acquired = 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		if ( $acquired ) {
			self::$held[ $name ] = true;
		}
		return $acquired;
	}

	/**
	 * Release only a lock acquired by this request.
	 *
	 * @param string $scope Same server-derived scope passed to acquire().
	 */
	public function release( string $scope ): void {
		global $wpdb;
		$name = $this->lock_name( $scope );
		if ( ! isset( self::$held[ $name ] ) ) {
			return;
		}
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases exactly this request's session lock.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		} finally {
			unset( self::$held[ $name ] );
		}
	}

	/**
	 * Bounded lock name with publisher-site isolation.
	 *
	 * @param string $scope Server-derived scope, never request input.
	 */
	private function lock_name( string $scope ): string {
		global $wpdb;
		// Named locks share a server namespace across databases and installations.
		$identity = $wpdb->dbname . ':' . $this->table_name() . ':' . get_current_blog_id() . ':' . $scope;
		return 'aggr_report_' . substr( hash( 'sha256', $identity ), 0, 48 );
	}

	/**
	 * Remove account or organization data on its authoritative deletion hook.
	 *
	 * @param string $column Allowlisted identity column.
	 * @param int    $id Deleted id.
	 */
	public function delete_for( string $column, int $id ): void {
		if ( $id <= 0 || ! in_array( $column, array( 'user_id', 'org_id' ), true ) || ! $this->table_exists() ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lifecycle deletion limited by the deleted account/org id.
		$wpdb->delete( $this->table_name(), array( $column => $id ) );
	}
}
