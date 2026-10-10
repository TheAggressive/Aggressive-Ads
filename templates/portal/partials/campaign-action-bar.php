<?php
/**
 * The wizard step's action, pinned to the bottom of a phone.
 *
 * Under 40rem the step's one action rides the bottom of the screen with what is
 * left to do, so it is reachable without scrolling past every card. It is
 * `.aggr-actionbar` (the bar Review's decisions use), after the layout grid
 * rather than in it, so it settles in place after the order summary.
 *
 * Included by `screens/campaign.php` after `campaign-order-summary.php`, which
 * decides the action once in `$aggr_cta`; this draws it. At any width exactly one primary action is on
 * screen: here under 40rem, the step's own button between 40rem and the
 * summary's two-column width, and the summary's button above that. The others
 * are `display: none`, which removes them from the accessibility tree and from
 * every `getByRole` lookup.
 *
 * @var array{form: string, labels: array<int, array{class: string, text: string}>, locked: bool, note: string} $aggr_cta The step's action.
 * @var string                                                                                               $aggr_step Current display step.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_bar_reason_id = 'aggr-actionbar-reason';
?>
<div class="aggr-actionbar aggr-actionbar--wizard" role="region" aria-label="<?php esc_attr_e( 'Continue', 'aggressive-ads' ); ?>">
	<?php if ( $aggr_cta['locked'] && '' !== $aggr_cta['note'] ) : ?>
		<div class="aggr-actionbar__status">
			<span id="<?php echo esc_attr( $aggr_bar_reason_id ); ?>" class="aggr-actionbar__reason"><?php echo esc_html( $aggr_cta['note'] ); ?></span>
		</div>
	<?php endif; ?>
	<div class="aggr-actionbar__actions">
		<button
			class="aggr-button"
			type="<?php echo $aggr_cta['locked'] ? 'button' : 'submit'; ?>"
			<?php echo $aggr_cta['locked'] ? 'disabled aria-describedby="' . esc_attr( $aggr_bar_reason_id ) . '"' : 'form="' . esc_attr( $aggr_cta['form'] ) . '"'; ?>
		>
			<?php foreach ( $aggr_cta['labels'] as $aggr_bar_label ) : ?>
				<?php if ( '' === $aggr_bar_label['class'] ) : ?>
					<?php echo esc_html( $aggr_bar_label['text'] ); ?>
				<?php else : ?>
					<span class="<?php echo esc_attr( $aggr_bar_label['class'] ); ?>"><?php echo esc_html( $aggr_bar_label['text'] ); ?></span>
				<?php endif; ?>
			<?php endforeach; ?>
		</button>
	</div>
</div>
