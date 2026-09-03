<?php
/**
 * The version-to-migration map.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;
use Aggressive\Ads\Workflow\Decision_Metrics;
use Aggressive\Ads\Repository\Creative_Attachment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Access_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Service_Container;
use Aggressive\Ads\Storage\Private_Storage;

/**
 * What each database version does, in one place.
 *
 * Separate from `Service_Registrar` because a wrong factory throws on boot while
 * a wrong migration runs once against real data. Each step names the service
 * doing the work, so the history reads as decisions rather than implementation
 * and `LineItemUpgradeWiringTest` exercises the real map.
 */
final class Migration_Map {

	/**
	 * Every migration step, keyed by the database version it produces.
	 *
	 * @param Service_Container $c Container, for resolving the services each step drives.
	 * @return array<int, callable(): void>
	 */
	public static function steps( Service_Container $c ): array {
		return array(
			2  => static function () use ( $c ): void {
				$c->get( Installer::class )->install_org_access();
			},
			4  => static function () use ( $c ): void {
				$c->get( Installer::class )->install_delivery_tables();
			},
			5  => static function () use ( $c ): void {
				$c->get( Installer::class )->migrate_event_token_uniqueness();
			},
			// Renames the private creative directory. No admin notice:
			// a site whose server rule still names the old path is
			// covered because the migration leaves nothing behind it.
			6  => static function () use ( $c ): void {
				$c->get( Private_Storage::class )->migrate_legacy_directory();
			},
			// Creative promoted before the marker existed is still in
			// the Media Library until it is marked.
			7  => static function () use ( $c ): void {
				$c->get( Creative_Attachment_Repository::class )->backfill_creative_attachment_marks();
			},
			// The daily private-storage probe and its stored verdict
			// outlived the notice they fed. Left alone they would stay
			// on the schedule of every upgraded site forever, firing a
			// callback nothing registers any more.
			8  => static function (): void {
				wp_clear_scheduled_hook( 'aggr_verify_private_storage' );
				delete_option( 'aggr_private_storage_status' );
			},
			// The object index gains org_id, which for_object() also
			// filters on. Without it the optimizer index-merges and
			// filesorts; see Audit_Repository::migrate_object_index().
			9  => static function () use ( $c ): void {
				$c->get( Audit_Repository::class )->migrate_object_index();
			},
			// Lookup keys move off wp_salt( 'auth' ) onto a salt that
			// does not rotate. Until this runs, a site whose auth salts
			// ever changed cannot rename an organization and is not
			// detecting duplicate names at all. Recomputed from the
			// plaintext the same rows already carry, so nothing is lost.
			10 => static function () use ( $c ): void {
				$c->get( Org_Access_Repository::class )->reindex_active_keys();
			},
			// Creative uploaded before encryption at rest is still
			// plaintext on disk. Non-destructive and resumable: a file
			// that will not encrypt cleanly stays as it was, and reads
			// pass an unencrypted file through, so an interrupted run
			// leaves a working mixture rather than a broken queue.
			11 => static function () use ( $c ): void {
				$c->get( Private_Storage::class )->encrypt_existing_files();
			},
			12 => static function () use ( $c ): void {
				$c->get( Installer::class )->install_line_items();
				$c->get( Line_Item_Migrator::class )->start();
			},
			// The default line item's name is derived from the campaign
			// title, and nothing re-derived it after a rename. Adding
			// the column gives every existing row the "derived"
			// default, which is wrong for any line item a publisher
			// renamed, so the rows are classified rather than assumed.
			13 => static function () use ( $c ): void {
				$c->get( Installer::class )->install_line_items();
				$c->get( Line_Item_Migrator::class )->start_name_provenance();
			},

			/*
			 * P2 schema only. No backfill runs here: the tables are
			 * empty and nothing reads them yet, so a site upgrading to
			 * 14 gains two tables and no behaviour. The migration that
			 * fills them ships with the code that reads them, so a
			 * half-migrated site is never a site serving from a table
			 * nobody has finished writing.
			 */
			14 => static function () use ( $c ): void {
				$c->get( Installer::class )->install_creative_model();
			},

			/*
			 * The asset table gains root_creative_id, which is the key
			 * every revision of one artwork shares. Version 14 shipped
			 * the table empty and nothing read it, so adding the column
			 * and starting the backfill together is safe: there is no
			 * existing row to reconcile.
			 */
			15 => static function () use ( $c ): void {
				$c->get( Installer::class )->install_creative_model();
				$c->get( Creative_Assignment_Migrator::class )->start();
			},

			/*
			 * A cap belongs to the line item, so its counter must too. Counting
			 * by campaign was right only while a campaign had exactly one line
			 * item; a second would have spent its sibling's impressions.
			 */
			16 => static function () use ( $c ): void {
				$c->get( Rollup_Repository::class )->migrate_line_item_attribution();
			},

			/*
			 * Additive only. History is deliberately left NULL — a day before
			 * measurement existed has no viewability, and writing zero would
			 * read as "nothing was seen" rather than "nobody was looking".
			 */
			17 => static function () use ( $c ): void {
				$c->get( Rollup_Repository::class )->install_table();

				// The boundary between "nobody was measuring" and "nothing was
				// seen". Recorded once, so the reconciler can tell days either
				// side of it apart rather than zeroing history.
				add_option( Rollup_Repository::OPTION_VIEWABILITY_SINCE, gmdate( 'Y-m-d' ), '', false );
			},

			/*
			 * P12 storage only. The conversion ledger is created empty and
			 * nothing writes it yet, so a site upgrading to 18 gains a table,
			 * a nullable column and no behaviour — the same staging that
			 * version 14 used for the creative model, and for the same reason:
			 * the code that fills a table must ship with the code that reads
			 * it, or a half-migrated site is a site serving from a table
			 * nobody has finished writing.
			 *
			 * `conversions` is left NULL on history for P11's reason one phase
			 * on. A day before conversions were measured did not convert
			 * nobody; nobody was counting.
			 */
			18 => static function () use ( $c ): void {
				$c->get( Installer::class )->install_conversions();
				$c->get( Rollup_Repository::class )->install_table();
			},

			/*
			 * The definitions table, which version 18's ledger points at by id.
			 * A definition is created by staff, so an upgraded site has none
			 * until somebody makes one — and with no definition, ingestion
			 * accepts nothing, which is the correct default for a site that has
			 * not asked to measure conversions.
			 *
			 * `install_conversions()` creates both tables, so a repair install
			 * heals a site whose upgrade stopped between them.
			 */
			19 => static function () use ( $c ): void {
				$c->get( Installer::class )->install_conversions();
			},

			/*
			 * Repairs assignments whose denormalized snapshot went stale.
			 *
			 * `candidates_for_placement()` selects on `status = 'live'` and
			 * reads `attachment_id` off the row. The only code that ever wrote
			 * either from the campaign was version 15's backfill, so a campaign
			 * that went live afterwards kept assignments at `draft` and could
			 * never serve — and `Assignment_Projection` only fixes that from
			 * the next transition onwards. Existing rows need this.
			 *
			 * Data, not schema, and the first migration here that changes what
			 * delivers. It is idempotent and derives from the campaign rather
			 * than stepping from the current value, so a re-run reaches the same
			 * state; terminal rows are excluded, so a withdrawal survives.
			 */
			20 => static function () use ( $c ): void {
				$c->get( Creative_Assignment_Repository::class )->reproject_all();
			},

			/*
			 * The credential table for server-to-server reporting.
			 *
			 * Empty on arrival, and unlike versions 14 and 18 that is the end
			 * state rather than a staging one: a credential is a secret staff
			 * issue deliberately, so an upgraded site has none until somebody
			 * makes one, and with none the new route authenticates nobody. That
			 * is the correct default for a site that has not asked for
			 * server-side reporting.
			 *
			 * `install_conversions()` creates all three tables, so a repair
			 * install heals a site whose upgrade stopped part way.
			 */
			21 => static function () use ( $c ): void {
				$c->get( Installer::class )->install_conversions();
			},

			/*
			 * `operator_paused` records who paused an assignment.
			 *
			 * Additive, and every existing row is correctly 0: before this
			 * column there was no way for a person to pause one assignment and
			 * have it stay paused, so no historical row can have been in that
			 * state. Defaulting to zero is the true answer rather than a
			 * convenient one — unlike `viewables`, where zero would have meant
			 * "nothing was seen" instead of "nobody was counting".
			 *
			 * A campaign-paused assignment stays exactly as it is and is
			 * resumed by the next campaign transition, which is the behaviour it
			 * already had.
			 */
			22 => static function () use ( $c ): void {
				$c->get( Creative_Assignment_Repository::class )->install_table();
			},

			/*
			 * Decision outcome counters, and the end of the option they replace.
			 *
			 * The option is deleted rather than backfilled, and that loss is
			 * deliberate: it held one unbounded running total with no time
			 * dimension, so there is no day to attribute any of it to. Carrying
			 * the number forward would put a total of unknown age beside
			 * per-day rows and invite somebody to add them together.
			 *
			 * Additive otherwise — a new table, nothing existing changes shape,
			 * so a site that rolls back loses counters and no history.
			 */
			23 => static function () use ( $c ): void {
				$c->get( Decision_Rollup_Repository::class )->install_table();

				delete_option( Decision_Metrics::LEGACY_OPTION_EXCLUSIONS );
			},

			/*
			 * Schema versioning, and tenancy that stops moving.
			 *
			 * `aggr_rollups` gains `org_id` and `projector_version`;
			 * `aggr_events` gains `schema_version`. All three are additive with
			 * defaults, so `dbDelta` adds columns and no existing row changes
			 * meaning.
			 *
			 * **The rows are filled in place rather than rebuilt**, and the
			 * reason is that this table is not only the reporting source: it is
			 * the pacing and frequency counter. Emptying it to let the
			 * reconciler regenerate history would also reset every live cap,
			 * and a campaign whose counter starts from nothing overdelivers for
			 * the rest of the day — `ReleaseUpgradePathTest` names that
			 * consequence for the same table one migration earlier.
			 *
			 * One `UPDATE` with a join, no cursor and no staging, because there
			 * is nothing to stage: the statement is idempotent on `org_id = 0`,
			 * so an interrupted run resumes by being run again, and a site with
			 * no unattributed rows does no work.
			 *
			 * What it cannot do is recover *historical* tenancy. Nothing ever
			 * recorded which organization owned a campaign last month, so this
			 * writes today's answer onto older rows — which is exactly what the
			 * read-time join it replaces already returned. The value of the
			 * column is that tenancy stops moving from here, not that the past
			 * becomes knowable.
			 */
			24 => static function () use ( $c ): void {
				$c->get( Installer::class )->install_delivery_tables();

				$c->get( \Aggressive\Ads\Repository\Rollup_Repository::class )->backfill_org_ids();
			},
		);
	}
}
