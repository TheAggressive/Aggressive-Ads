<?php
/**
 * Machine-readable durability snapshot around an HTTP load run.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Workflow\Event_Retention;
use Aggressive\Ads\Workflow\Rollup_Reconciler;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

global $wpdb;

$fixture = get_option( 'aggr_load_fixture', array() );

if ( ! is_array( $fixture ) || (int) ( $fixture['placement_id'] ?? 0 ) <= 0 ) {
	WP_CLI::error( 'The load fixture is missing. Run bin/load/seed.php first.' );
}

$container    = Plugin::instance()->container();
$events       = $container->get( Event_Repository::class );
$rollups      = $container->get( Rollup_Repository::class );
$assignments  = $container->get( Creative_Assignment_Repository::class );
$event_table  = $events->table_name();
$rollup_table = $rollups->table_name();
$placement_id = (int) $fixture['placement_id'];
$cache_key    = 'aggr_load_atomic_' . wp_generate_uuid4();

wp_cache_delete( $cache_key, 'aggr_load' );
$cache_added   = wp_cache_add( $cache_key, 1, 'aggr_load', 60 );
$cache_count   = $cache_added ? wp_cache_incr( $cache_key, 1, 'aggr_load' ) : false;
$cache_deleted = wp_cache_delete( $cache_key, 'aggr_load' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only measurement of this plugin's isolated load-test ledger.
$event_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$event_table} WHERE event = %s AND placement_id = %d", Event_Repository::TYPE_IMPRESSION, $placement_id ) );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only measurement of this plugin's isolated load-test projection.
$rollup_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(impressions), 0) FROM {$rollup_table} WHERE placement_id = %d", $placement_id ) );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Replay uniqueness verification on the isolated load-test ledger.
$duplicate_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT token_hash, event FROM {$event_table} GROUP BY token_hash, event HAVING COUNT(*) > 1) duplicate_events" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL operational counter sampled before and after the isolated load run.
$deadlock_row = $wpdb->get_row( "SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'", ARRAY_A );
$deadlocks    = is_array( $deadlock_row ) ? (int) ( $deadlock_row['Value'] ?? 0 ) : 0;

$snapshot = array(
	'timestamp_gmt'        => gmdate( 'c' ),
	'wordpress_version'    => get_bloginfo( 'version' ),
	'php_version'          => PHP_VERSION,
	'database_version'     => $wpdb->db_version(),
	'fixture_ads'          => (int) ( $fixture['ads'] ?? 0 ),
	'candidate_count'      => count( $assignments->candidates_for_placement( $placement_id, time() ) ),
	'event_count'          => $event_count,
	'rollup_count'         => $rollup_count,
	'duplicate_count'      => $duplicate_count,
	'innodb_deadlocks'     => $deadlocks,
	'external_cache'       => wp_using_ext_object_cache(),
	'atomic_cache_counter' => $cache_added && 2 === $cache_count && $cache_deleted,
	'reconciler_schedule'  => wp_get_schedule( Rollup_Reconciler::HOOK ),
	'retention_schedule'   => wp_get_schedule( Event_Retention::HOOK ),
);

WP_CLI::line( (string) wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES ) );
