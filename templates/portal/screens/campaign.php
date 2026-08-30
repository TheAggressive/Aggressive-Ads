<?php
/**
 * Campaign detail contents.
 *
 * The campaign itself arrives from templates/portal/campaigns-detail.php, which
 * resolves it before any output so the response status can still be set. This
 * file only renders.
 *
 * @package Aggressive\Ads
 *
 * @var array<string, mixed> $aggr_campaign The campaign to render.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Workflow\Creative_Manager;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Creative_Actions;

$aggr_creatives          = is_array( $aggr_campaign['creatives'] ) ? $aggr_campaign['creatives'] : array();
$aggr_creative_updates   = is_array( $aggr_campaign['creative_updates'] ) ? $aggr_campaign['creative_updates'] : array();
$aggr_notes              = (string) $aggr_campaign['review_notes'];
$aggr_places             = is_array( $aggr_campaign['placements'] ) ? $aggr_campaign['placements'] : array();
$aggr_place_ids          = is_array( $aggr_campaign['placement_ids'] ) ? array_map( 'intval', $aggr_campaign['placement_ids'] ) : array();
$aggr_options            = is_array( $aggr_campaign['placement_options'] ) ? $aggr_campaign['placement_options'] : array();
$aggr_packages           = is_array( $aggr_campaign['package_options'] ) ? $aggr_campaign['package_options'] : array();
$aggr_slots              = is_array( $aggr_campaign['creative_slots'] ) ? $aggr_campaign['creative_slots'] : array();
$aggr_readiness          = is_array( $aggr_campaign['readiness'] ) ? $aggr_campaign['readiness'] : array();
$aggr_review_problems    = is_array( $aggr_readiness['problems'] ?? null ) ? $aggr_readiness['problems'] : array();
$aggr_review_ready       = true === ( $aggr_readiness['ready'] ?? false );
$aggr_package_id         = (int) $aggr_campaign['package_id'];
$aggr_notice             = Campaign_Actions::request_notice();
$aggr_error              = Campaign_Actions::request_error_code();
$aggr_error_for          = Campaign_Actions::error_field( $aggr_error );
$aggr_step               = Campaign_Actions::request_step( (string) $aggr_campaign['wizard_step'] );
$aggr_step               = in_array( $aggr_step, array( 'details', 'package', 'creative', 'destination', 'review', 'submit' ), true ) ? $aggr_step : 'review';
$aggr_campaign_url       = Routes::url( Request::ROUTE_CAMPAIGNS, (int) $aggr_campaign['id'] );
$aggr_creative_notice    = Creative_Actions::request_notice();
$aggr_creative_error     = Creative_Actions::request_error_code();
$aggr_error_placement    = Creative_Actions::request_error_placement();
$aggr_creative_error_for = Creative_Actions::error_target( $aggr_creative_error, $aggr_error_placement );
$aggr_min_start_date     = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );
$aggr_creative_ready     = array() !== $aggr_slots;
$aggr_overlays           = array();
$aggr_line_items         = is_array( $aggr_campaign['line_items'] ?? null ) ? $aggr_campaign['line_items'] : array();

foreach ( $aggr_slots as $aggr_slot ) {
	if ( ! $aggr_slot['active'] || 1 !== count( $aggr_slot['creatives'] ) ) {
		$aggr_creative_ready = false;
	}
}

/*
 * Editing a running campaign is a mode, not a status: the campaign stays live
 * throughout. It is only entered when the site allows it, the campaign is
 * running, and nothing is already with the review team.
 */
$aggr_confirming_cancel = true === ( $aggr_campaign['can_cancel'] ?? false )
	&& Campaign_Actions::wants_cancel_confirmation();

$aggr_editing_changes = Campaign_Actions::wants_change_editor()
	&& true === ( $aggr_campaign['can_request_changes'] ?? false );

if ( 'creative' === $aggr_step && '' !== $aggr_creative_notice ) {
	$aggr_notice = '';
}
?>
<nav class="aggr-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'aggressive-ads' ); ?>">
	<a href="<?php echo esc_url( Routes::url( Request::ROUTE_CAMPAIGNS ) ); ?>">
		<?php esc_html_e( 'Campaigns', 'aggressive-ads' ); ?>
	</a>
</nav>

