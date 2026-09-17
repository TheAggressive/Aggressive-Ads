<?php
/**
 * Clears the rate limiter's counters for the browser suite.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

/*
 * Clear the sign-in rate limiter.
 *
 * The browser suite signs in several times per run, and the limiter counts per
 * client — so a few consecutive runs from one machine trip it and every spec
 * then fails on a "too many attempts" message that has nothing to do with what
 * it was testing. Once per run was not enough either: one run grew past twenty
 * portal sign-ins inside the fifteen-minute window, so the reflow spec at the
 * end was refused. `signIn()` runs this before each sign-in, so the count a
 * spec meets never depends on how many specs ran before it.
 *
 * Resetting the counter is test isolation; raising the limit to accommodate
 * the tests would be weakening the control the tests exist to protect.
 */
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Development fixture: transients have no lookup API by prefix, and this file never ships.
$aggr_limiter_keys = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_aggr_rl_%'"
);

foreach ( (array) $aggr_limiter_keys as $aggr_option ) {
	delete_transient( str_replace( '_transient_', '', (string) $aggr_option ) );
}
