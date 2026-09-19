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
 * @var bool                 $aggr_upload_from_edit   Drawn in a running campaign's edit flow.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Domain\Size_Template;
use Aggressive\Ads\Portal\Creative_Actions;
use Aggressive\Ads\Portal\Creative_Feedback;

$aggr_slot_key          = (string) $aggr_slot['id'];
$aggr_default_click_url = (string) ( $aggr_campaign['default_click_url'] ?? '' );
$aggr_click_error       = ( 'aggr-click-' . $aggr_slot_key ) === $aggr_creative_error_for;

/*
 * A blank artboard at the placement's exact size, so whoever makes the ad
 * starts from the dimensions the upload will be checked against rather than
 * from a number copied out of this sentence. Built here, from a size already
 * validated by the placement, as a data URI: nothing to host, nothing to fetch.
 */
$aggr_template = Size_Template::svg( (string) $aggr_slot['size'] );
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
	<?php if ( true === ( $aggr_upload_from_edit ?? false ) ) : ?>
		<?php // Back to the edit flow's Ads step afterwards, not to the wizard a running campaign cannot open. ?>
		<input type="hidden" name="<?php echo esc_attr( Creative_Feedback::RETURN_FIELD ); ?>" value="<?php echo esc_attr( Creative_Feedback::RETURN_EDIT ); ?>">
	<?php endif; ?>

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
		<?php
		echo esc_html(
			'' === $aggr_default_click_url
				? __( 'Uploads by itself: choose a file, enter its link, and the upload starts when you leave the link field.', 'aggressive-ads' )
				: __( 'Uploads as soon as you choose a file, linking to the address below unless you change it first.', 'aggressive-ads' )
		);
		?>
	</p>

	<div class="aggr-field">
		<label for="aggr-file-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>"><?php esc_html_e( 'Ad creative file', 'aggressive-ads' ); ?></label>
		<p id="aggr-file-hint-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>" class="aggr-hint">
			<?php
			printf(
				/* translators: %s: this placement's maximum file size, e.g. 150 KB. */
				esc_html__( 'Maximum file size: %s.', 'aggressive-ads' ),
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

		<?php
		/*
		 * Shown only while a file is on its way, and only by script: a plain
		 * form post has no progress to read, and a bar standing still at zero
		 * would say the upload had stalled.
		 */
		?>
		<div class="aggr-upload-progress" data-aggr-upload-progress hidden>
			<span class="aggr-pill aggr-pill--pending">
				<?php esc_html_e( 'Uploading', 'aggressive-ads' ); ?>
				<span aria-hidden="true">·</span>
				<span data-aggr-upload-percent>0%</span>
			</span>
			<?php /* translators: %s: required image dimensions, e.g. 728x90. */ ?>
			<progress class="aggr-upload-progress__bar" max="100" value="0" aria-label="<?php echo esc_attr( sprintf( __( 'Upload progress for %s', 'aggressive-ads' ), (string) $aggr_slot['size'] ) ); ?>"></progress>
			<div class="aggr-upload-progress__foot">
				<p class="aggr-upload-progress__file" data-aggr-upload-file></p>
				<button class="aggr-card-action" type="button" data-aggr-upload-cancel disabled><?php esc_html_e( 'Cancel upload', 'aggressive-ads' ); ?></button>
			</div>
		</div>

		<?php if ( '' !== $aggr_template ) : ?>
			<p class="aggr-hint">
				<a class="aggr-card-action" download="<?php echo esc_attr( 'ad-template-' . (string) $aggr_slot['size'] . '.svg' ); ?>" href="<?php echo esc_url( 'data:image/svg+xml;charset=utf-8,' . rawurlencode( $aggr_template ), array( 'data' ) ); ?>">
					<?php
					esc_html_e( 'Download template', 'aggressive-ads' );
					echo '<span class="aggr-sr"> ';
					printf(
						/* translators: %s: required image dimensions, e.g. 728x90. */
						esc_html__( 'for %s', 'aggressive-ads' ),
						esc_html( (string) $aggr_slot['size'] )
					);
					echo '</span>';
					?>
				</a>
			</p>
		<?php endif; ?>
	</div>

	<?php
	/*
	 * **Asked once per campaign, not once per card.** When the campaign already
	 * has a link, the field arrives filled with it and folded behind the address
	 * it holds; choosing a file is then the whole upload. It is still a real,
	 * posted field inside a native <details>, so a browser without script sends
	 * the same address, and a refused one reopens the fold.
	 */
	?>
	<?php if ( '' !== $aggr_default_click_url ) : ?>
		<details class="aggr-upload-destination" <?php echo $aggr_click_error ? 'open' : ''; ?>>
			<summary>
				<span class="aggr-uploaded__destination-label"><?php esc_html_e( 'Destination', 'aggressive-ads' ); ?></span>
				<span class="aggr-uploaded__destination-value"><?php echo esc_html( $aggr_default_click_url ); ?></span>
				<span class="aggr-upload-destination__change"><?php esc_html_e( 'Use a different link', 'aggressive-ads' ); ?></span>
			</summary>
	<?php endif; ?>
	<div class="aggr-field">
		<label for="aggr-click-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>"><?php esc_html_e( 'Destination URL', 'aggressive-ads' ); ?></label>
		<p id="aggr-click-hint-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>" class="aggr-hint"><?php esc_html_e( 'Where someone should go after selecting the advertisement. Use a complete http or https URL.', 'aggressive-ads' ); ?></p>
		<input id="aggr-click-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>" name="click_url" type="url" inputmode="url" value="<?php echo esc_attr( $aggr_default_click_url ); ?>" required aria-describedby="aggr-upload-auto-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?> aggr-click-hint-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?><?php echo ( 'aggr-click-' . $aggr_slot['id'] ) === $aggr_creative_error_for ? ' aggr-creative-error' : ''; ?>" <?php echo ( 'aggr-click-' . $aggr_slot['id'] ) === $aggr_creative_error_for ? 'aria-invalid="true"' : ''; ?>>
	</div>
	<?php if ( '' !== $aggr_default_click_url ) : ?>
		</details>
	<?php endif; ?>

	<button class="aggr-button" type="submit" hidden><?php esc_html_e( 'Upload creative', 'aggressive-ads' ); ?></button>
</form>
