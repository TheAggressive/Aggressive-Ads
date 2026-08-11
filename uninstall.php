<?php
/**
 * Uninstall handler.
 *
 * Runs only on a real uninstall, never on deactivation. WordPress loads this
 * file standalone with no plugin bootstrapped, so it wires up its own
 * autoloader rather than assuming anything is available.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/inc/class-autoloader.php';

LAAO_Advertiser_Portal\Autoloader::register( __DIR__ . '/inc' );

$laao_ads_installer_options = LAAO_Advertiser_Portal\Install\Installer::options();

// Roles and their granted capabilities go, because leaving an advertiser role
// behind on a site with no plugin is a capability nobody can explain later.
( new LAAO_Advertiser_Portal\Security\Roles() )->remove();

( new LAAO_Advertiser_Portal\Repository\Audit_Repository() )->drop_table();

// The reconciler's hourly event, which otherwise stays in the cron array
// pointing at a hook nothing answers.
LAAO_Advertiser_Portal\Workflow\Campaign_Clock::unschedule();

/*
 * Campaign, creative and organization content is deliberately preserved unless
 * the site owner explicitly opted in.
 *
 * Deleting a plugin should not silently destroy the record of what a business
 * ran and billed for. Someone who genuinely wants that has to ask for it — and
 * the option is read before the options are deleted, for obvious reasons.
 */
$laao_ads_delete_content = (bool) get_option(
	LAAO_Advertiser_Portal\Install\Installer::OPTION_DELETE_DATA,
	false
);

foreach ( $laao_ads_installer_options as $laao_ads_option ) {
	delete_option( $laao_ads_option );
}

if ( ! $laao_ads_delete_content ) {
	return;
}

/*
 * Deleted in batches, not all at once.
 *
 * A site with tens of thousands of creatives cannot load every id into one
 * request and then delete them one at a time — it times out partway, leaving
 * the plugin gone and its content half-removed, which is the worst of both
 * outcomes and impossible to resume.
 */
const LAAO_ADS_UNINSTALL_BATCH = 200;

// Our own statuses plus the core ones the non-campaign types are stored in.
$laao_ads_statuses = array_merge(
	LAAO_Advertiser_Portal\Core\Post_Statuses::all(),
	array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' )
);

foreach ( LAAO_Advertiser_Portal\Core\Post_Types::all() as $laao_ads_post_type ) {
	do {
		$laao_ads_batch = get_posts(
			array(
				'post_type'              => $laao_ads_post_type,

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
				'post_status'            => $laao_ads_statuses,
				'numberposts'            => LAAO_ADS_UNINSTALL_BATCH,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			)
		);

		$laao_ads_deleted = 0;

		foreach ( $laao_ads_batch as $laao_ads_post_id ) {
			if ( false !== wp_delete_post( (int) $laao_ads_post_id, true ) ) {
				++$laao_ads_deleted;
			}
		}

		// Terminates on an empty batch, and also when a batch cannot be
		// deleted at all — otherwise a post another plugin refuses to delete
		// spins this loop until the request dies.
	} while ( array() !== $laao_ads_batch && $laao_ads_deleted > 0 );
}
