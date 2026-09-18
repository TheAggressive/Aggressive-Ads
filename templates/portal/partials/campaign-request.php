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
use Aggressive\Ads\Portal\Campaign_Nonces;

$aggr_request     = is_array( $aggr_campaign['action_request'] ?? null ) ? $aggr_campaign['action_request'] : array();
$aggr_options     = is_array( $aggr_campaign['requestable_actions'] ?? null ) ? $aggr_campaign['requestable_actions'] : array();
$aggr_campaign_id = (int) $aggr_campaign['id'];

if ( array() === $aggr_request && array() === $aggr_options ) {
	return;
}
?>
<?php if ( array() !== $aggr_request ) : ?>
	<?php // A request waiting on the team is news, so it is shown open, as a card of its own. ?>
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
<?php else : ?>
	<?php
	/*
	 * Folded until somebody needs it. The form used to stand open on every
	 * running campaign — a radio already on "Pause this campaign" and an empty
	 * reason box — the largest thing on the page and the least used.
	 */
	?>
	<details class="aggr-step-card aggr-request">
		<summary class="aggr-request__summary">
			<h2 id="aggr-request-heading" class="aggr-step-card__title"><?php esc_html_e( 'Need to pause or cancel?', 'aggressive-ads' ); ?></h2>
			<span class="aggr-hint"><?php esc_html_e( 'The review team does it for you. Tell them what you need.', 'aggressive-ads' ); ?></span>
		</summary>

		<form class="aggr-form aggr-request__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::REQUEST_ACTION ); ?>">
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign_id ); ?>">
			<?php wp_nonce_field( Campaign_Nonces::action_nonce_action( $aggr_campaign_id ) ); ?>

			<fieldset class="aggr-fieldset">
				<legend class="aggr-changes__legend"><?php esc_html_e( 'What do you need?', 'aggressive-ads' ); ?></legend>
				<div class="aggr-choicegrid">
					<?php foreach ( $aggr_options as $aggr_index => $aggr_option ) : ?>
						<label class="aggr-choice">
							<input
								type="radio"
								name="requested_action"
								value="<?php echo esc_attr( (string) $aggr_option['action'] ); ?>"
								<?php checked( 0, (int) $aggr_index ); ?>
							>
							<span class="aggr-choice__text">
								<span class="aggr-package__name"><?php echo esc_html( (string) $aggr_option['label'] ); ?></span>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<div class="aggr-field">
				<label for="aggr-request-reason"><?php esc_html_e( 'Why?', 'aggressive-ads' ); ?></label>
				<textarea id="aggr-request-reason" name="reason" rows="4" maxlength="2000" required></textarea>
			</div>

			<div class="aggr-form__actions">
				<button class="aggr-button" type="submit">
					<?php esc_html_e( 'Send request', 'aggressive-ads' ); ?>
				</button>
			</div>
		</form>
	</details>
<?php endif; ?>
