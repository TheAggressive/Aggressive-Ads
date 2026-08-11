<?php
/**
 * Staff campaign-review detail.
 *
 * @package LAAO_Advertiser_Portal
 *
 * @var string                                    $laao_ads_filter   Queue filter.
 * @var int                                       $laao_ads_page     Queue page.
 * @var array{type: string, message: string, detail: string, action_url: string, action_label: string}|null $laao_ads_notice Result notice.
 * @var array<string, mixed>|null                 $laao_ads_campaign Campaign data.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Admin\Review_Screen;
use LAAO_Advertiser_Portal\Admin\Review_Data;

$laao_ads_back = Review_Screen::queue_url( $laao_ads_filter, $laao_ads_page );
?>
<div class="wrap laao-ads-portal laao-ads-admin">
	<p class="laao-ads-breadcrumb">
		<a href="<?php echo esc_url( $laao_ads_back ); ?>">&larr; <?php esc_html_e( 'Back to campaign review', 'laao-advertiser-portal' ); ?></a>
	</p>

	<?php if ( is_array( $laao_ads_notice ) ) : ?>
		<div class="laao-ads-flash laao-ads-flash--<?php echo esc_attr( $laao_ads_notice['type'] ); ?>" role="status">
			<p class="laao-ads-flash__message"><?php echo esc_html( $laao_ads_notice['message'] ); ?></p>

			<?php if ( '' !== (string) $laao_ads_notice['detail'] ) : ?>
				<p class="laao-ads-flash__detail"><?php echo esc_html( (string) $laao_ads_notice['detail'] ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== (string) $laao_ads_notice['action_url'] ) : ?>
				<p class="laao-ads-flash__action">
					<a href="<?php echo esc_url( (string) $laao_ads_notice['action_url'] ); ?>">
						<?php echo esc_html( (string) $laao_ads_notice['action_label'] ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! is_array( $laao_ads_campaign ) ) : ?>
		<section class="laao-ads-panel">
			<div class="laao-ads-empty">
				<h1 class="laao-ads-title"><?php esc_html_e( 'Campaign not found', 'laao-advertiser-portal' ); ?></h1>
				<p><?php esc_html_e( 'The campaign may have been removed. Return to the queue and choose another campaign.', 'laao-advertiser-portal' ); ?></p>
			</div>
		</section>
	</div>
		<?php return; ?>
	<?php endif; ?>

	<?php
	$laao_ads_campaign_id = (int) $laao_ads_campaign['id'];
	?>

	<header class="laao-ads-pagehead">
		<div>
			<h1 class="laao-ads-title"><?php echo esc_html( (string) $laao_ads_campaign['title'] ); ?></h1>
			<p class="laao-ads-lede"><?php echo esc_html( (string) $laao_ads_campaign['org_name'] ); ?></p>
		</div>
		<span class="laao-ads-pill laao-ads-pill--<?php echo esc_attr( (string) $laao_ads_campaign['pill'] ); ?>">
			<?php echo esc_html( (string) $laao_ads_campaign['status_text'] ); ?>
		</span>
	</header>

	<section class="laao-ads-panel" aria-labelledby="laao-ads-review-summary">
		<h2 id="laao-ads-review-summary" class="laao-ads-panel__head"><?php esc_html_e( 'Campaign summary', 'laao-advertiser-portal' ); ?></h2>
		<dl class="laao-ads-facts">
			<div class="laao-ads-fact">
				<dt><?php esc_html_e( 'Organization', 'laao-advertiser-portal' ); ?></dt>
				<dd><?php echo esc_html( (string) $laao_ads_campaign['org_name'] ); ?></dd>
			</div>
			<div class="laao-ads-fact">
				<dt><?php esc_html_e( 'Placements', 'laao-advertiser-portal' ); ?></dt>
				<dd><?php echo esc_html( implode( ', ', $laao_ads_campaign['placements'] ) ); ?></dd>
			</div>
			<div class="laao-ads-fact">
				<dt><?php esc_html_e( 'Schedule', 'laao-advertiser-portal' ); ?></dt>
				<dd>
					<?php
					if ( (int) $laao_ads_campaign['start_ts'] <= 0 ) {
						esc_html_e( 'Not scheduled', 'laao-advertiser-portal' );
					} else {
						echo esc_html( Review_Data::format_timestamp( (int) $laao_ads_campaign['start_ts'] ) );

						if ( (int) $laao_ads_campaign['end_ts'] > 0 ) {
							echo ' &ndash; ' . esc_html( Review_Data::format_timestamp( (int) $laao_ads_campaign['end_ts'] ) );
						}
					}
					?>
				</dd>
			</div>
			<div class="laao-ads-fact">
				<dt><?php esc_html_e( 'Reviewer', 'laao-advertiser-portal' ); ?></dt>
				<dd><?php echo esc_html( '' === (string) $laao_ads_campaign['reviewer'] ? __( 'Unassigned', 'laao-advertiser-portal' ) : (string) $laao_ads_campaign['reviewer'] ); ?></dd>
			</div>
			<div class="laao-ads-fact">
				<dt><?php esc_html_e( 'Submission', 'laao-advertiser-portal' ); ?></dt>
				<dd>
					<?php echo 0 === (int) $laao_ads_campaign['submitted_at'] ? esc_html__( 'Not submitted', 'laao-advertiser-portal' ) : esc_html( Review_Data::format_timestamp( (int) $laao_ads_campaign['submitted_at'], true ) ); ?>
				</dd>
			</div>
			<div class="laao-ads-fact">
				<dt><?php esc_html_e( 'Revision', 'laao-advertiser-portal' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( (int) $laao_ads_campaign['revision'] ) ); ?></dd>
			</div>
		</dl>
	</section>

	<?php if ( '' !== (string) $laao_ads_campaign['review_notes'] ) : ?>
		<section class="laao-ads-notice" aria-labelledby="laao-ads-review-feedback">
			<h2 id="laao-ads-review-feedback" class="laao-ads-notice__head"><?php esc_html_e( 'Advertiser-facing feedback', 'laao-advertiser-portal' ); ?></h2>
			<p><?php echo nl2br( esc_html( (string) $laao_ads_campaign['review_notes'] ) ); ?></p>
		</section>
	<?php endif; ?>

	<section class="laao-ads-panel" aria-labelledby="laao-ads-review-creatives">
		<h2 id="laao-ads-review-creatives" class="laao-ads-panel__head"><?php esc_html_e( 'Creative review', 'laao-advertiser-portal' ); ?></h2>

		<?php if ( array() === $laao_ads_campaign['creatives'] ) : ?>
			<div class="laao-ads-empty">
				<h3 class="laao-ads-empty__title"><?php esc_html_e( 'No creative uploaded', 'laao-advertiser-portal' ); ?></h3>
				<p><?php esc_html_e( 'This campaign cannot be approved until every placement has creative.', 'laao-advertiser-portal' ); ?></p>
			</div>
		<?php else : ?>
			<div class="laao-ads-creative-grid">
				<?php foreach ( $laao_ads_campaign['creatives'] as $laao_ads_creative ) : ?>
					<article class="laao-ads-creative">
						<div class="laao-ads-creative__preview">
							<img
								src="<?php echo esc_url( (string) $laao_ads_creative['preview'] ); ?>"
								alt="<?php echo esc_attr( (string) $laao_ads_creative['alt_text'] ); ?>"
								loading="lazy"
							>
						</div>
						<div class="laao-ads-creative__body">
							<h3><?php echo esc_html( (string) $laao_ads_creative['placement'] ); ?></h3>
							<dl>
								<div><dt><?php esc_html_e( 'Required size', 'laao-advertiser-portal' ); ?></dt><dd><?php echo esc_html( (string) $laao_ads_creative['size'] ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Uploaded size', 'laao-advertiser-portal' ); ?></dt><dd><?php echo esc_html( (string) $laao_ads_creative['dimensions'] ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Alt text', 'laao-advertiser-portal' ); ?></dt><dd><?php echo esc_html( (string) $laao_ads_creative['alt_text'] ); ?></dd></div>
								<div>
									<dt><?php esc_html_e( 'Destination', 'laao-advertiser-portal' ); ?></dt>
									<dd class="laao-ads-table__url"><a href="<?php echo esc_url( (string) $laao_ads_creative['click_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $laao_ads_creative['click_url'] ); ?></a></dd>
								</div>
							</dl>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<div class="laao-ads-review-columns">
		<section class="laao-ads-panel" aria-labelledby="laao-ads-review-actions">
			<h2 id="laao-ads-review-actions" class="laao-ads-panel__head"><?php esc_html_e( 'Review actions', 'laao-advertiser-portal' ); ?></h2>

			<?php if ( array() === $laao_ads_campaign['actions'] ) : ?>
				<p class="laao-ads-empty"><?php esc_html_e( 'No staff action is available from this status.', 'laao-advertiser-portal' ); ?></p>
			<?php else : ?>
				<div class="laao-ads-actions">
					<?php foreach ( $laao_ads_campaign['actions'] as $laao_ads_action ) : ?>
						<form class="laao-ads-action" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
							<input type="hidden" name="action" value="<?php echo esc_attr( Review_Screen::TRANSITION_ACTION ); ?>">
							<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $laao_ads_campaign_id ); ?>">
							<input type="hidden" name="to" value="<?php echo esc_attr( (string) $laao_ads_action['to'] ); ?>">
							<input type="hidden" name="filter" value="<?php echo esc_attr( $laao_ads_filter ); ?>">
							<input type="hidden" name="queue_page" value="<?php echo esc_attr( (string) $laao_ads_page ); ?>">
							<?php wp_nonce_field( Review_Screen::nonce_action( $laao_ads_campaign_id ) ); ?>

							<?php if ( $laao_ads_action['needs_notes'] ) : ?>
								<label for="laao-ads-feedback-<?php echo esc_attr( (string) $laao_ads_action['to'] ); ?>">
									<?php esc_html_e( 'Feedback the advertiser will see', 'laao-advertiser-portal' ); ?>
								</label>
								<textarea id="laao-ads-feedback-<?php echo esc_attr( (string) $laao_ads_action['to'] ); ?>" name="review_notes" rows="4" required></textarea>
							<?php endif; ?>

							<button class="laao-ads-button <?php echo $laao_ads_action['destructive'] ? 'laao-ads-button--danger' : ''; ?>" type="submit">
								<?php echo esc_html( (string) $laao_ads_action['label'] ); ?>
							</button>
						</form>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>

		<section class="laao-ads-panel" aria-labelledby="laao-ads-internal-notes">
			<h2 id="laao-ads-internal-notes" class="laao-ads-panel__head"><?php esc_html_e( 'Internal notes', 'laao-advertiser-portal' ); ?></h2>
			<form class="laao-ads-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( Review_Screen::NOTES_ACTION ); ?>">
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $laao_ads_campaign_id ); ?>">
				<input type="hidden" name="filter" value="<?php echo esc_attr( $laao_ads_filter ); ?>">
				<input type="hidden" name="queue_page" value="<?php echo esc_attr( (string) $laao_ads_page ); ?>">
				<?php wp_nonce_field( Review_Screen::notes_nonce_action( $laao_ads_campaign_id ) ); ?>
				<label for="laao-ads-internal-notes-field"><?php esc_html_e( 'Visible to staff only', 'laao-advertiser-portal' ); ?></label>
				<textarea id="laao-ads-internal-notes-field" name="internal_notes" rows="7"><?php echo esc_textarea( (string) $laao_ads_campaign['internal_notes'] ); ?></textarea>
				<button class="laao-ads-button laao-ads-button--secondary" type="submit"><?php esc_html_e( 'Save internal notes', 'laao-advertiser-portal' ); ?></button>
			</form>
		</section>
	</div>

	<?php if ( $laao_ads_campaign['can_view_audit'] ) : ?>
		<section class="laao-ads-panel" aria-labelledby="laao-ads-audit-timeline">
			<h2 id="laao-ads-audit-timeline" class="laao-ads-panel__head"><?php esc_html_e( 'Audit timeline', 'laao-advertiser-portal' ); ?></h2>
			<?php if ( array() === $laao_ads_campaign['audit'] ) : ?>
				<p class="laao-ads-empty"><?php esc_html_e( 'No audit events have been recorded for this campaign.', 'laao-advertiser-portal' ); ?></p>
			<?php else : ?>
				<ol class="laao-ads-timeline">
					<?php foreach ( $laao_ads_campaign['audit'] as $laao_ads_event ) : ?>
						<li class="laao-ads-timeline__item">
							<div class="laao-ads-timeline__message"><?php echo esc_html( (string) $laao_ads_event['message'] ); ?></div>
							<div class="laao-ads-timeline__meta">
								<?php
								printf(
									/* translators: 1: actor name. 2: event date and time. 3: event outcome. */
									esc_html__( '%1$s · %2$s · %3$s', 'laao-advertiser-portal' ),
									esc_html( '' === (string) $laao_ads_event['actor'] ? __( 'Unknown user', 'laao-advertiser-portal' ) : (string) $laao_ads_event['actor'] ),
									esc_html( Review_Data::format_timestamp( (int) $laao_ads_event['created_at'], true ) ),
									esc_html( (string) $laao_ads_event['outcome'] )
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
