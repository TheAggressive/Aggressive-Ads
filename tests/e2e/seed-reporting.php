<?php
/**
 * Turns Reporting on, and puts something in the counters to report.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Decision_Outcome;
use Aggressive\Ads\Domain\No_Fill_Reason;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

/*
 * **Reporting ships off, so the browser suite has to switch it on.**
 *
 * Every reporting surface is gated on the module, which means a spec that
 * simply navigated to the Reports screen would find the "switched off" notice
 * and pass every assertion it could still make. That is the shape of test this
 * repository keeps catching: green over a screen it never actually saw.
 */
$settings = Plugin::instance()->container()->get( Settings::class );
$document = $settings->get();

$document['modules'][ Settings_Schema::MODULE_REPORTING ] = true;

if ( ! $settings->save( $document ) ) {
	WP_CLI::error( 'seed-reporting: could not enable the Reporting module.' );
}

$placement = get_page_by_path( 'e2e-browser-placement', OBJECT, Post_Types::PLACEMENT );

if ( ! $placement instanceof WP_Post ) {
	WP_CLI::error( 'seed-reporting: the browser placement is missing; seed-mappings must run first.' );
}

/*
 * A spread that exercises every branch the screen has: a fill rate below 100%,
 * more than one reason, and therefore a reason table with shares in it. A
 * placement that filled every time would render the "every request was filled"
 * path and prove nothing about the table.
 */
$rollups = Plugin::instance()->container()->get( Decision_Rollup_Repository::class );

$written = $rollups->add(
	gmdate( 'Y-m-d' ),
	$placement->ID,
	array(
		Decision_Outcome::REQUEST          => 120,
		Decision_Outcome::FILL             => 90,
		No_Fill_Reason::TARGETING_MISMATCH => 20,
		No_Fill_Reason::FREQUENCY_CAPPED   => 10,
	)
);

if ( ! $written ) {
	WP_CLI::error( 'seed-reporting: the decision counters were not written.' );
}

WP_CLI::success( sprintf( 'seed-reporting: reporting on, counters seeded for placement %d.', $placement->ID ) );
