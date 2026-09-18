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
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Workflow\Campaign_Editor;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Nonces;
use Aggressive\Ads\Portal\Creative_Feedback;
use Aggressive\Ads\Portal\Portal_Notice;
use Aggressive\Ads\Portal\View_Data;

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
$aggr_step               = in_array( $aggr_step, Campaign_Editor::DISPLAY_STEPS, true ) ? $aggr_step : 'review';
$aggr_campaign_url       = Routes::url( Request::ROUTE_CAMPAIGNS, (int) $aggr_campaign['id'] );
$aggr_creative_notice    = Creative_Feedback::request_notice();
$aggr_creative_error     = Creative_Feedback::request_error_code();
$aggr_error_placement    = Creative_Feedback::request_error_placement();
$aggr_creative_error_for = Creative_Feedback::error_target( $aggr_creative_error, $aggr_error_placement );

/*
 * A refused destination edit names its own creative's field. The upload
 * form's targets are keyed by placement, and a placement can hold ten
 * creatives, so reusing that key would open the wrong fold.
 */
$aggr_error_creative = Creative_Feedback::request_error_creative();

if ( $aggr_error_creative > 0 && in_array( $aggr_creative_error, array( 'aggr_click_url_required', 'aggr_click_url_invalid', 'aggr_destination_frozen', 'aggr_destination_forbidden' ), true ) ) {
	$aggr_creative_error_for = 'aggr-destination-' . $aggr_error_creative;
}

/*
 * The size limit belongs to the placement the upload was for, and a
 * redirect carries only an error code and a placement id. Reading it off
 * the slots already on this screen keeps the banner quoting the same
 * number the form beside it does.
 */
$aggr_error_max_bytes = 0;

foreach ( $aggr_slots as $aggr_error_slot ) {
	if ( is_array( $aggr_error_slot ) && (int) ( $aggr_error_slot['id'] ?? 0 ) === $aggr_error_placement ) {
		$aggr_error_max_bytes = (int) ( $aggr_error_slot['max_bytes'] ?? 0 );
		break;
	}
}
// Decided by Portal\View_Data, which is where it can be tested.
$aggr_min_start_date = (string) ( $aggr_campaign['min_start_date'] ?? '' );
$aggr_creative_ready = array() !== $aggr_slots;
$aggr_overlays       = array();
$aggr_line_items     = is_array( $aggr_campaign['line_items'] ?? null ) ? $aggr_campaign['line_items'] : array();

/*
 * The wizard is on screen, and the panels below it are describing steps the
 * advertiser has not reached yet.
 *
 * `editable` alone is the wrong test: Edit_Window::allows() is true for staff
 * in every status, so keying on it would blank these panels for a reviewer
 * opening a submitted campaign. Staff working a client's campaign keep them.
 */
$aggr_wizard_on_screen = true === $aggr_campaign['editable'] && empty( $aggr_campaign['on_behalf'] );

/*
 * **At least one creative per active placement, not exactly one.**
 *
 * `exactly one` contradicted the two rules that actually decide anything.
 * `Creative_Manager` permits ten creatives on a placement, and
 * `Campaign_Validator` accepts a placement covered by any number of them —
 * `Coverage_Service` counts a placement as covered once, however many cover it,
 * and a creative still awaiting review covers for submission.
 *
 * So an advertiser could upload a second creative, was permitted to by the
 * manager, would have been accepted by the validator, and was then stopped here
 * by a wizard step telling them a placement needs exactly one. A dead end made
 * by the only rule in the system that held that opinion — and the reason
 * weighted variants, which delivery has supported all along, were unreachable
 * without calling the REST route by hand.
 */
