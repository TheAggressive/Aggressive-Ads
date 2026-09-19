<?php
/**
 * One creative's share of the placement it competes on.
 *
 * Rendered only where a placement holds more than one creative, because a share
 * control beside a single advertisement claims to do something it cannot: with
 * nothing to compete against, every weight delivers the same hundred per cent.
 * Showing it anyway would be the interface asserting a choice the selector never
 * makes.
 *
 * **A share is dragged, not typed.** The percentage is a proportion of one
 * placement, and a number box says nothing about proportion — so the control
 * is a slider, the figure beside it reads out where it is, and the bar above
 * the ads shows the split it makes. It saves on release, like the destination
 * link; the button below it is for a browser that will not run the module,
 * where the slider is still a slider and Save is how it gets sent.
 *
 * **The number is the percentage.** It used to be the stored weight — a
 * relative number, so 70 could show as 41% — and setting one creative's left
 * the others alone, which is why a column of them never added to anything.
 * `Share_Editor` gives this one the percentage asked for and divides the rest
 * between the others in the proportions they already had.
 *
 * @var array<string, mixed> $aggr_creative One uploaded creative, with its weight and share attached.
 * @var array<string, mixed> $aggr_slot     The placement it competes on, and every creative on it.
 * @var array<string, mixed> $aggr_campaign The campaign being edited.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Portal\Creative_Actions;

$aggr_share_ratio = $aggr_creative['share'] ?? null;

if ( count( $aggr_slot['creatives'] ) < 2 || null === ( $aggr_creative['weight'] ?? null ) || null === $aggr_share_ratio ) {
	return;
}

$aggr_share_id      = 'aggr-share-' . (int) $aggr_creative['id'];
$aggr_share_percent = max( Assignment_Rules::MIN_WEIGHT, (int) round( (float) $aggr_share_ratio * Assignment_Rules::SHARE_TOTAL ) );

// Everything else on the placement keeps at least one per cent.
$aggr_share_max = Assignment_Rules::SHARE_TOTAL - ( count( $aggr_slot['creatives'] ) - 1 );
?>
<form
	class="aggr-share"
	method="post"
	action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
	data-aggr-save="<?php echo esc_attr( 'aggr-save-share-' . (int) $aggr_creative['id'] ); ?>"
	data-aggr-share-form="<?php echo esc_attr( (string) (int) $aggr_creative['id'] ); ?>"
>
	<input type="hidden" name="action" value="<?php echo esc_attr( Creative_Actions::WEIGHT_ACTION ); ?>">
	<input type="hidden" name="creative_id" value="<?php echo esc_attr( (string) (int) $aggr_creative['id'] ); ?>">
	<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) (int) $aggr_campaign['id'] ); ?>">
	<?php wp_nonce_field( Creative_Actions::weight_nonce_action( (int) $aggr_creative['id'] ) ); ?>

	<label class="aggr-share__label" for="<?php echo esc_attr( $aggr_share_id ); ?>">
		<?php esc_html_e( 'Share of this placement', 'aggressive-ads' ); ?>
	</label>

	<?php
	/*
	 * A range, so the control is the proportion rather than a number that
	 * stands for one. It posts the same field a number box did, and a browser
	 * without the module still drags it and presses Save.
	 */
	?>
	<input
		class="aggr-share__range"
		id="<?php echo esc_attr( $aggr_share_id ); ?>"
		type="range"
		name="share"
		value="<?php echo esc_attr( (string) $aggr_share_percent ); ?>"
		min="<?php echo esc_attr( (string) Assignment_Rules::MIN_WEIGHT ); ?>"
		max="<?php echo esc_attr( (string) $aggr_share_max ); ?>"
		step="1"
		aria-describedby="<?php echo esc_attr( 'aggr-share-note-' . (int) $aggr_creative['id'] ); ?>"
	>

	<?php // Not `aria-live`: the slider announces its own value as it moves. ?>
	<output class="aggr-share__value" for="<?php echo esc_attr( $aggr_share_id ); ?>" data-aggr-share-value>
		<?php
		printf(
			/* translators: %d: a share of one placement, e.g. 70. */
			esc_html__( '%d%%', 'aggressive-ads' ),
			(int) $aggr_share_percent
		);
		?>
	</output>

	<?php
	/*
	 * Hidden in the markup rather than by script, so it never paints for the
	 * browsers that save on release. `<noscript>` brings it back, the way the
	 * upload form's own button does.
	 */
	?>
	<noscript><style>.aggr-portal .aggr-share button[type="submit"][hidden]{display:inline-flex!important}</style></noscript>
	<button class="aggr-button aggr-button--small aggr-button--secondary" type="submit" hidden><?php esc_html_e( 'Save', 'aggressive-ads' ); ?></button>

	<?php
	/*
	 * What setting this one does to the others, said where it is set. The id
	 * is what the server patches after a save, so the sentence moves with the
	 * numbers rather than going stale until the next page load.
	 */
	?>
	<p class="aggr-share__result" id="<?php echo esc_attr( 'aggr-share-note-' . (int) $aggr_creative['id'] ); ?>">
		<?php
		printf(
			/* translators: 1: this ad's share, e.g. 70. 2: what is left for the others, e.g. 30. */
			esc_html__( 'Shown %1$d%% of the time here. The other ads share the remaining %2$d%%.', 'aggressive-ads' ),
			(int) $aggr_share_percent,
			(int) ( Assignment_Rules::SHARE_TOTAL - $aggr_share_percent )
		);
		?>
	</p>
</form>
