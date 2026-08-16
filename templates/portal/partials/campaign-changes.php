<?php
/**
 * Editing a campaign that is already running.
 *
 * Mirrors the creation wizard — numbered steps, one concern per step, a review
 * step that submits — because an advertiser who has made a campaign already
 * knows this shape. It is not the same wizard: package selection and creative
 * upload are not proposal fields, and every save here stages a change instead
 * of writing to the campaign.
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

use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;

$aggr_edit_fields = is_array( $aggr_campaign['live_edit_fields'] ?? null ) ? $aggr_campaign['live_edit_fields'] : array();
$aggr_draft       = is_array( $aggr_campaign['draft_edits'] ?? null ) ? $aggr_campaign['draft_edits'] : array();
$aggr_values      = is_array( $aggr_campaign['edit_values'] ?? null ) ? $aggr_campaign['edit_values'] : array();
$aggr_campaign_id = (int) $aggr_campaign['id'];
$aggr_edit_step   = Campaign_Actions::request_change_step();
$aggr_edit_url    = add_query_arg( 'edit', '1', Routes::url( Request::ROUTE_CAMPAIGNS, $aggr_campaign_id ) );

$aggr_has_details     = in_array( 'title', $aggr_edit_fields, true ) || in_array( 'advertiser_notes', $aggr_edit_fields, true ) || in_array( 'placement_ids', $aggr_edit_fields, true );
$aggr_has_schedule    = in_array( 'start_ts', $aggr_edit_fields, true );
$aggr_has_destination = in_array( 'click_urls', $aggr_edit_fields, true );

/**
 * Renders a step link, marking the current one.
 *
 * @param string $step    Step key.
 * @param string $label   Visible label.
 * @param string $current Current step.
 * @param string $base    Edit URL.
 * @return void
 */
