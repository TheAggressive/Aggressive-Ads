<?php
/**
 * A pause, restart or cancel request that is waiting on the review team.
 *
 * Asking is a dialog opened from More actions. This card is only the answer
 * that is already in flight: it is news about the campaign, so it stays on
 * the page, with the reason and a way to take it back. The campaign is
 * unchanged until staff act.
 *
 * @package Aggressive\Ads
 *
 * @var array<string, mixed> $aggr_campaign The campaign being viewed.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Nonces;

$aggr_request     = is_array( $aggr_campaign['action_request'] ?? null ) ? $aggr_campaign['action_request'] : array();
$aggr_campaign_id = (int) $aggr_campaign['id'];

if ( array() === $aggr_request ) {
	return;
}
?>
<section class="aggr-step-card aggr-request" aria-labelledby="aggr-request-heading">
	<h2 id="aggr-request-heading" class="aggr-step-card__title"><?php esc_html_e( 'Your request', 'aggressive-ads' ); ?></h2>

	<div class="aggr-alert" role="status">
		<p>
			<?php
			printf(
				/* translators: %s: what was requested, e.g. Pause this campaign. */
				esc_html__( 'Requested: %s. The review team will be in touch; your campaign is unchanged until they act.', 'aggressive-ads' ),
				esc_html( (string) $aggr_campaign['action_request_label'] )
			);
			?>
		</p>
	</div>

	<?php if ( '' !== (string) $aggr_request['reason'] ) : ?>
		<blockquote class="aggr-request__reason"><?php echo esc_html( (string) $aggr_request['reason'] ); ?></blockquote>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::REQUEST_WITHDRAW ); ?>">
		<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign_id ); ?>">
		<?php wp_nonce_field( Campaign_Nonces::withdraw_action_nonce_action( $aggr_campaign_id ) ); ?>
		<button class="aggr-button aggr-button--secondary" type="submit">
			<?php esc_html_e( 'Withdraw this request', 'aggressive-ads' ); ?>
		</button>
	</form>
</section>
