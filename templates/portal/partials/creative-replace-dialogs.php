<?php
/**
 * Creative replacement dialogs — rendered in wp_footer outside .laao-ads-shell.
 *
 * Sitting outside the shell lets the dialog store set inert on the page root
 * without inerting the dialog itself. No-JS uses :target on the overlay id.
 *
 * @package LAAO_Advertiser_Portal
 *
 * @var array<string, mixed>                                                        $laao_ads_campaign Campaign row.
 * @var list<array{id: string, creative: array<string, mixed>}>                     $laao_ads_dialogs  Dialog specs.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Assets\Assets;
use LAAO_Advertiser_Portal\Portal\Creative_Actions;

if ( ! isset( $laao_ads_dialogs ) || ! is_array( $laao_ads_dialogs ) || array() === $laao_ads_dialogs ) {
	return;
}

$laao_ads_campaign = isset( $laao_ads_campaign ) && is_array( $laao_ads_campaign ) ? $laao_ads_campaign : array();
?>
<div
	id="laao-ads-overlays"
	class="laao-ads-overlays"
	data-wp-interactive="<?php echo esc_attr( Assets::DIALOG_STORE ); ?>"
>
	<?php foreach ( $laao_ads_dialogs as $laao_ads_dialog ) : ?>
		<?php
		if ( ! is_array( $laao_ads_dialog ) ) {
			continue;
		}

		$laao_ads_dialog_id = (string) ( $laao_ads_dialog['id'] ?? '' );
		$laao_ads_creative  = is_array( $laao_ads_dialog['creative'] ?? null ) ? $laao_ads_dialog['creative'] : array();

		if ( '' === $laao_ads_dialog_id || array() === $laao_ads_creative ) {
			continue;
		}

		$laao_ads_label_id       = $laao_ads_dialog_id . '-label';
		$laao_ads_dialog_context = wp_interactivity_data_wp_context(
			array(
				'dialogId' => $laao_ads_dialog_id,
			)
		);
		$laao_ads_opened_label   = sprintf(
			/* translators: %s: placement name. */
			__( 'Update ad dialog opened for %s', 'laao-advertiser-portal' ),
			(string) $laao_ads_creative['placement']
		);
		?>
		<div
			id="<?php echo esc_attr( $laao_ads_dialog_id ); ?>"
			class="laao-ads-overlay"
			data-dialog-id="<?php echo esc_attr( $laao_ads_dialog_id ); ?>"
			<?php echo $laao_ads_dialog_context; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context(). ?>
			data-wp-init="actions.init"
		>
			<div
				class="laao-ads-overlay__backdrop"
				data-laao-ads-dialog-close
				aria-hidden="true"
			></div>

			<div
				class="laao-ads-overlay__panel"
				role="dialog"
				aria-modal="true"
				aria-labelledby="<?php echo esc_attr( $laao_ads_label_id ); ?>"
				tabindex="-1"
			>
				<div class="laao-ads-overlay__header">
					<h2 id="<?php echo esc_attr( $laao_ads_label_id ); ?>" class="laao-ads-overlay__title">
						<?php
						printf(
							/* translators: %s: placement name. */
							esc_html__( 'Update %s', 'laao-advertiser-portal' ),
							esc_html( (string) $laao_ads_creative['placement'] )
						);
						?>
					</h2>
					<a
						class="laao-ads-overlay__close"
						href="#laao-ads-update-creatives-heading"
						aria-label="<?php esc_attr_e( 'Close', 'laao-advertiser-portal' ); ?>"
						data-laao-ads-dialog-close
					>
						<?php
						$laao_ads_icon = 'close';
						require LAAO_ADS_PLUGIN_DIR . 'templates/portal/partials/icon.php';
						?>
					</a>
				</div>

				<div class="laao-ads-overlay__body">
					<p class="laao-ads-hint">
						<?php esc_html_e( 'The current ad keeps running until staff approve this replacement.', 'laao-advertiser-portal' ); ?>
					</p>

					<form class="laao-ads-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data">
						<input type="hidden" name="action" value="<?php echo esc_attr( Creative_Actions::REPLACE_ACTION ); ?>">
						<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) ( $laao_ads_campaign['id'] ?? '' ) ); ?>">
						<input type="hidden" name="creative_id" value="<?php echo esc_attr( (string) $laao_ads_creative['id'] ); ?>">
						<?php wp_nonce_field( Creative_Actions::replace_nonce_action( (int) $laao_ads_creative['id'] ) ); ?>

						<div class="laao-ads-field">
							<label for="laao-ads-replacement-file-<?php echo esc_attr( (string) $laao_ads_creative['id'] ); ?>"><?php esc_html_e( 'Replacement image', 'laao-advertiser-portal' ); ?></label>
							<input id="laao-ads-replacement-file-<?php echo esc_attr( (string) $laao_ads_creative['id'] ); ?>" name="file" type="file" accept="image/jpeg,image/png,image/gif,image/webp" required>
							<p class="laao-ads-hint">
								<?php
								printf(
									/* translators: %s: required creative dimensions, for example 728x90. */
									esc_html__( 'Exactly %s. JPEG, PNG, GIF, or WebP.', 'laao-advertiser-portal' ),
									esc_html( (string) $laao_ads_creative['size'] )
								);
								?>
							</p>
						</div>

						<div class="laao-ads-field">
							<label for="laao-ads-replacement-url-<?php echo esc_attr( (string) $laao_ads_creative['id'] ); ?>"><?php esc_html_e( 'Destination URL', 'laao-advertiser-portal' ); ?></label>
							<input id="laao-ads-replacement-url-<?php echo esc_attr( (string) $laao_ads_creative['id'] ); ?>" name="click_url" type="url" value="<?php echo esc_attr( (string) $laao_ads_creative['click_url'] ); ?>" required>
						</div>

						<div class="laao-ads-overlay__actions">
							<a
								class="laao-ads-button laao-ads-button--secondary"
								href="#laao-ads-update-creatives-heading"
								data-laao-ads-dialog-close
							>
								<?php esc_html_e( 'Cancel', 'laao-advertiser-portal' ); ?>
							</a>
							<button class="laao-ads-button" type="submit"><?php esc_html_e( 'Submit replacement for review', 'laao-advertiser-portal' ); ?></button>
						</div>
					</form>
				</div>
			</div>

			<div
				class="laao-ads-overlay__announcer"
				data-label="<?php echo esc_attr( $laao_ads_opened_label ); ?>"
				aria-live="polite"
			></div>
		</div>

		<?php
		// No-JS close control: :target shows the overlay; Cancel clears the hash.
		unset( $laao_ads_dialog_context, $laao_ads_opened_label, $laao_ads_label_id );
		?>
	<?php endforeach; ?>
</div>
