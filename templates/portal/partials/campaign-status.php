<?php
/**
 * Where a submitted campaign has got to.
 *
 * The screen after the wizard: a dated line of stages, what the advertiser can
 * do now, what has happened so far, and the campaign and its ads beside it.
 * Every date on it is one the campaign already stores or the audit trail
 * recorded; a stage that has not happened says so rather than guessing.
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
use Aggressive\Ads\Portal\Date_Input;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Nonces;

$aggr_status_now   = (string) $aggr_campaign['status'];
$aggr_status_zone  = wp_timezone();
$aggr_created_ts   = (int) get_post_timestamp( (int) $aggr_campaign['id'] );
$aggr_submitted_ts = (int) $aggr_campaign['submitted_at'];

/*
 * A 12-hour clock: advertisers are not all fluent in 24-hour time. The format
 * is translatable, so a locale that reads 24-hour time naturally can have it.
 */
/* translators: date and time of a campaign event, as a PHP date format (https://www.php.net/manual/datetime.format.php), e.g. Sep 16 · 2:05 PM. */
$aggr_when_format = __( 'M j · g:i A', 'aggressive-ads' );
$aggr_status_day  = static function ( string $ymd ) use ( $aggr_status_zone ): string {
	$day = '' === $ymd ? false : DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, $aggr_status_zone );

	return false === $day ? '—' : (string) wp_date( 'M j', $day->getTimestamp(), $aggr_status_zone );
};

$aggr_status_places = is_array( $aggr_campaign['placements'] ?? null ) ? array_map( 'strval', $aggr_campaign['placements'] ) : array();
$aggr_status_lines  = is_array( $aggr_campaign['line_items'] ?? null ) ? $aggr_campaign['line_items'] : array();
$aggr_status_line   = is_array( $aggr_status_lines[0] ?? null ) ? $aggr_status_lines[0] : null;

$aggr_status_pricing = match ( (string) ( $aggr_status_line['pricing_model'] ?? '' ) ) {
	'flat'           => __( 'Flat fee', 'aggressive-ads' ),
	'cpm'            => __( 'Per 1,000 impressions', 'aggressive-ads' ),
	'cpc'            => __( 'Per click', 'aggressive-ads' ),
	'cpa'            => __( 'Per conversion', 'aggressive-ads' ),
	'share_of_voice' => __( 'Share of the placement', 'aggressive-ads' ),
	default          => '—',
};
$aggr_status_pacing = match ( (string) ( $aggr_status_line['pacing_mode'] ?? '' ) ) {
	'even'  => __( 'Spread evenly over the dates', 'aggressive-ads' ),
	'asap'  => __( 'As fast as possible', 'aggressive-ads' ),
	default => '—',
};

$aggr_history = is_array( $aggr_campaign['history'] ?? null ) ? $aggr_campaign['history'] : array(
	'items'  => array(),
	'stages' => array(),
);

/*
 * A stage is dated from the audit trail, which records when the campaign
 * actually reached it. The stored timestamps stay as the fallback: a campaign
 * older than this feature has no rows for stages it passed, and "—" beside
 * Approved on a campaign that is plainly live reads as a fault.
 */
$aggr_stage_dates = is_array( $aggr_history['stages'] ?? null ) ? $aggr_history['stages'] : array();
$aggr_stage_when  = static function ( string $status, string $fallback = '—' ) use ( $aggr_stage_dates, $aggr_when_format ): string {
	$at = (int) ( $aggr_stage_dates[ $status ] ?? 0 );

	return $at > 0 ? (string) wp_date( $aggr_when_format, $at ) : $fallback;
};

