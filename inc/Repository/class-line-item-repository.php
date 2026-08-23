<?php
/**
 * Campaign line-item persistence.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Line_Item_Rules;
use Aggressive\Ads\Install\Schema;

/** The only code that reads or writes aggr_line_items. */
final class Line_Item_Repository {
	/**
	 * Request-local table existence cache.
	 *
	 * @var bool|null
	 */
	private ?bool $table_exists = null;

	/**
	 * Builds the repository.
	 *
	 * @param Campaign_Repository $campaigns Campaign persistence.
	 */
	public function __construct( private readonly Campaign_Repository $campaigns ) {
	}

	/** Returns the fully prefixed table name. */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . Schema::LINE_ITEMS_TABLE;
	}

	/** Creates or repairs the line-item table. */
	public function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( Schema::line_items_table_ddl( $this->table_name(), $wpdb->get_charset_collate() ) );
		$this->table_exists = null;
	}

	/** Whether the line-item table exists. */
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

	/** Drops the line-item table during a destructive uninstall. */
	public function drop_table(): void {
		global $wpdb;
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall of this repository's fixed table.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		$this->table_exists = false;
	}

	/**
	 * Deletes every delivery strategy owned by a deleted campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 */
	public function delete_for_campaign( int $campaign_id ): void {
		global $wpdb;
		if ( $campaign_id <= 0 || ! $this->table_exists() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table ownership cleanup.
		$wpdb->delete( $this->table_name(), array( 'campaign_id' => $campaign_id ), array( '%d' ) );
	}

	/**
	 * Returns a campaign's compatibility line item, creating it atomically when
	 * an interrupted background migration has not reached that campaign yet.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<string, mixed>|null
	 */
	public function ensure_default( int $campaign_id ): ?array {
		$current = $this->default_for_campaign( $campaign_id );
		if ( null !== $current ) {
			return $current;
		}

		if ( ! $this->campaigns->exists( $campaign_id ) || ! $this->table_exists() ) {
			return null;
		}

		global $wpdb;
		$now             = time();
		$was_suppressing = $wpdb->suppress_errors( true );

		try {
			// The unique (campaign_id, default_key) key turns concurrent lazy
			// creation into one winner and one harmless duplicate-key refusal.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom-table persistence with an explicit format list.
			$wpdb->insert(
				$this->table_name(),
				array(
					'campaign_id'       => $campaign_id,
					'organization_id'   => $this->campaigns->org_id( $campaign_id ),
					'name'              => $this->default_name( $campaign_id ),
					'status'            => Line_Item_Rules::status_for_campaign( $this->campaigns->status( $campaign_id ) ),
					'start_at_ts'       => $this->campaigns->start_ts( $campaign_id ),
					'end_at_ts'         => $this->campaigns->end_ts( $campaign_id ),
					'pricing_model'     => 'flat',
					'goal_type'         => 'none',
					'goal_amount'       => 0,
					'budget_cents'      => $this->campaigns->budget_cents( $campaign_id ),
					'daily_cap'         => 0,
					'lifetime_cap'      => 0,
					'priority'          => 100,
					'pacing_mode'       => 'even',
					'weight'            => 100,
					'targeting_rules'   => '{}',
					'frequency_policy'  => '{}',
					'delivery_settings' => '{}',
					'revision'          => 1,
					'default_key'       => 1,
					'created_at_ts'     => $now,
					'updated_at_ts'     => $now,
				),
				array( '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d' )
			);
		} finally {
			$wpdb->suppress_errors( $was_suppressing );
		}

		return $this->default_for_campaign( $campaign_id );
	}

	/**
	 * Returns a campaign's default line item without creating one.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<string, mixed>|null
	 */
	public function default_for_campaign( int $campaign_id ): ?array {
		global $wpdb;
		if ( $campaign_id <= 0 || ! $this->table_exists() ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact unique-key lookup in custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id = %d AND default_key = 1 LIMIT 1', $this->table_name(), $campaign_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_row( $row ) : null;
	}

	/**
	 * Returns all line items for a campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_campaign( int $campaign_id ): array {
		global $wpdb;
		if ( $campaign_id <= 0 || ! $this->table_exists() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed campaign lookup in custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id = %d ORDER BY id ASC', $this->table_name(), $campaign_id ), ARRAY_A );

		return is_array( $rows ) ? array_map( array( $this, 'normalize_row' ), $rows ) : array();
	}

	/**
	 * Atomically updates an editable delivery strategy.
	 *
	 * @param int                       $id                Line-item id.
	 * @param int                       $campaign_id       Parent campaign id.
	 * @param array<string, int|string> $fields Validated fields.
	 * @param int                       $expected_revision Last-seen revision.
	 * @return int|false New revision or false on conflict/write failure.
	 */
	public function update( int $id, int $campaign_id, array $fields, int $expected_revision ): int|false {
		global $wpdb;

		$formats = array(
			'name'          => '%s',
			'pricing_model' => '%s',
			'goal_type'     => '%s',
			'goal_amount'   => '%d',
			'budget_cents'  => '%d',
			'daily_cap'     => '%d',
			'lifetime_cap'  => '%d',
			'priority'      => '%d',
			'pacing_mode'   => '%s',
			'weight'        => '%d',
		);
		$sets    = array();
		$values  = array();

		foreach ( $fields as $field => $value ) {
			if ( ! isset( $formats[ $field ] ) ) {
				continue;
			}
			$sets[]   = "{$field} = {$formats[$field]}";
			$values[] = $value;
		}

		if ( array() === $sets ) {
			return $expected_revision;
		}

		$next     = $expected_revision + 1;
		$sets[]   = 'revision = %d';
		$values[] = $next;
		$sets[]   = 'updated_at_ts = %d';
		$values[] = time();
		$values[] = $id;
		$values[] = $campaign_id;
		$values[] = $expected_revision;

		// Identifiers come only from the fixed allowlist above.
		$sql = 'UPDATE %i SET ' . implode( ', ', $sets ) . ' WHERE id = %d AND campaign_id = %d AND revision = %d';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query values are prepared; identifiers come from the fixed allowlist above.
		$written = $wpdb->query( $wpdb->prepare( $sql, $this->table_name(), ...$values ) );

		return 1 === $written ? $next : false;
	}

	/**
	 * Mirrors fields that remain campaign-authoritative during P1.
	 *
	 * @param int  $campaign_id    Campaign id.
	 * @param bool $sync_commercial Include schedule and legacy package budget.
	 */
	public function sync_default_from_campaign( int $campaign_id, bool $sync_commercial = true ): bool {
		global $wpdb;
		$row = $this->ensure_default( $campaign_id );
		if ( null === $row ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compatibility projection update in custom table.
		$fields  = array(
			'organization_id' => $this->campaigns->org_id( $campaign_id ),
			'status'          => Line_Item_Rules::status_for_campaign( $this->campaigns->status( $campaign_id ) ),
			'updated_at_ts'   => time(),
		);
		$formats = array( '%d', '%s', '%d' );

		if ( $sync_commercial ) {
			$fields['start_at_ts']  = $this->campaigns->start_ts( $campaign_id );
			$fields['end_at_ts']    = $this->campaigns->end_ts( $campaign_id );
			$fields['budget_cents'] = $this->campaigns->budget_cents( $campaign_id );
			$formats                = array_merge( $formats, array( '%d', '%d', '%d' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compatibility projection update in the custom table.
		$written = $wpdb->update(
			$this->table_name(),
			$fields,
			array( 'id' => (int) $row['id'] ),
			$formats,
			array( '%d' )
		);

		return false !== $written;
	}

	/**
	 * Returns a bounded page of campaign ids for migration.
	 *
	 * @param int $cursor Last migrated campaign id.
	 * @param int $limit  Maximum rows to return.
	 * @return array<int, int>
	 */
	public function campaign_ids_after( int $cursor, int $limit ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded primary-key migration scan owned by the persistence layer.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
				Post_Types::CAMPAIGN,
				max( 0, $cursor ),
				$limit
			)
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Derives a non-empty table-safe name from legacy Campaign data.
	 *
	 * @param int $campaign_id Campaign id.
	 */
	private function default_name( int $campaign_id ): string {
		$name = trim( wp_strip_all_tags( $this->campaigns->title( $campaign_id ) ) );
		if ( '' === $name ) {
			$name = sprintf( 'Campaign %d', $campaign_id );
		}

		return mb_substr( $name, 0, Line_Item_Rules::MAX_NAME_LENGTH );
	}

	/**
	 * Normalizes a raw database row.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed>
	 */
	private function normalize_row( array $row ): array {
		foreach ( array( 'id', 'campaign_id', 'organization_id', 'start_at_ts', 'end_at_ts', 'goal_amount', 'budget_cents', 'daily_cap', 'lifetime_cap', 'priority', 'weight', 'revision', 'created_at_ts', 'updated_at_ts' ) as $field ) {
			$row[ $field ] = (int) ( $row[ $field ] ?? 0 );
		}

		$row['is_default'] = 1 === (int) ( $row['default_key'] ?? 0 );
		unset( $row['default_key'] );

		foreach ( array( 'targeting_rules', 'frequency_policy', 'delivery_settings' ) as $field ) {
			$decoded       = json_decode( (string) ( $row[ $field ] ?? '{}' ), true );
			$row[ $field ] = is_array( $decoded ) ? $decoded : array();
		}

		return $row;
	}
}
