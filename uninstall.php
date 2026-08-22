<?php
/**
 * Uninstall handler.
 *
 * Runs only on a real uninstall, never on deactivation. WordPress loads this
 * file standalone with no plugin bootstrapped, so it wires up its own
 * autoloader rather than assuming anything is available.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/inc/class-autoloader.php';

Aggressive\Ads\Autoloader::register( __DIR__ . '/inc' );

/**
 * Deletes campaign, creative and organization posts on the current site.
 *
 * Stays in this file because get_posts() is forbidden inside inc/ outside
 * Repository/. Schema, roles, cron and options are Uninstaller.
 *
 * @return void
 */
function aggr_uninstall_delete_content(): void {
	$aggr_batch_size = 200;

	$aggr_statuses = array_merge(
		Aggressive\Ads\Core\Post_Statuses::all(),
		array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' )
	);

	$aggr_post_types = Aggressive\Ads\Core\Post_Types::all();

	foreach ( $aggr_post_types as $aggr_post_type ) {
		do {
			$aggr_batch = get_posts(
				array(
					'post_type'              => $aggr_post_type,

					/*
					 * Every status by name, and never 'any'.
					 *
					 * 'any' means "every status not excluded from search", and all
					 * eleven campaign statuses are excluded from search by design.
					 * This loop therefore matched no campaign at all: an uninstall
					 * that had been asked to delete the content deleted the
					 * organizations and packages, reported success, and left every
					 * campaign row in the database.
					 */
					'post_status'            => $aggr_statuses,
					'numberposts'            => $aggr_batch_size,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'update_post_meta_cache' => false,
				)
			);

			$aggr_deleted = 0;

			foreach ( $aggr_batch as $aggr_post_id ) {
				if ( false !== wp_delete_post( (int) $aggr_post_id, true ) ) {
					++$aggr_deleted;
				}
			}

			// Terminates on an empty batch, and also when a batch cannot be
			// deleted at all — otherwise a post another plugin refuses to delete
			// spins this loop until the request dies.
		} while ( array() !== $aggr_batch && $aggr_deleted > 0 );
	}
}

/**
 * Removes the private creative directory and everything under it.
 *
 * Tied to the same opt-in as the content above, not run unconditionally. The
 * creative posts and the bytes they point at are one record: deleting the files
 * while preserving the posts leaves a campaign history whose creatives cannot
 * be opened, which is worse than leaving both.
 *
 * @return void
 */
function aggr_uninstall_delete_private_files(): void {
	$uploads = wp_upload_dir();
	$base    = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ? $uploads['basedir'] : '';

	if ( '' === $base ) {
		return;
	}

	global $wp_filesystem;

	if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		WP_Filesystem();
	}

	$aggr_filesystem = $wp_filesystem;

	// The pre-6 name too: a site upgraded partway, or never upgraded at all,
	// still has bytes under it, and uninstall is the last chance to clear them.
	foreach ( array( 'ads-uploads', 'aggr-private' ) as $aggr_directory ) {
		$aggr_root = rtrim( $base, '/\\' ) . '/' . $aggr_directory;

		if ( ! is_dir( $aggr_root ) ) {
			continue;
		}

		$aggr_names = scandir( $aggr_root );

		if ( false === $aggr_names ) {
			continue;
		}

		foreach ( $aggr_names as $aggr_name ) {
			if ( '.' === $aggr_name || '..' === $aggr_name ) {
				continue;
			}

			$aggr_path = $aggr_root . '/' . $aggr_name;

			if ( is_file( $aggr_path ) ) {
				wp_delete_file( $aggr_path );
			}
		}

		// Left in place when anything unexpected remains: a stray file is
		// somebody else's, and this is not the code to decide otherwise.
		if ( $aggr_filesystem instanceof WP_Filesystem_Base ) {
			$aggr_filesystem->rmdir( $aggr_root );
		}
	}
}

$aggr_after_schema = static function ( bool $delete_content ): void {
	if ( $delete_content ) {
		aggr_uninstall_delete_content();
		aggr_uninstall_delete_private_files();
	}
};

/*
 * Campaign, creative and organization content is deliberately preserved unless
 * the site owner explicitly opted in. Deleting a plugin should not silently
 * destroy the record of what a business ran and billed for.
 *
 * Network Admin uninstall walks every site. A per-site uninstall touches only
 * the current blog, so removing the plugin from one tenant cannot wipe another.
 * See docs/data-schema.md.
 */
if ( is_multisite() && is_network_admin() ) {
	Aggressive\Ads\Install\Uninstaller::run_network( $aggr_after_schema );
} else {
	$aggr_delete_content = Aggressive\Ads\Install\Uninstaller::should_delete_content();
	Aggressive\Ads\Install\Uninstaller::run_for_current_site();
	$aggr_after_schema( $aggr_delete_content );
}
