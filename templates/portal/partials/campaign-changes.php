<?php
/**
 * Editing a campaign that is already running.
 *
 * Creation's screens, drawn from creation's own partials — the package grid,
 * the schedule, the Destination card, the size cards — because an advertiser
 * who made the campaign already knows them, and two copies of a screen are
 * how one of them drifts. What differs is what saving means: every save here
 * stages a proposal instead of writing to the campaign, which keeps running
 * exactly as approved until the review team accepts it. That is why the
 * summary card is "Your changes" rather than the order.
 *
 * The steps themselves are drawn in the head by `campaign-changes-steps.php`.
 *
 * Only the fields the site has enabled are rendered at all. "Absent, not
 * disabled" is the rule throughout the plugin: a greyed-out control still tells
 * an advertiser the field exists and still arrives in a hand-built POST.
 *
 * @package Aggressive\Ads
 *
 * @var array<string, mixed> $aggr_campaign The campaign being edited.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Edit_Steps;
use Aggressive\Ads\Portal\Campaign_Nonces;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;

$aggr_edit_fields = is_array( $aggr_campaign['live_edit_fields'] ?? null ) ? $aggr_campaign['live_edit_fields'] : array();
$aggr_draft       = is_array( $aggr_campaign['draft_edits'] ?? null ) ? $aggr_campaign['draft_edits'] : array();
$aggr_values      = is_array( $aggr_campaign['edit_values'] ?? null ) ? $aggr_campaign['edit_values'] : array();
$aggr_campaign_id = (int) $aggr_campaign['id'];
$aggr_can_update  = true === ( $aggr_campaign['can_request_updates'] ?? false );
$aggr_edit_steps  = Campaign_Edit_Steps::for_fields( $aggr_edit_fields, $aggr_can_update );
$aggr_edit_step   = Campaign_Edit_Steps::current( Campaign_Actions::request_change_step(), $aggr_edit_steps );
$aggr_edit_next   = Campaign_Edit_Steps::next( $aggr_edit_step, $aggr_edit_steps );

$aggr_has_schedule = Campaign_Edit_Steps::has_dates( $aggr_edit_fields );
$aggr_has_links    = Campaign_Edit_Steps::has_links( $aggr_edit_fields );

/*
 * Ad replacements go to the review team as each is uploaded, on their own
 * track, so they are not staged edits; they are listed beside the staged
 * ones so "Your changes" is everything the advertiser has asked for.
 */
$aggr_pending_ads = array();

foreach ( is_array( $aggr_creative_updates ?? null ) ? $aggr_creative_updates : array() as $aggr_update_row ) {
	if ( 'pending' !== (string) ( $aggr_update_row['state'] ?? '' ) ) {
		continue;
	}

	foreach ( $aggr_campaign['creatives'] as $aggr_update_creative ) {
		if ( (int) $aggr_update_creative['id'] === (int) $aggr_update_row['creative_id'] ) {
			$aggr_pending_ads[] = (string) $aggr_update_creative['placement'];
		}
	}
}
$aggr_placement_cards = is_array( $aggr_campaign['edit_placement_options'] ?? null ) ? $aggr_campaign['edit_placement_options'] : array();

$aggr_selected_placements = array_map( 'intval', (array) ( $aggr_values['placement_ids'] ?? array() ) );

/*
 * The package the proposal holds, and whether it sets the length. A fixed
 * package's end is derived, exactly as creation derives it, so the schedule
 * below hides the end and states the last day instead.
 */
$aggr_can_package   = in_array( 'package_id', $aggr_edit_fields, true );
$aggr_on_sale       = is_array( $aggr_campaign['package_options'] ?? null ) ? $aggr_campaign['package_options'] : array();
$aggr_edit_package  = (int) ( $aggr_values['package_id'] ?? 0 );
$aggr_package_days  = 0;
$aggr_package_found = false;