<div class="aggr-pagehead">
	<div>
		<div class="aggr-pagehead__heading">
			<h1 class="aggr-title"><?php echo esc_html( (string) $aggr_campaign['title'] ); ?></h1>

			<span class="aggr-pill aggr-pill--<?php echo esc_attr( (string) $aggr_campaign['pill'] ); ?>">
				<?php echo esc_html( (string) $aggr_campaign['status_text'] ); ?>
			</span>
		</div>

		<p class="aggr-lede"><?php echo esc_html( (string) $aggr_campaign['dates'] ); ?></p>
	</div>

	<div class="aggr-pagehead__actions">
		<?php if ( true === $aggr_campaign['can_withdraw'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::WITHDRAW_ACTION ); ?>">
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) (int) $aggr_campaign['id'] ); ?>">
				<?php wp_nonce_field( Campaign_Actions::withdraw_nonce_action( (int) $aggr_campaign['id'] ) ); ?>
				<button class="aggr-button" type="submit">
					<?php esc_html_e( 'Withdraw and edit', 'aggressive-ads' ); ?>
				</button>
			</form>
		<?php endif; ?>

		<?php if ( true === $aggr_campaign['can_request_changes'] && ! $aggr_editing_changes ) : ?>
			<a class="aggr-button" href="<?php echo esc_url( add_query_arg( 'edit', '1', $aggr_campaign_url ) ); ?>">
				<?php esc_html_e( 'Edit', 'aggressive-ads' ); ?>
			</a>
		<?php endif; ?>

		<?php if ( true === $aggr_campaign['can_copy'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::COPY_ACTION ); ?>">
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) (int) $aggr_campaign['id'] ); ?>">
				<?php wp_nonce_field( Campaign_Actions::copy_nonce_action( (int) $aggr_campaign['id'] ) ); ?>
				<button class="aggr-button aggr-button--secondary" type="submit">
					<?php echo esc_html( (string) $aggr_campaign['copy_label'] ); ?>
				</button>
			</form>
		<?php endif; ?>

		<?php if ( true === $aggr_campaign['can_cancel'] ) : ?>
			<?php
			/*
			 * Outlined rather than filled, and last in the row. It sits beside
			 * Edit and Duplicate because that is where a reader looks for what
			 * they can do to a campaign — but it is irreversible, so it must not
			 * compete with them for a glance.
			 *
			 * `formnovalidate` is absent deliberately: this posts to its own
			 * handler, and the confirmation is the interstitial screen rather
			 * than a JavaScript dialog, so the flow works with scripting off.
			 */
			?>
			<form method="get" action="<?php echo esc_url( $aggr_campaign_url ); ?>">
				<input type="hidden" name="confirm" value="cancel">
				<button class="aggr-button aggr-button--outline-danger" type="submit">
					<?php echo esc_html( (string) $aggr_campaign['cancel_label'] ); ?>
				</button>
			</form>
		<?php endif; ?>
	</div>
</div>

<?php if ( $aggr_confirming_cancel ) : ?>
	<section class="aggr-panel aggr-panel--danger" aria-labelledby="aggr-confirm-heading">
		<h2 id="aggr-confirm-heading" class="aggr-panel__head" tabindex="-1">
			<?php echo esc_html( (string) $aggr_campaign['cancel_label'] ); ?>
		</h2>

		<p>
			<?php
			echo esc_html(
				Post_Statuses::DRAFT === (string) $aggr_campaign['status']
					? __( 'This campaign will be closed and can never be reopened or submitted. Its record stays in your list so you can see what happened.', 'aggressive-ads' )
					: __( 'This campaign will stop and can never be restarted. Its record and any delivery figures stay in your list.', 'aggressive-ads' )
			);
			?>
		</p>
		<p><?php esc_html_e( 'If you only want to change something, go back and use Edit instead.', 'aggressive-ads' ); ?></p>

		<div class="aggr-form__actions">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::CANCEL_ACTION ); ?>">
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) (int) $aggr_campaign['id'] ); ?>">
				<?php wp_nonce_field( Campaign_Actions::cancel_nonce_action( (int) $aggr_campaign['id'] ) ); ?>
				<button class="aggr-button aggr-button--outline-danger" type="submit">
					<?php echo esc_html( (string) $aggr_campaign['cancel_label'] ); ?>
				</button>
			</form>

			<a class="aggr-button aggr-button--secondary" href="<?php echo esc_url( $aggr_campaign_url ); ?>">
				<?php esc_html_e( 'Keep this campaign', 'aggressive-ads' ); ?>
			</a>
		</div>
	</section>
	<?php
	// Nothing below this point applies while the reader is being asked to
	// confirm: showing the wizard underneath a confirmation invites them to
	// carry on editing a campaign they are about to end.
	return;
endif;
?>

<?php if ( in_array( $aggr_notice, array( 'created', 'copied', 'saved', 'package_saved', 'schedule_saved', 'submitted', 'withdrawn', 'changes_requested', 'changes_cancelled', 'changes_saved' ), true ) ) : ?>
	<div class="aggr-alert aggr-alert--success" role="status">
		<p>
			<?php
				echo esc_html(
					match ( $aggr_notice ) {
						'created'        => __( 'Campaign created. Add the details below to continue.', 'aggressive-ads' ),
						'copied'         => __( 'Campaign copied. Choose new dates before submitting.', 'aggressive-ads' ),
						'package_saved'  => __( 'Package saved. Add one correctly sized creative for each placement.', 'aggressive-ads' ),
						'schedule_saved' => __( 'Destinations and schedule saved. Continue to review when you are ready.', 'aggressive-ads' ),
						'submitted'      => __( 'Campaign submitted. It is now in the review queue.', 'aggressive-ads' ),
						'withdrawn'      => __( 'Campaign withdrawn from review and reopened for editing. Submit it again when you are ready.', 'aggressive-ads' ),
						'changes_requested' => __( 'Edits submitted for review. Your campaign keeps running as approved until the review team decides.', 'aggressive-ads' ),
						'changes_cancelled' => __( 'Edits discarded. Nothing about your running campaign changed.', 'aggressive-ads' ),
						'changes_saved'     => __( 'Saved. Nothing is sent to the review team until you submit these edits.', 'aggressive-ads' ),
						default          => __( 'Details saved. Choose a package to continue.', 'aggressive-ads' ),
					}
				);
			?>
		</p>
	</div>
<?php elseif ( 'error' === $aggr_notice ) : ?>
	<div class="aggr-alert aggr-alert--error" role="alert" tabindex="-1">
		<h2><?php esc_html_e( 'There is a problem', 'aggressive-ads' ); ?></h2>
		<p id="aggr-campaign-error">
			<?php if ( '' !== $aggr_error_for ) : ?>
				<a href="#<?php echo esc_attr( $aggr_error_for ); ?>">
					<?php echo esc_html( Campaign_Actions::error_message( $aggr_error ) ); ?>
				</a>
			<?php else : ?>
				<?php echo esc_html( Campaign_Actions::error_message( $aggr_error ) ); ?>
			<?php endif; ?>
		</p>
	</div>
