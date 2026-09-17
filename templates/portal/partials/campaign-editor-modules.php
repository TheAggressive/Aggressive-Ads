<?php
/**
 * Hydrates wizard / autosave / upload stores for an editable campaign.
 *
 * @package Aggressive\Ads
 *
 * @var array<string, mixed> $aggr_campaign Campaign row.
 * @var string               $aggr_step     Current display step.
 * @var array<int, mixed>    $aggr_slots    Creative slots.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Workflow\Campaign_Editor;

$aggr_campaign  = isset( $aggr_campaign ) && is_array( $aggr_campaign ) ? $aggr_campaign : array();
$aggr_step      = isset( $aggr_step ) && is_string( $aggr_step ) ? $aggr_step : 'details';
$aggr_slots     = isset( $aggr_slots ) && is_array( $aggr_slots ) ? $aggr_slots : array();
$aggr_wizard_id = 'campaign-' . (int) ( $aggr_campaign['id'] ?? 0 );

/*
 * Read from the canonical list rather than a copy of it. This is what a screen
 * reader announces on every step change, so a list that drifts from the one the
 * wizard actually renders announces the wrong position and nothing on screen
 * looks wrong.
 */
$aggr_step_position = array_search( $aggr_step, Campaign_Editor::DISPLAY_STEPS, true );

$aggr_step_label = sprintf(
	/* translators: 1: current step number, 2: total steps, 3: step title. */
	__( 'Step %1$s of %2$s: %3$s', 'aggressive-ads' ),
	(string) ( false === $aggr_step_position ? 1 : $aggr_step_position + 1 ),
	(string) count( Campaign_Editor::DISPLAY_STEPS ),
	match ( $aggr_step ) {
		'details'  => __( 'Choose a package and dates', 'aggressive-ads' ),
		'creative' => __( 'Add your ads', 'aggressive-ads' ),
		default    => __( 'Review and submit', 'aggressive-ads' ),
	}
);

$aggr_editor_slots = array();

foreach ( $aggr_slots as $aggr_slot ) {
	if ( ! is_array( $aggr_slot ) ) {
		continue;
	}

	/*
	 * A missing limit resolves to the default rather than to zero. Zero
	 * reaches the browser as `maxBytes` and every file is larger than
	 * zero, so the fallback for an absent number would have been a
	 * placement that silently refuses everything.
	 */
	$aggr_slot_max = Upload_Rules::resolve_max_bytes( (int) ( $aggr_slot['max_bytes'] ?? 0 ) );

	$aggr_editor_slots[] = array(
		'id'        => (int) ( $aggr_slot['id'] ?? 0 ),
		'size'      => (string) ( $aggr_slot['size'] ?? '' ),
		'max_bytes' => $aggr_slot_max,
		'max_size'  => (string) size_format( $aggr_slot_max ),
	);
}

Plugin::instance()->container()->get( Assets::class )->hydrate_campaign_editor(
	array(
		'id'           => (int) ( $aggr_campaign['id'] ?? 0 ),
		'wizard_step'  => $aggr_step,
		'autosave_rev' => (int) ( $aggr_campaign['autosave_rev'] ?? 0 ),
		'step_label'   => $aggr_step_label,
		'slots'        => $aggr_editor_slots,
	)
);
