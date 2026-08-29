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
					'name_is_derived'   => 1,
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
				array( '%d', '%d', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d' )
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
	/**
	 * Delivery policy for a set of line items, keyed by line-item id.
	 *
	 * The decision stages read priority, pacing, caps, targeting and frequency
	 * from the candidate row, and the P2 candidate query selects none of them —
	 * it returns the assignment's own columns only. Without this every stage
	 * fell back to its default, so a configured priority, cap, targeting rule or
	 * frequency policy changed nothing at serve time.
	 *
	 * Deliberately a separate read rather than a join onto
	 * `candidates_for_placement()`: that query is P2's documented contract with
	 * an asserted plan, and widening it to carry policy would change what
	 * `EXPLAIN` chooses. One bounded query for the whole set keeps the decision
	 * path free of per-candidate work either way.
	 *
	 * @param array<int, int> $line_item_ids Line-item ids.
	 * @param string          $day_utc       UTC day for the daily counter; defaults to today.
	 * @return array<int, array<string, mixed>> Keyed by line-item id.
	 */
	public function delivery_policies_for( array $line_item_ids, string $day_utc = '' ): array {
		$ids = array();

		foreach ( $line_item_ids as $line_item_id ) {
			$id = (int) $line_item_id;

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		$ids = array_values( $ids );

		if ( array() === $ids || ! $this->table_exists() ) {
			return array();
		}

		global $wpdb;

		$table        = $this->table_name();
		$rollups      = $wpdb->prefix . Schema::ROLLUPS_TABLE;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$day          = '' !== $day_utc ? $day_utc : gmdate( 'Y-m-d' );
		$args         = array_merge( array( $day ), $ids );

		/*
		 * Policy and counters in one statement rather than two. The decision
		 * path is measured in queries, not in methods, and the second read was
		 * enough on its own to put a cold thousand-candidate fill over budget.
		 *
		 * Grouped by the primary key, so every non-aggregated column is
		 * functionally dependent and `ONLY_FULL_GROUP_BY` is satisfied.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Both table names are prefix+constant; placeholders are a fixed %d list matching $ids.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.priority, l.pacing_mode, l.goal_type, l.goal_amount,
					l.daily_cap, l.lifetime_cap, l.targeting_rules, l.frequency_policy,
					l.delivery_settings,
					COALESCE(SUM(r.impressions), 0) AS delivered_lifetime,
					COALESCE(SUM(CASE WHEN r.day_utc = %s THEN r.impressions ELSE 0 END), 0) AS delivered_today
				FROM {$table} l
				LEFT JOIN {$rollups} r ON r.line_item_id = l.id
				WHERE l.id IN ({$placeholders})
				GROUP BY l.id",
				...$args
			),
			ARRAY_A
		);
		// phpcs:enable

		$policies = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$policies[ (int) ( $row['id'] ?? 0 ) ] = array(
					'priority'           => (int) ( $row['priority'] ?? 0 ),
					'pacing_mode'        => (string) ( $row['pacing_mode'] ?? '' ),
					'goal_type'          => (string) ( $row['goal_type'] ?? '' ),
					'goal_amount'        => (int) ( $row['goal_amount'] ?? 0 ),
					'daily_cap'          => (int) ( $row['daily_cap'] ?? 0 ),
					'lifetime_cap'       => (int) ( $row['lifetime_cap'] ?? 0 ),
					'targeting_rules'    => $row['targeting_rules'] ?? null,
					// The column is `frequency_policy`; the stage reads
					// `frequency_rules`. Renamed here so neither has to know
					// about the other.
					'frequency_rules'    => $row['frequency_policy'] ?? null,
					'delivery_settings'  => $row['delivery_settings'] ?? null,
					'delivered_lifetime' => max( 0, (int) ( $row['delivered_lifetime'] ?? 0 ) ),
					'delivered_today'    => max( 0, (int) ( $row['delivered_today'] ?? 0 ) ),
				);
			}
		}

		return $policies;
	}

	/**
	 * Every line item on a campaign, oldest first.
	 *
	 * @param int $campaign_id Campaign post id.
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
			'name'              => '%s',
			'pricing_model'     => '%s',
			'goal_type'         => '%s',
			'goal_amount'       => '%d',
			'budget_cents'      => '%d',
			'daily_cap'         => '%d',
			'lifetime_cap'      => '%d',
			'priority'          => '%d',
			'pacing_mode'       => '%s',
			'weight'            => '%d',

			// Stored as the JSON text the validator produced. The columns
			// existed from P1 and nothing could write them until the delivery
			// stages that read them had a configuration surface.
			'targeting_rules'   => '%s',
			'frequency_policy'  => '%s',
			'delivery_settings' => '%s',
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

		// Writing a name is what makes it the publisher's rather than ours, so
		// the provenance flag is cleared at the moment the override happens
		// instead of being inferred later from the revision counter. Any edit
		// bumps that counter, so inferring from it would freeze the name of
		// every line item whose pacing somebody adjusted.
		if ( array_key_exists( 'name', $fields ) ) {
			$sets[]   = 'name_is_derived = %d';
			$values[] = 0;
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

		// The name follows the campaign only while it is still ours.
		//
		// Every other field here is campaign-owned and always projected. The
		// name is the one field with two possible owners, and leaving it out of
		// the projection is what let a campaign renamed after its first detail
		// view keep showing the placeholder the wizard invented: the default
		// line item is created on that first view, while the title is still
		// "Untitled campaign", and nothing re-derived it afterwards.
		if ( true === ( $row['name_is_derived'] ?? false ) ) {
			$fields['name'] = $this->default_name( $campaign_id );
			$formats[]      = '%s';
		}

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
	 * Classifies the provenance of names written before the flag existed.
	 *
	 * Rows already on disk when the column arrives all take its default, which
	 * says "derived" — right for the ones nobody renamed and wrong for the
	 * rest. This walks them and clears the flag wherever the stored name is not
	 * the one this class would generate today.
	 *
	 * The comparison calls `default_name()` rather than approximating it in
	 * SQL, because the two have to agree exactly: a row misread as derived has
	 * a publisher's rename silently overwritten on the next projection, and a
	 * row misread as overridden keeps a stale name forever. `default_name()`
	 * strips tags, trims, falls back for an empty title and truncates to the
	 * column width; a `LEFT(post_title, 191)` comparison disagrees on all four.
	 *
	 * Bounded and restartable: the caller passes the last id it finished and
	 * gets the next cursor back, so an interrupted run resumes instead of
	 * starting over.
	 *
	 * @param int $cursor Highest line-item id already classified.
	 * @param int $limit  Rows to examine in this batch.
	 * @return array{cursor: int, examined: int, corrected: int}
	 */
	public function backfill_name_provenance( int $cursor, int $limit ): array {
		global $wpdb;

		$limit  = max( 1, min( 500, $limit ) );
		$cursor = max( 0, $cursor );

		if ( ! $this->table_exists() ) {
			return array(
				'cursor'    => $cursor,
				'examined'  => 0,
				'corrected' => 0,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded primary-key migration scan owned by the persistence layer.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, campaign_id, name FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
				$this->table_name(),
				$cursor,
				$limit
			),
			ARRAY_A
		);

		$rows      = is_array( $rows ) ? $rows : array();
		$examined  = 0;
		$corrected = 0;

		foreach ( $rows as $row ) {
			$id     = (int) ( $row['id'] ?? 0 );
			$cursor = $id;
			++$examined;

			if ( (string) ( $row['name'] ?? '' ) === $this->default_name( (int) ( $row['campaign_id'] ?? 0 ) ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration write in the custom table.
			$wpdb->update(
				$this->table_name(),
				array( 'name_is_derived' => 0 ),
				array( 'id' => $id ),
				array( '%d' ),
				array( '%d' )
			);

			++$corrected;
		}

		return array(
			'cursor'    => $cursor,
			'examined'  => $examined,
			'corrected' => $corrected,
		);
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

		$row['is_default']      = 1 === (int) ( $row['default_key'] ?? 0 );
		$row['name_is_derived'] = 1 === (int) ( $row['name_is_derived'] ?? 0 );
		unset( $row['default_key'] );

		foreach ( array( 'targeting_rules', 'frequency_policy', 'delivery_settings' ) as $field ) {
			$decoded       = json_decode( (string) ( $row[ $field ] ?? '{}' ), true );
			$row[ $field ] = is_array( $decoded ) ? $decoded : array();
		}

		return $row;
	}
}