foreach ( $aggr_slots as $aggr_slot ) {
	if ( ! $aggr_slot['active'] || array() === $aggr_slot['creatives'] ) {
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

$aggr_wizard_id        = 'campaign-' . (int) $aggr_campaign['id'];
$aggr_autosave_context = function_exists( 'wp_interactivity_data_wp_context' )
	? wp_interactivity_data_wp_context( array( 'autosaveId' => $aggr_wizard_id ) )
	: '';

/*
 * Named for what the advertiser does on each, not for the data model:
 * "Destination and schedule" described two fields that no longer share a
 * step, and "Creative" is the plugin's word for what they call an ad.
 */
$aggr_steps = array(
	'details'  => __( 'Package & dates', 'aggressive-ads' ),
	'creative' => __( 'Ads', 'aggressive-ads' ),
	'review'   => __( 'Review & submit', 'aggressive-ads' ),
);

/*
 * Facts every step and the status view restate: how many days the run is, and
 * the sizes the package asks for, grouped so two 728×90 placements read as one
 * size twice rather than as a repeated label.
 */
$aggr_run_days  = 0;
$aggr_day_zone  = wp_timezone();
$aggr_first_day = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $aggr_campaign['start_date'], $aggr_day_zone );
$aggr_last_day  = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $aggr_campaign['end_date'], $aggr_day_zone );

if ( false !== $aggr_first_day && false !== $aggr_last_day && $aggr_last_day >= $aggr_first_day ) {
	$aggr_run_days = (int) $aggr_first_day->diff( $aggr_last_day )->days + 1;
}

$aggr_size_counts = array();

foreach ( $aggr_slots as $aggr_sized_slot ) {
	$aggr_size_label                      = str_replace( 'x', '×', (string) $aggr_sized_slot['size'] );
	$aggr_size_counts[ $aggr_size_label ] = ( $aggr_size_counts[ $aggr_size_label ] ?? 0 ) + 1;
}

$aggr_size_labels = array();

foreach ( $aggr_size_counts as $aggr_size_label => $aggr_size_count ) {
	$aggr_size_labels[] = $aggr_size_count > 1 ? $aggr_size_label . ' ×' . $aggr_size_count : $aggr_size_label;
}

$aggr_package_duration = '';

foreach ( $aggr_packages as $aggr_known_package ) {
	if ( (int) $aggr_known_package['id'] === $aggr_package_id ) {
		$aggr_package_duration = (string) $aggr_known_package['duration'];
	}
}