$aggr_step_link = static function ( string $step, string $label, string $current, string $base ): void {
	printf(
		'<li%1$s><a href="%2$s">%3$s</a></li>',
		$step === $current ? ' aria-current="step"' : '',
		esc_url( add_query_arg( 'step', $step, $base ) ),
		esc_html( $label )
	);
};
?>
<section class="aggr-panel" aria-labelledby="aggr-edit-heading">
	<h2 id="aggr-edit-heading" class="aggr-panel__head">
		<?php esc_html_e( 'Edit campaign', 'aggressive-ads' ); ?>
	</h2>

	<p class="aggr-hint"><?php esc_html_e( 'Your campaign keeps running exactly as approved while you edit. Nothing changes until you submit these edits and the review team accepts them.', 'aggressive-ads' ); ?></p>

	<ol class="aggr-steps" aria-label="<?php esc_attr_e( 'Campaign edit progress', 'aggressive-ads' ); ?>">
		<?php
		if ( $aggr_has_details ) {
			$aggr_step_link( 'details', __( 'Details', 'aggressive-ads' ), $aggr_edit_step, $aggr_edit_url );
		}

		if ( $aggr_has_schedule ) {
			$aggr_step_link( 'schedule', __( 'Schedule', 'aggressive-ads' ), $aggr_edit_step, $aggr_edit_url );
		}

		if ( $aggr_has_destination ) {
			$aggr_step_link( 'destination', __( 'Destination', 'aggressive-ads' ), $aggr_edit_step, $aggr_edit_url );
		}

		$aggr_step_link( 'review', __( 'Review and submit', 'aggressive-ads' ), $aggr_edit_step, $aggr_edit_url );
		?>
	</ol>

	<?php if ( 'review' === $aggr_edit_step ) : ?>
		<?php if ( array() === $aggr_draft ) : ?>
			<div class="aggr-empty">
				<p class="aggr-empty__title"><?php esc_html_e( 'Nothing changed yet', 'aggressive-ads' ); ?></p>
				<p><?php esc_html_e( 'Change something on an earlier step and it will appear here before you submit it.', 'aggressive-ads' ); ?></p>
			</div>
		<?php else : ?>
			<div class="aggr-tablewrap" role="region" aria-label="<?php esc_attr_e( 'Changes to submit', 'aggressive-ads' ); ?>" tabindex="0">
				<table class="aggr-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Field', 'aggressive-ads' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Currently', 'aggressive-ads' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Changing to', 'aggressive-ads' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $aggr_draft as $aggr_change ) : ?>
							<tr>
								<td class="aggr-table__primary"><?php echo esc_html( (string) $aggr_change['label'] ); ?></td>
								<td><?php echo esc_html( (string) $aggr_change['from'] ); ?></td>
								<td><?php echo esc_html( (string) $aggr_change['to'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::CHANGES_SUBMIT ); ?>">
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign_id ); ?>">
				<?php wp_nonce_field( Campaign_Actions::submit_changes_nonce_action( $aggr_campaign_id ) ); ?>
				<button class="aggr-button" type="submit">
					<?php esc_html_e( 'Submit for review', 'aggressive-ads' ); ?>
				</button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::CHANGES_CANCEL ); ?>">
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign_id ); ?>">
				<?php wp_nonce_field( Campaign_Actions::cancel_changes_nonce_action( $aggr_campaign_id ) ); ?>
				<button class="aggr-button aggr-button--secondary" type="submit">
					<?php esc_html_e( 'Discard these edits', 'aggressive-ads' ); ?>
				</button>
			</form>
		<?php endif; ?>
	<?php else : ?>
		<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::CHANGES_ACTION ); ?>">
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign_id ); ?>">
			<?php wp_nonce_field( Campaign_Actions::changes_nonce_action( $aggr_campaign_id ) ); ?>

			<?php if ( 'details' === $aggr_edit_step ) : ?>
				<input type="hidden" name="next_step" value="<?php echo esc_attr( $aggr_has_schedule ? 'schedule' : ( $aggr_has_destination ? 'destination' : 'review' ) ); ?>">

				<?php if ( in_array( 'title', $aggr_edit_fields, true ) ) : ?>
					<div class="aggr-field">
						<label for="aggr-edit-title"><?php esc_html_e( 'Campaign name', 'aggressive-ads' ); ?></label>
						<input type="text" id="aggr-edit-title" name="title" value="<?php echo esc_attr( (string) ( $aggr_values['title'] ?? '' ) ); ?>" maxlength="200">
					</div>
				<?php endif; ?>

				<?php if ( in_array( 'placement_ids', $aggr_edit_fields, true ) ) : ?>
					<fieldset class="aggr-field">
						<legend><?php esc_html_e( 'Placements', 'aggressive-ads' ); ?></legend>
						<p class="aggr-hint"><?php esc_html_e( 'Changing placements changes the advertisement size you must supply. If this is approved, the campaign stops running until you upload a correctly sized creative and it is reviewed.', 'aggressive-ads' ); ?></p>
						<?php foreach ( $aggr_campaign['placement_options'] as $aggr_option ) : ?>
							<label class="aggr-choice">
								<input
									type="checkbox"
									name="placement_ids[]"
									value="<?php echo esc_attr( (string) (int) $aggr_option['id'] ); ?>"
									<?php checked( in_array( (int) $aggr_option['id'], array_map( 'intval', (array) ( $aggr_values['placement_ids'] ?? array() ) ), true ) ); ?>
								>
								<?php echo esc_html( (string) $aggr_option['name'] ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>

				<?php if ( in_array( 'advertiser_notes', $aggr_edit_fields, true ) ) : ?>
					<div class="aggr-field">
						<label for="aggr-edit-notes"><?php esc_html_e( 'Notes for the review team', 'aggressive-ads' ); ?></label>
						<textarea id="aggr-edit-notes" name="advertiser_notes" rows="4" maxlength="2000"><?php echo esc_textarea( (string) ( $aggr_values['advertiser_notes'] ?? '' ) ); ?></textarea>
					</div>
				<?php endif; ?>
			<?php elseif ( 'schedule' === $aggr_edit_step ) : ?>
				<?php
				/*
				 * Staged values first, stored campaign only as the fallback.
				 *
				 * Every other step reads $aggr_values; this one read the stored
				 * campaign, so stepping back into Schedule redisplayed the live
				 * dates and saving wrote them straight back over a date change
				 * the advertiser had already staged — silently, because the form
				 * looked exactly as it should.
				 */
				?>
				<input type="hidden" name="next_step" value="<?php echo esc_attr( $aggr_has_destination ? 'destination' : 'review' ); ?>">

				<div class="aggr-field">
					<label for="aggr-edit-start"><?php esc_html_e( 'Start date', 'aggressive-ads' ); ?></label>
					<input type="date" id="aggr-edit-start" name="start_date" value="<?php echo esc_attr( (string) ( $aggr_values['start_date'] ?? $aggr_campaign['start_date'] ) ); ?>">
					<p class="aggr-hint"><?php esc_html_e( 'A start date that has already passed cannot be moved.', 'aggressive-ads' ); ?></p>
				</div>
				<div class="aggr-field">
					<label for="aggr-edit-end"><?php esc_html_e( 'End date', 'aggressive-ads' ); ?></label>
					<input type="date" id="aggr-edit-end" name="end_date" value="<?php echo esc_attr( (string) ( $aggr_values['end_date'] ?? $aggr_campaign['end_date'] ) ); ?>">
				</div>
			<?php else : ?>
				<input type="hidden" name="next_step" value="review">

				<?php foreach ( $aggr_campaign['creatives'] as $aggr_creative ) : ?>
					<div class="aggr-field">
						<label for="aggr-edit-url-<?php echo esc_attr( (string) (int) $aggr_creative['id'] ); ?>">
							<?php
							printf(
								/* translators: %s: the placement this advertisement runs in. */
								esc_html__( 'Destination for %s', 'aggressive-ads' ),
								esc_html( (string) $aggr_creative['placement'] )
							);
							?>
						</label>
						<input
							type="url"
							id="aggr-edit-url-<?php echo esc_attr( (string) (int) $aggr_creative['id'] ); ?>"
							name="click_urls[<?php echo esc_attr( (string) (int) $aggr_creative['id'] ); ?>]"
							value="<?php echo esc_attr( (string) ( $aggr_values['click_urls'][ (int) $aggr_creative['id'] ] ?? $aggr_creative['click_url'] ) ); ?>"
						>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<button class="aggr-button" type="submit">
				<?php esc_html_e( 'Save and continue', 'aggressive-ads' ); ?>
			</button>
		</form>
	<?php endif; ?>
</section>
