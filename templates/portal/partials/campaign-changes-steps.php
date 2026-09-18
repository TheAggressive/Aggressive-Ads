<?php
/**
 * The edit flow's steps, in the page head where the creation wizard puts its.
 *
 * Its own partial because the head is drawn before the flow itself, and both
 * have to agree on which steps exist: a site that has switched off schedule
 * edits must not show a Schedule step here that the flow below never renders.
 * `Campaign_Changes_Steps::for_fields()` would be the Portal-class version of
 * this; four lines of array building did not earn one.
 *
 * Scope is inherited from the screen.
 *
 * @var array<string, mixed> $aggr_campaign The campaign being edited.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;

$aggr_edit_fields = is_array( $aggr_campaign['live_edit_fields'] ?? null ) ? $aggr_campaign['live_edit_fields'] : array();
$aggr_edit_step   = Campaign_Actions::request_change_step();
$aggr_edit_url    = add_query_arg( 'edit', '1', Routes::url( Request::ROUTE_CAMPAIGNS, (int) $aggr_campaign['id'] ) );
$aggr_edit_steps  = array();

$aggr_edit_details = in_array( 'title', $aggr_edit_fields, true ) || in_array( 'advertiser_notes', $aggr_edit_fields, true ) || in_array( 'placement_ids', $aggr_edit_fields, true );
$aggr_edit_dates   = in_array( 'start_ts', $aggr_edit_fields, true );

/*
 * Three steps, as creation has: what it is and when it runs together, then
 * the links, then review. Name, placements and dates were two steps with a
 * save between them for no reason the advertiser could see; they post to
 * the same handler, which reads whichever fields arrive.
 */
if ( $aggr_edit_details || $aggr_edit_dates ) {
	$aggr_edit_steps['details'] = $aggr_edit_details && $aggr_edit_dates
		? __( 'Details & dates', 'aggressive-ads' )
		: ( $aggr_edit_details ? __( 'Details', 'aggressive-ads' ) : __( 'Dates', 'aggressive-ads' ) );
}

if ( in_array( 'click_urls', $aggr_edit_fields, true ) ) {
	$aggr_edit_steps['destination'] = __( 'Links', 'aggressive-ads' );
}

$aggr_edit_steps['review'] = __( 'Review & submit', 'aggressive-ads' );

// A link to the old separate Schedule step lands on the step that holds it now.
if ( 'schedule' === $aggr_edit_step ) {
	$aggr_edit_step = 'details';
}

$aggr_edit_number = (int) array_search( $aggr_edit_step, array_keys( $aggr_edit_steps ), true ) + 1;
?>
<ol class="aggr-steps" aria-label="<?php esc_attr_e( 'Campaign edit progress', 'aggressive-ads' ); ?>">
	<?php foreach ( $aggr_edit_steps as $aggr_edit_key => $aggr_edit_name ) : ?>
		<li <?php echo $aggr_edit_key === $aggr_edit_step ? 'aria-current="step"' : ''; ?>>
			<a href="<?php echo esc_url( add_query_arg( 'step', $aggr_edit_key, $aggr_edit_url ) ); ?>"><span class="aggr-steps__label"><?php echo esc_html( $aggr_edit_name ); ?></span></a>
		</li>
	<?php endforeach; ?>
</ol>
<p class="aggr-steps__count" aria-hidden="true">
	<?php
	printf(
		/* translators: 1: current step number. 2: number of steps. */
		esc_html__( 'Step %1$d of %2$d', 'aggressive-ads' ),
		(int) $aggr_edit_number,
		(int) count( $aggr_edit_steps )
	);
	?>
</p>
