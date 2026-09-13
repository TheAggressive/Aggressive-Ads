<?php
/**
 * The form that puts a new creative on one placement.
 *
 * Extracted from the creative step when that step's "add another" fold became
 * a dialog: the overlay host prints in `wp_footer`, so the form has to be a
 * file the host can require rather than markup nested inside the card.
 *
 * The interactivity store finds its controls with `document.getElementById`,
 * so moving the markup out of `.aggr-shell` does not detach the drag-and-drop
 * or the automatic upload from it.
 *
 * @var array<string, mixed> $aggr_slot     Placement and the creatives on it.
 * @var array<string, mixed> $aggr_campaign The campaign being edited.
 * @var string               $aggr_creative_error_for Which field owns the current error.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Portal\Creative_Actions;

?>
<form
	class="aggr-upload-form"
	method="post"
	enctype="multipart/form-data"
	action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
	data-wp-interactive="<?php echo esc_attr( Assets::UPLOAD_STORE ); ?>"
	<?php echo wp_interactivity_data_wp_context( array( 'uploadId' => (string) $aggr_slot['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context(). ?>
	data-aggr-upload="<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>"
	data-wp-init="actions.init"
>
	<input type="hidden" name="action" value="<?php echo esc_attr( Creative_Actions::UPLOAD_ACTION ); ?>">
	<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign['id'] ); ?>">
	<input type="hidden" name="placement_id" value="<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>">
	<?php wp_nonce_field( Creative_Actions::upload_nonce_action( (int) $aggr_campaign['id'], (int) $aggr_slot['id'] ) ); ?>

	<?php
	/*
	 * WCAG 3.2.2: the upload submits on its
	 * own once both fields are filled, and
	 * that is a change of context. It is
	 * only conformant because this sentence
	 * precedes both controls and is read
	 * out with each of them.
	 */
	?>
	<p id="aggr-upload-auto-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>" class="aggr-hint">
		<?php esc_html_e( 'There is no upload button: choose a file, enter the destination URL, then move on from that field and the upload starts by itself.', 'aggressive-ads' ); ?>
	</p>

	<div class="aggr-field">
		<label for="aggr-file-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>"><?php esc_html_e( 'Ad creative file', 'aggressive-ads' ); ?></label>
		<p id="aggr-file-hint-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>" class="aggr-hint">
			<?php
			printf(
				/* translators: 1: required image dimensions, e.g. 728x90. 2: this placement's maximum file size, e.g. 150 KB. */
				esc_html__( 'Required: %1$s pixels. Maximum file size: %2$s.', 'aggressive-ads' ),
				esc_html( (string) $aggr_slot['size'] ),
				esc_html( (string) $aggr_slot['max_size'] )
			);
			?>
		</p>
		<input id="aggr-file-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>" name="file" type="file" accept="image/jpeg,image/png,image/gif,image/webp" required aria-describedby="aggr-upload-auto-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?> aggr-file-hint-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?> aggr-upload-status-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?><?php echo ( 'aggr-file-' . $aggr_slot['id'] ) === $aggr_creative_error_for ? ' aggr-creative-error' : ''; ?>" <?php echo ( 'aggr-file-' . $aggr_slot['id'] ) === $aggr_creative_error_for ? 'aria-invalid="true"' : ''; ?>>
		<?php
		/*
		 * Visible, not `aggr-sr`.
		 *
		 * Every client-side check already ran and already
		 * announced why it refused a file — into a
		 * screen-reader-only paragraph. A sighted advertiser
		 * saw the file input empty itself and the upload
		 * button appear, with no stated reason for either,
		 * and read that as the automatic upload being broken.
		 * `:empty` keeps it out of the layout until it has
		 * something to say.
		 */
		?>
		<p id="aggr-upload-status-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>" class="aggr-upload-status" role="status" aria-live="polite"></p>
	</div>

	<div class="aggr-field">
		<label for="aggr-click-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>"><?php esc_html_e( 'Destination URL', 'aggressive-ads' ); ?></label>
		<p id="aggr-click-hint-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>" class="aggr-hint"><?php esc_html_e( 'Where someone should go after selecting the advertisement. Use a complete http or https URL.', 'aggressive-ads' ); ?></p>
		<input id="aggr-click-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>" name="click_url" type="url" inputmode="url" required aria-describedby="aggr-upload-auto-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?> aggr-click-hint-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?><?php echo ( 'aggr-click-' . $aggr_slot['id'] ) === $aggr_creative_error_for ? ' aggr-creative-error' : ''; ?>" <?php echo ( 'aggr-click-' . $aggr_slot['id'] ) === $aggr_creative_error_for ? 'aria-invalid="true"' : ''; ?>>
	</div>

	<button class="aggr-button" type="submit" hidden><?php esc_html_e( 'Upload creative', 'aggressive-ads' ); ?></button>
</form>
