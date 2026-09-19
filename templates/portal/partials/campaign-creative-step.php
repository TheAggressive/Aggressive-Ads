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

use Aggressive\Ads\Domain\Upload_Rules;
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
				$aggr_dest_mode  = 'draft';
				$aggr_dest_link  = $aggr_link;
				$aggr_dest_used  = $aggr_link_used;
				$aggr_dest_total = $aggr_sizes_total;
				$aggr_dest_check = is_array( $aggr_campaign['link_check'] ?? null ) ? $aggr_campaign['link_check'] : null;

				require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-destination-card.php';
				?>

				<?php // Not a named region: the step around it already carries this name, and two landmarks called "Add your ads" is one too many. ?>
				<section class="aggr-ads-section">
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
					 * Every file at once, each sent to the size it matches.
					 *
					 * Script only, and hidden without it rather than drawn and
					 * inert: a drop target that accepts files and does nothing is
					 * worse than none, and each size's own form below uploads the
					 * same way it always has. Shown only while a size is waiting
					 * for a file, since with none open every drop would be refused.
					 */
					?>
					<?php if ( $aggr_missing > 0 ) : ?>
						<noscript><style>.aggr-portal .aggr-dropzone{display:none!important}</style></noscript>
						<div class="aggr-dropzone" data-aggr-bulk>
							<span class="aggr-dropzone__icon" aria-hidden="true">
								<svg class="aggr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V4"/><path d="M7 9l5-5 5 5"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/></svg>
							</span>
							<span class="aggr-dropzone__text">
								<span class="aggr-dropzone__title"><?php esc_html_e( 'Drop all your files here', 'aggressive-ads' ); ?></span>
								<span id="aggr-dropzone-note" class="aggr-hint">
									<?php
									printf(
										/* translators: %s: the largest file any size in this package accepts, e.g. 150 KB. */
										esc_html__( 'Each file goes to the size it matches. JPEG, PNG, GIF, WebP or AVIF · up to %s each · files stay private until approved', 'aggressive-ads' ),
										esc_html( (string) size_format( $aggr_max_bytes > 0 ? $aggr_max_bytes : 153600 ) )
									);
									?>
								</span>
								<?php // Toggled by the drop zone as the link is typed; drawn by the server for the first paint. ?>
								<span class="aggr-hint aggr-dropzone__link-hint" data-aggr-bulk-link-hint <?php echo '' !== $aggr_link ? 'hidden' : ''; ?>><?php esc_html_e( 'Add the link your ads go to above. Files dropped before then wait for it.', 'aggressive-ads' ); ?></span>
							</span>
							<span class="aggr-dropzone__actions">
								<button class="aggr-button aggr-button--secondary" type="button" data-aggr-bulk-browse aria-describedby="aggr-dropzone-note"><?php esc_html_e( 'Browse files', 'aggressive-ads' ); ?></button>
								<?php // Opened by the button; out of the tab order so the one control is not announced twice. ?>
								<input class="aggr-sr" type="file" multiple accept="<?php echo esc_attr( Upload_Rules::accept_attribute() ); ?>" tabindex="-1" aria-hidden="true" data-aggr-bulk-input>
								<a class="aggr-card-action" href="<?php echo esc_url( Routes::url( Request::ROUTE_HELP ) . '#aggr-help-artwork' ); ?>"><?php esc_html_e( 'Blank templates', 'aggressive-ads' ); ?></a>
							</span>
							<p class="aggr-upload-status aggr-dropzone__summary" role="status" aria-live="polite" data-aggr-bulk-status></p>
							<ul class="aggr-dropzone__list" aria-live="polite" data-aggr-bulk-list hidden></ul>
						</div>
					<?php endif; ?>
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
										<?php
										$aggr_card_image   = (string) $aggr_creative['preview'];
										$aggr_card_link    = (string) $aggr_creative['click_url'];
										$aggr_card_actions = array(
											array( __( 'Preview', 'aggressive-ads' ), $aggr_preview_id, false ),
											array( __( 'Replace', 'aggressive-ads' ), $aggr_artwork_id, false ),
											array( __( 'Remove', 'aggressive-ads' ), $aggr_remove_id, true ),
										);
										$aggr_card_extras  = 'campaign-ad-card-draft.php';
										$aggr_card_place   = (string) $aggr_slot['name'];

										require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-ad-card.php';
										?>
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
										<?php
										$aggr_reuse_slots = $aggr_slots;

										require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-same-file.php';
										require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-upload-form.php';
										?>
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
