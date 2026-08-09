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

foreach ( LAAO_Advertiser_Portal\Core\Post_Types::all() as $laao_ads_post_type ) {
	$laao_ads_posts = get_posts(
		array(
			'post_type'        => $laao_ads_post_type,
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
		)
	);

	foreach ( $laao_ads_posts as $laao_ads_post_id ) {
		wp_delete_post( (int) $laao_ads_post_id, true );
	}
}
