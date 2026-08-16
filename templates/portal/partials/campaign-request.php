<?php
/**
 * Asking staff to pause, restart or cancel a running campaign.
 *
 * These transitions are staff-only by design: an advertiser reaching `live`
 * would break the invariant that only staff put an advertisement in front of
 * the public, and `paused` has no clock edge, so a campaign an advertiser
 * paused could never restart itself. So the portal sends a request rather than
 * performing the change, and staff act with the buttons the review screen
 * already derives from Transition_Table.
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

$aggr_request     = is_array( $aggr_campaign['action_request'] ?? null ) ? $aggr_campaign['action_request'] : array();
$aggr_options     = is_array( $aggr_campaign['requestable_actions'] ?? null ) ? $aggr_campaign['requestable_actions'] : array();
$aggr_campaign_id = (int) $aggr_campaign['id'];

if ( array() === $aggr_request && array() === $aggr_options ) {
	return;
}
?>
<section class="aggr-panel" aria-labelledby="aggr-request-heading">
	<h2 id="aggr-request-heading" class="aggr-panel__head">
		<?php esc_html_e( 'Ask the review team', 'aggressive-ads' ); ?>
	</h2>

	<?php if ( array() !== $aggr_request ) : ?>
		<div class="aggr-alert aggr-alert--info" role="status">
			<p>
				<?php
				printf(
					/* translators: %s: the requested action, already translated. */
					esc_html__( 'Requested: %s. The review team will be in touch; your campaign is unchanged until they act.', 'aggressive-ads' ),
					esc_html( (string) $aggr_campaign['action_request_label'] )
				);
				?>
			</p>
		</div>

		<?php if ( '' !== (string) $aggr_request['reason'] ) : ?>
			<blockquote class="aggr-quote"><?php echo esc_html( (string) $aggr_request['reason'] ); ?></blockquote>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::REQUEST_WITHDRAW ); ?>">
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign_id ); ?>">
			<?php wp_nonce_field( Campaign_Actions::withdraw_action_nonce_action( $aggr_campaign_id ) ); ?>
			<button class="aggr-button aggr-button--secondary" type="submit">
				<?php esc_html_e( 'Withdraw this request', 'aggressive-ads' ); ?>
			</button>
		</form>
	<?php else : ?>
		<p class="aggr-hint"><?php esc_html_e( 'Pausing or cancelling a campaign that is already running is done by the review team. Tell them what you need and they will take it from here.', 'aggressive-ads' ); ?></p>

		<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::REQUEST_ACTION ); ?>">
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign_id ); ?>">
			<?php wp_nonce_field( Campaign_Actions::action_nonce_action( $aggr_campaign_id ) ); ?>

			<fieldset class="aggr-field">
				<legend><?php esc_html_e( 'What do you need?', 'aggressive-ads' ); ?></legend>
				<?php foreach ( $aggr_options as $aggr_index => $aggr_option ) : ?>
					<label class="aggr-choice">
						<input
							type="radio"
							name="requested_action"
							value="<?php echo esc_attr( (string) $aggr_option['action'] ); ?>"
							<?php checked( 0, (int) $aggr_index ); ?>
						>
						<?php echo esc_html( (string) $aggr_option['label'] ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<div class="aggr-field">
				<label for="aggr-request-reason"><?php esc_html_e( 'Why?', 'aggressive-ads' ); ?></label>
				<textarea id="aggr-request-reason" name="reason" rows="4" maxlength="2000" required></textarea>
			</div>

			<button class="aggr-button" type="submit">
				<?php esc_html_e( 'Send request', 'aggressive-ads' ); ?>
			</button>
		</form>
	<?php endif; ?>
</section>
