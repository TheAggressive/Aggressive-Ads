<?php
/**
 * New bytes for a creative that review has not yet accepted.
 *
 * Swapping a banner used to mean removing the creative and uploading again,
 * which drops the placement's coverage in between and throws away the
 * destination, the share and the position in the rotation. This keeps the
 * record and changes only its file.
 *
 * The server decides whether that is allowed — `Revision_Policy` is the
 * authority and `Creative_Manager::replace_artwork()` asks it. Once artwork is
 * approved the answer is no, and the route becomes a reviewed update.
 *
 * @var array<string, mixed> $aggr_creative   One uploaded creative.
 * @var array<string, mixed> $aggr_campaign   The campaign being edited.
 * @var string               $aggr_close_href Element id the dialog returns focus to.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Creative_Actions;

$aggr_artwork_creative = (int) ( $aggr_creative['id'] ?? 0 );

if ( $aggr_artwork_creative < 1 ) {
	return;
}

$aggr_artwork_field = 'aggr-artwork-' . $aggr_artwork_creative;

// Set by the overlay host. Defaulted so a body is never rendered with a
// cancel link pointing at nothing.
$aggr_close_href = isset( $aggr_close_href ) && is_string( $aggr_close_href ) ? $aggr_close_href : 'aggr-details-heading';
?>
<p class="aggr-hint">
	<?php esc_html_e( 'The destination, the share and the run dates stay as they are. Only the image changes.', 'aggressive-ads' ); ?>
</p>

<form class="aggr-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="<?php echo esc_attr( Creative_Actions::ARTWORK_ACTION ); ?>">
	<input type="hidden" name="creative_id" value="<?php echo esc_attr( (string) $aggr_artwork_creative ); ?>">
	<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) (int) ( $aggr_campaign['id'] ?? 0 ) ); ?>">
	<?php wp_nonce_field( Creative_Actions::artwork_nonce_action( $aggr_artwork_creative ) ); ?>

	<div class="aggr-field">
		<label for="<?php echo esc_attr( $aggr_artwork_field ); ?>">
			<?php esc_html_e( 'New ad creative file', 'aggressive-ads' ); ?>
		</label>
		<p class="aggr-hint">
			<?php
			printf(
				/* translators: %s: required image dimensions, e.g. 728x90. */
				esc_html__( 'Required: %s pixels, the same as the creative it replaces.', 'aggressive-ads' ),
				esc_html( (string) ( $aggr_creative['dimensions'] ?? '' ) )
			);
			?>
		</p>
		<input
			id="<?php echo esc_attr( $aggr_artwork_field ); ?>"
			name="file"
			type="file"
			accept="image/jpeg,image/png,image/gif,image/webp"
			required
		>
	</div>

	<div class="aggr-overlay__actions">
		<a
			class="aggr-button aggr-button--secondary"
			href="#<?php echo esc_attr( $aggr_close_href ); ?>"
			data-aggr-dialog-close
		><?php esc_html_e( 'Cancel', 'aggressive-ads' ); ?></a>
		<button class="aggr-button" type="submit"><?php esc_html_e( 'Replace artwork', 'aggressive-ads' ); ?></button>
	</div>
</form>
