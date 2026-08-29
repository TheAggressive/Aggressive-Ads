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
				$c->get( Creative_Repository::class )->backfill_creative_attachment_marks();
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
			},
		);
	}
}
