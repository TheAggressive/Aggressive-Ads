<?php
/**
 * What a running campaign's ad card adds below the shared card: an update
 * waiting for review, with a way to withdraw it, or why the ad is not running.
 *
 * Scope is inherited from `campaign-ad-card.php`.
 *
 * @var array<string, mixed>      $aggr_campaign The campaign.
 * @var array<string, mixed>      $aggr_creative The ad.
 * @var array<string, mixed>|null $aggr_pending  Its update waiting for review, if any.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Creative_Actions;
?>
<?php if ( null !== $aggr_pending ) : ?>
	<p><span class="aggr-pill aggr-pill--pending"><?php esc_html_e( 'Update waiting for review', 'aggressive-ads' ); ?></span></p>
	<p class="aggr-hint"><?php esc_html_e( 'The ad above is the update. The current one keeps running until the review team accepts it.', 'aggressive-ads' ); ?></p>
	<form class="aggr-uploaded__actions" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<input type="hidden" name="action" value="<?php echo esc_attr( Creative_Actions::WITHDRAW_ACTION ); ?>">
		<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign['id'] ); ?>">
		<input type="hidden" name="replacement_id" value="<?php echo esc_attr( (string) $aggr_pending['id'] ); ?>">
		<?php wp_nonce_field( Creative_Actions::withdraw_nonce_action( (int) $aggr_pending['id'] ) ); ?>
		<button class="aggr-card-action" type="submit"><?php esc_html_e( 'Withdraw update', 'aggressive-ads' ); ?></button>
	</form>
<?php elseif ( true === $aggr_creative['rejected'] ) : ?>
	<?php // The reason, to the person it was written for, and what to do about it. ?>
	<p><span class="aggr-pill aggr-pill--danger"><?php echo esc_html( (string) $aggr_creative['state_text'] ); ?></span></p>
	<?php if ( '' !== (string) $aggr_creative['notes'] ) : ?>
		<p><?php echo esc_html( (string) $aggr_creative['notes'] ); ?></p>
	<?php endif; ?>
	<p class="aggr-hint"><?php esc_html_e( 'This ad is not running. Select Update to supply a replacement.', 'aggressive-ads' ); ?></p>
<?php elseif ( true !== $aggr_creative['approved'] ) : ?>
	<p><span class="aggr-pill aggr-pill--pending"><?php echo esc_html( (string) $aggr_creative['state_text'] ); ?></span></p>
<?php endif; ?>
