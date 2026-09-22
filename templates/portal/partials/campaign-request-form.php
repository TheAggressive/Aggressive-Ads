<?php
/**
 * The pause, restart or cancel request, inside the shared dialog.
 *
 * An advertiser cannot make these transitions. The form records a request
 * and staff decide with the buttons the review screen already has. Nothing
 * here changes the campaign.
 *
 * Rendered by campaign-overlays.php. The trigger is a link in More actions;
 * this is only the body. A request already waiting is a card on the page
 * instead, because that is news, not a thing to go and find.
 *
 * @var array<string, mixed>                  $aggr_campaign           The campaign being viewed.
 * @var array<int, array<string, mixed>>      $aggr_request_options    Transitions staff can be asked for.
 * @var string                                $aggr_request_error      Sentence for a refused send, or empty.
 * @var string                                $aggr_request_error_code Error code, or empty.
 * @var string                                $aggr_dialog_id          Overlay id.
 * @var string                                $aggr_close_href         Element id the dialog returns to, without '#'.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Nonces;
use Aggressive\Ads\Workflow\Campaign_Action_Requests;

$aggr_request_options    = isset( $aggr_request_options ) && is_array( $aggr_request_options ) ? $aggr_request_options : array();
$aggr_request_error      = isset( $aggr_request_error ) ? (string) $aggr_request_error : '';
$aggr_request_error_code = isset( $aggr_request_error_code ) ? (string) $aggr_request_error_code : '';
$aggr_dialog_id          = isset( $aggr_dialog_id ) ? (string) $aggr_dialog_id : '';
$aggr_close_href         = isset( $aggr_close_href ) ? (string) $aggr_close_href : '';
$aggr_campaign_id        = (int) ( $aggr_campaign['id'] ?? 0 );
$aggr_error_id           = $aggr_dialog_id . '-error';
$aggr_reason_invalid     = in_array( $aggr_request_error_code, array( 'aggr_action_reason_required', 'aggr_action_reason_long' ), true );
$aggr_action_invalid     = 'aggr_action_not_requestable' === $aggr_request_error_code;

if ( array() === $aggr_request_options || $aggr_campaign_id < 1 ) {
	return;
}
?>
<?php if ( '' !== $aggr_request_error ) : ?>
	<p class="aggr-alert aggr-alert--error" id="<?php echo esc_attr( $aggr_error_id ); ?>"><?php echo esc_html( $aggr_request_error ); ?></p>
<?php endif; ?>

<p class="aggr-hint"><?php esc_html_e( 'The review team does it for you. Tell them what you need.', 'aggressive-ads' ); ?></p>

<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::REQUEST_ACTION ); ?>">
	<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign_id ); ?>">
	<?php wp_nonce_field( Campaign_Nonces::action_nonce_action( $aggr_campaign_id ) ); ?>

	<?php if ( 1 === count( $aggr_request_options ) ) : ?>
		<input type="hidden" name="requested_action" value="<?php echo esc_attr( (string) $aggr_request_options[0]['action'] ); ?>">
	<?php else : ?>
		<fieldset
			class="aggr-fieldset"
			<?php if ( $aggr_action_invalid ) : ?>
				aria-invalid="true"
				aria-describedby="<?php echo esc_attr( $aggr_error_id ); ?>"
			<?php endif; ?>
		>
			<legend><?php esc_html_e( 'What do you need?', 'aggressive-ads' ); ?></legend>
			<div class="aggr-choicegrid aggr-request__choices">
				<?php foreach ( $aggr_request_options as $aggr_option ) : ?>
					<label class="aggr-choice">
						<input type="radio" name="requested_action" value="<?php echo esc_attr( (string) $aggr_option['action'] ); ?>" required>
						<span class="aggr-choice__text">
							<span class="aggr-package__name"><?php echo esc_html( (string) $aggr_option['label'] ); ?></span>
						</span>
					</label>
				<?php endforeach; ?>
			</div>
		</fieldset>
	<?php endif; ?>

	<div class="aggr-field">
		<label for="aggr-request-reason"><?php esc_html_e( 'Why?', 'aggressive-ads' ); ?></label>
		<textarea
			id="aggr-request-reason"
			name="reason"
			rows="4"
			maxlength="<?php echo esc_attr( (string) Campaign_Action_Requests::MAX_REASON_LENGTH ); ?>"
			required
			<?php if ( $aggr_reason_invalid ) : ?>
				aria-invalid="true"
				aria-describedby="<?php echo esc_attr( $aggr_error_id ); ?>"
			<?php endif; ?>
		></textarea>
	</div>

	<div class="aggr-overlay__actions">
		<a
			class="aggr-button aggr-button--secondary"
			href="#<?php echo esc_attr( $aggr_close_href ); ?>"
			data-aggr-dialog-close
		>
			<?php esc_html_e( 'Close', 'aggressive-ads' ); ?>
		</a>
		<button class="aggr-button" type="submit">
			<?php esc_html_e( 'Send request', 'aggressive-ads' ); ?>
		</button>
	</div>
</form>
