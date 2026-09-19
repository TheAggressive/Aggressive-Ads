<?php
/**
 * The edit flow's steps, in the page head where the creation wizard puts its.
 *
 * Its own partial because the head is drawn before the flow itself. Which
 * steps exist, and what they are called, is `Portal\Campaign_Edit_Steps`,
 * which the flow below reads too, so the head cannot offer a step the flow
 * never renders.
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
use Aggressive\Ads\Portal\Campaign_Edit_Steps;

$aggr_edit_fields = is_array( $aggr_campaign['live_edit_fields'] ?? null ) ? $aggr_campaign['live_edit_fields'] : array();
$aggr_edit_steps  = Campaign_Edit_Steps::for_fields( $aggr_edit_fields );
$aggr_edit_step   = Campaign_Edit_Steps::current( Campaign_Actions::request_change_step(), $aggr_edit_steps );
$aggr_edit_number = (int) array_search( $aggr_edit_step, array_keys( $aggr_edit_steps ), true ) + 1;
?>
<ol class="aggr-steps" aria-label="<?php esc_attr_e( 'Campaign edit progress', 'aggressive-ads' ); ?>">
	<?php foreach ( $aggr_edit_steps as $aggr_edit_key => $aggr_edit_name ) : ?>
		<li <?php echo $aggr_edit_key === $aggr_edit_step ? 'aria-current="step"' : ''; ?>>
			<a href="<?php echo esc_url( Campaign_Edit_Steps::url( (int) $aggr_campaign['id'], $aggr_edit_key ) ); ?>"><span class="aggr-steps__label"><?php echo esc_html( $aggr_edit_name ); ?></span></a>
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
