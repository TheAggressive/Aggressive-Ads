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
				<p class="aggr-hint"><?php esc_html_e( 'Upload one ad creative for every placement. Files stay in private storage until staff approve the campaign. JPEG, PNG, GIF, and WebP are supported. Each placement states its own size limit below.', 'aggressive-ads' ); ?></p>

				<?php if ( array() === $aggr_slots ) : ?>
					<div class="aggr-empty">
						<p class="aggr-empty__title"><?php esc_html_e( 'Choose a package first', 'aggressive-ads' ); ?></p>
						<p><?php esc_html_e( 'A package supplies the placements and exact ad creative sizes required for this campaign.', 'aggressive-ads' ); ?></p>
					</div>
				<?php else : ?>
					<?php
					/*
					 * The upload button ships hidden and comes back only when
					 * the automatic path cannot finish.
					 *
					 * It used to ship visible and be hidden by `init`, which
					 * meant it painted on every load and vanished a moment
					 * later — right under a sentence saying there is no upload
					 * button. Hiding it in the markup is the only way to not
					 * show it, since nothing that runs after first paint can
					 * un-paint it.
					 *
					 * `<noscript>` restores it for a browser that will never
					 * run `init`, mirroring `Placement_Slot`'s collapse rule.
					 * `!important` and the extra specificity are to beat
					 * `.aggr-portal [hidden]`, which is itself `!important`
					 * because `.aggr-button` sets a `display` that the UA's
					 * plain `[hidden]` rule cannot outrank.
					 *
					 * A browser that runs script but never loads the module is
					 * the case this does not cover, and it is not stranded:
					 * pressing Enter in the destination field submits the form
					 * natively, hidden default button and all.
					 */
					?>
					<noscript><style>.aggr-portal .aggr-upload-form button[type="submit"][hidden]{display:inline-flex!important}</style></noscript>
					<div class="aggr-upload-list">
						<?php foreach ( $aggr_slots as $aggr_slot ) : ?>
							<?php $aggr_slot_empty = array() === $aggr_slot['creatives']; ?>
							<section class="aggr-upload-card" aria-labelledby="aggr-slot-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>">
								<div class="aggr-upload-card__head">
									<div>
										<h3 id="aggr-slot-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>"><?php echo esc_html( (string) $aggr_slot['name'] ); ?></h3>
										<?php
										/*
										 * What to prepare, said only while there is still
										 * something to prepare.
										 *
										 * It used to sit on every card permanently, so a
										 * placement that was already finished carried a
										 * sentence of instructions for a task nobody had
										 * left to do — and repeated a size the file's own
										 * dimensions state a few lines below.
										 */
										?>
										<?php if ( $aggr_slot_empty ) : ?>
											<p>
												<?php
												/* translators: %s: required image dimensions, e.g. 728x90. */
												printf( esc_html__( 'Required ad creative size: %s pixels', 'aggressive-ads' ), esc_html( (string) $aggr_slot['size'] ) );
												?>
											</p>
										<?php endif; ?>
									</div>
									<?php
									/*
									 * A badge only where it asks for something.
									 *
									 * "Uploaded" sat in green beside a visible thumbnail
									 * of the thing it was reporting, which is how a
									 * reader learns that the badges on this screen are
									 * decoration. The ones that remain — this and the
									 * rejection pill below — each need a decision.
									 */
									?>
									<?php if ( $aggr_slot_empty ) : ?>
										<span class="aggr-pill aggr-pill--pending">
											<?php esc_html_e( 'Creative needed', 'aggressive-ads' ); ?>
										</span>
									<?php endif; ?>
								</div>

								<?php if ( ! $aggr_slot['active'] ) : ?>
									<div class="aggr-alert aggr-alert--error" role="alert">
										<p><?php esc_html_e( 'This placement is no longer available. Return to the package step and choose an available package.', 'aggressive-ads' ); ?></p>
									</div>
								<?php else : ?>
									<?php foreach ( $aggr_slot['creatives'] as $aggr_creative ) : ?>
										<?php
										$aggr_creative_key    = (int) $aggr_creative['id'];
										$aggr_preview_id      = 'aggr-preview-' . $aggr_creative_key;
										$aggr_remove_id       = 'aggr-remove-' . $aggr_creative_key;
										$aggr_artwork_id      = 'aggr-swap-' . $aggr_creative_key;
										$aggr_destination_dlg = 'aggr-destination-dialog-' . $aggr_creative_key;
										$aggr_window_id       = 'aggr-window-' . $aggr_creative_key;
										$aggr_close_href      = 'aggr-slot-' . (int) $aggr_slot['id'];

										/*
										 * Every edit on this card is a dialog, so the card
										 * itself stays a picture of the creative and a
										 * short list of what can be done to it.
										 */
										foreach ( array(
											'preview'     => $aggr_preview_id,
											'remove'      => $aggr_remove_id,
											'artwork'     => $aggr_artwork_id,
											'destination' => $aggr_destination_dlg,
											'window'      => $aggr_window_id,
										) as $aggr_dialog_kind => $aggr_dialog_key ) {
											$aggr_overlays[] = array(
												'kind'     => $aggr_dialog_kind,
												'id'       => $aggr_dialog_key,
												'creative' => $aggr_creative,
												'placement' => (string) $aggr_slot['name'],
												'close_href' => $aggr_close_href,
												'error_for' => $aggr_creative_error_for,
											);
										}
										?>
										<div class="aggr-uploaded">
										<?php
										/*
										* Beneath the artwork, not on it.
										*
										* They were text over a gradient, which is a bet on what
										* the advertiser uploaded: a scrim tuned for a dark banner
										* is unreadable over a light one, and a creative is
										* whatever somebody sends. Off the image the contrast is
										* the page’s own and known, nothing covers the artwork, and
										* there is no reveal to get right for keyboards and
										* touchscreens because nothing is hidden.
										*/
										?>
											<div class="aggr-uploaded__figure">
												<div class="aggr-uploaded__thumb">
													<img class="aggr-uploaded__image" src="<?php echo esc_url( (string) $aggr_creative['preview'] ); ?>" alt="<?php echo esc_attr( (string) $aggr_creative['alt_text'] ); ?>" loading="lazy">
												</div>

												<div class="aggr-creative-actions">
													<a
														class="aggr-card-action"
														href="#<?php echo esc_attr( $aggr_preview_id ); ?>"
														aria-haspopup="dialog"
														aria-controls="<?php echo esc_attr( $aggr_preview_id ); ?>"
														aria-expanded="false"
													><?php esc_html_e( 'Preview', 'aggressive-ads' ); ?></a>
													<a
														class="aggr-card-action"
														href="#<?php echo esc_attr( $aggr_artwork_id ); ?>"
														aria-haspopup="dialog"
														aria-controls="<?php echo esc_attr( $aggr_artwork_id ); ?>"
														aria-expanded="false"
													><?php esc_html_e( 'Replace', 'aggressive-ads' ); ?></a>
													<a
														class="aggr-card-action aggr-card-action--danger"
														href="#<?php echo esc_attr( $aggr_remove_id ); ?>"
														aria-haspopup="dialog"
														aria-controls="<?php echo esc_attr( $aggr_remove_id ); ?>"
														aria-expanded="false"
													><?php esc_html_e( 'Remove', 'aggressive-ads' ); ?></a>
												</div>
											</div>
											<div class="aggr-uploaded__details">
												<?php
												/*
												 * Destination first, filename after.
												 *
												 * The filename was the bold line and the
												 * destination was unlabelled grey text that
												 * read as debris. It is the wrong way round:
												 * nobody checks what the file was called, and
												 * the address every click goes to is both the
												 * thing that defines the creative and the
												 * thing most likely to be wrong.
												 */
												?>
												<p class="aggr-uploaded__destination">
													<span class="aggr-uploaded__destination-label"><?php esc_html_e( 'Goes to', 'aggressive-ads' ); ?></span>
													<span class="aggr-uploaded__destination-value" id="<?php echo esc_attr( 'aggr-destination-value-' . $aggr_creative_key ); ?>"><?php echo esc_html( (string) $aggr_creative['click_url'] ); ?></span>
												</p>
												<p class="aggr-uploaded__meta">
													<?php
													echo esc_html(
														sprintf(
															/* translators: 1: file name. 2: image dimensions, e.g. 728x90. 3: file size, e.g. 54 KB. */
															__( '%1$s · %2$s · %3$s', 'aggressive-ads' ),
															(string) $aggr_creative['name'],
															(string) $aggr_creative['dimensions'],
															size_format( (int) $aggr_creative['bytes'] )
														)
													);
													?>
												</p>
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
												<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-variant-status.php'; ?>

												<?php
												/*
												 * Two edits, two dialogs, one row. Removal is not
												 * among them any more: it lives on the artwork it
												 * destroys, where the thing being removed is in
												 * front of you when you ask for it.
												 */
												?>
												<div class="aggr-uploaded__actions">
													<a
														class="aggr-card-action"
														href="#<?php echo esc_attr( $aggr_destination_dlg ); ?>"
														aria-haspopup="dialog"
														aria-controls="<?php echo esc_attr( $aggr_destination_dlg ); ?>"
														aria-expanded="false"
													><?php esc_html_e( 'Edit destination', 'aggressive-ads' ); ?></a>

													<?php
													/*
													 * The trigger says whether there is anything
													 * behind it.
													 *
													 * The fold this replaces showed a saved window
													 * without being opened. A dialog cannot, so the
													 * label has to carry it — otherwise dates
													 * somebody set are indistinguishable from dates
													 * nobody set, which is the whole reason the
													 * fold used to open itself.
													 */
													$aggr_has_window = '' !== (string) ( $aggr_creative['starts_on'] ?? '' )
														|| '' !== (string) ( $aggr_creative['ends_on'] ?? '' );
													?>
													<a
														class="aggr-card-action<?php echo $aggr_has_window ? ' aggr-card-action--set' : ''; ?>"
														href="#<?php echo esc_attr( $aggr_window_id ); ?>"
														aria-haspopup="dialog"
														aria-controls="<?php echo esc_attr( $aggr_window_id ); ?>"
														aria-expanded="false"
													>
														<span id="<?php echo esc_attr( 'aggr-window-label-' . (int) ( $aggr_creative['assignment_id'] ?? 0 ) ); ?>">
															<?php
															echo esc_html(
																$aggr_has_window
																	? __( 'Custom run dates set', 'aggressive-ads' )
																	: __( 'Add custom run dates', 'aggressive-ads' )
															);
															?>
														</span>
													</a>
												</div>
											</div>
										</div>
									<?php endforeach; ?>

									<?php
									/*
									 * An empty placement leads with the form; a filled one
									 * puts it behind a dialog. A refused upload reopens that
									 * dialog through the URL fragment the redirect carries,
									 * not through anything decided here.
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
									<?php elseif ( $aggr_slot_empty ) : ?>
										<?php
										/*
										 * An empty placement leads with the form itself.
										 * Uploading is the whole point of the card, and
										 * putting the only thing it asks for behind a
										 * dialog would be a click in front of the task.
										 */
										?>
										<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-upload-form.php'; ?>
									<?php else : ?>
										<?php
										$aggr_add_id     = 'aggr-add-' . (int) $aggr_slot['id'];
										$aggr_overlays[] = array(
											'kind'       => 'add',
											'id'         => $aggr_add_id,
											'creative'   => array(),
											'slot'       => $aggr_slot,
											'placement'  => (string) $aggr_slot['name'],
											'close_href' => 'aggr-slot-' . (int) $aggr_slot['id'],
											'error_for'  => $aggr_creative_error_for,
										);
										?>
										<p class="aggr-add-creative">
											<a
												class="aggr-card-action"
												href="#<?php echo esc_attr( $aggr_add_id ); ?>"
												aria-haspopup="dialog"
												aria-controls="<?php echo esc_attr( $aggr_add_id ); ?>"
												aria-expanded="false"
											><?php esc_html_e( 'Add a rotating creative', 'aggressive-ads' ); ?></a>
										</p>
									<?php endif; ?>
								<?php endif; ?>
							</section>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div class="aggr-form__actions">
					<?php if ( ! $aggr_creative_ready ) : ?>
						<?php /* Before the controls, so it is read as the reason there is no way on rather than as a footnote after it. */ ?>
						<p class="aggr-hint"><?php esc_html_e( 'Upload at least one creative for every active package placement to continue.', 'aggressive-ads' ); ?></p>
					<?php endif; ?>
					<a class="aggr-button aggr-button--secondary" href="<?php echo esc_url( add_query_arg( 'step', 'details', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Back to details', 'aggressive-ads' ); ?></a>
					<?php if ( $aggr_creative_ready ) : ?>
						<a class="aggr-button" href="<?php echo esc_url( add_query_arg( 'step', 'destination', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Continue to schedule', 'aggressive-ads' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
			<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-overlays.php'; ?>