<?php endif; ?>

<?php if ( in_array( $aggr_creative_notice, array( 'creative_uploaded', 'creative_removed', 'creative_update_requested', 'creative_update_withdrawn' ), true ) ) : ?>
	<div class="aggr-alert aggr-alert--success" role="status">
		<p>
			<?php
			echo esc_html(
				match ( $aggr_creative_notice ) {
					'creative_uploaded'         => __( 'Creative uploaded and stored privately.', 'aggressive-ads' ),
					'creative_removed'          => __( 'Creative removed.', 'aggressive-ads' ),
					'creative_update_requested' => __( 'Your ad update is waiting for review. The current ad will keep running.', 'aggressive-ads' ),
					default                     => __( 'The pending ad update was withdrawn.', 'aggressive-ads' ),
				}
			);
			?>
		</p>
	</div>
<?php elseif ( 'error' === $aggr_creative_notice ) : ?>
	<div class="aggr-alert aggr-alert--error" role="alert" tabindex="-1">
		<h2><?php esc_html_e( 'There is a problem with the creative', 'aggressive-ads' ); ?></h2>
		<p id="aggr-creative-error">
			<?php if ( '' !== $aggr_creative_error_for ) : ?>
				<a href="#<?php echo esc_attr( $aggr_creative_error_for ); ?>"><?php echo esc_html( Creative_Actions::error_message( $aggr_creative_error ) ); ?></a>
			<?php else : ?>
				<?php echo esc_html( Creative_Actions::error_message( $aggr_creative_error ) ); ?>
			<?php endif; ?>
		</p>
	</div>
<?php endif; ?>

<?php if ( '' !== $aggr_notes ) : ?>
	<section class="aggr-notice" aria-labelledby="aggr-notes-heading">
		<h2 id="aggr-notes-heading" class="aggr-notice__head">
			<?php esc_html_e( 'Notes from the review team', 'aggressive-ads' ); ?>
		</h2>
		<p><?php echo esc_html( $aggr_notes ); ?></p>
	</section>
<?php endif; ?>