$aggr_step_number = (int) array_search( $aggr_step, array_keys( $aggr_steps ), true ) + 1;
?>
<div class="aggr-pagehead aggr-pagehead--campaign">
	<div class="aggr-pagehead__main">
		<nav class="aggr-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'aggressive-ads' ); ?>">
			<a href="<?php echo esc_url( Routes::url( Request::ROUTE_CAMPAIGNS ) ); ?>">
				<?php esc_html_e( 'Campaigns', 'aggressive-ads' ); ?>
			</a>
			<span aria-hidden="true">/</span>
			<span aria-current="page">
				<?php
				echo esc_html(
					true === $aggr_campaign['editable'] && Post_Statuses::DRAFT === (string) $aggr_campaign['status']
						? __( 'New campaign', 'aggressive-ads' )
						: (string) $aggr_campaign['title']
				);
				?>
			</span>
		</nav>

		<div class="aggr-pagehead__heading">
			<?php
			/*
			 * **The heading is where a campaign is renamed.** The name used to
			 * be the first field of the wizard, which made inventing a label
			 * the price of starting. The wizard names the campaign from its
			 * package instead, and the name is changed where it is read.
			 *
			 * The markup is the plain heading either way. The autosave module
			 * turns its text into a button styled to be indistinguishable from
			 * it, so nothing moves or repaints; without script the review step
			 * carries an ordinary rename form.
			 */
			?>
			<?php if ( true === $aggr_campaign['editable'] ) : ?>
				<h1
					id="aggr-campaign-title"
					class="aggr-title"
					data-wp-interactive="<?php echo esc_attr( Assets::AUTOSAVE_STORE ); ?>"
					<?php echo $aggr_autosave_context; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context(). ?>
					data-wp-init="actions.initTitle"
				><?php echo esc_html( (string) $aggr_campaign['title'] ); ?></h1>
				<svg class="aggr-pagehead__rename" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></svg>
			<?php else : ?>
				<h1 class="aggr-title"><?php echo esc_html( (string) $aggr_campaign['title'] ); ?></h1>
			<?php endif; ?>

			<span class="aggr-pill aggr-pill--<?php echo esc_attr( (string) $aggr_campaign['pill'] ); ?>">
				<?php echo esc_html( (string) $aggr_campaign['status_text'] ); ?>
			</span>
		</div>

		<p class="aggr-lede aggr-pagehead__meta">
			<?php if ( true === $aggr_campaign['editable'] ) : ?>
				<?php // Every field on these steps autosaves; a failed save says so beside the field it was. ?>
				<span class="aggr-saved"><?php esc_html_e( 'All changes saved', 'aggressive-ads' ); ?></span>
			<?php elseif ( (int) $aggr_campaign['submitted_at'] > 0 ) : ?>
				<span class="aggr-saved">
					<?php
					printf(
						/* translators: %s: when the campaign was submitted, e.g. Sep 13 · 10:42. */
						esc_html__( 'Submitted %s', 'aggressive-ads' ),
						esc_html( (string) wp_date( /* translators: date and time of a campaign event, as a PHP date format, e.g. Sep 16 · 2:05 PM. */ __( 'M j · g:i A', 'aggressive-ads' ), (int) $aggr_campaign['submitted_at'] ) )
					);
					?>
				</span>
			<?php else : ?>
				<span><?php echo esc_html( (string) $aggr_campaign['dates'] ); ?></span>
			<?php endif; ?>
		</p>
	</div>

	<div class="aggr-pagehead__actions">
		<?php if ( true === $aggr_campaign['editable'] ) : ?>
			<?php
			/*
			 * Every step is a link. Submit used to be the one gated step, and it
			 * was a page of its own; submitting is now the last thing on review,
			 * and review only offers the button once the campaign is ready.
			 */
			?>
			<ol class="aggr-steps" aria-label="<?php esc_attr_e( 'Campaign creation progress', 'aggressive-ads' ); ?>">
				<?php foreach ( $aggr_steps as $aggr_step_key => $aggr_step_name ) : ?>
					<li <?php echo $aggr_step_key === $aggr_step ? 'aria-current="step"' : ''; ?>>
						<a data-aggr-step="<?php echo esc_attr( $aggr_step_key ); ?>" href="<?php echo esc_url( add_query_arg( 'step', $aggr_step_key, $aggr_campaign_url ) ); ?>"><span class="aggr-steps__label"><?php echo esc_html( $aggr_step_name ); ?></span></a>
					</li>
				<?php endforeach; ?>
			</ol>
			<p class="aggr-steps__count" aria-hidden="true">
				<?php
				printf(
					/* translators: 1: current step number. 2: number of steps. */
					esc_html__( 'Step %1$d of %2$d', 'aggressive-ads' ),
					(int) $aggr_step_number,
					(int) count( $aggr_steps )
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( true === $aggr_campaign['can_withdraw'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::WITHDRAW_ACTION ); ?>">
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) (int) $aggr_campaign['id'] ); ?>">
				<?php wp_nonce_field( Campaign_Nonces::withdraw_nonce_action( (int) $aggr_campaign['id'] ) ); ?>
				<button class="aggr-button aggr-button--secondary" type="submit">
					<?php esc_html_e( 'Withdraw to edit', 'aggressive-ads' ); ?>
				</button>
			</form>
		<?php endif; ?>

		<?php if ( $aggr_editing_changes && true !== ( $aggr_campaign['edits_submitted'] ?? false ) ) : ?>
			<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-changes-steps.php'; ?>
		<?php endif; ?>

		<?php if ( true === $aggr_campaign['can_request_changes'] && ! $aggr_editing_changes ) : ?>
			<a class="aggr-button" href="<?php echo esc_url( add_query_arg( 'edit', '1', $aggr_campaign_url ) ); ?>">
				<?php esc_html_e( 'Edit', 'aggressive-ads' ); ?>
			</a>
		<?php endif; ?>

		<?php
		/*
		 * Duplicate and delete in a menu, not beside the steps as two large
		 * buttons. Neither moves this campaign on, and a solid button beside
		 * the progress bar competes with the one action that does. Delete is
		 * last and still leads to its confirmation screen rather than acting.
		 *
		 * Native <details>, so it opens from the keyboard and without script.
		 */
		?>
		<?php if ( true === $aggr_campaign['can_copy'] || true === $aggr_campaign['can_cancel'] ) : ?>
			<details class="aggr-menu">
				<summary class="aggr-button aggr-button--secondary aggr-menu__toggle" aria-label="<?php esc_attr_e( 'More actions', 'aggressive-ads' ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><circle cx="5" cy="12" r="1.9"/><circle cx="12" cy="12" r="1.9"/><circle cx="19" cy="12" r="1.9"/></svg>
				</summary>
				<div class="aggr-menu__panel">
					<?php if ( true === $aggr_campaign['can_copy'] ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::COPY_ACTION ); ?>">
							<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) (int) $aggr_campaign['id'] ); ?>">
							<?php wp_nonce_field( Campaign_Nonces::copy_nonce_action( (int) $aggr_campaign['id'] ) ); ?>
							<button class="aggr-menu__item" type="submit">
								<?php echo esc_html( (string) $aggr_campaign['copy_label'] ); ?>
							</button>
						</form>
					<?php endif; ?>

					<?php if ( true === $aggr_campaign['can_cancel'] ) : ?>
						<form method="get" action="<?php echo esc_url( $aggr_campaign_url ); ?>">
							<input type="hidden" name="confirm" value="cancel">
							<button class="aggr-menu__item aggr-menu__item--danger" type="submit">
								<?php echo esc_html( (string) $aggr_campaign['cancel_label'] ); ?>
							</button>
						</form>
					<?php endif; ?>
				</div>
			</details>
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
				<?php wp_nonce_field( Campaign_Nonces::cancel_nonce_action( (int) $aggr_campaign['id'] ) ); ?>
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

