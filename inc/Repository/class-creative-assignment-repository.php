<?php
/**
 * Persistence for creative assignments.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Assignment_Rules;
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
	 * Finds or creates the compatibility assignment for one creative.
	 *
	 * Idempotent by database rule rather than by application care: the unique
	 * `(line_item_id, placement_id, compat_key)` key means a concurrent lazy
	 * read and a background batch cannot both create one, and the loser reads
	 * the winner's row instead of failing.
	 *
	 * The delivery columns are copied from the creative deliberately — see
	 * docs/data-schema.md. The source is an immutable revision, so the copy
	 * cannot drift; that is what lets the serving path read one indexed row
	 * instead of seven postmeta joins.
	 *
	 * @param array<string, mixed> $fields Assignment fields, all server-derived.
	 * @return array<string, mixed>|null The row, or null when it could not be made.
	 */
	public function ensure( array $fields ): ?array {
		$line_item_id = (int) ( $fields['line_item_id'] ?? 0 );
		$placement_id = (int) ( $fields['placement_id'] ?? 0 );

		if ( $line_item_id <= 0 || $placement_id <= 0 || ! $this->table_exists() ) {
			return null;
		}

		$existing = $this->compatibility_row( $line_item_id, $placement_id );

		if ( null !== $existing ) {
			return $existing;
		}

		global $wpdb;
		$now = time();

		$row = array(
			'line_item_id'    => $line_item_id,
			'campaign_id'     => (int) ( $fields['campaign_id'] ?? 0 ),
			'organization_id' => (int) ( $fields['organization_id'] ?? 0 ),
			'asset_id'        => (int) ( $fields['asset_id'] ?? 0 ),
			'revision_id'     => (int) ( $fields['revision_id'] ?? 0 ),
			'placement_id'    => $placement_id,
			'status'          => (string) ( $fields['status'] ?? 'draft' ),
			'weight'          => max( 1, (int) ( $fields['weight'] ?? 100 ) ),
			'start_at_ts'     => 0,
			'end_at_ts'       => 0,
			'click_url'       => (string) ( $fields['click_url'] ?? '' ),
			'alt_text'        => mb_substr( (string) ( $fields['alt_text'] ?? '' ), 0, 255 ),
			'width'           => (int) ( $fields['width'] ?? 0 ),
			'height'          => (int) ( $fields['height'] ?? 0 ),
			'attachment_id'   => (int) ( $fields['attachment_id'] ?? 0 ),
			'revision'        => 1,
			'compat_key'      => 1,
			'created_at_ts'   => $now,
			'updated_at_ts'   => $now,
		);

		$was_suppressing = $wpdb->suppress_errors( true );

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom-table persistence with an explicit format list.
			$wpdb->insert(
				$this->table_name(),
				$row,
				array( '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d' )
			);
		} finally {
			$wpdb->suppress_errors( $was_suppressing );
		}

		// Either this request created it or another one did; both answers are
		// the same row, which is the point of the unique key.
		return $this->compatibility_row( $line_item_id, $placement_id );
	}

	/**
	 * The compatibility assignment for a line item and placement, if any.
	 *
	 * @param int $line_item_id Line-item id.
	 * @param int $placement_id Placement id.
	 * @return array<string, mixed>|null
	 */
	public function compatibility_row( int $line_item_id, int $placement_id ): ?array {
		if ( $line_item_id <= 0 || $placement_id <= 0 || ! $this->table_exists() ) {
			return null;
		}

		global $wpdb;
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read owned by this repository.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE line_item_id = %d AND placement_id = %d AND compat_key = 1 LIMIT 1', $table, $line_item_id, $placement_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Every assignment on a campaign, oldest first.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_campaign( int $campaign_id ): array {
		if ( $campaign_id <= 0 || ! $this->table_exists() ) {
			return array();
		}

		global $wpdb;
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read owned by this repository.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id = %d ORDER BY id ASC', $table, $campaign_id ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Moves a compatibility assignment onto a newer revision.
	 *
	 * The snapshot columns move with the pointer, because they describe the
	 * revision being pointed at. Leaving them behind would be worse than not
	 * denormalizing at all: the row would name one revision and describe
	 * another, and nothing would say which was right.
	 *
	 * @param int                  $line_item_id Line-item id.
	 * @param int                  $placement_id Placement id.
	 * @param int                  $revision_id  New revision id.
	 * @param array<string, mixed> $snapshot     Delivery fields to refresh.
	 * @return bool Whether a row was moved.
	 */
	public function point_at_revision( int $line_item_id, int $placement_id, int $revision_id, array $snapshot ): bool {
		if ( $line_item_id <= 0 || $placement_id <= 0 || $revision_id <= 0 || ! $this->table_exists() ) {
			return false;
		}

		$current = $this->compatibility_row( $line_item_id, $placement_id );

		if ( null === $current ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table update owned by this repository.
		$updated = $wpdb->update(
			$this->table_name(),
			array(
				'revision_id'   => $revision_id,
				'click_url'     => (string) ( $snapshot['click_url'] ?? $current['click_url'] ),
				'alt_text'      => mb_substr( (string) ( $snapshot['alt_text'] ?? $current['alt_text'] ), 0, 255 ),
				'revision'      => (int) $current['revision'] + 1,
				'updated_at_ts' => time(),
			),
			array( 'id' => (int) $current['id'] ),
			array( '%d', '%s', '%s', '%d', '%d' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * One assignment, scoped to the campaign that must own it.
	 *
	 * The campaign is part of the lookup rather than checked afterwards. A
	 * caller supplies both ids and neither is trusted: an assignment id from
	 * another tenant simply does not resolve, so the route answers "not found"
	 * without ever having to decide whether to say "forbidden".
	 *
	 * @param int $assignment_id Assignment id.
	 * @param int $campaign_id   Campaign that must own it.
	 * @return array<string, mixed>|null
	 */
	public function find_for_campaign( int $assignment_id, int $campaign_id ): ?array {
		if ( $assignment_id <= 0 || $campaign_id <= 0 || ! $this->table_exists() ) {
			return null;
		}

		global $wpdb;
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read owned by this repository.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND campaign_id = %d LIMIT 1', $table, $assignment_id, $campaign_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Applies validated changes under optimistic concurrency.
	 *
	 * The expected revision is in the `WHERE`, not read and compared first.
	 * Two editors saving the same assignment at the same moment both pass a
	 * check-then-write; only one satisfies a conditional update, and the loser
	 * is told rather than silently overwriting.
	 *
	 * @param int                  $assignment_id     Assignment id.
	 * @param int                  $campaign_id       Owning campaign.
	 * @param array<string, mixed> $fields            Validated values.
	 * @param int                  $expected_revision Last-seen revision.
	 * @return int|false New revision, or false when someone else won.
	 */
	public function update( int $assignment_id, int $campaign_id, array $fields, int $expected_revision ): int|false {
		if ( $assignment_id <= 0 || array() === $fields || ! $this->table_exists() ) {
			return false;
		}

		global $wpdb;

		$next    = $expected_revision + 1;
		$columns = array( 'weight', 'start_at_ts', 'end_at_ts', 'status' );
		$set     = array();
		$values  = array();

		foreach ( $columns as $column ) {
			if ( ! array_key_exists( $column, $fields ) ) {
				continue;
			}

			$set[]    = 'status' === $column ? "{$column} = %s" : "{$column} = %d";
			$values[] = 'status' === $column ? (string) $fields[ $column ] : (int) $fields[ $column ];
		}

		if ( array() === $set ) {
			return false;
		}

		$set[]    = 'revision = %d';
		$values[] = $next;
		$set[]    = 'updated_at_ts = %d';
		$values[] = time();

		$values[] = $assignment_id;
		$values[] = $campaign_id;
		$values[] = $expected_revision;

		// Identifiers come only from the fixed allowlist above.
		$sql = 'UPDATE %i SET ' . implode( ', ', $set ) . ' WHERE id = %d AND campaign_id = %d AND revision = %d';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query values are prepared; identifiers come from the fixed allowlist above.
		$updated = $wpdb->query( $wpdb->prepare( $sql, $this->table_name(), ...$values ) );

		return 1 === $updated ? $next : false;
	}

	/**
	 * Delivery candidates for one placement, in one indexed query.
	 *
	 * **The P3 read contract.** Inputs, output shape, ordering and visibility
	 * are documented in docs/data-schema.md; P3 consumes this rather than
	 * writing its own, so a second definition of "deliverable" cannot appear.
	 *
	 * One query, no postmeta joins, no per-candidate read. Everything a
	 * decision needs is on the row because approval denormalized it from an
	 * immutable revision — which is what the assignment table is for.
	 *
	 * `delivery (placement_id, status, start_at_ts, end_at_ts, id)` serves the
	 * whole predicate and the ordering. The trailing `id` is what makes paging
	 * deterministic when several rows share a window.
	 *
	 * Zero start or end means "inherit the parent", so an unset bound is open
	 * here and narrowed by whoever knows the parent — this layer does not judge
	 * a window it cannot see the parent of.
	 *
	 * @param int $placement_id Placement to fill.
	 * @param int $now          Evaluation time, UTC seconds.
	 * @param int $limit        Maximum candidates.
	 * @return array<int, array<string, mixed>>
	 */
	public function candidates_for_placement( int $placement_id, int $now, int $limit = 100 ): array {
		if ( $placement_id <= 0 || ! $this->table_exists() ) {
			return array();
		}

		global $wpdb;

		$limit = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The candidate read is the hot path; caching belongs to the caller, which knows the fill window.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, line_item_id, campaign_id, organization_id, asset_id, revision_id,
					placement_id, status, weight, start_at_ts, end_at_ts,
					click_url, alt_text, width, height, attachment_id
				FROM %i
				WHERE placement_id = %d
					AND status = %s
					AND ( start_at_ts = 0 OR start_at_ts <= %d )
					AND ( end_at_ts = 0 OR end_at_ts > %d )
				ORDER BY id ASC
				LIMIT %d',
				$this->table_name(),
				$placement_id,
				Assignment_Rules::LIVE,
				$now,
				$now,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Withdraws an assignment, keeping the row.
	 *
	 * Cancelled rather than deleted: the contract is explicit that deletion,
	 * supersession and withdrawal have distinct meanings and none is a silent
	 * removal of history.
	 *
	 * `compat_key` is cleared to NULL, which frees the unique slot so the same
	 * placement can take a new compatibility assignment later. A retired row
	 * holding the slot would refuse every future upload to that placement and
	 * look like a database bug.
	 *
	 * @param int $assignment_id     Assignment id.
	 * @param int $campaign_id       Owning campaign.
	 * @param int $expected_revision Last-seen revision.
	 * @return int|false New revision, or false when someone else won.
	 */
	public function retire( int $assignment_id, int $campaign_id, int $expected_revision ): int|false {
		if ( $assignment_id <= 0 || $campaign_id <= 0 || ! $this->table_exists() ) {
			return false;
		}

		global $wpdb;

		$next = $expected_revision + 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query values are prepared; the identifier is this repository's own table.
		$written = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, compat_key = NULL, revision = %d, updated_at_ts = %d WHERE id = %d AND campaign_id = %d AND revision = %d',
				$this->table_name(),
				Assignment_Rules::CANCELLED,
				$next,
				time(),
				$assignment_id,
				$campaign_id,
				$expected_revision
			)
		);

		return 1 === $written ? $next : false;
	}

	/**
	 * Retires every live assignment on a deleted placement.
	 *
	 * Retired rather than removed, so the row still explains what ran there.
	 * Left live it stays a delivery candidate for a slot that no longer exists.
	 *
	 * Scoped to rows that are not already terminal, so running it twice is a
	 * no-op rather than a second write.
	 *
	 * @param int $placement_id Placement id.
	 * @return int Rows retired.
	 */
	public function retire_for_placement( int $placement_id ): int {
		if ( $placement_id <= 0 || ! $this->table_exists() ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query values are prepared; the identifier is this repository's own table.
		$written = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, compat_key = NULL, revision = revision + 1, updated_at_ts = %d
				WHERE placement_id = %d AND status NOT IN ( %s, %s )',
				$this->table_name(),
				Assignment_Rules::CANCELLED,
				time(),
				$placement_id,
				Assignment_Rules::CANCELLED,
				Assignment_Rules::COMPLETED
			)
		);

		return is_int( $written ) ? $written : 0;
	}

	/**
	 * How many creatives have no assignment at all.
	 *
	 * A left join rather than two counts: a creative can legitimately have no
	 * assignment (no campaign, no placement), and subtracting totals would
	 * report a wrong number the moment one row is assigned twice — which the
	 * schema permits by design, since many creatives per placement is the point.
	 *
	 * @return int
	 */
	public function creatives_without_assignment(): int {
		if ( ! $this->table_exists() ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Site Health diagnostic over this plugin's own table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i p LEFT JOIN %i a ON a.revision_id = p.ID WHERE p.post_type = %s AND a.id IS NULL',
				$wpdb->posts,
				$this->table_name(),
				Post_Types::CREATIVE
			)
		);
	}

	/**
	 * Removes every assignment owned by a deleted campaign.
	 *
	 * Hard deletion rather than a tombstone, and the reason is evidence rather
	 * than preference: `aggr_rollups` is keyed by placement, campaign and day
	 * and carries no creative or assignment id, so removing these rows costs no
	 * reporting history.
	 *
	 * @param int $campaign_id Campaign id.
	 */
	public function delete_for_campaign( int $campaign_id ): void {
		if ( $campaign_id <= 0 || ! $this->table_exists() ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table deletion owned by this repository.
		$wpdb->delete( $this->table_name(), array( 'campaign_id' => $campaign_id ), array( '%d' ) );
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
