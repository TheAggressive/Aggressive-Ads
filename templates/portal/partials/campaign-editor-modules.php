<?php
/**
 * Hydrates wizard / autosave / upload stores for an editable campaign.
 *
 * @package Aggressive\Ads
 *
 * @var array<string, mixed> $aggr_campaign Campaign row.
 * @var string               $aggr_step     Current display step.
 * @var bool                 $aggr_review_ready Whether submit is reachable.
 * @var array<int, mixed>    $aggr_slots    Creative slots.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Plugin;

$aggr_campaign     = isset( $aggr_campaign ) && is_array( $aggr_campaign ) ? $aggr_campaign : array();
$aggr_step         = isset( $aggr_step ) && is_string( $aggr_step ) ? $aggr_step : 'details';
$aggr_review_ready = true === ( $aggr_review_ready ?? false );
$aggr_slots        = isset( $aggr_slots ) && is_array( $aggr_slots ) ? $aggr_slots : array();
$aggr_wizard_id    = 'campaign-' . (int) ( $aggr_campaign['id'] ?? 0 );

$aggr_step_label = sprintf(
	/* translators: 1: current step number, 2: total steps, 3: step title. */
	__( 'Step %1$s of %2$s: %3$s', 'aggressive-ads' ),
	(string) ( array_search( $aggr_step, array( 'details', 'package', 'creative', 'destination', 'review', 'submit' ), true ) + 1 ),
	'6',
	match ( $aggr_step ) {
		'details'     => __( 'Campaign details', 'aggressive-ads' ),
		'package'     => __( 'Choose a package', 'aggressive-ads' ),
		'creative'    => __( 'Upload creative', 'aggressive-ads' ),
		'destination' => __( 'Confirm destinations and schedule', 'aggressive-ads' ),
		'review'      => __( 'Review your campaign', 'aggressive-ads' ),
		default       => __( 'Submit your campaign', 'aggressive-ads' ),
	}
);

$aggr_editor_slots = array();

foreach ( $aggr_slots as $aggr_slot ) {
	if ( ! is_array( $aggr_slot ) ) {
		continue;
	}

	$aggr_editor_slots[] = array(
		'id'   => (int) ( $aggr_slot['id'] ?? 0 ),
		'size' => (string) ( $aggr_slot['size'] ?? '' ),
	);
}

Plugin::instance()->container()->get( Assets::class )->hydrate_campaign_editor(
	array(
		'id'           => (int) ( $aggr_campaign['id'] ?? 0 ),
		'wizard_step'  => $aggr_step,
		'autosave_rev' => (int) ( $aggr_campaign['autosave_rev'] ?? 0 ),
		'submit_ready' => $aggr_review_ready,
		'step_label'   => $aggr_step_label,
		'slots'        => $aggr_editor_slots,
	)
);
