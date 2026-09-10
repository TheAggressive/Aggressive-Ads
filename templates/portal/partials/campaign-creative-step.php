<?php
/**
 * The campaign wizard's creative step.
 *
 * Split out of `campaign.php` when that screen reached 960 lines against a
 * thousand-line gate. The seam is a wizard step, which is the unit this screen
 * is already organised by, and this is the step that keeps growing: variants
 * added share here, and window and status are still to come.
 *
 * Scope is inherited from the screen, as it is for every partial here.
 *
 * @var array<string, mixed>           $aggr_campaign     The campaign being edited.
 * @var array<int, array<string, mixed>> $aggr_slots      Placements and the creatives on each.
 * @var array<int, array<string, mixed>> $aggr_overlays   Dialogs the step appends to.
 * @var string                         $aggr_campaign_url This campaign's portal URL.
 * @var bool                           $aggr_creative_ready Whether every active placement has a creative.
 * @var string                         $aggr_creative_error_for Which upload form owns the current error.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Portal\Creative_Actions;
use Aggressive\Ads\Workflow\Creative_Manager;

?>
			<div class="aggr-form">
				<p class="aggr-hint"><?php esc_html_e( 'Upload one ad creative for every placement. Files stay in private storage until staff approve the campaign. JPEG, PNG, GIF, and WebP are supported, up to 2 MB.', 'aggressive-ads' ); ?></p>

				<?php if ( array() === $aggr_slots ) : ?>
					<div class="aggr-empty">
						<p class="aggr-empty__title"><?php esc_html_e( 'Choose a package first', 'aggressive-ads' ); ?></p>
						<p><?php esc_html_e( 'A package supplies the placements and exact ad creative sizes required for this campaign.', 'aggressive-ads' ); ?></p>
					</div>
				<?php else : ?>
					<div class="aggr-upload-list">
						<?php foreach ( $aggr_slots as $aggr_slot ) : ?>
							<section class="aggr-upload-card" aria-labelledby="aggr-slot-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>">
								<div class="aggr-upload-card__head">
									<div>
										<h3 id="aggr-slot-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>"><?php echo esc_html( (string) $aggr_slot['name'] ); ?></h3>
										<p>
											<?php
											/* translators: %s: required image dimensions, e.g. 728x90. */
											printf( esc_html__( 'Required ad creative size: %s pixels', 'aggressive-ads' ), esc_html( (string) $aggr_slot['size'] ) );
											?>
										</p>
									</div>
									<span class="aggr-pill aggr-pill--<?php echo array() === $aggr_slot['creatives'] ? 'pending' : 'live'; ?>">
										<?php echo esc_html( array() === $aggr_slot['creatives'] ? __( 'Creative needed', 'aggressive-ads' ) : __( 'Uploaded', 'aggressive-ads' ) ); ?>
									</span>
								</div>

								<?php if ( ! $aggr_slot['active'] ) : ?>
									<div class="aggr-alert aggr-alert--error" role="alert">
										<p><?php esc_html_e( 'This placement is no longer available. Return to the package step and choose an available package.', 'aggressive-ads' ); ?></p>
									</div>
								<?php else : ?>
									<?php foreach ( $aggr_slot['creatives'] as $aggr_creative ) : ?>
										<?php
										$aggr_preview_id = 'aggr-preview-' . (int) $aggr_creative['id'];
										$aggr_remove_id  = 'aggr-remove-' . (int) $aggr_creative['id'];
										$aggr_close_href = 'aggr-slot-' . (int) $aggr_slot['id'];
										$aggr_overlays[] = array(
											'kind'       => 'preview',
											'id'         => $aggr_preview_id,
											'creative'   => $aggr_creative,
											'placement'  => (string) $aggr_slot['name'],
											'close_href' => $aggr_close_href,
										);
										$aggr_overlays[] = array(
											'kind'       => 'remove',
											'id'         => $aggr_remove_id,
											'creative'   => $aggr_creative,
											'placement'  => (string) $aggr_slot['name'],
											'close_href' => $aggr_close_href,
										);
										?>
										<div class="aggr-uploaded">
											<a
												class="aggr-uploaded__preview"
												href="#<?php echo esc_attr( $aggr_preview_id ); ?>"
												aria-haspopup="dialog"
												aria-controls="<?php echo esc_attr( $aggr_preview_id ); ?>"
												aria-expanded="false"
											>
												<img src="<?php echo esc_url( (string) $aggr_creative['preview'] ); ?>" alt="<?php echo esc_attr( (string) $aggr_creative['alt_text'] ); ?>" loading="lazy">
											</a>
											<div class="aggr-uploaded__details">
												<p><strong><?php echo esc_html( (string) $aggr_creative['name'] ); ?></strong></p>
												<p><?php echo esc_html( (string) $aggr_creative['dimensions'] . ' · ' . size_format( (int) $aggr_creative['bytes'] ) ); ?></p>
												<p class="aggr-table__url"><?php echo esc_html( (string) $aggr_creative['click_url'] ); ?></p>
												<?php if ( true === $aggr_creative['rejected'] ) : ?>
													<?php
													/*
													 * A creative turned down while the campaign was
													 * running is still here when the advertiser comes
													 * back to edit, and it still will not serve. Saying
													 * why here as well as on the running view is what
													 * makes the next upload the right one.
													 */
													?>
													<p><span class="aggr-pill aggr-pill--danger"><?php echo esc_html( (string) $aggr_creative['state_text'] ); ?></span></p>
													<?php if ( '' !== (string) $aggr_creative['notes'] ) : ?>
														<p><?php echo esc_html( (string) $aggr_creative['notes'] ); ?></p>
													<?php endif; ?>
												<?php endif; ?>
												<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-variant-share.php'; ?>
												<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-variant-window.php'; ?>
												<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-variant-status.php'; ?>

												<a
													class="aggr-button aggr-button--danger"
													href="#<?php echo esc_attr( $aggr_remove_id ); ?>"
													aria-haspopup="dialog"
													aria-controls="<?php echo esc_attr( $aggr_remove_id ); ?>"
													aria-expanded="false"
												><?php esc_html_e( 'Remove creative', 'aggressive-ads' ); ?></a>
											</div>
										</div>
									<?php endforeach; ?>

									<?php
									/*
									 * The form stays, alongside whatever is
									 * already uploaded.
									 *
									 * It used to be shown *instead of* the
									 * creatives, which was the interface half
									 * of the one-per-placement rule: once
									 * something existed there was no way to add
									 * another. A placement may now hold several,
									 * so the only thing that closes the form is
									 * reaching the backstop.
									 */
									?>
									<?php if ( count( $aggr_slot['creatives'] ) >= Creative_Manager::MAX_CREATIVES_PER_PLACEMENT ) : ?>
										<p class="aggr-hint">
											<?php
											printf(
												/* translators: %d: maximum creatives allowed on one placement. */
												esc_html__( 'This placement has the maximum of %d creatives. Remove one to add another.', 'aggressive-ads' ),
												(int) Creative_Manager::MAX_CREATIVES_PER_PLACEMENT
											);
											?>
										</p>
									<?php else : ?>
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

										<div class="aggr-field">
											<label for="aggr-file-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>"><?php esc_html_e( 'Ad creative file', 'aggressive-ads' ); ?></label>
											<p id="aggr-file-hint-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>" class="aggr-hint">
												<?php
												/* translators: %s: required image dimensions, e.g. 728x90. */
												printf( esc_html__( 'Required: %s pixels. Maximum file size: 2 MB.', 'aggressive-ads' ), esc_html( (string) $aggr_slot['size'] ) );
												?>
											</p>
											<input id="aggr-file-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>" name="file" type="file" accept="image/jpeg,image/png,image/gif,image/webp" required aria-describedby="aggr-file-hint-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?> aggr-upload-status-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?><?php echo ( 'aggr-file-' . $aggr_slot['id'] ) === $aggr_creative_error_for ? ' aggr-creative-error' : ''; ?>" <?php echo ( 'aggr-file-' . $aggr_slot['id'] ) === $aggr_creative_error_for ? 'aria-invalid="true"' : ''; ?>>
											<p id="aggr-upload-status-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>" class="aggr-sr" role="status" aria-live="polite"></p>
										</div>

										<div class="aggr-field">
											<label for="aggr-click-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>"><?php esc_html_e( 'Destination URL', 'aggressive-ads' ); ?></label>
											<p id="aggr-click-hint-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>" class="aggr-hint"><?php esc_html_e( 'Where someone should go after selecting the advertisement. Use a complete http or https URL.', 'aggressive-ads' ); ?></p>
											<input id="aggr-click-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>" name="click_url" type="url" inputmode="url" required aria-describedby="aggr-click-hint-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?><?php echo ( 'aggr-click-' . $aggr_slot['id'] ) === $aggr_creative_error_for ? ' aggr-creative-error' : ''; ?>" <?php echo ( 'aggr-click-' . $aggr_slot['id'] ) === $aggr_creative_error_for ? 'aria-invalid="true"' : ''; ?>>
										</div>

										<button class="aggr-button" type="submit"><?php esc_html_e( 'Upload creative', 'aggressive-ads' ); ?></button>
									</form>
									<?php endif; ?>
								<?php endif; ?>
							</section>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div class="aggr-form__actions">
					<a class="aggr-button aggr-button--secondary" href="<?php echo esc_url( add_query_arg( 'step', 'package', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Back to package', 'aggressive-ads' ); ?></a>
					<?php if ( $aggr_creative_ready ) : ?>
						<a class="aggr-button" href="<?php echo esc_url( add_query_arg( 'step', 'destination', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Continue to schedule', 'aggressive-ads' ); ?></a>
					<?php else : ?>
						<p class="aggr-hint"><?php esc_html_e( 'Upload at least one creative for every active package placement to continue.', 'aggressive-ads' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-overlays.php'; ?>