foreach ( $aggr_on_sale as $aggr_sale_package ) {
	if ( (int) $aggr_sale_package['id'] === $aggr_edit_package ) {
		$aggr_package_days  = (int) $aggr_sale_package['duration_days'];
		$aggr_package_found = true;
	}
}

$aggr_edit_start_ts = (int) ( $aggr_values['start_ts'] ?? 0 );
$aggr_edit_fixed    = $aggr_package_days > 0;
$aggr_edit_run_end  = $aggr_edit_fixed && $aggr_edit_start_ts > 0 ? Campaign_Rules::fixed_end_ts( $aggr_edit_start_ts, $aggr_package_days, wp_timezone()->getName() ) : 0;
$aggr_edit_card     = 0;
?>
<?php // `aggr-wizard` is creation's container, so the size cards lay out as creation's do at every width. ?>
<div class="aggr-columns aggr-changes aggr-wizard">
	<div class="aggr-columns__main">
		<div class="<?php echo 'review' === $aggr_edit_step ? 'aggr-step-card' : 'aggr-changes__steps'; ?>">
			<?php
			if ( 'review' === $aggr_edit_step ) :
				?>
				<p class="aggr-eyebrow" aria-hidden="true"><?php esc_html_e( 'Review', 'aggressive-ads' ); ?></p>
				<h2 id="aggr-edit-heading" class="aggr-step-card__title"><?php esc_html_e( 'Check your changes', 'aggressive-ads' ); ?></h2>
				<p class="aggr-hint"><?php esc_html_e( 'The campaign keeps running exactly as approved until the review team accepts these.', 'aggressive-ads' ); ?></p>

				<?php if ( array() !== $aggr_pending_ads ) : ?>
					<p class="aggr-hint">
						<?php
						printf(
							/* translators: %s: comma-separated placements, e.g. Homepage leaderboard. */
							esc_html__( 'New artwork is already with the review team for: %s. It does not need submitting again.', 'aggressive-ads' ),
							esc_html( implode( ', ', $aggr_pending_ads ) )
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( array() === $aggr_draft ) : ?>
					<div class="aggr-empty">
						<p class="aggr-empty__title"><?php esc_html_e( 'Nothing changed yet', 'aggressive-ads' ); ?></p>
						<p><?php esc_html_e( 'Change something on an earlier step and it will appear here before you submit it.', 'aggressive-ads' ); ?></p>
					</div>
				<?php else : ?>
					<ul class="aggr-changes__list">
						<?php foreach ( $aggr_draft as $aggr_change ) : ?>
							<li class="aggr-changes__item">
								<span class="aggr-changes__field"><?php echo esc_html( (string) $aggr_change['label'] ); ?></span>
								<span class="aggr-changes__from">
									<span class="aggr-sr"><?php esc_html_e( 'Currently', 'aggressive-ads' ); ?></span>
									<?php echo esc_html( '' !== (string) $aggr_change['from'] ? (string) $aggr_change['from'] : '—' ); ?>
								</span>
								<span class="aggr-changes__arrow" aria-hidden="true">→</span>
								<span class="aggr-changes__to">
									<span class="aggr-sr"><?php esc_html_e( 'Changing to', 'aggressive-ads' ); ?></span>
									<?php echo esc_html( '' !== (string) $aggr_change['to'] ? (string) $aggr_change['to'] : '—' ); ?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php
			elseif ( Campaign_Edit_Steps::DETAILS === $aggr_edit_step ) :
				?>
				<form id="aggr-changes-form" class="aggr-form aggr-changes__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::CHANGES_ACTION ); ?>">
					<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign_id ); ?>">
					<input type="hidden" name="next_step" value="<?php echo esc_attr( $aggr_edit_next ); ?>">
					<?php wp_nonce_field( Campaign_Nonces::changes_nonce_action( $aggr_campaign_id ) ); ?>

					<?php
					/*
					 * Creation's first step: the package, then the schedule, in
					 * one form, so the schedule follows the package as it is
					 * chosen. The handler stages whichever fields arrive; an
					 * unchanged value is not a change, so a campaign that has
					 * started is not refused for a start it left alone.
					 */
					?>
					<?php if ( $aggr_can_package || in_array( 'placement_ids', $aggr_edit_fields, true ) ) : ?>
						<div class="aggr-step-card">
						<fieldset id="aggr-packages" class="aggr-fieldset">
							<legend>
								<span class="aggr-eyebrow" aria-hidden="true"><?php echo esc_html( sprintf( /* translators: %s: the card's number, e.g. 01. */ __( '%s · Package', 'aggressive-ads' ), str_pad( (string) ++$aggr_edit_card, 2, '0', STR_PAD_LEFT ) ) ); ?></span>
								<span id="aggr-edit-heading" class="aggr-step-card__title"><?php echo esc_html( $aggr_can_package ? __( 'Choose a package', 'aggressive-ads' ) : __( 'Your package', 'aggressive-ads' ) ); ?></span>
							</legend>

							<?php if ( $aggr_can_package && array() !== $aggr_on_sale ) : ?>
								<p id="aggr-packages-hint" class="aggr-hint"><?php esc_html_e( 'Upgrade or change your package here. Your campaign keeps running on its current package, at its current price, until the review team accepts the change.', 'aggressive-ads' ); ?></p>

								<?php if ( ! $aggr_package_found && '' !== (string) ( $aggr_campaign['package_name'] ?? '' ) ) : ?>
									<?php // Retired since it was bought: still what is running, so still named. ?>
									<p class="aggr-changes__package">
										<span class="aggr-package__name"><?php echo esc_html( (string) $aggr_campaign['package_name'] ); ?></span>
										<span class="aggr-package__badge"><?php esc_html_e( 'Your package', 'aggressive-ads' ); ?></span>
										<span class="aggr-package__price"><?php echo esc_html( (string) ( $aggr_campaign['package_price'] ?? '' ) ); ?></span>
									</p>
								<?php endif; ?>

								<?php
								$aggr_grid_packages = $aggr_on_sale;
								$aggr_grid_selected = $aggr_edit_package;
								$aggr_grid_current  = (int) ( $aggr_campaign['package_id'] ?? 0 );
								$aggr_grid_hint     = 'aggr-packages-hint';

								require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-package-grid.php';
								?>
							<?php elseif ( '' !== (string) ( $aggr_campaign['package_name'] ?? '' ) ) : ?>
								<p class="aggr-changes__package">
									<span class="aggr-package__name"><?php echo esc_html( (string) $aggr_campaign['package_name'] ); ?></span>
									<?php if ( '' !== (string) ( $aggr_campaign['package_price'] ?? '' ) ) : ?>
										<span class="aggr-package__price"><?php echo esc_html( (string) $aggr_campaign['package_price'] ); ?></span>
									<?php endif; ?>
								</p>
							<?php endif; ?>

							<?php if ( in_array( 'placement_ids', $aggr_edit_fields, true ) ) : ?>
								<fieldset class="aggr-fieldset" aria-describedby="aggr-edit-placements-hint">
									<legend class="aggr-changes__legend"><?php esc_html_e( 'Placements', 'aggressive-ads' ); ?></legend>
									<p id="aggr-edit-placements-hint" class="aggr-hint"><?php esc_html_e( 'The placements your package includes. Changing them changes the ad sizes you must supply: if this is approved, a size with no ad does not run until you add one and it is reviewed.', 'aggressive-ads' ); ?></p>
									<div class="aggr-choicegrid aggr-changes__placements">
										<?php foreach ( $aggr_placement_cards as $aggr_option ) : ?>
											<label class="aggr-choice aggr-choice--package">
												<input
													type="checkbox"
													name="placement_ids[]"
													value="<?php echo esc_attr( (string) (int) $aggr_option['id'] ); ?>"
													<?php checked( in_array( (int) $aggr_option['id'], $aggr_selected_placements, true ) ); ?>
												>
												<span class="aggr-choice__text">
													<span class="aggr-package__name"><?php echo esc_html( (string) $aggr_option['name'] ); ?></span>
													<span class="aggr-package__meta">
														<?php
														printf(
															/* translators: %s: the largest file this placement accepts, e.g. 150 KB. */
															esc_html__( 'Files up to %s', 'aggressive-ads' ),
															esc_html( (string) $aggr_option['max_size'] )
														);
														?>
													</span>
													<?php if ( is_array( $aggr_option['shape'] ?? null ) ) : ?>
														<span class="aggr-package__sizes" aria-hidden="true">
															<span class="aggr-package__size">
																<span class="aggr-package__shape" style="width: <?php echo esc_attr( (string) (int) $aggr_option['shape']['width'] ); ?>px; height: <?php echo esc_attr( (string) (int) $aggr_option['shape']['height'] ); ?>px"></span>
																<?php echo esc_html( (string) $aggr_option['shape']['label'] ); ?>
															</span>
														</span>
														<span class="aggr-sr"><?php echo esc_html( (string) $aggr_option['shape']['label'] ); ?></span>
													<?php endif; ?>
												</span>
											</label>
										<?php endforeach; ?>
									</div>
								</fieldset>
							<?php endif; ?>
						</fieldset>
						</div>
					<?php endif; ?>

					<?php if ( $aggr_has_schedule ) : ?>
						<div class="aggr-step-card">
						<fieldset id="aggr-schedule" class="aggr-fieldset">
							<legend>
								<span class="aggr-eyebrow" aria-hidden="true"><?php echo esc_html( sprintf( /* translators: %s: the card's number, e.g. 02. */ __( '%s · Schedule', 'aggressive-ads' ), str_pad( (string) ++$aggr_edit_card, 2, '0', STR_PAD_LEFT ) ) ); ?></span>
								<span id="aggr-edit-dates-heading" class="aggr-step-card__title"><?php esc_html_e( 'When should it run?', 'aggressive-ads' ); ?></span>
							</legend>
							<p class="aggr-hint">
								<?php
								echo $aggr_edit_fixed
									? esc_html__( 'The package sets how long it runs. The start day counts as the first day.', 'aggressive-ads' )
									: esc_html__( 'A custom schedule: choose a start and an end.', 'aggressive-ads' );
								?>
							</p>

							<?php
							/*
							 * Staged values first, the stored campaign only as the
							 * fallback — `edit_values` is both. The stored start
							 * decides the lock, not the staged one: it is the
							 * campaign already running that cannot be moved, and
							 * Live_Edit_Rules refuses the same change on the server.
							 */
							$aggr_sched_start        = (string) ( $aggr_values['start_date'] ?? $aggr_campaign['start_date'] );
							$aggr_sched_end          = (string) ( $aggr_values['end_date'] ?? $aggr_campaign['end_date'] );
							$aggr_sched_min          = '';
							$aggr_sched_fixed        = $aggr_edit_fixed;
							$aggr_sched_run_end      = $aggr_edit_run_end;
							$aggr_sched_locked       = '' !== (string) $aggr_campaign['start_date'] && (string) $aggr_campaign['start_date'] <= (string) wp_date( 'Y-m-d', null, wp_timezone() );
							$aggr_sched_end_required = '' !== (string) $aggr_campaign['end_date'];

							require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-schedule-fields.php';
							?>
						</fieldset>
						</div>
					<?php endif; ?>

					<?php if ( in_array( 'title', $aggr_edit_fields, true ) || in_array( 'advertiser_notes', $aggr_edit_fields, true ) ) : ?>
						<section class="aggr-step-card" aria-labelledby="aggr-edit-name-heading">
							<p class="aggr-eyebrow" aria-hidden="true"><?php echo esc_html( sprintf( /* translators: %s: the card's number, e.g. 03. */ __( '%s · Details', 'aggressive-ads' ), str_pad( (string) ++$aggr_edit_card, 2, '0', STR_PAD_LEFT ) ) ); ?></p>
							<h2 id="aggr-edit-name-heading" class="aggr-step-card__title"><?php esc_html_e( 'Name and notes', 'aggressive-ads' ); ?></h2>

							<?php if ( in_array( 'title', $aggr_edit_fields, true ) ) : ?>
								<div class="aggr-field">
									<label for="aggr-edit-title"><?php esc_html_e( 'Campaign name', 'aggressive-ads' ); ?></label>
									<input type="text" id="aggr-edit-title" name="title" value="<?php echo esc_attr( (string) ( $aggr_values['title'] ?? '' ) ); ?>" maxlength="200">
								</div>
							<?php endif; ?>

							<?php if ( in_array( 'advertiser_notes', $aggr_edit_fields, true ) ) : ?>
								<div class="aggr-field">
									<label for="aggr-edit-notes"><?php esc_html_e( 'Notes for the review team', 'aggressive-ads' ); ?></label>
									<textarea id="aggr-edit-notes" name="advertiser_notes" rows="4" maxlength="2000"><?php echo esc_textarea( (string) ( $aggr_values['advertiser_notes'] ?? '' ) ); ?></textarea>
								</div>
							<?php endif; ?>
						</section>
					<?php endif; ?>

					<div class="aggr-form__actions">
						<button class="aggr-button" type="submit">
							<?php esc_html_e( 'Save and continue', 'aggressive-ads' ); ?>
						</button>
					</div>
				</form>
			<?php else : ?>
				<?php
				/*
				 * Ads, as creation's second step is: the link every ad goes
				 * to, then the ads themselves. The link is staged with the
				 * rest of the edit; replacing an ad is its own request, sent
				 * from its Update dialog as soon as the file is chosen.
				 */
				?>
				<div class="aggr-form">
					<?php // The step's name, so the cards' headings below it are not a level skipped. ?>
					<h2 id="aggr-edit-heading" class="aggr-sr"><?php echo esc_html( $aggr_edit_steps[ Campaign_Edit_Steps::DESTINATION ] ?? __( 'Ads', 'aggressive-ads' ) ); ?></h2>
					<?php if ( $aggr_has_links ) : ?>
						<?php
						$aggr_dest_mode  = 'edit';
						$aggr_dest_link  = (string) ( $aggr_campaign['edit_link'] ?? '' );
						$aggr_dest_used  = (int) ( $aggr_campaign['edit_link_used'] ?? 0 );
						$aggr_dest_total = (int) ( $aggr_campaign['edit_link_total'] ?? 0 );
						$aggr_dest_check = is_array( $aggr_campaign['edit_link_check'] ?? null ) ? $aggr_campaign['edit_link_check'] : null;
						$aggr_dest_next  = $aggr_edit_next;

						require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-destination-card.php';
						?>
					<?php endif; ?>

					<?php
					$aggr_ads_eyebrow = $aggr_has_links ? __( '02 · Ads', 'aggressive-ads' ) : __( '01 · Ads', 'aggressive-ads' );

					require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-edit-ads.php';
					?>

					<div class="aggr-form__actions">
						<?php if ( isset( $aggr_edit_steps[ Campaign_Edit_Steps::DETAILS ] ) ) : ?>
							<a class="aggr-button aggr-button--secondary" href="<?php echo esc_url( Campaign_Edit_Steps::url( $aggr_campaign_id, Campaign_Edit_Steps::DETAILS ) ); ?>"><?php esc_html_e( 'Back to package & dates', 'aggressive-ads' ); ?></a>
						<?php endif; ?>
						<?php if ( $aggr_has_links ) : ?>
							<button class="aggr-button" type="submit" form="aggr-changes-form">
								<?php esc_html_e( 'Save and continue', 'aggressive-ads' ); ?>
							</button>
						<?php else : ?>
							<a class="aggr-button" href="<?php echo esc_url( Campaign_Edit_Steps::url( $aggr_campaign_id, Campaign_Edit_Steps::REVIEW ) ); ?>"><?php esc_html_e( 'Continue to review', 'aggressive-ads' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<aside class="aggr-columns__side">
		<section class="aggr-summary" aria-labelledby="aggr-changes-summary-heading">
			<div class="aggr-summary__head">
				<h2 id="aggr-changes-summary-heading" class="aggr-eyebrow"><?php esc_html_e( 'Your changes', 'aggressive-ads' ); ?></h2>
				<span class="aggr-pill aggr-pill--pending"><?php esc_html_e( 'Editing', 'aggressive-ads' ); ?></span>
			</div>

			<p class="aggr-hint"><?php esc_html_e( 'Your campaign keeps running exactly as approved while you edit. Nothing changes until you submit these and the review team accepts them.', 'aggressive-ads' ); ?></p>

			<?php if ( array() === $aggr_draft && array() === $aggr_pending_ads ) : ?>
				<p class="aggr-changes__none"><?php esc_html_e( 'Nothing changed yet.', 'aggressive-ads' ); ?></p>
			<?php else : ?>
				<dl class="aggr-summary__rows">
					<?php foreach ( $aggr_draft as $aggr_change ) : ?>
						<div>
							<dt><?php echo esc_html( (string) $aggr_change['label'] ); ?></dt>
							<dd>
								<?php echo esc_html( '' !== (string) $aggr_change['to'] ? (string) $aggr_change['to'] : '—' ); ?>
								<span class="aggr-summary__sub">
									<?php
									printf(
										/* translators: %s: the value before the change. */
										esc_html__( 'was %s', 'aggressive-ads' ),
										esc_html( '' !== (string) $aggr_change['from'] ? (string) $aggr_change['from'] : '—' )
									);
									?>
								</span>
							</dd>
						</div>
					<?php endforeach; ?>
					<?php foreach ( $aggr_pending_ads as $aggr_pending_place ) : ?>
						<div>
							<dt><?php esc_html_e( 'New artwork', 'aggressive-ads' ); ?></dt>
							<dd>
								<?php echo esc_html( $aggr_pending_place ); ?>
								<span class="aggr-summary__sub"><?php esc_html_e( 'with the review team', 'aggressive-ads' ); ?></span>
							</dd>
						</div>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>

			<div class="aggr-summary__actions">
				<?php if ( 'review' === $aggr_edit_step && array() !== $aggr_draft ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::CHANGES_SUBMIT ); ?>">
						<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign_id ); ?>">
						<?php wp_nonce_field( Campaign_Nonces::submit_changes_nonce_action( $aggr_campaign_id ) ); ?>
						<button class="aggr-button aggr-button--block" type="submit">
							<?php esc_html_e( 'Submit for review', 'aggressive-ads' ); ?>
						</button>
					</form>
				<?php endif; ?>

				<?php if ( array() !== $aggr_draft ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::CHANGES_CANCEL ); ?>">
						<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign_id ); ?>">
						<?php wp_nonce_field( Campaign_Nonces::cancel_changes_nonce_action( $aggr_campaign_id ) ); ?>
						<button class="aggr-card-action" type="submit">
							<?php esc_html_e( 'Discard these edits', 'aggressive-ads' ); ?>
						</button>
					</form>
				<?php endif; ?>

				<?php // Leaving keeps what was staged; it is still there on the next visit. ?>
				<a class="aggr-card-action" href="<?php echo esc_url( Routes::url( Request::ROUTE_CAMPAIGNS, $aggr_campaign_id ) ); ?>"><?php esc_html_e( 'Back to the campaign', 'aggressive-ads' ); ?></a>
			</div>
		</section>
	</aside>
</div>
