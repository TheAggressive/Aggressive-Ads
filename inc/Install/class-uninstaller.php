<?php
/**
 * Removes this plugin's schema, roles, cron and options from the current site.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Org_Access_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Asset_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Workflow\Campaign_Clock;
use Aggressive\Ads\Workflow\Audit_Retention;
use Aggressive\Ads\Workflow\Creative_Retention;
use Aggressive\Ads\Workflow\Ending_Soon_Notifier;
use Aggressive\Ads\Workflow\Event_Retention;
use Aggressive\Ads\Workflow\Rollup_Reconciler;

/**
 * The current-site half of uninstall.php.
 *
 * Post deletion stays in uninstall.php: get_posts() is forbidden outside
 * inc/Repository/, and the root uninstall file is the documented exception
 * for a one-shot content wipe. This class drops tables, roles, cron and
 * options — the work site deletion also needs, because core's
 * wp_uninitialize_site() does not drop plugin tables.
 */
final class Uninstaller {

	/**
	 * Removes the private creative directory and everything under it.
	 *
	 * Lives here rather than in uninstall.php because that file executes an
	 * uninstall the moment it is required, so nothing in it can be reached by a
	 * test without destroying the site running the test. This deletes an
	 * advertiser's only remaining copy of unapproved artwork; it is the last
	 * code in the plugin that should be taken on trust.
	 *
	 * Tied to the same opt-in as the content deletion beside it, not run
	 * unconditionally. The creative posts and the bytes they point at are one
	 * record: deleting the files while preserving the posts leaves a campaign
	 * history whose creatives cannot be opened, which is worse than leaving
	 * both.
	 *
	 * @return int Files deleted.
	 */
	public static function delete_private_files(): int {
		$uploads = wp_upload_dir();
		$base    = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ? $uploads['basedir'] : '';

		if ( '' === $base ) {
			return 0;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			WP_Filesystem();
		}

		$deleted = 0;

		/*
		 * The pre-6 name too. A site upgraded partway, or never upgraded at
		 * all, still has bytes under it, and uninstall is the last chance to
		 * clear them.
		 */
		foreach ( array( Private_Storage::DIRECTORY, Private_Storage::LEGACY_DIRECTORY ) as $directory ) {
			$root = rtrim( $base, '/\\' ) . '/' . $directory;

			if ( ! is_dir( $root ) ) {
				continue;
			}

			$names = scandir( $root );

			if ( false === $names ) {
				continue;
			}

			foreach ( $names as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}

				$path = $root . '/' . $name;

				if ( is_file( $path ) ) {
					wp_delete_file( $path );
					++$deleted;
				}
			}

			// Left in place when anything unexpected remains: a stray
			// directory is somebody else's, and this is not the code to decide
			// otherwise.
			if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
				$wp_filesystem->rmdir( $root );
			}
		}

		return $deleted;
	}

	/**
	 * Whether the current site opted to delete campaign content.
	 *
	 * Read before run_for_current_site() deletes the option that holds the
	 * answer.
	 */
	public static function should_delete_content(): bool {
		return (bool) get_option( Installer::OPTION_DELETE_DATA, false );
	}

	/**
	 * Drops plugin tables, roles, cron and options on the current blog.
	 *
	 * Does not delete posts. WordPress drops wp_{n}_posts on site deletion;
	 * uninstall.php deletes posts only when the site owner opted in.
	 */
	public static function run_for_current_site(): void {
		( new Roles() )->remove();

		( new Audit_Repository() )->drop_table();
		( new Org_Access_Repository() )->drop_table();
		( new Event_Repository() )->drop_table();
		( new Rollup_Repository() )->drop_table();
		( new Line_Item_Repository( new Campaign_Repository() ) )->drop_table();
		( new Creative_Assignment_Repository() )->drop_table();
		( new Creative_Asset_Repository() )->drop_table();

		Campaign_Clock::unschedule();
		Ending_Soon_Notifier::unschedule();
		Creative_Retention::unschedule();
		Audit_Retention::unschedule();
		Event_Retention::unschedule();
		Rollup_Reconciler::unschedule();
		Line_Item_Migrator::unschedule();

		/*
		 * Legacy, and named as literals on purpose: the service that owned these
		 * is gone. A site that ran an older version still has the daily probe on
		 * its schedule and the verdict in its options, and uninstall is the last
		 * chance to take them with us.
		 */
		wp_clear_scheduled_hook( 'aggr_verify_private_storage' );
		delete_option( 'aggr_private_storage_status' );

		foreach ( Installer::options() as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * Walks every site and runs the current-site cleanup on each.
	 *
	 * Only the network Plugins screen should call this. A per-site uninstall
	 * that walked the network would wipe tenants still using the plugin.
	 *
	 * @param callable(bool): void|null $after_schema Called on each site after
	 *                                                schema removal, with the
	 *                                                pre-read delete-content flag.
	 */
	public static function run_network( ?callable $after_schema = null ): void {
		foreach ( self::site_ids() as $blog_id ) {
			self::on_site(
				$blog_id,
				static function () use ( $after_schema ): void {
					$delete = self::should_delete_content();
					self::run_for_current_site();

					if ( null !== $after_schema ) {
						$after_schema( $delete );
					}
				}
			);
		}
	}

	/**
	 * Runs a callback against another blog's prefix, then restores.
	 *
	 * Fill, beacon, and the click hop must never call this. Install and
	 * uninstall are the only legitimate switch_to_blog() uses in this plugin.
	 *
	 * @param int      $blog_id  Target site.
	 * @param callable $callback Work that reads $wpdb->prefix.
	 */
	public static function on_site( int $blog_id, callable $callback ): void {
		if ( $blog_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Site install and uninstall must run against the target prefix. The fill path never switches.
		switch_to_blog( $blog_id );

		try {
			$callback();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Every site id, including archived, in bounded pages.
	 *
	 * @return array<int, int>
	 */
	private static function site_ids(): array {
		$ids    = array();
		$offset = 0;

		do {
			$page = get_sites(
				array(
					'fields'  => 'ids',
					'number'  => 100,
					'offset'  => $offset,
					'deleted' => 0,
				)
			);

			if ( ! is_array( $page ) || array() === $page ) {
				break;
			}

			$ids     = array_merge( $ids, array_map( 'intval', $page ) );
			$offset += 100;
			$got     = count( $page );
		} while ( 100 === $got );

		return $ids;
	}
}
