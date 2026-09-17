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
 * @var string                         $aggr_error_for    Field the current error belongs to.
 * @var string                         $aggr_wizard_id    This campaign's autosave id.
 * @var string                         $aggr_autosave_context Autosave store context attribute.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Nonces;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Workflow\Creative_Manager;

/*
 * How much is left, counted the way the Continue button decides it: an active
 * placement with at least one creative is done.
 */
$aggr_sizes_total = count( $aggr_slots );
$aggr_sizes_ready = 0;

$aggr_link      = (string) ( $aggr_campaign['default_click_url'] ?? '' );
$aggr_link_used = 0;
$aggr_max_bytes = 0;
$aggr_missing   = 0;

foreach ( $aggr_slots as $aggr_counted_slot ) {
	if ( $aggr_counted_slot['active'] && array() !== $aggr_counted_slot['creatives'] ) {
		++$aggr_sizes_ready;
	}

	$aggr_max_bytes = max( $aggr_max_bytes, (int) ( $aggr_counted_slot['max_bytes'] ?? 0 ) );

	if ( array() === $aggr_counted_slot['creatives'] ) {
		++$aggr_missing;
	}

	// A size counts once, when its first ad goes to the campaign's link.
	if ( '' !== $aggr_link && (string) ( $aggr_counted_slot['creatives'][0]['click_url'] ?? '' ) === $aggr_link ) {
		++$aggr_link_used;
	}
}
?>
			<div class="aggr-form">
				<?php
				/*
				 * One link for every ad, set here and saved on the campaign as it is
				 * typed. Every size card below starts from it and can still change its
				 * own. Checking that the link resolves, and tracking tags, are #291.
				 */
				?>
				<section class="aggr-ads-section" aria-labelledby="aggr-ads-link-heading">
					<div class="aggr-ads-section__head">
						<div>
							<p class="aggr-eyebrow"><?php esc_html_e( '01 · Destination', 'aggressive-ads' ); ?></p>
							<h3 id="aggr-ads-link-heading"><?php esc_html_e( 'Where should people go when they click?', 'aggressive-ads' ); ?></h3>
							<p class="aggr-hint"><?php esc_html_e( 'One link for every ad. Any single size can still have its own.', 'aggressive-ads' ); ?></p>
						</div>
					</div>
					<form
						method="post"
						action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						data-wp-interactive="<?php echo esc_attr( Assets::AUTOSAVE_STORE ); ?>"
						<?php echo $aggr_autosave_context; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context(). ?>
						data-aggr-autosave="<?php echo esc_attr( $aggr_wizard_id ); ?>"
						data-wp-init="actions.init"
					>
						<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::SAVE_ACTION ); ?>">
						<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign['id'] ); ?>">
						<input type="hidden" name="autosave_rev" value="<?php echo esc_attr( (string) $aggr_campaign['autosave_rev'] ); ?>">
						<?php wp_nonce_field( Campaign_Nonces::save_nonce_action( (int) $aggr_campaign['id'] ) ); ?>

						<label class="aggr-sr" for="aggr-campaign-link"><?php esc_html_e( 'Destination link for every ad', 'aggressive-ads' ); ?></label>
						<div class="aggr-ads-link">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>
							<input
								id="aggr-campaign-link"
								class="aggr-ads-link__input"
								name="default_click_url"
								type="url"
								inputmode="url"
								placeholder="https://"
								aria-describedby="aggr-campaign-link-status"
								value="<?php echo esc_attr( $aggr_link ); ?>"
								<?php echo 'aggr-campaign-link' === $aggr_error_for ? 'aria-invalid="true"' : ''; ?>
							>
							<?php if ( '' !== $aggr_link ) : ?>
								<?php // Only a saved link shows it, and a saved link has passed the same check a creative's does. ?>
								<span class="aggr-pill aggr-pill--live aggr-ads-link__status"><?php esc_html_e( 'Valid link', 'aggressive-ads' ); ?></span>
							<?php endif; ?>
						</div>
						<?php
						/*
						 * Autosave is the only save the design draws. A browser without
						 * script has no autosave, so it alone gets a button.
						 */
						?>
						<p id="aggr-campaign-link-status" class="aggr-ads-link__status-text" data-aggr-link-status role="status" aria-live="polite"></p>
						<noscript><button class="aggr-button aggr-button--secondary aggr-button--small" type="submit"><?php esc_html_e( 'Save link', 'aggressive-ads' ); ?></button></noscript>
						<div class="aggr-ads-link__meta">
							<span class="aggr-ads-link__used">
								<?php
								printf(
									/* translators: 1: sizes whose ad goes to this link. 2: sizes in the package. */
									esc_html( _n( 'Used by %1$d of %2$d ad', 'Used by %1$d of %2$d ads', $aggr_sizes_total, 'aggressive-ads' ) ),
									(int) $aggr_link_used,
									(int) $aggr_sizes_total
								);
								?>
							</span>
							<?php // Tracking tags are #291; drawn now so the layout is the approved one. ?>
							<button class="aggr-ads-link__tags" type="button" disabled aria-describedby="aggr-tags-note"><?php esc_html_e( 'Add tracking tags', 'aggressive-ads' ); ?></button>
							<span id="aggr-tags-note" class="aggr-sr"><?php esc_html_e( 'Coming soon.', 'aggressive-ads' ); ?></span>
						</div>
					</form>
				</section>

				<section class="aggr-ads-section" aria-labelledby="aggr-ads-heading">
					<div class="aggr-ads-section__head">
						<div>
							<p class="aggr-eyebrow"><?php esc_html_e( '02 · Ads', 'aggressive-ads' ); ?></p>
							<h3 id="aggr-ads-heading"><?php esc_html_e( 'Add your ads', 'aggressive-ads' ); ?></h3>
							<p class="aggr-hint"><?php esc_html_e( 'One image for each size. Files stay private until staff approve the campaign.', 'aggressive-ads' ); ?></p>
						</div>
						<?php if ( $aggr_sizes_total > 0 ) : ?>
							<div class="aggr-ads-progress">
								<span>
									<?php
									printf(
										/* translators: 1: sizes that have an ad. 2: sizes in the package. */
										esc_html( _n( '%1$d of %2$d size ready', '%1$d of %2$d sizes ready', $aggr_sizes_total, 'aggressive-ads' ) ),
										(int) $aggr_sizes_ready,
										(int) $aggr_sizes_total
									);
									?>
								</span>
								<span class="aggr-summary__bar" aria-hidden="true"><span style="width: <?php echo esc_attr( (string) (int) round( 100 * $aggr_sizes_ready / $aggr_sizes_total ) ); ?>%"></span></span>
							</div>
						<?php endif; ?>
					</div>

					<?php
					/*
					 * A preview of uploading every size at once. Disabled and labelled as
					 * coming, because a drop target that accepts files and does nothing is
					 * worse than none; each size's own form below does the uploading today.
					 */
					?>
					<div class="aggr-dropzone" aria-describedby="aggr-dropzone-note">
						<span class="aggr-dropzone__icon" aria-hidden="true">
							<svg class="aggr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V4"/><path d="M7 9l5-5 5 5"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/></svg>
						</span>
						<span class="aggr-dropzone__text">
							<span class="aggr-dropzone__title"><?php esc_html_e( 'Drop all your files here', 'aggressive-ads' ); ?></span>
							<span class="aggr-hint" style="margin: 0">
								<?php
								printf(
									/* translators: %s: the largest file any size in this package accepts, e.g. 150 KB. */
									esc_html__( 'JPEG, PNG, GIF or WebP · up to %s each · files stay private until approved', 'aggressive-ads' ),
									esc_html( (string) size_format( $aggr_max_bytes > 0 ? $aggr_max_bytes : 153600 ) )
								);
								?>
							</span>
							<span id="aggr-dropzone-note" class="aggr-sr"><?php esc_html_e( 'Dropping every file at once is coming soon. Add each file to its size below.', 'aggressive-ads' ); ?></span>
						</span>
						<span class="aggr-dropzone__actions">
							<button class="aggr-button aggr-button--secondary" type="button" disabled><?php esc_html_e( 'Browse files', 'aggressive-ads' ); ?></button>
							<a class="aggr-card-action" href="<?php echo esc_url( Routes::url( Request::ROUTE_HELP ) . '#aggr-help-artwork' ); ?>"><?php esc_html_e( 'Blank templates', 'aggressive-ads' ); ?></a>
						</span>
					</div>
				</section>

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
					<div id="aggr-uploads" class="aggr-upload-list">
						<?php foreach ( $aggr_slots as $aggr_slot ) : ?>
							<?php $aggr_slot_empty = array() === $aggr_slot['creatives']; ?>
							<?php
							$aggr_slot_dims = array();
							$aggr_slot_w    = 1 === preg_match( '/^(\d+)x(\d+)$/', (string) $aggr_slot['size'], $aggr_slot_dims ) ? (int) $aggr_slot_dims[1] : 0;
							$aggr_slot_h    = $aggr_slot_w > 0 ? (int) $aggr_slot_dims[2] : 0;
							// Wide enough that half a row would squash its shape into a line.
							$aggr_slot_wide = $aggr_slot_h >= 200 && $aggr_slot_w >= 2 * $aggr_slot_h;
							?>
							<section class="aggr-upload-card<?php echo $aggr_slot_wide ? ' aggr-upload-card--wide' : ''; ?>" aria-labelledby="aggr-slot-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>">
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
										<p class="aggr-upload-card__dims"><?php echo esc_html( $aggr_slot_w > 0 ? $aggr_slot_w . ' × ' . $aggr_slot_h . ' px' : (string) $aggr_slot['size'] ); ?></p>
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
											<?php esc_html_e( 'Needs a file', 'aggressive-ads' ); ?>
										</span>
									<?php elseif ( $aggr_slot['active'] ) : ?>
										<span class="aggr-pill aggr-pill--live"><?php esc_html_e( 'Ready', 'aggressive-ads' ); ?></span>
									<?php endif; ?>
								</div>

								<?php if ( ! $aggr_slot['active'] ) : ?>
									<div class="aggr-alert aggr-alert--error" role="alert">
										<p><?php esc_html_e( 'This placement is no longer available. Go back to package and dates and choose an available package.', 'aggressive-ads' ); ?></p>
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
															/* translators: 1: file name. 2: file size, e.g. 54 KB. 3: where it links, e.g. goes to shared link. */
															__( '%1$s · %2$s · %3$s', 'aggressive-ads' ),
															(string) $aggr_creative['name'],
															size_format( (int) $aggr_creative['bytes'] ),
															'' !== $aggr_link && (string) $aggr_creative['click_url'] === $aggr_link
																? __( 'goes to shared link', 'aggressive-ads' )
																: __( 'has its own link', 'aggressive-ads' )
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
										<?php if ( $aggr_slot_w > 0 ) : ?>
											<div class="aggr-upload-card__stage" aria-hidden="true">
												<span class="aggr-upload-card__frame" style="aspect-ratio: <?php echo esc_attr( $aggr_slot_w . ' / ' . $aggr_slot_h ); ?>; --aggr-frame-width: <?php echo esc_attr( (string) min( 20, max( 3, round( $aggr_slot_w / 36, 1 ) ) ) ); ?>rem"><?php echo esc_html( $aggr_slot_w . ' × ' . $aggr_slot_h ); ?></span>
											</div>
										<?php endif; ?>
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
						<p class="aggr-hint">
							<?php
							printf(
								/* translators: %d: sizes that still need a file. */
								esc_html( _n( '%d size still needs a file.', '%d sizes still need a file.', $aggr_missing, 'aggressive-ads' ) ),
								(int) $aggr_missing
							);
							?>
						</p>
					<?php endif; ?>
					<a class="aggr-button aggr-button--secondary" href="<?php echo esc_url( add_query_arg( 'step', 'details', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Back to package & dates', 'aggressive-ads' ); ?></a>
					<?php if ( $aggr_creative_ready ) : ?>
						<?php
						/*
						 * A post, not a link. Leaving this step is a promise that the ads and
						 * the dates are complete, and the server checks it before the resume
						 * point moves to review.
						 */
						?>
						<form id="aggr-creative-complete" class="aggr-wizard__primary" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::COMPLETE_CREATIVE_ACTION ); ?>">
							<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign['id'] ); ?>">
							<input type="hidden" name="autosave_rev" value="<?php echo esc_attr( (string) (int) ( $aggr_campaign['autosave_rev'] ?? 0 ) ); ?>">
							<?php wp_nonce_field( Campaign_Nonces::creative_nonce_action( (int) $aggr_campaign['id'] ) ); ?>
							<button class="aggr-button" type="submit"><?php esc_html_e( 'Continue to review', 'aggressive-ads' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			</div>
			<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-overlays.php'; ?>