<?php
/*
 * Notices are queued, not drawn. The layout owns the region they appear in,
 * so every screen reports the same way and severity means one thing across
 * the portal — before this each screen built its own banner and chose its own
 * colours.
 */
if ( in_array( $aggr_notice, array( 'created', 'copied', 'saved', 'reviewing', 'renamed', 'submitted', 'withdrawn', 'changes_requested', 'changes_cancelled', 'changes_saved' ), true ) ) {
	Portal_Notice::add(
		match ( $aggr_notice ) {
			'created'           => __( 'Campaign created. Choose a package and when it should run.', 'aggressive-ads' ),
			'copied'            => __( 'Campaign copied. Choose new dates to continue.', 'aggressive-ads' ),
			'reviewing'         => __( 'Your ads are in. Check everything below, then submit.', 'aggressive-ads' ),
			'renamed'           => __( 'Campaign renamed.', 'aggressive-ads' ),
			'submitted'         => __( 'Campaign submitted. It is now in the review queue.', 'aggressive-ads' ),
			'withdrawn'         => __( 'Campaign withdrawn from review and reopened for editing. Submit it again when you are ready.', 'aggressive-ads' ),
			'changes_requested' => __( 'Edits submitted for review. Your campaign keeps running as approved until the review team decides.', 'aggressive-ads' ),
			'changes_cancelled' => __( 'Edits discarded. Nothing about your running campaign changed.', 'aggressive-ads' ),
			'changes_saved'     => __( 'Saved. Nothing is sent to the review team until you submit these edits.', 'aggressive-ads' ),
			default             => __( 'Saved. Now add an ad for each size.', 'aggressive-ads' ),
		},
		'success'
	);
} elseif ( 'error' === $aggr_notice ) {
	Portal_Notice::add( Campaign_Actions::error_message( $aggr_error ), 'error', $aggr_error_for );
}

