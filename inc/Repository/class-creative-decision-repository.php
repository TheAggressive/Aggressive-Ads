<?php
/**
 * Persistence for what a reviewer decided about a revision.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Install\Schema;

/**
 * Append-only decisions: approved, rejected, and why.
 *
 * **Not the audit log, deliberately.** The audit log records everything that
 * happened for somebody investigating; this is the product surface a reviewer
 * and an advertiser read — "what happened to this ad, and when" — and a screen
 * that has to filter an audit stream to draw it drifts from what was decided
 * the first time either changes. `docs/platform-p17-creative-experience.md`
 * names it as the record that makes a rejection as durable as an approval.
 *
 * Nothing here updates a row. A decision that was made is a fact about a
 * moment, and the correction for a wrong one is the next decision.
 */
final class Creative_Decision_Repository {

	/** A revision a reviewer published. */
	public const APPROVED = 'approved';

	/** A revision a reviewer refused, with the reason the advertiser reads. */
	public const REJECTED = 'rejected';

	/** A proposed replacement a reviewer refused. */
	public const CHANGES_REJECTED = 'changes_rejected';

	/** A proposed replacement a reviewer accepted. */
	public const CHANGES_APPROVED = 'changes_approved';

	/**
	 * How much explanation is stored, matching what the reviewer may write.
	 */
	public const MAX_REASON_LENGTH = 2000;

	/**
	 * How many decisions one read returns.
	 *
	 * A revision collects a handful in its life. The cap is here so a screen's
	 * cost does not depend on that staying true.
	 */
	public const MAX_ROWS = 200;

	/**
	 * Cached table-existence answer, or null when not yet asked.
	 *
	 * @var bool|null
	 */
	private ?bool $table_exists = null;

	/**
	 * Prefixed table name, scoped to the current site.
	 *
	 * @return string
	 */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . Schema::CREATIVE_DECISIONS_TABLE;
	}

	/**
	 * Creates or repairs the table.
	 *
	 * @return void
	 */
	public function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( Schema::creative_decisions_table_ddl( $this->table_name(), $wpdb->get_charset_collate() ) );
		$this->table_exists = null;
	}

	/**
	 * Whether the table exists.
	 *
	 * @return bool
	 */
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

	/**
	 * Drops the table during a destructive uninstall.
	 *
	 * @return void
	 */
	public function drop_table(): void {
		global $wpdb;

		$table = esc_sql( $this->table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall of this repository's fixed table.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		$this->table_exists = false;
	}

	/**
	 * Records one decision about one revision.
	 *
	 * **Never fails the decision it describes.** A reviewer's approval has
	 * already published the artwork by the time this runs; a table that cannot
	 * be written is a reporting problem, not a reason to tell the reviewer
	 * their decision did not happen. The caller checks the return only to
	 * decide whether to say the history is complete.
	 *
	 * @param array{revision_id: int, campaign_id: int, organization_id: int, decision: string, reason?: string, actor_user_id?: int} $fields What was decided.
	 * @return int The row id, or zero when it could not be written.
	 */
	public function record( array $fields ): int {
		$revision_id = (int) ( $fields['revision_id'] ?? 0 );
		$decision    = (string) ( $fields['decision'] ?? '' );

		if ( $revision_id <= 0 || ! in_array( $decision, self::decisions(), true ) || ! $this->table_exists() ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table persistence with an explicit format list.
		$written = $wpdb->insert(
			$this->table_name(),
			array(
				'revision_id'     => $revision_id,
				'campaign_id'     => (int) ( $fields['campaign_id'] ?? 0 ),
				'organization_id' => (int) ( $fields['organization_id'] ?? 0 ),
				'blog_id'         => get_current_blog_id(),
				'decision'        => $decision,
				'reason'          => mb_substr( (string) ( $fields['reason'] ?? '' ), 0, self::MAX_REASON_LENGTH ),
				'actor_user_id'   => (int) ( $fields['actor_user_id'] ?? 0 ),
				'decided_at_ts'   => time(),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d' )
		);

		return false === $written ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Every decision about one revision, oldest first.
	 *
	 * @param int $revision_id Creative revision id.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_revision( int $revision_id ): array {
		if ( $revision_id <= 0 || ! $this->table_exists() ) {
			return array();
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read owned by this repository.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE revision_id = %d AND blog_id = %d ORDER BY id ASC LIMIT %d',
				$this->table_name(),
				$revision_id,
				get_current_blog_id(),
				self::MAX_ROWS
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every decision on one campaign's revisions, newest first.
	 *
	 * The campaign is the scope a screen is authorized against, so it is the
	 * scope the read takes: a caller that has checked one campaign cannot
	 * accidentally receive another's rows.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_campaign( int $campaign_id ): array {
		if ( $campaign_id <= 0 || ! $this->table_exists() ) {
			return array();
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read owned by this repository.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE campaign_id = %d AND blog_id = %d ORDER BY id DESC LIMIT %d',
				$this->table_name(),
				$campaign_id,
				get_current_blog_id(),
				self::MAX_ROWS
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Removes a campaign's decisions when the campaign itself is deleted.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return void
	 */
	public function delete_for_campaign( int $campaign_id ): void {
		if ( $campaign_id <= 0 || ! $this->table_exists() ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table cleanup owned by this repository.
		$wpdb->delete(
			$this->table_name(),
			array(
				'campaign_id' => $campaign_id,
				'blog_id'     => get_current_blog_id(),
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * The decisions this table stores.
	 *
	 * @return array<int, string>
	 */
	public static function decisions(): array {
		return array( self::APPROVED, self::REJECTED, self::CHANGES_APPROVED, self::CHANGES_REJECTED );
	}

	/**
	 * The table's columns, for the schema test that keeps DDL and reads in step.
	 *
	 * @return array<int, string>
	 */
	public function columns(): array {
		return Schema::creative_decisions_columns();
	}
}
