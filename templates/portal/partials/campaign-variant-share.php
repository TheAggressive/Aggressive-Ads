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
 * Weighted delivery is not new — `Domain\Weighted_Selection` has always read
 * this number, and two creatives at 3 and 1 have always rotated three to one.
 * What was missing was any way to see or set it outside the REST route.
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

$aggr_share_weight = $aggr_creative['weight'] ?? null;

if ( count( $aggr_slot['creatives'] ) < 2 || null === $aggr_share_weight ) {
	return;
}

$aggr_share_id    = 'aggr-share-' . (int) $aggr_creative['id'];
$aggr_share_ratio = $aggr_creative['share'] ?? null;
?>
<form
	class="aggr-share"
	method="post"
	action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
	data-aggr-save="<?php echo esc_attr( 'aggr-save-share-' . (int) $aggr_creative['id'] ); ?>"
>
	<input type="hidden" name="action" value="<?php echo esc_attr( Creative_Actions::WEIGHT_ACTION ); ?>">
	<input type="hidden" name="creative_id" value="<?php echo esc_attr( (string) (int) $aggr_creative['id'] ); ?>">
	<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) (int) $aggr_campaign['id'] ); ?>">
	<?php wp_nonce_field( Creative_Actions::weight_nonce_action( (int) $aggr_creative['id'] ) ); ?>

	<label class="aggr-share__label" for="<?php echo esc_attr( $aggr_share_id ); ?>">
		<?php esc_html_e( 'Share', 'aggressive-ads' ); ?>
	</label>

	<input
		class="aggr-share__input"
		id="<?php echo esc_attr( $aggr_share_id ); ?>"
		type="number"
		name="weight"
		value="<?php echo esc_attr( (string) (int) $aggr_share_weight ); ?>"
		min="<?php echo esc_attr( (string) Assignment_Rules::MIN_WEIGHT ); ?>"
		max="<?php echo esc_attr( (string) Assignment_Rules::MAX_WEIGHT ); ?>"
		step="1"
		inputmode="numeric"
	>

	<button class="aggr-button aggr-button--small" type="submit"><?php esc_html_e( 'Save share', 'aggressive-ads' ); ?></button>

	<?php if ( null !== $aggr_share_ratio ) : ?>
		<?php
		/*
		 * The weight is a relative number and means nothing on its own — 3 is
		 * only three times something. The percentage is what the advertiser is
		 * actually deciding, so it is shown beside the control that sets it
		 * rather than left to be worked out.
		 */
		?>
		<p class="aggr-share__result" id="<?php echo esc_attr( 'aggr-share-ratio-' . (int) $aggr_creative['id'] ); ?>">
			<?php
			printf(
				/* translators: %s: this creative's share of the placement, e.g. 75%. */
				esc_html__( 'About %s of this placement.', 'aggressive-ads' ),
				esc_html( number_format_i18n( (float) $aggr_share_ratio * 100, 0 ) . '%' )
			);
			?>
		</p>
	<?php endif; ?>
</form>