if ( in_array( $aggr_creative_notice, array( 'creative_uploaded', 'creative_removed', 'creative_update_requested', 'creative_update_withdrawn', 'creative_weight_saved', 'creative_paused', 'creative_resumed', 'creative_window_saved', 'creative_destination_saved' ), true ) ) {
	Portal_Notice::add(
		match ( $aggr_creative_notice ) {
			'creative_uploaded'          => __( 'Creative uploaded and stored privately.', 'aggressive-ads' ),
			'creative_removed'           => __( 'Creative removed.', 'aggressive-ads' ),
			'creative_update_requested'  => __( 'Your ad update is waiting for review. The current ad will keep running.', 'aggressive-ads' ),
			'creative_weight_saved'      => __( 'Share saved. The new split applies to the next advertisement served.', 'aggressive-ads' ),
			'creative_paused'            => __( 'Paused. The other creatives on that placement take its share.', 'aggressive-ads' ),
			'creative_resumed'           => __( 'Resumed. It starts serving again on the next request.', 'aggressive-ads' ),
			'creative_window_saved'      => __( 'Dates saved.', 'aggressive-ads' ),
			'creative_destination_saved' => __( 'Destination saved.', 'aggressive-ads' ),
			default                      => __( 'The pending ad update was withdrawn.', 'aggressive-ads' ),
		},
		'success'
	);
} elseif ( Creative_Feedback::ERROR_NOTICE === $aggr_creative_notice ) {
	Portal_Notice::add(
		Creative_Feedback::error_message( $aggr_creative_error, $aggr_error_max_bytes ),
		'error',
		$aggr_creative_error_for
	);
}
?>

<?php if ( '' !== $aggr_notes ) : ?>
	<section class="aggr-notice" aria-labelledby="aggr-notes-heading">
		<h2 id="aggr-notes-heading" class="aggr-notice__head">
			<?php esc_html_e( 'Notes from the review team', 'aggressive-ads' ); ?>
		</h2>
		<p><?php echo esc_html( $aggr_notes ); ?></p>
	</section>
<?php endif; ?>

<?php
/*
 * The other half of that conversation, read back.
 *
 * The note is written on the submit step, and submitting is what takes that
 * step away — so without this the advertiser can no longer see what they told
 * the review team, on the screen where the team's reply to it appears.
 */
?>
<?php if ( ! $aggr_wizard_on_screen && '' !== trim( (string) $aggr_campaign['advertiser_notes'] ) ) : ?>
	<section class="aggr-panel" aria-labelledby="aggr-sent-notes-heading">
		<h2 id="aggr-sent-notes-heading" class="aggr-panel__head">
			<?php esc_html_e( 'Your notes for the review team', 'aggressive-ads' ); ?>
		</h2>
		<p class="aggr-notes-body"><?php echo esc_html( (string) $aggr_campaign['advertiser_notes'] ); ?></p>
	</section>
<?php endif; ?>

<?php
/*
 * How the campaign is delivered — pricing and pacing — is part of the status
 * view's side card now, in words an advertiser uses. The panel that stood here
 * led every campaign page with "Line item", "FLAT" and "Even": ad-server terms
 * above the only question a running campaign's owner has, which is how it is
 * doing.
 */
