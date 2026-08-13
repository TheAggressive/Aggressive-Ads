<?php
/**
 * Staff campaign-review detail.
 *
 * @package Aggressive\Ads
 *
 * @var string                                    $aggr_filter   Queue filter.
 * @var int                                       $aggr_page     Queue page.
 * @var array{type: string, message: string, detail: string, action_url: string, action_label: string}|null $aggr_notice Result notice.
 * @var array<string, mixed>|null                 $aggr_campaign Campaign data.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Admin\Review_Screen;
use Aggressive\Ads\Admin\Review_Data;
use Aggressive\Ads\Admin\Creative_Change_Actions;

$aggr_back = Review_Screen::queue_url( $aggr_filter, $aggr_page );
?>
<div class="wrap aggr-portal aggr-admin">
	<p class="aggr-breadcrumb">
		<a href="<?php echo esc_url( $aggr_back ); ?>">&larr; <?php esc_html_e( 'Back to campaign review', 'aggressive-ads' ); ?></a>
	</p>

	<?php if ( is_array( $aggr_notice ) ) : ?>
		<div class="aggr-flash aggr-flash--<?php echo esc_attr( $aggr_notice['type'] ); ?>" role="status">
			<p class="aggr-flash__message"><?php echo esc_html( $aggr_notice['message'] ); ?></p>

			<?php if ( '' !== (string) $aggr_notice['detail'] ) : ?>
				<p class="aggr-flash__detail"><?php echo esc_html( (string) $aggr_notice['detail'] ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== (string) $aggr_notice['action_url'] ) : ?>
				<p class="aggr-flash__action">
					<a href="<?php echo esc_url( (string) $aggr_notice['action_url'] ); ?>">
						<?php echo esc_html( (string) $aggr_notice['action_label'] ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! is_array( $aggr_campaign ) ) : ?>
		<section class="aggr-panel">
			<div class="aggr-empty">
				<h1 class="aggr-title"><?php esc_html_e( 'Campaign not found', 'aggressive-ads' ); ?></h1>
				<p><?php esc_html_e( 'The campaign may have been removed. Return to the queue and choose another campaign.', 'aggressive-ads' ); ?></p>
			</div>
		</section>
	</div>
		<?php return; ?>
	<?php endif; ?>

	<?php
	$aggr_campaign_id = (int) $aggr_campaign['id'];
	?>

	<header class="aggr-pagehead">
		<div>
			<h1 class="aggr-title"><?php echo esc_html( (string) $aggr_campaign['title'] ); ?></h1>
			<p class="aggr-lede"><?php echo esc_html( (string) $aggr_campaign['org_name'] ); ?></p>
		</div>
		<span class="aggr-pill aggr-pill--<?php echo esc_attr( (string) $aggr_campaign['pill'] ); ?>">
			<?php echo esc_html( (string) $aggr_campaign['status_text'] ); ?>
		</span>
	</header>

	<section class="aggr-panel" aria-labelledby="aggr-review-summary">
		<h2 id="aggr-review-summary" class="aggr-panel__head"><?php esc_html_e( 'Campaign summary', 'aggressive-ads' ); ?></h2>
		<dl class="aggr-facts">
			<div class="aggr-fact">
				<dt><?php esc_html_e( 'Organization', 'aggressive-ads' ); ?></dt>
				<dd><?php echo esc_html( (string) $aggr_campaign['org_name'] ); ?></dd>
			</div>
			<div class="aggr-fact">
				<dt><?php esc_html_e( 'Placements', 'aggressive-ads' ); ?></dt>
				<dd><?php echo esc_html( implode( ', ', $aggr_campaign['placements'] ) ); ?></dd>
			</div>
			<div class="aggr-fact">
				<dt><?php esc_html_e( 'Schedule', 'aggressive-ads' ); ?></dt>
				<dd>
					<?php
					if ( (int) $aggr_campaign['start_ts'] <= 0 ) {
						esc_html_e( 'Not scheduled', 'aggressive-ads' );
					} else {
						echo esc_html( Review_Data::format_timestamp( (int) $aggr_campaign['start_ts'] ) );

						if ( (int) $aggr_campaign['end_ts'] > 0 ) {
							echo ' &ndash; ' . esc_html( Review_Data::format_timestamp( (int) $aggr_campaign['end_ts'] ) );
						}
					}
					?>
				</dd>
			</div>
			<div class="aggr-fact">
				<dt><?php esc_html_e( 'Reviewer', 'aggressive-ads' ); ?></dt>
				<dd><?php echo esc_html( '' === (string) $aggr_campaign['reviewer'] ? __( 'Unassigned', 'aggressive-ads' ) : (string) $aggr_campaign['reviewer'] ); ?></dd>
			</div>
			<div class="aggr-fact">
				<dt><?php esc_html_e( 'Submission', 'aggressive-ads' ); ?></dt>
				<dd>
					<?php echo 0 === (int) $aggr_campaign['submitted_at'] ? esc_html__( 'Not submitted', 'aggressive-ads' ) : esc_html( Review_Data::format_timestamp( (int) $aggr_campaign['submitted_at'], true ) ); ?>
				</dd>
			</div>
			<div class="aggr-fact">
				<dt><?php esc_html_e( 'Revision', 'aggressive-ads' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( (int) $aggr_campaign['revision'] ) ); ?></dd>
			</div>
		</dl>
	</section>

	<?php if ( '' !== (string) $aggr_campaign['review_notes'] ) : ?>
		<section class="aggr-notice" aria-labelledby="aggr-review-feedback">
			<h2 id="aggr-review-feedback" class="aggr-notice__head"><?php esc_html_e( 'Advertiser-facing feedback', 'aggressive-ads' ); ?></h2>
			<p><?php echo nl2br( esc_html( (string) $aggr_campaign['review_notes'] ) ); ?></p>
		</section>
	<?php endif; ?>

	<section class="aggr-panel" aria-labelledby="aggr-review-creatives">
		<h2 id="aggr-review-creatives" class="aggr-panel__head"><?php esc_html_e( 'Creative review', 'aggressive-ads' ); ?></h2>

		<?php if ( array() === $aggr_campaign['creatives'] ) : ?>
			<div class="aggr-empty">
				<h3 class="aggr-empty__title"><?php esc_html_e( 'No creative uploaded', 'aggressive-ads' ); ?></h3>
				<p><?php esc_html_e( 'This campaign cannot be approved until every placement has creative.', 'aggressive-ads' ); ?></p>
			</div>
		<?php else : ?>
			<div class="aggr-creative-grid">
				<?php foreach ( $aggr_campaign['creatives'] as $aggr_creative ) : ?>
					<article class="aggr-creative">
						<div class="aggr-creative__preview">
							<img
								src="<?php echo esc_url( (string) $aggr_creative['preview'] ); ?>"
								alt="<?php echo esc_attr( (string) $aggr_creative['alt_text'] ); ?>"
								loading="lazy"
							>
						</div>
						<div class="aggr-creative__body">
							<h3><?php echo esc_html( (string) $aggr_creative['placement'] ); ?></h3>
							<dl>
								<div><dt><?php esc_html_e( 'Required size', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( (string) $aggr_creative['size'] ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Uploaded size', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( (string) $aggr_creative['dimensions'] ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Alt text', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( (string) $aggr_creative['alt_text'] ); ?></dd></div>
								<div>
									<dt><?php esc_html_e( 'Destination', 'aggressive-ads' ); ?></dt>
									<dd class="aggr-table__url"><a href="<?php echo esc_url( (string) $aggr_creative['click_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $aggr_creative['click_url'] ); ?></a></dd>
								</div>
							</dl>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<?php if ( array() !== $aggr_campaign['creative_updates'] ) : ?>
		<section class="aggr-panel" aria-labelledby="aggr-creative-updates">
			<h2 id="aggr-creative-updates" class="aggr-panel__head"><?php esc_html_e( 'Pending ad updates', 'aggressive-ads' ); ?></h2>
			<p><?php esc_html_e( 'The current ads remain in rotation until an update below is approved.', 'aggressive-ads' ); ?></p>

			<div class="aggr-creative-grid">
				<?php foreach ( $aggr_campaign['creative_updates'] as $aggr_update ) : ?>
					<article class="aggr-creative">
						<div class="aggr-creative__preview">
							<img src="<?php echo esc_url( (string) $aggr_update['preview'] ); ?>" alt="<?php echo esc_attr( (string) $aggr_update['alt_text'] ); ?>" loading="lazy">
						</div>
						<div class="aggr-creative__body">
							<h3><?php echo esc_html( (string) $aggr_update['placement'] ); ?></h3>
							<dl>
								<div><dt><?php esc_html_e( 'Required size', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( (string) $aggr_update['size'] ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Uploaded size', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( (string) $aggr_update['dimensions'] ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Current destination', 'aggressive-ads' ); ?></dt><dd class="aggr-table__url"><?php echo esc_html( (string) $aggr_update['current_url'] ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Proposed destination', 'aggressive-ads' ); ?></dt><dd class="aggr-table__url"><?php echo esc_html( (string) $aggr_update['click_url'] ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Current alt text', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( (string) $aggr_update['current_alt'] ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Proposed alt text', 'aggressive-ads' ); ?></dt><dd><?php echo esc_html( (string) $aggr_update['alt_text'] ); ?></dd></div>
							</dl>

							<form class="aggr-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
								<input type="hidden" name="action" value="<?php echo esc_attr( Creative_Change_Actions::ACTION ); ?>">
								<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign_id ); ?>">
								<input type="hidden" name="replacement_id" value="<?php echo esc_attr( (string) $aggr_update['id'] ); ?>">
								<?php wp_nonce_field( Creative_Change_Actions::nonce_action( (int) $aggr_update['id'] ) ); ?>
								<div class="aggr-form__actions">
									<button class="aggr-button" type="submit" name="decision" value="approve"><?php esc_html_e( 'Approve and replace', 'aggressive-ads' ); ?></button>
								</div>
								<label for="aggr-update-feedback-<?php echo esc_attr( (string) $aggr_update['id'] ); ?>"><?php esc_html_e( 'Feedback required when rejecting', 'aggressive-ads' ); ?></label>
								<textarea id="aggr-update-feedback-<?php echo esc_attr( (string) $aggr_update['id'] ); ?>" name="review_notes" rows="4" maxlength="2000"></textarea>
								<button class="aggr-button aggr-button--danger" type="submit" name="decision" value="reject"><?php esc_html_e( 'Reject update', 'aggressive-ads' ); ?></button>
							</form>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>

	<div class="aggr-review-columns">
		<section class="aggr-panel" aria-labelledby="aggr-review-actions">
			<h2 id="aggr-review-actions" class="aggr-panel__head"><?php esc_html_e( 'Review actions', 'aggressive-ads' ); ?></h2>

			<?php if ( array() === $aggr_campaign['actions'] ) : ?>
				<p class="aggr-empty"><?php esc_html_e( 'No staff action is available from this status.', 'aggressive-ads' ); ?></p>
			<?php else : ?>
				<div class="aggr-actions">
					<?php foreach ( $aggr_campaign['actions'] as $aggr_action ) : ?>
						<form class="aggr-action" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
							<input type="hidden" name="action" value="<?php echo esc_attr( Review_Screen::TRANSITION_ACTION ); ?>">
							<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign_id ); ?>">
							<input type="hidden" name="to" value="<?php echo esc_attr( (string) $aggr_action['to'] ); ?>">
							<input type="hidden" name="filter" value="<?php echo esc_attr( $aggr_filter ); ?>">
							<input type="hidden" name="queue_page" value="<?php echo esc_attr( (string) $aggr_page ); ?>">
							<?php wp_nonce_field( Review_Screen::nonce_action( $aggr_campaign_id ) ); ?>

							<?php if ( $aggr_action['needs_notes'] ) : ?>
								<label for="aggr-feedback-<?php echo esc_attr( (string) $aggr_action['to'] ); ?>">
									<?php esc_html_e( 'Feedback the advertiser will see', 'aggressive-ads' ); ?>
								</label>
								<textarea id="aggr-feedback-<?php echo esc_attr( (string) $aggr_action['to'] ); ?>" name="review_notes" rows="4" required></textarea>
							<?php endif; ?>

							<button class="aggr-button <?php echo $aggr_action['destructive'] ? 'aggr-button--danger' : ''; ?>" type="submit">
								<?php echo esc_html( (string) $aggr_action['label'] ); ?>
							</button>
						</form>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>

		<section class="aggr-panel" aria-labelledby="aggr-internal-notes">
			<h2 id="aggr-internal-notes" class="aggr-panel__head"><?php esc_html_e( 'Internal notes', 'aggressive-ads' ); ?></h2>
			<form class="aggr-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( Review_Screen::NOTES_ACTION ); ?>">
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign_id ); ?>">
				<input type="hidden" name="filter" value="<?php echo esc_attr( $aggr_filter ); ?>">
				<input type="hidden" name="queue_page" value="<?php echo esc_attr( (string) $aggr_page ); ?>">
				<?php wp_nonce_field( Review_Screen::notes_nonce_action( $aggr_campaign_id ) ); ?>
				<label for="aggr-internal-notes-field"><?php esc_html_e( 'Visible to staff only', 'aggressive-ads' ); ?></label>
				<textarea id="aggr-internal-notes-field" name="internal_notes" rows="7"><?php echo esc_textarea( (string) $aggr_campaign['internal_notes'] ); ?></textarea>
				<button class="aggr-button aggr-button--secondary" type="submit"><?php esc_html_e( 'Save internal notes', 'aggressive-ads' ); ?></button>
			</form>
		</section>
	</div>

	<?php if ( $aggr_campaign['can_view_audit'] ) : ?>
		<section class="aggr-panel" aria-labelledby="aggr-audit-timeline">
			<h2 id="aggr-audit-timeline" class="aggr-panel__head"><?php esc_html_e( 'Audit timeline', 'aggressive-ads' ); ?></h2>
			<?php if ( array() === $aggr_campaign['audit'] ) : ?>
				<p class="aggr-empty"><?php esc_html_e( 'No audit events have been recorded for this campaign.', 'aggressive-ads' ); ?></p>
			<?php else : ?>
				<ol class="aggr-timeline">
					<?php foreach ( $aggr_campaign['audit'] as $aggr_event ) : ?>
						<li class="aggr-timeline__item">
							<div class="aggr-timeline__message"><?php echo esc_html( (string) $aggr_event['message'] ); ?></div>
							<div class="aggr-timeline__meta">
								<?php
								printf(
									/* translators: 1: actor name. 2: event date and time. 3: event outcome. */
									esc_html__( '%1$s · %2$s · %3$s', 'aggressive-ads' ),
									esc_html( '' === (string) $aggr_event['actor'] ? __( 'Unknown user', 'aggressive-ads' ) : (string) $aggr_event['actor'] ),
									esc_html( Review_Data::format_timestamp( (int) $aggr_event['created_at'], true ) ),
									esc_html( (string) $aggr_event['outcome'] )
								);
								?>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</section>
	<?php endif; ?>
</div>