<?php if ( array() !== $aggr_line_items ) : ?>
	<section class="aggr-panel" aria-labelledby="aggr-delivery-strategy-heading">
		<h2 id="aggr-delivery-strategy-heading" class="aggr-panel__head">
			<?php esc_html_e( 'Delivery strategy', 'aggressive-ads' ); ?>
		</h2>
		<?php foreach ( $aggr_line_items as $aggr_line_item ) : ?>
			<dl class="aggr-facts">
				<div class="aggr-fact"><dt><?php esc_html_e( 'Line item', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( (string) $aggr_line_item['name'] ); ?></dd></div>
				<div class="aggr-fact"><dt><?php esc_html_e( 'Status', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $aggr_line_item['status'] ) ) ); ?></dd></div>
				<div class="aggr-fact"><dt><?php esc_html_e( 'Pricing', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( strtoupper( (string) $aggr_line_item['pricing_model'] ) ); ?></dd></div>
				<div class="aggr-fact"><dt><?php esc_html_e( 'Goal', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $aggr_line_item['goal_type'] ) ) ); ?></dd></div>
				<div class="aggr-fact"><dt><?php esc_html_e( 'Pacing', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( ucwords( (string) $aggr_line_item['pacing_mode'] ) ); ?></dd></div>
			</dl>
		<?php endforeach; ?>
	</section>
<?php endif; ?>

<?php
/*
 * Staff editing a client's campaign look at exactly the screen the client
 * would see, which is the point — and the hazard. Without this the only clue
 * that these edits land on somebody else's live campaign is the org name in
 * the top bar, which reads as your own.
 */
?>
<?php if ( ! empty( $aggr_campaign['on_behalf'] ) ) : ?>
	<section class="aggr-notice" aria-labelledby="aggr-on-behalf-heading">
		<h2 id="aggr-on-behalf-heading" class="aggr-notice__head">
			<?php esc_html_e( 'You are editing on behalf of this advertiser', 'aggressive-ads' ); ?>
		</h2>
		<p>
			<?php
			printf(
				/* translators: %s: organization name. */
				esc_html__( 'Changes you save here are recorded against %s and appear in their audit history as a staff edit.', 'aggressive-ads' ),
				esc_html( (string) ( $aggr_campaign['org_name'] ?? '' ) )
			);
			?>
		</p>
	</section>
<?php endif; ?>

<?php if ( true === $aggr_campaign['editable'] ) : ?>
	<?php
	$aggr_wizard_id        = 'campaign-' . (int) $aggr_campaign['id'];
	$aggr_wizard_context   = function_exists( 'wp_interactivity_data_wp_context' )
		? wp_interactivity_data_wp_context( array( 'wizardId' => $aggr_wizard_id ) )
		: '';
	$aggr_autosave_context = function_exists( 'wp_interactivity_data_wp_context' )
		? wp_interactivity_data_wp_context( array( 'autosaveId' => $aggr_wizard_id ) )
		: '';
	require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-editor-modules.php';
	?>
	<section
		class="aggr-panel"
		aria-labelledby="aggr-details-heading"
		data-wp-interactive="<?php echo esc_attr( Assets::WIZARD_STORE ); ?>"
		<?php echo $aggr_wizard_context; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context(). ?>
		data-wp-init="actions.init"
	>
		<div class="aggr-panel__head">
			<p class="aggr-eyebrow">
				<?php
				echo esc_html(
					match ( $aggr_step ) {
						'details'  => __( 'Step 1 of 6', 'aggressive-ads' ),
						'package'  => __( 'Step 2 of 6', 'aggressive-ads' ),
						'creative' => __( 'Step 3 of 6', 'aggressive-ads' ),
						'destination' => __( 'Step 4 of 6', 'aggressive-ads' ),
						'review'      => __( 'Step 5 of 6', 'aggressive-ads' ),
						default       => __( 'Step 6 of 6', 'aggressive-ads' ),
					}
				);
				?>
			</p>
			<h2 id="aggr-details-heading" tabindex="-1">
				<?php
				echo esc_html(
					match ( $aggr_step ) {
						'details'  => __( 'Campaign details', 'aggressive-ads' ),
						'package'  => __( 'Choose a package', 'aggressive-ads' ),
						'creative' => __( 'Upload creative', 'aggressive-ads' ),
						'destination' => __( 'Confirm destinations and schedule', 'aggressive-ads' ),
						'review'      => __( 'Review your campaign', 'aggressive-ads' ),
						default       => __( 'Submit your campaign', 'aggressive-ads' ),
					}
				);
				?>
			</h2>
		</div>

		<ol class="aggr-steps" aria-label="<?php esc_attr_e( 'Campaign creation progress', 'aggressive-ads' ); ?>">
			<li <?php echo 'details' === $aggr_step ? 'aria-current="step"' : ''; ?>>
				<a data-aggr-step="details" data-wp-on--click="actions.guardVisit" href="<?php echo esc_url( add_query_arg( 'step', 'details', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Details', 'aggressive-ads' ); ?></a>
			</li>
			<li <?php echo 'package' === $aggr_step ? 'aria-current="step"' : ''; ?>>
				<a data-aggr-step="package" data-wp-on--click="actions.guardVisit" href="<?php echo esc_url( add_query_arg( 'step', 'package', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Package', 'aggressive-ads' ); ?></a>
			</li>
			<li <?php echo 'creative' === $aggr_step ? 'aria-current="step"' : ''; ?>>
				<a data-aggr-step="creative" data-wp-on--click="actions.guardVisit" href="<?php echo esc_url( add_query_arg( 'step', 'creative', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Creative', 'aggressive-ads' ); ?></a>
			</li>
			<li <?php echo 'destination' === $aggr_step ? 'aria-current="step"' : ''; ?>>
				<a data-aggr-step="destination" data-wp-on--click="actions.guardVisit" href="<?php echo esc_url( add_query_arg( 'step', 'destination', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Destination and schedule', 'aggressive-ads' ); ?></a>
			</li>
			<li <?php echo 'review' === $aggr_step ? 'aria-current="step"' : ''; ?>>
				<a data-aggr-step="review" data-wp-on--click="actions.guardVisit" href="<?php echo esc_url( add_query_arg( 'step', 'review', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Review', 'aggressive-ads' ); ?></a>
			</li>
			<li <?php echo 'submit' === $aggr_step ? 'aria-current="step"' : ''; ?>>
				<?php if ( $aggr_review_ready && 'submit' !== $aggr_step ) : ?>
					<a data-aggr-step="submit" data-wp-on--click="actions.guardVisit" href="<?php echo esc_url( add_query_arg( 'step', 'submit', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Submit', 'aggressive-ads' ); ?></a>
				<?php else : ?>
					<?php esc_html_e( 'Submit', 'aggressive-ads' ); ?>
				<?php endif; ?>
			</li>
		</ol>
		<p id="aggr-wizard-status-<?php echo esc_attr( $aggr_wizard_id ); ?>" class="aggr-sr" role="status" aria-live="polite"></p>
		<p id="aggr-autosave-status-<?php echo esc_attr( $aggr_wizard_id ); ?>" class="aggr-sr" role="status" aria-live="polite"></p>

		<?php if ( 'details' === $aggr_step ) : ?>
		<form
			class="aggr-form"
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
			<?php wp_nonce_field( Campaign_Actions::save_nonce_action( (int) $aggr_campaign['id'] ) ); ?>

			<div class="aggr-field">
				<label for="aggr-title"><?php esc_html_e( 'Campaign name', 'aggressive-ads' ); ?></label>
				<p id="aggr-title-hint" class="aggr-hint"><?php esc_html_e( 'Use a name your team will recognize. This is not shown with the ad.', 'aggressive-ads' ); ?></p>
				<input
					id="aggr-title"
					name="title"
					type="text"
					value="<?php echo esc_attr( (string) $aggr_campaign['title'] ); ?>"
					maxlength="160"
					required
					aria-describedby="aggr-title-hint"
					<?php echo 'aggr-title' === $aggr_error_for ? 'aria-invalid="true"' : ''; ?>
				>
			</div>

			<fieldset id="aggr-placements" class="aggr-fieldset" <?php echo 'aggr-placements' === $aggr_error_for ? 'aria-invalid="true"' : ''; ?>>
				<legend><?php esc_html_e( 'Placement interests', 'aggressive-ads' ); ?></legend>
				<p class="aggr-hint"><?php esc_html_e( 'Optional. Note where you would like the campaign to appear. The package selected in the next step sets the final placement list.', 'aggressive-ads' ); ?></p>

				<?php if ( array() === $aggr_options ) : ?>
					<p><?php esc_html_e( 'No placements are available right now. You can save the draft and return later.', 'aggressive-ads' ); ?></p>
				<?php else : ?>
					<div class="aggr-choicegrid">
						<?php foreach ( $aggr_options as $aggr_option ) : ?>
							<?php
							$aggr_box   = explode( 'x', strtolower( (string) $aggr_option['size'] ) );
							$aggr_box_w = isset( $aggr_box[0] ) ? max( 1, (int) $aggr_box[0] ) : 1;
							$aggr_box_h = isset( $aggr_box[1] ) ? max( 1, (int) $aggr_box[1] ) : 1;
							?>
							<label class="aggr-choice">
								<input
									type="checkbox"
									name="placement_ids[]"
									value="<?php echo esc_attr( (string) $aggr_option['id'] ); ?>"
									<?php checked( in_array( (int) $aggr_option['id'], $aggr_place_ids, true ) ); ?>
								>
								<span class="aggr-sizebox" style="--aggr-box-w: <?php echo esc_attr( (string) $aggr_box_w ); ?>; --aggr-box-h: <?php echo esc_attr( (string) $aggr_box_h ); ?>" aria-hidden="true"></span>
								<span>
									<strong><?php echo esc_html( (string) $aggr_option['name'] ); ?></strong>
									<small><?php echo esc_html( (string) $aggr_option['size'] ); ?> px</small>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</fieldset>

			<div class="aggr-field">
				<label for="aggr-advertiser-notes"><?php esc_html_e( 'Notes for the review team', 'aggressive-ads' ); ?></label>
				<p id="aggr-notes-hint" class="aggr-hint"><?php esc_html_e( 'Optional. Include context that will help the team review this campaign.', 'aggressive-ads' ); ?></p>
				<textarea id="aggr-advertiser-notes" name="advertiser_notes" rows="4" aria-describedby="aggr-notes-hint"><?php echo esc_textarea( (string) $aggr_campaign['advertiser_notes'] ); ?></textarea>
			</div>

			<div class="aggr-form__actions">
				<button class="aggr-button" type="submit"><?php esc_html_e( 'Save and continue', 'aggressive-ads' ); ?></button>
			</div>
		</form>
		<?php elseif ( 'package' === $aggr_step ) : ?>
			<form
				class="aggr-form"
				method="post"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				data-wp-interactive="<?php echo esc_attr( Assets::AUTOSAVE_STORE ); ?>"
				<?php echo $aggr_autosave_context; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context(). ?>
				data-aggr-autosave="<?php echo esc_attr( $aggr_wizard_id ); ?>"
				data-wp-init="actions.init"
			>
				<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::SAVE_PACKAGE_ACTION ); ?>">
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign['id'] ); ?>">
				<input type="hidden" name="autosave_rev" value="<?php echo esc_attr( (string) $aggr_campaign['autosave_rev'] ); ?>">
				<?php wp_nonce_field( Campaign_Actions::package_nonce_action( (int) $aggr_campaign['id'] ) ); ?>

				<fieldset id="aggr-packages" class="aggr-fieldset" <?php echo 'aggr-packages' === $aggr_error_for ? 'aria-invalid="true"' : ''; ?>>
					<legend><?php esc_html_e( 'Available packages', 'aggressive-ads' ); ?></legend>
					<p class="aggr-hint"><?php esc_html_e( 'Selecting a package copies its current price and placements into this campaign so later catalogue changes cannot alter your draft.', 'aggressive-ads' ); ?></p>

					<?php if ( array() === $aggr_packages ) : ?>
						<div class="aggr-empty">
							<p class="aggr-empty__title"><?php esc_html_e( 'No packages are available', 'aggressive-ads' ); ?></p>
							<p><?php esc_html_e( 'The catalogue is not configured yet. Your draft is safe; please return later or get in touch.', 'aggressive-ads' ); ?></p>
						</div>
					<?php else : ?>
						<div class="aggr-choicegrid">
							<?php foreach ( $aggr_packages as $aggr_package ) : ?>
								<?php $aggr_selected_package_id = $aggr_package_id > 0 ? $aggr_package_id : ( (bool) $aggr_package['is_default'] ? (int) $aggr_package['id'] : 0 ); ?>
								<label class="aggr-choice aggr-choice--package">
									<input
										type="radio"
										name="package_id"
										value="<?php echo esc_attr( (string) $aggr_package['id'] ); ?>"
										required
										<?php checked( (int) $aggr_package['id'], $aggr_selected_package_id ); ?>
									>
									<span>
										<strong><?php echo esc_html( (string) $aggr_package['name'] ); ?></strong>
										<?php if ( (bool) $aggr_package['is_default'] ) : ?>
											<small><?php esc_html_e( 'Recommended', 'aggressive-ads' ); ?></small>
										<?php endif; ?>
										<small><?php echo esc_html( (string) $aggr_package['price'] . ' · ' . (string) $aggr_package['duration'] ); ?></small>
										<small><?php echo esc_html( implode( ', ', $aggr_package['placements'] ) ); ?></small>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</fieldset>

				<div class="aggr-form__actions">
					<a class="aggr-button aggr-button--secondary" href="<?php echo esc_url( add_query_arg( 'step', 'details', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Back to details', 'aggressive-ads' ); ?></a>
					<?php if ( array() !== $aggr_packages ) : ?>
						<button class="aggr-button" type="submit"><?php esc_html_e( 'Save package', 'aggressive-ads' ); ?></button>
					<?php endif; ?>
				</div>
			</form>
		<?php elseif ( 'creative' === $aggr_step ) : ?>
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
						<p class="aggr-hint"><?php esc_html_e( 'Upload one creative for every active package placement to continue.', 'aggressive-ads' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-overlays.php'; ?>
		<?php elseif ( 'destination' === $aggr_step ) : ?>
			<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::SAVE_SCHEDULE_ACTION ); ?>">
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign['id'] ); ?>">
				<input type="hidden" name="autosave_rev" value="<?php echo esc_attr( (string) $aggr_campaign['autosave_rev'] ); ?>">
				<?php wp_nonce_field( Campaign_Actions::schedule_nonce_action( (int) $aggr_campaign['id'] ) ); ?>

				<section id="aggr-destinations" class="aggr-confirmation" aria-labelledby="aggr-destinations-heading">
					<h3 id="aggr-destinations-heading"><?php esc_html_e( 'Creative destinations', 'aggressive-ads' ); ?></h3>
					<p class="aggr-hint"><?php esc_html_e( 'Confirm where each advertisement sends visitors. Return to the creative step if an address needs to change.', 'aggressive-ads' ); ?></p>

					<?php if ( ! $aggr_creative_ready ) : ?>
						<div class="aggr-alert aggr-alert--error" role="alert">
							<p><?php esc_html_e( 'Every active package placement needs exactly one creative before this schedule can be completed.', 'aggressive-ads' ); ?></p>
						</div>
					<?php endif; ?>

					<div class="aggr-destination-list">
						<?php foreach ( $aggr_creatives as $aggr_creative ) : ?>
							<article class="aggr-destination-card">
								<h4><?php echo esc_html( (string) $aggr_creative['placement'] ); ?></h4>
								<p class="aggr-table__url"><?php echo esc_html( (string) $aggr_creative['click_url'] ); ?></p>
							</article>
						<?php endforeach; ?>
					</div>
				</section>

				<div class="aggr-formgrid">
					<div class="aggr-field">
						<label for="aggr-start-date"><?php esc_html_e( 'Start date', 'aggressive-ads' ); ?></label>
						<p id="aggr-start-hint" class="aggr-hint"><?php esc_html_e( 'Required. The campaign begins at the start of this day in the site timezone.', 'aggressive-ads' ); ?></p>
						<input
							id="aggr-start-date"
							name="start_date"
							type="date"
							value="<?php echo esc_attr( (string) $aggr_campaign['start_date'] ); ?>"
							min="<?php echo esc_attr( $aggr_min_start_date ); ?>"
							required
							aria-describedby="aggr-start-hint<?php echo 'aggr-start-date' === $aggr_error_for ? ' aggr-campaign-error' : ''; ?>"
							<?php echo 'aggr-start-date' === $aggr_error_for ? 'aria-invalid="true"' : ''; ?>
						>
					</div>

					<div class="aggr-field">
						<label for="aggr-end-date"><?php esc_html_e( 'End date', 'aggressive-ads' ); ?></label>
						<p id="aggr-end-hint" class="aggr-hint"><?php esc_html_e( 'Optional. The campaign runs through the end of this day.', 'aggressive-ads' ); ?></p>
						<input
							id="aggr-end-date"
							name="end_date"
							type="date"
							value="<?php echo esc_attr( (string) $aggr_campaign['end_date'] ); ?>"
							aria-describedby="aggr-end-hint<?php echo 'aggr-end-date' === $aggr_error_for ? ' aggr-campaign-error' : ''; ?>"
							<?php echo 'aggr-end-date' === $aggr_error_for ? 'aria-invalid="true"' : ''; ?>
						>
					</div>
				</div>

				<div class="aggr-form__actions">
					<a class="aggr-button aggr-button--secondary" href="<?php echo esc_url( add_query_arg( 'step', 'creative', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Back to creative', 'aggressive-ads' ); ?></a>
					<?php if ( $aggr_creative_ready ) : ?>
						<button class="aggr-button" type="submit"><?php esc_html_e( 'Save and continue to review', 'aggressive-ads' ); ?></button>
					<?php endif; ?>
				</div>
			</form>
		<?php elseif ( 'review' === $aggr_step ) : ?>
			<div class="aggr-form aggr-review">
				<?php if ( $aggr_review_ready ) : ?>
					<section class="aggr-readiness aggr-readiness--ready" aria-labelledby="aggr-readiness-heading" role="status">
						<h3 id="aggr-readiness-heading"><?php esc_html_e( 'Ready for submission', 'aggressive-ads' ); ?></h3>
						<p><?php esc_html_e( 'The campaign currently passes every submission check. Review the information below before continuing.', 'aggressive-ads' ); ?></p>
					</section>
				<?php else : ?>
					<section class="aggr-readiness aggr-readiness--issues" aria-labelledby="aggr-readiness-heading" role="alert">
						<h3 id="aggr-readiness-heading"><?php esc_html_e( 'Changes are still needed', 'aggressive-ads' ); ?></h3>
						<p><?php esc_html_e( 'Resolve every item below before submitting this campaign.', 'aggressive-ads' ); ?></p>
						<ol class="aggr-readiness__list">
							<?php foreach ( $aggr_review_problems as $aggr_problem ) : ?>
								<li>
									<span><?php echo esc_html( (string) $aggr_problem['message'] ); ?></span>
									<a href="<?php echo esc_url( add_query_arg( 'step', (string) $aggr_problem['step'], $aggr_campaign_url ) . '#' . (string) $aggr_problem['target'] ); ?>"><?php esc_html_e( 'Edit', 'aggressive-ads' ); ?></a>
								</li>
							<?php endforeach; ?>
						</ol>
					</section>
				<?php endif; ?>

				<div class="aggr-review-grid">
					<section class="aggr-review-card" aria-labelledby="aggr-review-details-heading">
						<div class="aggr-review-card__head">
							<h3 id="aggr-review-details-heading"><?php esc_html_e( 'Campaign details', 'aggressive-ads' ); ?></h3>
							<a href="<?php echo esc_url( add_query_arg( 'step', 'details', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Edit', 'aggressive-ads' ); ?></a>
						</div>
						<dl class="aggr-review-list">
							<div><dt><?php esc_html_e( 'Name', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( (string) $aggr_campaign['title'] ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Notes', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( '' === (string) $aggr_campaign['advertiser_notes'] ? __( 'None', 'aggressive-ads' ) : (string) $aggr_campaign['advertiser_notes'] ); ?></dd></div>
						</dl>
					</section>

					<section class="aggr-review-card" aria-labelledby="aggr-review-package-heading">
						<div class="aggr-review-card__head">
							<h3 id="aggr-review-package-heading"><?php esc_html_e( 'Package', 'aggressive-ads' ); ?></h3>
							<a href="<?php echo esc_url( add_query_arg( 'step', 'package', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Edit', 'aggressive-ads' ); ?></a>
						</div>
						<dl class="aggr-review-list">
							<div><dt><?php esc_html_e( 'Package', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( '' === (string) $aggr_campaign['package_name'] ? __( 'Not selected', 'aggressive-ads' ) : (string) $aggr_campaign['package_name'] ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Price', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( '' === (string) $aggr_campaign['package_price'] ? '—' : (string) $aggr_campaign['package_price'] ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Placements', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( array() === $aggr_places ? __( 'None selected', 'aggressive-ads' ) : implode( ', ', $aggr_places ) ); ?></dd></div>
						</dl>
					</section>

					<section class="aggr-review-card" aria-labelledby="aggr-review-schedule-heading">
						<div class="aggr-review-card__head">
							<h3 id="aggr-review-schedule-heading"><?php esc_html_e( 'Schedule', 'aggressive-ads' ); ?></h3>
							<a href="<?php echo esc_url( add_query_arg( 'step', 'destination', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Edit', 'aggressive-ads' ); ?></a>
						</div>
						<p><?php echo esc_html( (string) $aggr_campaign['dates'] ); ?></p>
					</section>
				</div>

				<section class="aggr-review-card aggr-review-card--wide" aria-labelledby="aggr-review-creative-heading">
					<div class="aggr-review-card__head">
						<h3 id="aggr-review-creative-heading"><?php esc_html_e( 'Creative', 'aggressive-ads' ); ?></h3>
						<a href="<?php echo esc_url( add_query_arg( 'step', 'creative', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Edit', 'aggressive-ads' ); ?></a>
					</div>
					<div class="aggr-review-creatives">
						<?php foreach ( $aggr_creatives as $aggr_creative ) : ?>
							<article class="aggr-review-creative">
								<div class="aggr-review-creative__preview"><img src="<?php echo esc_url( (string) $aggr_creative['preview'] ); ?>" alt="<?php echo esc_attr( (string) $aggr_creative['alt_text'] ); ?>" loading="lazy"></div>
								<div>
									<h4><?php echo esc_html( (string) $aggr_creative['placement'] ); ?></h4>
									<p><?php echo esc_html( (string) $aggr_creative['dimensions'] ); ?></p>
									<p class="aggr-table__url"><?php echo esc_html( (string) $aggr_creative['click_url'] ); ?></p>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
				</section>

				<div class="aggr-form__actions">
					<a class="aggr-button aggr-button--secondary" href="<?php echo esc_url( add_query_arg( 'step', 'destination', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Back to schedule', 'aggressive-ads' ); ?></a>
					<?php if ( $aggr_review_ready ) : ?>
						<a class="aggr-button" href="<?php echo esc_url( add_query_arg( 'step', 'submit', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Continue to submit', 'aggressive-ads' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		<?php else : ?>
			<div class="aggr-form aggr-submit">
				<?php if ( $aggr_review_ready ) : ?>
					<section class="aggr-submit-card" aria-labelledby="aggr-submit-heading">
						<h3 id="aggr-submit-heading"><?php esc_html_e( 'Send this campaign to the review team?', 'aggressive-ads' ); ?></h3>
						<p><?php esc_html_e( 'Submission locks campaign editing while the review team checks the creative, destinations, schedule, and placement availability.', 'aggressive-ads' ); ?></p>
						<ul>
							<li><?php esc_html_e( 'You can withdraw the campaign only until a reviewer claims it.', 'aggressive-ads' ); ?></li>
							<li><?php esc_html_e( 'If changes are requested, the campaign becomes editable again.', 'aggressive-ads' ); ?></li>
							<li><?php esc_html_e( 'The submission checks run again when you press the button below.', 'aggressive-ads' ); ?></li>
						</ul>
						<dl class="aggr-review-list">
							<div><dt><?php esc_html_e( 'Campaign', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( (string) $aggr_campaign['title'] ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Package', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( (string) $aggr_campaign['package_name'] ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Schedule', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( (string) $aggr_campaign['dates'] ); ?></dd></div>
						</dl>
					</section>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::SUBMIT_ACTION ); ?>">
						<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign['id'] ); ?>">
						<?php wp_nonce_field( Campaign_Actions::submit_nonce_action( (int) $aggr_campaign['id'] ) ); ?>
						<div class="aggr-form__actions">
							<a class="aggr-button aggr-button--secondary" href="<?php echo esc_url( add_query_arg( 'step', 'review', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Back to review', 'aggressive-ads' ); ?></a>
							<button class="aggr-button" type="submit"><?php esc_html_e( 'Submit campaign for review', 'aggressive-ads' ); ?></button>
						</div>
					</form>
				<?php else : ?>
					<section class="aggr-readiness aggr-readiness--issues" aria-labelledby="aggr-readiness-heading" role="alert">
						<h3 id="aggr-readiness-heading"><?php esc_html_e( 'Campaign is no longer ready', 'aggressive-ads' ); ?></h3>
						<p><?php esc_html_e( 'Resolve every item below before returning to submission.', 'aggressive-ads' ); ?></p>
						<ol class="aggr-readiness__list">
							<?php foreach ( $aggr_review_problems as $aggr_problem ) : ?>
								<li>
									<span><?php echo esc_html( (string) $aggr_problem['message'] ); ?></span>
									<a href="<?php echo esc_url( add_query_arg( 'step', (string) $aggr_problem['step'], $aggr_campaign_url ) . '#' . (string) $aggr_problem['target'] ); ?>"><?php esc_html_e( 'Edit', 'aggressive-ads' ); ?></a>
								</li>
							<?php endforeach; ?>
						</ol>
					</section>
					<div class="aggr-form__actions">
						<a class="aggr-button aggr-button--secondary" href="<?php echo esc_url( add_query_arg( 'step', 'review', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Back to review', 'aggressive-ads' ); ?></a>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</section>
<?php endif; ?>

<?php if ( true !== $aggr_campaign['editable'] || ! in_array( $aggr_step, array( 'review', 'submit' ), true ) ) : ?>
<section class="aggr-panel" aria-labelledby="aggr-summary-heading">
	<h2 id="aggr-summary-heading" class="aggr-panel__head">
		<?php esc_html_e( 'Summary', 'aggressive-ads' ); ?>
	</h2>

	<dl class="aggr-facts">
		<div class="aggr-fact">
			<dt><?php esc_html_e( 'Placements', 'aggressive-ads' ); ?></dt>
			<dd>
				<?php
				echo esc_html(
					array() === $aggr_places
						? __( 'None selected', 'aggressive-ads' )
						: implode( ', ', $aggr_places )
				);
				?>
			</dd>
		</div>

		<div class="aggr-fact">
			<dt><?php esc_html_e( 'Creatives', 'aggressive-ads' ); ?></dt>
			<dd><?php echo esc_html( number_format_i18n( count( $aggr_creatives ) ) ); ?></dd>
		</div>

		<div class="aggr-fact">
			<dt><?php esc_html_e( 'Revision', 'aggressive-ads' ); ?></dt>
			<dd><?php echo esc_html( number_format_i18n( (int) $aggr_campaign['revision'] ) ); ?></dd>
		</div>

		<?php if ( isset( $aggr_campaign['impressions'], $aggr_campaign['clicks'] ) ) : ?>
		<div class="aggr-fact">
			<dt><?php esc_html_e( 'Impressions', 'aggressive-ads' ); ?></dt>
			<dd><?php echo esc_html( number_format_i18n( (int) $aggr_campaign['impressions'] ) ); ?></dd>
		</div>
		<div class="aggr-fact">
			<dt><?php esc_html_e( 'Clicks', 'aggressive-ads' ); ?></dt>
			<dd><?php echo esc_html( number_format_i18n( (int) $aggr_campaign['clicks'] ) ); ?></dd>
		</div>
		<div class="aggr-fact">
			<dt><?php esc_html_e( 'CTR', 'aggressive-ads' ); ?></dt>
			<dd>
				<?php
				$aggr_ctr = $aggr_campaign['ctr'] ?? null;
				echo esc_html(
					is_float( $aggr_ctr )
						? sprintf(
							/* translators: %s: click-through rate as a percentage, e.g. 1.2. */
							__( '%s%%', 'aggressive-ads' ),
							number_format_i18n( $aggr_ctr * 100, 1 )
						)
						: __( '—', 'aggressive-ads' )
				);
				?>
			</dd>
		</div>
		<?php endif; ?>
	</dl>
</section>

	<?php
	if ( true === ( $aggr_campaign['edits_submitted'] ?? false ) ) {
		require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-changes-pending.php';
	} elseif ( $aggr_editing_changes ) {
		require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-changes.php';
	}
	?>

	<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-request.php'; ?>

	<?php if ( true !== $aggr_campaign['can_request_updates'] ) : ?>
<section class="aggr-panel" aria-labelledby="aggr-creatives-heading">
	<h2 id="aggr-creatives-heading" class="aggr-panel__head">
		<?php esc_html_e( 'Creatives', 'aggressive-ads' ); ?>
	</h2>

		<?php if ( array() === $aggr_creatives ) : ?>
		<div class="aggr-empty">
			<p class="aggr-empty__title"><?php esc_html_e( 'No creatives yet', 'aggressive-ads' ); ?></p>
			<p><?php esc_html_e( 'A campaign needs at least one creative before it can be submitted.', 'aggressive-ads' ); ?></p>
		</div>
		<?php else : ?>
			<div class="aggr-tablewrap" role="region" aria-label="<?php esc_attr_e( 'Creatives table', 'aggressive-ads' ); ?>" tabindex="0">
			<table class="aggr-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Placement', 'aggressive-ads' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Size', 'aggressive-ads' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Destination', 'aggressive-ads' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $aggr_creatives as $aggr_creative ) : ?>
						<tr>
							<td class="aggr-table__primary"><?php echo esc_html( (string) $aggr_creative['placement'] ); ?></td>
							<td>
								<?php
								echo esc_html(
									'' !== $aggr_creative['dimensions']
										? (string) $aggr_creative['dimensions']
										: (string) $aggr_creative['size']
								);
								?>
							</td>
							<td class="aggr-table__url">
								<?php echo esc_html( (string) $aggr_creative['click_url'] ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
</section>
	<?php endif; ?>

	<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-ad-updates.php'; ?>

		<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-update-history.php'; ?>
	<?php endif; ?>
