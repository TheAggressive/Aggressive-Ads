<?php
/**
 * Native fill rollups.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Install\Schema;

/**
 * The table's schema, its writes, and the reads delivery itself makes.
 *
 * Org-scoped reporting reads live in `Rollup_Report_Repository`. Same table,
 * two review standards: this half is judged on contention and query budget on
 * the serving path, that half on tenant isolation and range bounds.
 */
final class Rollup_Repository {

	/**
	 * The UTC day viewability measurement began, or '' when it has not.
	 *
	 * Recorded once by the migration rather than inferred, because "no viewable
	 * events that day" cannot distinguish a day nobody measured from a day
	 * nothing was seen.
	 */
	public const OPTION_VIEWABILITY_SINCE = 'aggr_viewability_since';

	/**
	 * Which projector wrote a row's counters.
	 *
	 * Stamped on every write so a day rebuilt by a later projector and a day
	 * written live are distinguishable. Without it a projection bug and real
	 * history look identical, and "is this number old code's answer?" has no
	 * way to be asked. Bump this when the projection's arithmetic changes, not
	 * when unrelated code moves.
	 */
	public const PROJECTOR_VERSION = 1;

	/**
	 * Fully prefixed table name.
	 */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . Schema::ROLLUPS_TABLE;
	}

	/**
	 * Creates or updates the rollups table.
	 */
	public function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( Schema::rollups_table_ddl( $this->table_name(), $wpdb->get_charset_collate() ) );
		$this->drop_legacy_slot_day_index();
	}

	/**
	 * Whether the rollups table exists.
	 */
	public function table_exists(): bool {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection on a custom table; caching existence is how a failed install looks healthy.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Distinct projector versions present in the projection.
	 *
	 * **The reader that makes `projector_version` load-bearing rather than
	 * decorative.** A column nothing looks at is a column that rots: this
	 * codebase already shipped `allow_s2s` stored, validated and exposed over
	 * REST while nothing set it, so every definition refused every server
	 * report. Writing a version and never reading one is the same shape.
	 *
	 * What it answers is "which code wrote these numbers", which is otherwise
	 * only answerable by opening the database. More than one version present
	 * means a rollout is mid-flight or a rollback happened, and the days
	 * written by the older projector are the ones to reproject.
	 *
	 * @return list<int> Versions found, ascending.
	 */
	public function projector_versions(): array {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return array();
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Diagnostic over this plugin's own projection; a cached answer is how a stale rollout looks finished.
		$found = $wpdb->get_col( "SELECT DISTINCT projector_version FROM {$table} ORDER BY projector_version ASC" );

		return array_values( array_map( 'intval', is_array( $found ) ? $found : array() ) );
	}

	/**
	 * Fills `org_id` on rows written before the column existed.
	 *
	 * One statement, idempotent on `org_id = 0`: an interrupted run resumes by
	 * being run again, and a site with nothing unattributed does no work. That
	 * is why this needs no cursor, no batch size and no resume state — the
	 * predicate *is* the cursor.
	 *
	 * **Filled in place rather than rebuilt**, because this table is the pacing
	 * and frequency counter as well as the reporting source. Emptying it to let
	 * the reconciler regenerate history would reset every live cap, and a
	 * campaign whose counter restarts overdelivers for the rest of the day.
	 *
	 * It writes *today's* organization onto older rows, which is not a
	 * reconstruction of history: nothing ever recorded who owned a campaign
	 * last month. It is the same answer the read-time join returned, written
	 * down once so it stops changing.
	 *
	 * House rows (`campaign_id = 0`) are skipped and keep `org_id = 0`. They
	 * are never attributed to an organization, and an `INNER JOIN` would drop
	 * them anyway — stating it in the predicate makes that intent rather than
	 * an accident of the join.
	 *
	 * @return int Rows attributed.
	 */
	public function backfill_org_ids(): int {
		global $wpdb;

		$table = $this->table_name();
		$meta  = $wpdb->postmeta;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-time attribution across this plugin's projection and core postmeta; the only interpolations are table names and the meta key is prepared.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} r
				INNER JOIN {$meta} m ON m.post_id = r.campaign_id AND m.meta_key = %s
				SET r.org_id = CAST(m.meta_value AS UNSIGNED)
				WHERE r.org_id = 0 AND r.campaign_id > 0",
				Campaign_Repository::META_ORG_ID
			)
		);
		// phpcs:enable

		return is_int( $updated ) ? $updated : 0;
	}

	/**
	 * Drops the rollups table. Uninstall only.
	 */
	public function drop_table(): void {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping this plugin's own table on uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Adds the line-item column and re-keys the table.
	 *
	 * `dbDelta` adds the column and the new unique key, and drops neither the
	 * old `slot_day` unique nor its meaning: left in place it would refuse a
	 * second line item's row for the same slot and day, which is exactly the
	 * case this migration exists to allow. The same shape as the v5 token index.
	 *
	 * Existing rows are attributed to the campaign's default line item, which is
	 * correct because until now a campaign had exactly one — that assumption is
	 * what made counting by campaign work, and it is what makes the backfill
	 * safe.
	 *
	 * @return void
	 */
	public function migrate_line_item_attribution(): void {
		global $wpdb;

		// Creates the column and drops the pre-v16 unique.
		$this->install_table();

		if ( ! $this->table_exists() ) {
			return;
		}

		$table      = $this->table_name();
		$line_items = $wpdb->prefix . Schema::LINE_ITEMS_TABLE;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Both table names are prefix+constant; there is no caller input in this statement.
		$wpdb->query(
			"UPDATE {$table} r
			JOIN {$line_items} l ON l.campaign_id = r.campaign_id AND l.default_key = 1
			SET r.line_item_id = l.id
			WHERE r.line_item_id = 0"
		);
		// phpcs:enable
	}

	/**
	 * `dbDelta` adds `slot_line_day` but will not drop the pre-v16 `slot_day`.
	 *
	 * Left in place it refuses a second line item's row for the same slot and
	 * day — exactly the case the new key exists to allow. Dropped here rather
	 * than only in the migration so a repair install heals it too, matching how
	 * the events table handles its own superseded unique.
	 *
	 * @return void
	 */
	private function drop_legacy_slot_day_index(): void {
		global $wpdb;

		$table = $this->table_name();

		/*
		 * **Not guarded on `table_exists()`.** That helper asks
		 * `SHOW TABLES LIKE`, which cannot see a temporary table — and every
		 * table in the WordPress suite is temporary, because `WP_UnitTestCase`
		 * rewrites `CREATE TABLE` into its `TEMPORARY` form. A guard written
		 * that way returns early exactly where the drop is being verified, so
		 * the migration looks correct while the superseded index survives.
		 *
		 * That is not hypothetical: it happened to the decision rollups table,
		 * whose drop is reachable only after a mid-suite reinstall. This one
		 * works today because nothing reinstalls its table first, which is an
		 * ordering accident rather than a property.
		 *
		 * Asking the index list answers both questions at once: a table that is
		 * not there has no indexes, so nothing is dropped and nothing errors.
		 */
		$suppressed = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection on this plugin's table.
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );

		$wpdb->suppress_errors( $suppressed );

		$names = is_array( $rows ) ? array_values( array_unique( array_column( $rows, 'Key_name' ) ) ) : array();

		if ( array() === $names ) {
			return;
		}

		if ( in_array( 'slot_day', $names, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping the pre-v16 unique so a second line item can hold its own row.
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX slot_day" );
		}
	}

	/**
	 * Increments today's counter for one slot, campaign and line item.
	 *
	 * @param string $column       impressions|clicks|viewables|conversions.
	 * @param int    $placement_id Placement id.
	 * @param int    $campaign_id  Campaign id, or 0 for house.
	 * @param string $day_utc      Optional UTC Y-m-d. Invalid values use today.
	 * @param int    $line_item_id Line item the delivery is spent against, or 0.
	 * @param int    $org_id       Owning organization, frozen onto the row.
	 * @return bool
	 */
	public function increment( string $column, int $placement_id, int $campaign_id, string $day_utc = '', int $line_item_id = 0, int $org_id = 0 ): bool {
		global $wpdb;

		if ( ! in_array( $column, array( 'impressions', 'clicks', 'viewables', 'conversions' ), true ) ) {
			return false;
		}

		$table = $this->table_name();
		$day   = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day_utc ) ? $day_utc : gmdate( 'Y-m-d' );

		/*
		 * Both nullable columns exist to keep "nobody was measuring" apart from
		 * "nothing happened", and each is written the way that preserves it.
		 *
		 * `viewables` starts at 0 on any row a delivery, click or view touches,
		 * so the day is marked as measured even before anything is seen.
		 * COALESCE on update for the same reason: a row carried over from before
		 * the column existed becomes 0, not NULL + 1.
		 *
		 * `conversions` stays NULL unless this call *is* a conversion. Unlike
		 * viewability, a delivery is no evidence that conversion tracking is
		 * running — the publisher may have defined no conversion at all — so an
		 * impression must not mark the day as measured.
		 *
		 * And a conversion leaves `viewables` NULL rather than 0, which is the
		 * subtle half. A conversion is attributed to the day the outcome
		 * happened, routinely days after the click, so it can create a row for a
		 * day this site served nothing. Writing 0 there would invent a day of
		 * impressions nobody saw.
		 *
		 * Both are SQL literals rather than placeholders because
		 * `$wpdb->prepare()` cannot emit a real NULL: a null handed to `%s`
		 * becomes the empty string, which an unsigned bigint stores as 0 — the
		 * one value this is all avoiding. Neither literal is input; both come
		 * from comparing an already-allowlisted column name.
		 */
		$viewables_value   = 'conversions' === $column ? 'NULL' : (string) ( 'viewables' === $column ? 1 : 0 );
		$conversions_value = 'conversions' === $column ? '1' : 'NULL';

		/*
		 * `org_id` is set on insert and **filled but never changed** on update.
		 * It is a durable fact about the delivery, so a later write must not
		 * move it — but a row that predates the column, or one created by a
		 * conversion before any delivery resolved an organization, holds 0 and
		 * should take the first real answer that arrives.
		 */

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefix+constant; column and the two literals are allowlisted to impressions|clicks|viewables|conversions.
		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (day_utc, placement_id, campaign_id, line_item_id, org_id, impressions, clicks, viewables, conversions, projector_version)
				VALUES (%s, %d, %d, %d, %d, %d, %d, {$viewables_value}, {$conversions_value}, %d)
				ON DUPLICATE KEY UPDATE {$column} = COALESCE({$column}, 0) + 1,
					org_id = IF(org_id = 0, VALUES(org_id), org_id),
					projector_version = VALUES(projector_version)",
				$day,
				$placement_id,
				$campaign_id,
				$line_item_id,
				max( 0, $org_id ),
				'impressions' === $column ? 1 : 0,
				'clicks' === $column ? 1 : 0,
				self::PROJECTOR_VERSION
			)
		);
		// phpcs:enable

		return false !== $written;
	}

	/**
	 * Rebuilds one closed UTC day's counters exactly from the event ledger.
	 *
	 * INSERT ... SELECT is one atomic statement. Re-running it is idempotent;
	 * it repairs a synchronous projection failure without replaying an event.
	 *
	 * @param string $day_utc Closed UTC Y-m-d.
	 */
	public function reconcile_day( string $day_utc ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day_utc ) ) {
			return false;
		}

		$start = strtotime( $day_utc . ' 00:00:00 UTC' );

		if ( false === $start ) {
			return false;
		}

		global $wpdb;

		$rollups = $this->table_name();
		$events  = $wpdb->prefix . Schema::EVENTS_TABLE;
		$end     = $start + DAY_IN_SECONDS;

		/*
		 * The event ledger records the creative, not the line item, so the
		 * attribution is recovered by joining the assignment that served it.
		 * That join is durable because withdrawal retires an assignment rather
		 * than deleting it — history keeps something to point at.
		 */
		$assignments = $wpdb->prefix . 'aggr_creative_assignments';

		/*
		 * A day before measurement began reconciles to NULL, not zero.
		 *
		 * Without this the reconciler destroys the distinction the column
		 * exists for: it walks from the earliest ledger day whenever there is
		 * no watermark, and every pre-P11 day has no viewable events, so
		 * `VALUES(viewables)` is 0. History would be rewritten from "nobody was
		 * measuring" to "not one ad was seen", which is the alarming reading
		 * and the false one.
		 */
		$since = (string) get_option( self::OPTION_VIEWABILITY_SINCE, '' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Idempotent projection repair between this plugin's two custom tables.
		$meta = $wpdb->postmeta;

		/*
		 * **The reconciler repairs counters and leaves dimensions alone.**
		 *
		 * `org_id` is filled when the row does not have one and never changed
		 * when it does. That asymmetry is the whole point of freezing it: this
		 * statement runs over closed days on a schedule, and re-deriving
		 * tenancy here would silently undo the freeze every night for anything
		 * that had since changed hands — the exact drift the column exists to
		 * stop, reintroduced by the machinery meant to guarantee accuracy.
		 *
		 * The join is `LEFT` for the same reason as the assignment one: a house
		 * fill has no organization and must still reconcile.
		 */
		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$rollups} (day_utc, placement_id, campaign_id, line_item_id, org_id, impressions, clicks, viewables, projector_version)
				SELECT %s, e.placement_id, e.campaign_id, COALESCE(a.line_item_id, 0), COALESCE(MAX(m.meta_value), 0),
					SUM(CASE WHEN e.event IN (%s, %s) THEN 1 ELSE 0 END),
					SUM(CASE WHEN e.event = %s THEN 1 ELSE 0 END),
					IF(%s = '' OR %s < %s, NULL, SUM(CASE WHEN e.event = %s THEN 1 ELSE 0 END)),
					%d
				FROM {$events} e
				LEFT JOIN {$assignments} a
					ON a.revision_id = e.creative_id AND a.placement_id = e.placement_id
				LEFT JOIN {$meta} m
					ON m.post_id = e.campaign_id AND m.meta_key = %s
				WHERE e.created_at_ts >= %d AND e.created_at_ts < %d
				GROUP BY e.placement_id, e.campaign_id, COALESCE(a.line_item_id, 0)
				ON DUPLICATE KEY UPDATE impressions = VALUES(impressions), clicks = VALUES(clicks),
					viewables = IF(VALUES(viewables) = 0 AND viewables IS NULL, NULL, VALUES(viewables)),
					org_id = IF(org_id = 0, VALUES(org_id), org_id),
					projector_version = VALUES(projector_version)",
				$day_utc,
				Event_Repository::TYPE_SERVED,
				Event_Repository::TYPE_IMPRESSION,
				Event_Repository::TYPE_CLICK,
				$since,
				$day_utc,
				$since,
				Event_Repository::TYPE_VIEWABLE,
				self::PROJECTOR_VERSION,
				Campaign_Repository::META_ORG_ID,
				$start,
				$end
			)
		);
		// phpcs:enable

		return false !== $written && $this->reconcile_conversions( $day_utc, $start, $end );
	}

	/**
	 * Rebuilds one day's conversion counter from the conversion ledger.
	 *
	 * A second statement rather than a wider join, because conversions do not
	 * live in `aggr_events` and cannot: that table is unique on
	 * `(token_hash, event)`, which would permit one conversion per fill for all
	 * time. Joining two ledgers with different grains in one aggregate would
	 * multiply the impression counts by the number of conversions, which is the
	 * classic fan-out bug and would silently inflate every other column.
	 *
	 * Counted by `occurred_at_ts`, so a report that arrives late still lands on
	 * the day the outcome happened rather than the day the reporter sent it.
	 *
	 * **No measurement-boundary option, unlike viewability, and the asymmetry is
	 * the point.** That reconcile writes a row for every day that has *events*,
	 * so a pre-P11 day would be swept up and its `viewables` set to a measured
	 * zero — hence `OPTION_VIEWABILITY_SINCE`. This one selects from the
	 * conversion ledger, so a day with no conversions produces no rows, fires no
	 * `ON DUPLICATE KEY UPDATE`, and leaves an existing NULL exactly as it was.
	 * History is protected by the shape of the query rather than by a flag.
	 *
	 * A boundary option was written first and then deleted, because sabotaging
	 * it changed nothing: a guard that cannot fail is not protecting anything,
	 * and shipping one would have implied a safety this does not need.
	 *
	 * @param string $day_utc Closed UTC Y-m-d.
	 * @param int    $start   Inclusive lower Unix bound.
	 * @param int    $end     Exclusive upper Unix bound.
	 */
	private function reconcile_conversions( string $day_utc, int $start, int $end ): bool {
		global $wpdb;

		$rollups     = $this->table_name();
		$conversions = $wpdb->prefix . Schema::CONVERSIONS_TABLE;

		/*
		 * `line_item_id` is stored on the conversion row rather than recovered
		 * by a join, because it was resolved at ingestion from the assignment
		 * that served the fill. Re-deriving it here could disagree with the
		 * live projection if the assignment moved in between, and a reconcile
		 * that disagrees with the counter it repairs is worse than no reconcile.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Idempotent projection repair between this plugin's two custom tables.
		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$rollups} (day_utc, placement_id, campaign_id, line_item_id, impressions, clicks, viewables, conversions)
				SELECT %s, c.placement_id, c.campaign_id, c.line_item_id, 0, 0, NULL, COUNT(*)
				FROM {$conversions} c
				WHERE c.occurred_at_ts >= %d AND c.occurred_at_ts < %d
				GROUP BY c.placement_id, c.campaign_id, c.line_item_id
				ON DUPLICATE KEY UPDATE conversions = VALUES(conversions)",
				$day_utc,
				$start,
				$end
			)
		);
		// phpcs:enable

		return false !== $written;
	}

	/**
	 * Clicks and conversions for one closed UTC day, across the whole site.
	 *
	 * The pair, because neither means anything alone: conversions without clicks
	 * is a site that served nothing, and clicks without conversions is either a
	 * quiet day or a reporting path that is not running. Only an operator
	 * looking at both can tell those apart, so only both are returned.
	 *
	 * `conversions` stays nullable all the way out, exactly as `viewables` does.
	 * Coalescing it to zero here would turn "no day was measured" into "nothing
	 * converted", which is the distinction the column exists for.
	 *
	 * Deliberately not organization-scoped: this answers an operator's question
	 * about the installation, not an advertiser's about their campaign.
	 *
	 * @param string $day_utc Closed UTC Y-m-d.
	 * @return array{clicks: int, conversions: int|null}
	 */
	public function day_conversions( string $day_utc ): array {
		$empty = array(
			'clicks'      => 0,
			'conversions' => null,
		);

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day_utc ) || ! $this->table_exists() ) {
			return $empty;
		}

		global $wpdb;

		$table = $this->table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefix+constant; the day is prepared.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(clicks), 0) AS clicks, SUM(conversions) AS conversions
				FROM {$table}
				WHERE day_utc = %s",
				$day_utc
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $row ) ) {
			return $empty;
		}

		return array(
			'clicks'      => (int) $row['clicks'],
			'conversions' => null === $row['conversions'] ? null : (int) $row['conversions'],
		);
	}

	/**
	 * Per-campaign totals for a trusted id list.
	 *
	 * Callers must pass only ids already authorized for the current user.
	 * This is a SUM, not an ownership check.
	 *
	 * @param array<int, int> $campaign_ids Campaign post ids.
	 * @return array<int, array{impressions: int, clicks: int}>
	 */
	/**
	 * Site-wide delivered and viewable counts for one UTC day.
	 *
	 * Not organization-scoped: this answers an operator's question about whether
	 * measurement is working at all, which is a property of the site rather than
	 * of a tenant.
	 *
	 * `viewables` stays nullable for the reason it is nullable everywhere else —
	 * a day nobody measured is not a day nothing was seen.
	 *
	 * @param string $day_utc UTC day, `Y-m-d`.
	 * @return array{impressions: int, viewables: int|null}
	 */
	public function day_viewability( string $day_utc ): array {
		$empty = array(
			'impressions' => 0,
			'viewables'   => null,
		);

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day_utc ) || ! $this->table_exists() ) {
			return $empty;
		}

		global $wpdb;

		$table = $this->table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefix+constant; the day is prepared.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(impressions), 0) AS impressions, SUM(viewables) AS viewables
				FROM {$table}
				WHERE day_utc = %s",
				$day_utc
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $row ) ) {
			return $empty;
		}

		return array(
			'impressions' => (int) $row['impressions'],
			'viewables'   => null === $row['viewables'] ? null : (int) $row['viewables'],
		);
	}

	/**
	 * Lifetime and same-day impressions per line item, for pacing.
	 *
	 * Keyed by line item because that is what carries a cap. Counting by
	 * campaign was correct only while every campaign had exactly one line item —
	 * the moment a second exists, each would count its siblings' impressions
	 * against its own cap and stop delivering early.
	 *
	 * One query for the whole candidate set rather than one per candidate: the
	 * decision path may not do work proportional to the number of candidates.
	 *
	 * The day is UTC, matching how rollups are keyed, so "today" means the same
	 * thing here as it does in the row being read.
	 *
	 * @param array<int, int> $line_item_ids Line-item ids.
	 * @param string          $day_utc       UTC day, `Y-m-d`; defaults to today.
	 * @return array<int, array{lifetime: int, today: int}> Keyed by line-item id.
	 */
	public function delivery_totals_for_line_items( array $line_item_ids, string $day_utc = '' ): array {
		$ids = array();

		foreach ( $line_item_ids as $line_item_id ) {
			$id = (int) $line_item_id;

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		$ids = array_values( $ids );

		if ( array() === $ids ) {
			return array();
		}

		global $wpdb;

		$table        = $this->table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$day          = '' !== $day_utc ? $day_utc : gmdate( 'Y-m-d' );
		$args         = array_merge( array( $day ), $ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name is prefix+constant; placeholders are a fixed %d list matching $ids.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT line_item_id,
					SUM(impressions) AS lifetime,
					SUM(CASE WHEN day_utc = %s THEN impressions ELSE 0 END) AS today
				FROM {$table}
				WHERE line_item_id IN ({$placeholders})
				GROUP BY line_item_id",
				...$args
			),
			ARRAY_A
		);
		// phpcs:enable

		$totals = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$totals[ (int) ( $row['line_item_id'] ?? 0 ) ] = array(
					'lifetime' => max( 0, (int) ( $row['lifetime'] ?? 0 ) ),
					'today'    => max( 0, (int) ( $row['today'] ?? 0 ) ),
				);
			}
		}

		return $totals;
	}

	/**
	 * Lifetime impressions, clicks and conversions per campaign.
	 *
	 * `conversions` is nullable for the reason the column is: a campaign that
	 * ran before P12 did not convert nobody, it was not being counted. A
	 * `COALESCE` here would turn an unmeasured campaign into a failed one.
	 *
	 * @param array<int, int> $campaign_ids Campaign post ids.
	 * @return array<int, array{impressions: int, clicks: int, conversions: int|null}> Keyed by campaign id.
	 */
	public function totals_for_campaigns( array $campaign_ids ): array {
		$ids = array();

		foreach ( $campaign_ids as $campaign_id ) {
			$id = (int) $campaign_id;

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		$ids = array_values( $ids );

		if ( array() === $ids ) {
			return array();
		}

		global $wpdb;

		$table        = $this->table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name is prefix+constant; placeholders are a fixed %d list matching $ids.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT campaign_id, SUM(impressions) AS impressions, SUM(clicks) AS clicks,
					SUM(conversions) AS conversions
				FROM {$table}
				WHERE campaign_id IN ({$placeholders})
				GROUP BY campaign_id",
				...$ids
			),
			ARRAY_A
		);
		// phpcs:enable

		$totals = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$campaign_id = (int) $row['campaign_id'];

				$totals[ $campaign_id ] = array(
					'impressions' => (int) $row['impressions'],
					'clicks'      => (int) $row['clicks'],

					// SUM of all-NULL is NULL, which is the answer wanted: no
					// measured day contributed, so there is nothing to report.
					'conversions' => null === $row['conversions'] ? null : (int) $row['conversions'],
				);
			}
		}

		return $totals;
	}
}
