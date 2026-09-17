<?php
/**
 * The order summary beside the campaign wizard.
 *
 * What the advertiser is buying, restated beside every step with the one action
 * that moves them on. On a wide panel the step's own primary button is hidden
 * and this one submits the step's form through `form=`, so there is still
 * exactly one; on a narrow panel the step keeps its button and this card sits
 * below it as a summary.
 *
 * Scope is inherited from the screen, as it is for every partial here.
 *
 * @var array<string, mixed>             $aggr_campaign        The campaign being edited.
 * @var array<int, array<string, mixed>> $aggr_slots           Placements and the creatives on each.
 * @var string                           $aggr_step            Current display step.
 * @var bool                             $aggr_creative_ready  Whether every active placement has a creative.
 * @var bool                             $aggr_review_ready    Whether every submission check passes.
 * @var array<int, array<string, mixed>> $aggr_review_problems Advertiser-safe readiness problems.
 * @var int                              $aggr_run_days        Days in the run, or zero when open or unset.
 * @var array<int, string>               $aggr_size_labels     The package's sizes, grouped.
 * @var string                           $aggr_package_duration The package's duration in words.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_summary_total   = 0;
$aggr_summary_ready   = 0;
$aggr_summary_missing = array();

foreach ( $aggr_slots as $aggr_summary_slot ) {
	if ( ! is_array( $aggr_summary_slot ) ) {
		continue;
	}

	++$aggr_summary_total;

	if ( ! empty( $aggr_summary_slot['active'] ) && array() !== ( $aggr_summary_slot['creatives'] ?? array() ) ) {
		++$aggr_summary_ready;
	} else {
		$aggr_summary_missing[] = str_replace( 'x', '×', (string) $aggr_summary_slot['size'] );
	}
}

$aggr_summary_package = (string) ( $aggr_campaign['package_name'] ?? '' );
$aggr_summary_price   = (string) ( $aggr_campaign['package_price'] ?? '' );
$aggr_summary_link    = (string) ( $aggr_campaign['default_click_url'] ?? '' );
$aggr_summary_left    = count( $aggr_review_problems );
$aggr_summary_percent = $aggr_summary_total > 0 ? (int) round( 100 * $aggr_summary_ready / $aggr_summary_total ) : 0;

// Only the ads are missing, and only one: the note can name the file.
$aggr_summary_codes    = array_unique( array_column( $aggr_review_problems, 'code' ) );
$aggr_summary_one_file = 1 === count( $aggr_summary_missing ) && array() === array_diff( $aggr_summary_codes, array( 'no_creatives', 'placement_uncovered' ) );
?>
<aside class="aggr-summary" aria-labelledby="aggr-order-summary-heading">
	<div class="aggr-summary__head">
		<h3 id="aggr-order-summary-heading" class="aggr-eyebrow"><?php esc_html_e( 'Order summary', 'aggressive-ads' ); ?></h3>
		<span class="aggr-pill aggr-pill--<?php echo esc_attr( (string) ( $aggr_campaign['pill'] ?? 'neutral' ) ); ?>"><?php echo esc_html( (string) ( $aggr_campaign['status_text'] ?? '' ) ); ?></span>
	</div>

	<dl class="aggr-summary__rows">
		<div>
			<dt><?php esc_html_e( 'Package', 'aggressive-ads' ); ?></dt>
			<dd>
				<?php if ( '' === $aggr_summary_package ) : ?>
					<span class="aggr-summary__muted"><?php esc_html_e( 'Not chosen yet', 'aggressive-ads' ); ?></span>
				<?php else : ?>
					<?php echo esc_html( $aggr_summary_package ); ?>
					<?php if ( '' !== $aggr_package_duration ) : ?>
						<span class="aggr-summary__sub"><?php echo esc_html( $aggr_package_duration ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
			</dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'Schedule', 'aggressive-ads' ); ?></dt>
			<dd>
				<?php echo esc_html( (string) ( $aggr_campaign['dates'] ?? '' ) ); ?>
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
		<div>
			<dt><?php esc_html_e( 'Sizes', 'aggressive-ads' ); ?></dt>
			<dd>
				<?php if ( 0 === $aggr_summary_total ) : ?>
					<span class="aggr-summary__muted"><?php esc_html_e( 'Sizes come with the package', 'aggressive-ads' ); ?></span>
				<?php else : ?>
					<?php
					printf(
						/* translators: %d: number of placements in the package. */
						esc_html( _n( '%d placement', '%d placements', $aggr_summary_total, 'aggressive-ads' ) ),
						(int) $aggr_summary_total
					);
					?>
					<span class="aggr-summary__sub"><?php echo esc_html( implode( ' · ', $aggr_size_labels ) ); ?></span>
				<?php endif; ?>
			</dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'Link', 'aggressive-ads' ); ?></dt>
			<dd>
				<?php if ( '' === $aggr_summary_link ) : ?>
					<span class="aggr-summary__muted"><?php esc_html_e( 'Added on the next step', 'aggressive-ads' ); ?></span>
				<?php else : ?>
					<span class="aggr-summary__link"><?php echo esc_html( $aggr_summary_link ); ?></span>
					<?php if ( 'creative' === $aggr_step ) : ?>
						<span class="aggr-summary__sub"><?php esc_html_e( 'Shared by every ad', 'aggressive-ads' ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
			</dd>
		</div>
		<?php if ( 'details' !== $aggr_step && $aggr_summary_total > 0 ) : ?>
			<div>
				<dt><?php esc_html_e( 'Ads', 'aggressive-ads' ); ?></dt>
				<dd>
					<?php
					printf(
						/* translators: 1: sizes that have an ad. 2: sizes in the package. */
						esc_html__( '%1$d of %2$d ready', 'aggressive-ads' ),
						(int) $aggr_summary_ready,
						(int) $aggr_summary_total
					);
					?>
					<?php // Decoration beside the sentence above, which carries the number. ?>
					<span class="aggr-summary__bar" aria-hidden="true"><span style="width: <?php echo esc_attr( (string) $aggr_summary_percent ); ?>%"></span></span>
				</dd>
			</div>
		<?php endif; ?>
	</dl>

	<div class="aggr-summary__total">
		<span><?php esc_html_e( 'Total', 'aggressive-ads' ); ?></span>
		<span class="aggr-summary__price"><?php echo esc_html( '' === $aggr_summary_price ? '—' : $aggr_summary_price ); ?></span>
	</div>

	<?php if ( 'details' === $aggr_step ) : ?>
		<button class="aggr-button aggr-summary__cta" type="submit" form="aggr-plan-form">
			<span class="aggr-noscript-label"><?php esc_html_e( 'Save and continue', 'aggressive-ads' ); ?></span>
			<span class="aggr-script-label"><?php esc_html_e( 'Continue to ads', 'aggressive-ads' ); ?></span>
		</button>
		<p class="aggr-summary__note"><?php esc_html_e( 'You can change any of this until you submit.', 'aggressive-ads' ); ?></p>
	<?php elseif ( 'creative' === $aggr_step ) : ?>
		<?php if ( $aggr_creative_ready ) : ?>
			<button class="aggr-button aggr-summary__cta" type="submit" form="aggr-creative-complete"><?php esc_html_e( 'Continue to review', 'aggressive-ads' ); ?></button>
		<?php else : ?>
			<button class="aggr-button aggr-summary__cta" type="button" disabled><?php esc_html_e( 'Continue to review', 'aggressive-ads' ); ?></button>
			<p class="aggr-summary__note">
				<?php
				printf(
					/* translators: %d: sizes that still need a file. */
					esc_html( _n( '%d size still needs a file.', '%d sizes still need a file.', count( $aggr_summary_missing ), 'aggressive-ads' ) ),
					(int) count( $aggr_summary_missing )
				);
				?>
			</p>
		<?php endif; ?>
	<?php elseif ( $aggr_review_ready ) : ?>
		<button class="aggr-button aggr-summary__cta" type="submit" form="aggr-submit-form"><?php esc_html_e( 'Submit for review', 'aggressive-ads' ); ?></button>
		<p class="aggr-summary__note"><?php esc_html_e( 'The submission checks run again when you press it.', 'aggressive-ads' ); ?></p>
	<?php else : ?>
		<button class="aggr-button aggr-summary__cta" type="button" disabled><?php esc_html_e( 'Submit for review', 'aggressive-ads' ); ?></button>
		<p class="aggr-summary__note">
			<?php
			if ( $aggr_summary_one_file ) {
				printf(
					/* translators: %s: the size still missing its file, e.g. 720×300. */
					esc_html__( 'Add the %s file to submit.', 'aggressive-ads' ),
					esc_html( $aggr_summary_missing[0] )
				);
			} else {
				printf(
					/* translators: %d: number of problems still to resolve. */
					esc_html( _n( '%d thing left before you can submit.', '%d things left before you can submit.', $aggr_summary_left, 'aggressive-ads' ) ),
					(int) $aggr_summary_left
				);
			}
			?>
		</p>
	<?php endif; ?>
</aside>
