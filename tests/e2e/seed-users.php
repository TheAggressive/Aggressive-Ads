<?php
/**
 * Makes the two browser-test identities deterministic on any local site.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$aggr_admin = get_user_by( 'login', 'admin' );

if ( ! $aggr_admin instanceof WP_User ) {
	$aggr_admin_id = wp_insert_user(
		array(
			'user_login' => 'admin',
			'user_pass'  => 'admin',
			'user_email' => 'admin@example.test',
			'role'       => 'administrator',
		)
	);

	if ( is_wp_error( $aggr_admin_id ) ) {
		WP_CLI::error( 'Could not create the E2E administrator: ' . $aggr_admin_id->get_error_message() );
	}

	$aggr_admin = get_user_by( 'id', $aggr_admin_id );
}

if ( ! $aggr_admin instanceof WP_User ) {
	WP_CLI::error( 'Could not resolve the E2E administrator.' );
}

/*
 * add_role(), not set_role(). This runs against a real development site, where
 * the account named `admin` may legitimately carry roles this suite knows
 * nothing about — set_role() replaces the lot, and nothing here restores them.
 * Granting the capability the suite needs is enough; taking others away is
 * collateral, and it is not reversible.
 */
if ( ! in_array( 'administrator', $aggr_admin->roles, true ) ) {
	$aggr_admin->add_role( 'administrator' );
}

wp_set_password( 'admin', $aggr_admin->ID );

$aggr_advertiser = get_user_by( 'login', 'advertiser' );

if ( ! $aggr_advertiser instanceof WP_User ) {
	WP_CLI::error( 'The development seed did not create the E2E advertiser.' );
}

wp_set_password( 'advertiser', $aggr_advertiser->ID );

WP_CLI::log( 'E2E users are ready.' );
