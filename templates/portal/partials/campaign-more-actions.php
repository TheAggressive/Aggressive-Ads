<?php
/**
 * Duplicate, a staff request, and delete.
 *
 * None of them is the step the page is asking for, so they stay in a menu.
 * Delete is last and still leads to its confirmation screen rather than acting.
 *
 * The request is a link to the shared dialog, the same contract as Update: a
 * hash, so it opens with no script, and aria-haspopup so the module traps
 * focus. Native <details>, so the menu itself opens from the keyboard and
 * without script.
 *
 * Scope is inherited from the campaign screen.
 *
 * @var array<string, mixed> $aggr_campaign          The campaign being viewed.
 * @var string               $aggr_campaign_url      This campaign's address.
 * @var bool                 $aggr_request_dialog    Whether the request dialog is on the page.
 * @var string               $aggr_request_dialog_id Overlay id.
 * @var string               $aggr_request_prompt    Menu label, matching the dialog title.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Nonces;

$aggr_request_dialog    = isset( $aggr_request_dialog ) && $aggr_request_dialog;
$aggr_request_dialog_id = isset( $aggr_request_dialog_id ) ? (string) $aggr_request_dialog_id : '';
$aggr_request_prompt    = isset( $aggr_request_prompt ) ? (string) $aggr_request_prompt : '';

if ( true !== ( $aggr_campaign['can_copy'] ?? false ) && true !== ( $aggr_campaign['can_cancel'] ?? false ) && ! $aggr_request_dialog ) {
	return;
}
?>
<details class="aggr-menu">
	<?php
	/*
	 * The button class sets inline-flex, and that drops the button role
	 * Chromium gives a summary. The toggle is then a generic: it still
	 * opens, and nothing can find it as a button.
	 */
	?>
	<summary class="aggr-button aggr-button--secondary aggr-menu__toggle" role="button" aria-label="<?php esc_attr_e( 'More actions', 'aggressive-ads' ); ?>">
		<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><circle cx="5" cy="12" r="1.9"/><circle cx="12" cy="12" r="1.9"/><circle cx="19" cy="12" r="1.9"/></svg>
	</summary>
	<div class="aggr-menu__panel">
		<?php if ( true === $aggr_campaign['can_copy'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::COPY_ACTION ); ?>">
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) (int) $aggr_campaign['id'] ); ?>">
				<?php wp_nonce_field( Campaign_Nonces::copy_nonce_action( (int) $aggr_campaign['id'] ) ); ?>
				<button class="aggr-menu__item" type="submit">
					<?php echo esc_html( (string) $aggr_campaign['copy_label'] ); ?>
				</button>
			</form>
		<?php endif; ?>

		<?php if ( $aggr_request_dialog ) : ?>
			<a
				class="aggr-menu__item"
				id="<?php echo esc_attr( $aggr_request_dialog_id ); ?>-open"
				href="#<?php echo esc_attr( $aggr_request_dialog_id ); ?>"
				aria-haspopup="dialog"
				aria-controls="<?php echo esc_attr( $aggr_request_dialog_id ); ?>"
				aria-expanded="false"
			>
				<?php echo esc_html( $aggr_request_prompt ); ?>
			</a>
		<?php endif; ?>

		<?php if ( true === $aggr_campaign['can_cancel'] ) : ?>
			<form method="get" action="<?php echo esc_url( $aggr_campaign_url ); ?>">
				<input type="hidden" name="confirm" value="cancel">
				<button class="aggr-menu__item aggr-menu__item--danger" type="submit">
					<?php echo esc_html( (string) $aggr_campaign['cancel_label'] ); ?>
				</button>
			</form>
		<?php endif; ?>
	</div>
</details>
