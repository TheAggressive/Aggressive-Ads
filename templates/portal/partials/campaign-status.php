<?php
/**
 * Where a submitted campaign has got to.
 *
 * The screen after the wizard: a dated line of stages, what the advertiser can
 * do now, what has happened so far, and the campaign and its ads beside it.
 * Every date on it is one the campaign already stores; a stage that has not
 * happened says so rather than guessing. The full activity log is #295.
 *
 * Scope is inherited from the screen, as it is for every partial here.
 *
 * @var array<string, mixed>             $aggr_campaign         The campaign.
 * @var array<int, array<string, mixed>> $aggr_creatives        Its creatives.
 * @var int                              $aggr_run_days         Days in the run, or zero.
 * @var string                           $aggr_package_duration The package's duration in words.
 * @var array<int, string>               $aggr_size_labels      The package's sizes, grouped.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Nonces;

$aggr_status_now   = (string) $aggr_campaign['status'];
$aggr_status_zone  = wp_timezone();
$aggr_created_ts   = (int) get_post_timestamp( (int) $aggr_campaign['id'] );
$aggr_submitted_ts = (int) $aggr_campaign['submitted_at'];
$aggr_status_day   = static function ( string $ymd ) use ( $aggr_status_zone ): string {
	$day = '' === $ymd ? false : DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, $aggr_status_zone );

	return false === $day ? '—' : (string) wp_date( 'M j', $day->getTimestamp(), $aggr_status_zone );
};

$aggr_stages = array(
	array( Post_Statuses::DRAFT, __( 'Draft', 'aggressive-ads' ), $aggr_created_ts > 0 ? (string) wp_date( 'M j', $aggr_created_ts ) : '—' ),
	array( Post_Statuses::SUBMITTED, __( 'Submitted', 'aggressive-ads' ), $aggr_submitted_ts > 0 ? (string) wp_date( 'M j · H:i', $aggr_submitted_ts ) : '—' ),
	array( Post_Statuses::REVIEW, __( 'In review', 'aggressive-ads' ), '—' ),
	array( Post_Statuses::APPROVED, __( 'Approved', 'aggressive-ads' ), '—' ),
	array( Post_Statuses::SCHEDULED, __( 'Scheduled', 'aggressive-ads' ), $aggr_status_day( (string) $aggr_campaign['start_date'] ) ),
	array( Post_Statuses::LIVE, __( 'Live', 'aggressive-ads' ), $aggr_status_day( (string) $aggr_campaign['start_date'] ) ),
	array( Post_Statuses::COMPLETE, __( 'Complete', 'aggressive-ads' ), $aggr_status_day( (string) $aggr_campaign['end_date'] ) ),
);

// Where on that line the campaign is. Paused is still live; changes requested is back at draft.
$aggr_stage_at = match ( $aggr_status_now ) {
	Post_Statuses::SUBMITTED => 1,
	Post_Statuses::REVIEW    => 2,
	Post_Statuses::APPROVED  => 3,
	Post_Statuses::SCHEDULED => 4,
	Post_Statuses::LIVE, Post_Statuses::PAUSED => 5,
	Post_Statuses::COMPLETE  => 6,
	default                  => 0,
};

$aggr_status_note = match ( $aggr_status_now ) {
	Post_Statuses::SUBMITTED => __( 'The review team has your campaign. You can withdraw it to edit until a reviewer starts.', 'aggressive-ads' ),
	Post_Statuses::REVIEW    => __( 'Someone on the review team is looking at it now.', 'aggressive-ads' ),
	Post_Statuses::CHANGES   => __( 'The review team asked for changes. Edit it and submit again.', 'aggressive-ads' ),
	Post_Statuses::REJECTED  => __( 'Not approved. The reason is in the notes from the review team.', 'aggressive-ads' ),
	Post_Statuses::APPROVED, Post_Statuses::SCHEDULED => __( 'Approved. It starts by itself on its start date.', 'aggressive-ads' ),
	Post_Statuses::LIVE      => __( 'Being shown on the site right now.', 'aggressive-ads' ),
	Post_Statuses::PAUSED    => __( 'Temporarily not being shown. Get in touch if this is unexpected.', 'aggressive-ads' ),
	Post_Statuses::COMPLETE  => __( 'Finished. Run it again from your campaign list.', 'aggressive-ads' ),
	default                  => __( 'Cancelled and no longer running.', 'aggressive-ads' ),
};
?>
<div class="aggr-columns aggr-status">
	<div class="aggr-columns__main">
		<section class="aggr-step-card" aria-labelledby="aggr-status-heading">
			<div class="aggr-ads-section__head">
				<div>
					<h2 id="aggr-status-heading" class="aggr-step-card__title"><?php esc_html_e( 'Where it has got to', 'aggressive-ads' ); ?></h2>
					<p class="aggr-hint"><?php esc_html_e( 'Each stage is dated as it happens.', 'aggressive-ads' ); ?></p>
				</div>
			</div>

			<ol class="aggr-timeline">
				<?php foreach ( $aggr_stages as $aggr_stage_index => $aggr_stage ) : ?>
					<?php
					$aggr_stage_state = $aggr_stage_index < $aggr_stage_at ? 'done' : ( $aggr_stage_index === $aggr_stage_at ? 'now' : 'next' );
					?>
					<li class="aggr-timeline__stage aggr-timeline__stage--<?php echo esc_attr( $aggr_stage_state ); ?>" <?php echo 'now' === $aggr_stage_state ? 'aria-current="step"' : ''; ?>>
						<span class="aggr-timeline__mark" aria-hidden="true"></span>
						<span class="aggr-timeline__name"><?php echo esc_html( (string) $aggr_stage[1] ); ?></span>
						<span class="aggr-timeline__date"><?php echo esc_html( 'now' === $aggr_stage_state && $aggr_stage_index > 1 ? __( 'Now', 'aggressive-ads' ) : (string) $aggr_stage[2] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>

			<div class="aggr-status__note">
				<p><?php echo esc_html( $aggr_status_note ); ?></p>
				<?php if ( true === $aggr_campaign['can_withdraw'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::WITHDRAW_ACTION ); ?>">
						<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) (int) $aggr_campaign['id'] ); ?>">
						<?php wp_nonce_field( Campaign_Nonces::withdraw_nonce_action( (int) $aggr_campaign['id'] ) ); ?>
						<button class="aggr-card-action" type="submit"><?php esc_html_e( 'Withdraw to edit', 'aggressive-ads' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</section>

		<section class="aggr-step-card" aria-labelledby="aggr-activity-heading">
			<h2 id="aggr-activity-heading" class="aggr-step-card__title"><?php esc_html_e( 'Activity', 'aggressive-ads' ); ?></h2>
			<ul class="aggr-activity">
				<?php if ( $aggr_submitted_ts > 0 ) : ?>
					<li>
						<span class="aggr-activity__when"><?php echo esc_html( (string) wp_date( 'M j · H:i', $aggr_submitted_ts ) ); ?></span>
						<span>
							<?php
							echo esc_html(
								'' !== trim( (string) $aggr_campaign['advertiser_notes'] )
									? __( 'Submitted for review with a note for the team', 'aggressive-ads' )
									: __( 'Submitted for review', 'aggressive-ads' )
							);
							?>
						</span>
					</li>
				<?php endif; ?>
				<?php if ( $aggr_created_ts > 0 ) : ?>
					<li>
						<span class="aggr-activity__when"><?php echo esc_html( (string) wp_date( 'M j · H:i', $aggr_created_ts ) ); ?></span>
						<span>
							<?php
							echo esc_html(
								'' !== (string) $aggr_campaign['package_name']
									? sprintf(
										/* translators: %s: package name. */
										__( 'Draft started from %s', 'aggressive-ads' ),
										(string) $aggr_campaign['package_name']
									)
									: __( 'Draft started', 'aggressive-ads' )
							);
							?>
						</span>
					</li>
				<?php endif; ?>
			</ul>
		</section>
	</div>

	<aside class="aggr-columns__side">
		<section class="aggr-summary" aria-labelledby="aggr-status-campaign-heading">
			<div class="aggr-summary__head">
				<h2 id="aggr-status-campaign-heading" class="aggr-eyebrow"><?php esc_html_e( 'Campaign', 'aggressive-ads' ); ?></h2>
				<span class="aggr-pill aggr-pill--<?php echo esc_attr( (string) $aggr_campaign['pill'] ); ?>"><?php echo esc_html( (string) $aggr_campaign['status_text'] ); ?></span>
			</div>
			<dl class="aggr-summary__rows">
				<div>
					<dt><?php esc_html_e( 'Package', 'aggressive-ads' ); ?></dt>
					<dd>
						<?php echo esc_html( '' !== (string) $aggr_campaign['package_name'] ? (string) $aggr_campaign['package_name'] : '—' ); ?>
						<?php if ( '' !== $aggr_package_duration ) : ?>
							<span class="aggr-summary__sub"><?php echo esc_html( $aggr_package_duration ); ?></span>
						<?php endif; ?>
					</dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Schedule', 'aggressive-ads' ); ?></dt>
					<dd>
						<?php echo esc_html( (string) $aggr_campaign['dates'] ); ?>
						<?php if ( $aggr_run_days > 0 ) : ?>
							<span class="aggr-summary__sub">
								<?php
								printf(
									/* translators: %d: number of days the campaign runs. */
									esc_html( _n( '%d day · site time', '%d days · site time', $aggr_run_days, 'aggressive-ads' ) ),
									(int) $aggr_run_days
								);
								?>
							</span>
						<?php endif; ?>
					</dd>
				</div>
				<?php if ( '' !== (string) ( $aggr_campaign['default_click_url'] ?? '' ) ) : ?>
					<div>
						<dt><?php esc_html_e( 'Link', 'aggressive-ads' ); ?></dt>
						<dd><span class="aggr-summary__link"><?php echo esc_html( (string) $aggr_campaign['default_click_url'] ); ?></span></dd>
					</div>
				<?php endif; ?>
			</dl>
			<div class="aggr-summary__total">
				<span><?php esc_html_e( 'Total', 'aggressive-ads' ); ?></span>
				<span class="aggr-summary__price"><?php echo esc_html( '' !== (string) $aggr_campaign['package_price'] ? (string) $aggr_campaign['package_price'] : '—' ); ?></span>
			</div>
		</section>

		<?php if ( array() !== $aggr_creatives ) : ?>
			<section class="aggr-summary" aria-labelledby="aggr-status-ads-heading">
				<div class="aggr-summary__head">
					<h2 id="aggr-status-ads-heading" class="aggr-eyebrow"><?php esc_html_e( 'Your ads', 'aggressive-ads' ); ?></h2>
					<span class="aggr-upload-card__dims">
						<?php
						printf(
							/* translators: %d: number of ad sizes. */
							esc_html( _n( '%d size', '%d sizes', count( $aggr_size_labels ), 'aggressive-ads' ) ),
							(int) count( $aggr_size_labels )
						);
						?>
					</span>
				</div>
				<ul class="aggr-status__ads">
					<?php foreach ( $aggr_creatives as $aggr_status_ad ) : ?>
						<li>
							<img src="<?php echo esc_url( (string) $aggr_status_ad['preview'] ); ?>" alt="<?php echo esc_attr( (string) $aggr_status_ad['alt_text'] ); ?>" loading="lazy">
							<span class="aggr-upload-card__dims"><?php echo esc_html( (string) $aggr_status_ad['dimensions'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
	</aside>
</div>