?>

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
	$aggr_wizard_context = function_exists( 'wp_interactivity_data_wp_context' )
		? wp_interactivity_data_wp_context( array( 'wizardId' => $aggr_wizard_id ) )
		: '';
	require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-editor-modules.php';

	?>
	<section
		class="aggr-wizard-shell"
		aria-labelledby="aggr-details-heading"
		data-wp-interactive="<?php echo esc_attr( Assets::WIZARD_STORE ); ?>"
		<?php echo $aggr_wizard_context; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context(). ?>
		data-wp-init="actions.init"
	>
		<?php
		/*
		 * The step bar lives in the page head now, and the page title already
		 * says what this is. The heading stays for the reader who needs it:
		 * focus moves here on every step so a screen reader hears where it is.
		 */
		?>
		<div class="aggr-sr">
			<h2 id="aggr-details-heading" tabindex="-1">
				<?php
				echo esc_html(
					match ( $aggr_step ) {
						'details'  => __( 'Choose a package and dates', 'aggressive-ads' ),
						'creative' => __( 'Add your ads', 'aggressive-ads' ),
						default    => __( 'Review and submit', 'aggressive-ads' ),
					}
				);
				?>
			</h2>
		</div>
		<p id="aggr-wizard-status-<?php echo esc_attr( $aggr_wizard_id ); ?>" class="aggr-sr" role="status" aria-live="polite"></p>
		<p id="aggr-autosave-status-<?php echo esc_attr( $aggr_wizard_id ); ?>" class="aggr-sr" role="status" aria-live="polite"></p>

		<div class="aggr-wizard">
			<div class="aggr-wizard__layout">
				<div class="aggr-wizard__main">
					<?php
					if ( 'details' === $aggr_step ) {
						require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-plan-step.php';
					} elseif ( 'creative' === $aggr_step ) {
						require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-creative-step.php';
					} else {
						require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-review-step.php';
					}
					?>
				</div>
				<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-order-summary.php'; ?>
			</div>
		</div>
	</section>
<?php endif; ?>

<?php
/*
 * Everything below the wizard stands down while the wizard is running.
 *
 * Summary, Creatives, ad updates, variant comparison and update history all
 * describe a campaign that exists; on step 1 they describe one that does not.
 * Creatives is the clearest case — it renders "No creatives yet" as a warning
 * about step 3 while step 1 is asking for a name — but Summary is the same
 * thing said twice, echoing back the fields the form above is still
 * collecting.
 *
 * The step test stays for staff working on a client's behalf: they keep these
 * panels, except on review, which already summarises the campaign itself.
 */
?>
<?php
/*
 * The campaign's own delivery, above where it has got to: once a campaign is
 * running, how it is doing is the first question. Empty until it could have
 * delivered, and when Reporting is off.
 */
if ( ! $aggr_editing_changes && array() !== ( $aggr_campaign['delivery'] ?? array() ) ) {
	$aggr_delivery_view   = Plugin::instance()->container()->get( View_Data::class );
	$aggr_delivery        = $aggr_campaign['delivery'];
	$aggr_series          = $aggr_campaign['delivery_series'];
	$aggr_range           = $aggr_delivery_view->delivery_range_label();
	$aggr_freshness       = $aggr_delivery_view->delivery_freshness_note();
	$aggr_counting        = $aggr_delivery_view->delivery_counting_from();
	$aggr_window          = $aggr_delivery_view->delivery_window();
	$aggr_delivery_base   = $aggr_campaign_url;
	$aggr_delivery_title  = __( 'Delivery', 'aggressive-ads' );
	$aggr_export_campaign = (int) $aggr_campaign['id'];

	require AGGR_PLUGIN_DIR . 'templates/portal/partials/delivery-card.php';
}

if ( true !== $aggr_campaign['editable'] && ! $aggr_editing_changes ) {
	require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-status.php';
}
?>

<?php
/*
 * Editing a running campaign is a flow of its own, laid out as the creation
 * wizard is. Everything that describes the campaign as it stands — delivery,
 * its ads, the request form — would sit under that flow describing values the
 * advertiser is in the middle of changing, so none of it is drawn while they
 * edit; "Back to the campaign" is one click away.
 */
?>
<?php if ( $aggr_editing_changes && true !== ( $aggr_campaign['edits_submitted'] ?? false ) ) : ?>
	<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-changes.php'; ?>
<?php elseif ( ! $aggr_wizard_on_screen && ( true !== $aggr_campaign['editable'] || 'review' !== $aggr_step ) ) : ?>
	<?php
	/*
	 * The Summary panel that stood here repeated the delivery card's figures
	 * and the side card's placements a third time; what it alone said is in
	 * the side card now.
	 */
	?>
	<?php
	if ( true === ( $aggr_campaign['edits_submitted'] ?? false ) ) {
		require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-changes-pending.php';
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

	<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-variant-comparison.php'; ?>

		<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-update-history.php'; ?>
	<?php endif; ?>
