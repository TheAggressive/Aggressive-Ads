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

$aggr_after_schema = static function ( bool $delete_content ): void {
	if ( $delete_content ) {
		aggr_uninstall_delete_content();
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