$aggr_stages = array(
	array( Post_Statuses::DRAFT, __( 'Draft', 'aggressive-ads' ), $aggr_created_ts > 0 ? (string) wp_date( 'M j', $aggr_created_ts ) : '—' ),
	array( Post_Statuses::SUBMITTED, __( 'Submitted', 'aggressive-ads' ), $aggr_stage_when( Post_Statuses::SUBMITTED, $aggr_submitted_ts > 0 ? (string) wp_date( $aggr_when_format, $aggr_submitted_ts ) : '—' ) ),
	array( Post_Statuses::REVIEW, __( 'In review', 'aggressive-ads' ), $aggr_stage_when( Post_Statuses::REVIEW ) ),
	array( Post_Statuses::APPROVED, __( 'Approved', 'aggressive-ads' ), $aggr_stage_when( Post_Statuses::APPROVED ) ),
	array( Post_Statuses::SCHEDULED, __( 'Scheduled', 'aggressive-ads' ), $aggr_stage_when( Post_Statuses::SCHEDULED, $aggr_status_day( (string) $aggr_campaign['start_date'] ) ) ),
	array( Post_Statuses::LIVE, __( 'Live', 'aggressive-ads' ), $aggr_stage_when( Post_Statuses::LIVE, $aggr_status_day( (string) $aggr_campaign['start_date'] ) ) ),
	array( Post_Statuses::COMPLETE, __( 'Complete', 'aggressive-ads' ), $aggr_stage_when( Post_Statuses::COMPLETE, $aggr_status_day( (string) $aggr_campaign['end_date'] ) ) ),
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
				<?php
				/*
				 * Straight from the audit trail, filtered by
				 * Domain\Advertiser_Events, oldest entry last.
				 *
				 * "Draft started" is always the last line: nothing records a
				 * campaign being created — the post's own date is that fact —
				 * so the trail cannot supply it. The submitted line below it
				 * is only for a campaign older than these events, where the
				 * stored timestamp is all there is.
				 */
				$aggr_activity = is_array( $aggr_history['items'] ?? null ) ? $aggr_history['items'] : array();
				?>
				<?php foreach ( $aggr_activity as $aggr_entry ) : ?>
					<li>
						<span class="aggr-activity__when"><?php echo esc_html( (int) $aggr_entry['at'] > 0 ? (string) wp_date( $aggr_when_format, (int) $aggr_entry['at'] ) : '—' ); ?></span>
						<span>
							<?php echo esc_html( (string) $aggr_entry['text'] ); ?>
							<span class="aggr-activity__who"><?php echo esc_html( (string) $aggr_entry['who'] ); ?></span>
						</span>
					</li>
				<?php endforeach; ?>
				<?php if ( array() === $aggr_activity && $aggr_submitted_ts > 0 ) : ?>
					<li>
						<span class="aggr-activity__when"><?php echo esc_html( (string) wp_date( $aggr_when_format, $aggr_submitted_ts ) ); ?></span>
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
						<span class="aggr-activity__when"><?php echo esc_html( (string) wp_date( $aggr_when_format, $aggr_created_ts ) ); ?></span>
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
				<?php // A campaign made before packages, or by staff, has none; a row saying "—" is noise. ?>
				<?php if ( '' !== (string) $aggr_campaign['package_name'] ) : ?>
					<div>
						<dt><?php esc_html_e( 'Package', 'aggressive-ads' ); ?></dt>
						<dd>
							<?php echo esc_html( (string) $aggr_campaign['package_name'] ); ?>
							<?php if ( '' !== $aggr_package_duration ) : ?>
								<span class="aggr-summary__sub"><?php echo esc_html( $aggr_package_duration ); ?></span>
							<?php endif; ?>
						</dd>
					</div>
				<?php endif; ?>
				<div>
					<dt><?php esc_html_e( 'Schedule', 'aggressive-ads' ); ?></dt>
					<dd>
						<?php echo esc_html( (string) $aggr_campaign['dates'] ); ?>
						<?php if ( $aggr_run_days > 0 ) : ?>
							<span class="aggr-summary__sub">
								<?php
								printf(
									/* translators: 1: number of days the campaign runs. 2: the site's timezone on the first day, e.g. PDT. */
									esc_html( _n( '%1$d day · %2$s', '%1$d days · %2$s', $aggr_run_days, 'aggressive-ads' ) ),
									(int) $aggr_run_days,
									esc_html( Date_Input::zone_abbreviation( (string) ( $aggr_campaign['start_date'] ?? '' ) ) )
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
				<?php if ( array() !== $aggr_status_places ) : ?>
					<div>
						<dt><?php echo esc_html( _n( 'Placement', 'Placements', count( $aggr_status_places ), 'aggressive-ads' ) ); ?></dt>
						<dd>
							<?php echo esc_html( implode( ', ', $aggr_status_places ) ); ?>
							<span class="aggr-summary__sub">
								<?php
								printf(
									/* translators: %s: number of ads on the campaign. */
									esc_html( _n( '%s ad', '%s ads', count( $aggr_creatives ), 'aggressive-ads' ) ),
									esc_html( number_format_i18n( count( $aggr_creatives ) ) )
								);
								?>
							</span>
						</dd>
					</div>
				<?php endif; ?>
				<?php if ( null !== $aggr_status_line ) : ?>
					<?php
					/*
					 * How it is delivered, in the advertiser's words. This was a
					 * panel of its own that led the page with "Line item", "FLAT"
					 * and "Even"; the facts are the same, the terms are theirs.
					 */
					?>
					<div>
						<dt><?php esc_html_e( 'Pricing', 'aggressive-ads' ); ?></dt>
						<dd><?php echo esc_html( $aggr_status_pricing ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Pacing', 'aggressive-ads' ); ?></dt>
						<dd><?php echo esc_html( $aggr_status_pacing ); ?></dd>
					</div>
				<?php endif; ?>
			</dl>
			<?php if ( '' !== (string) $aggr_campaign['package_price'] ) : ?>
				<div class="aggr-summary__total">
					<span><?php esc_html_e( 'Total', 'aggressive-ads' ); ?></span>
					<span class="aggr-summary__price"><?php echo esc_html( (string) $aggr_campaign['package_price'] ); ?></span>
				</div>
			<?php endif; ?>
		</section>

		<?php // Once ads can be updated, the "Your ads" panel below lists them with their controls. ?>
		<?php if ( array() !== $aggr_creatives && true !== $aggr_campaign['can_request_updates'] ) : ?>
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
