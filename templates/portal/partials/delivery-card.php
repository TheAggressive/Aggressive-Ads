<?php
/**
 * Native delivery for a window: tiles against the previous window, the daily
 * chart, and the CSV. One card on the dashboard for the organization, and on a
 * campaign's page for that campaign, so the two can never read differently.
 *
 * Scope is inherited from the screen that includes it.
 *
 * @var array<int, array<string, mixed>> $aggr_delivery        Tiles, or empty when Reporting is off.
 * @var array<int, array<string, mixed>> $aggr_series          Daily points.
 * @var string                           $aggr_range           The window in words.
 * @var string                           $aggr_freshness       Which days may still change, as a sentence.
 * @var string                           $aggr_counting        First UTC day still being counted, or ''.
 * @var array<string, mixed>             $aggr_window          The window and the export's slice of it.
 * @var string                           $aggr_delivery_base   The page the window links and form return to.
 * @var string                           $aggr_delivery_title  The card's heading.
 * @var int                              $aggr_export_campaign The campaign the CSV covers, or 0 for the organization.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Workflow\Reporting_Read;

$aggr_export_days = $aggr_window['export_days'];
$aggr_export_from = $aggr_window['export_from'];
$aggr_export_to   = $aggr_window['export_to'];
?>
<?php
/*
 * The window picker sits in this section's header, not above it.
 *
 * Its scope is whatever it is next to. Floating between the pipeline counts
 * and these tiles, it claimed both, and the pipeline counts have no window.
 */
if ( array() !== $aggr_delivery ) :
	?>
<section class="aggr-delivery" aria-labelledby="aggr-delivery-heading aggr-delivery-window">
<div class="aggr-delivery__head">
	<div>
		<h2 id="aggr-delivery-heading" class="aggr-delivery__title">
			<?php echo esc_html( $aggr_delivery_title ); ?>
		</h2>
		<p id="aggr-delivery-window" class="aggr-delivery__window"><?php echo esc_html( $aggr_range ); ?></p>
		<?php
		/*
		 * Days are UTC, and midnight UTC is somebody's afternoon. The sentence
		 * is true as written; the local-time module restates when a day starts
		 * in the viewer's own zone.
		 */
		?>
		<p class="aggr-delivery__window">
			<span
				data-aggr-local="clock"
				data-aggr-datetime="<?php echo esc_attr( gmdate( 'Y-m-d' ) . 'T00:00:00Z' ); ?>"
				data-aggr-local-format="<?php /* translators: %s: midnight UTC as the viewer's local time, e.g. 5:00 PM. */ esc_attr_e( 'Each day starts at %s your time.', 'aggressive-ads' ); ?>"
			><?php esc_html_e( 'Each day runs midnight to midnight UTC.', 'aggressive-ads' ); ?></span>
		</p>
	</div>

<form class="aggr-range" method="get" action="<?php echo esc_url( $aggr_delivery_base ); ?>">
	<h2 class="aggr-sr"><?php esc_html_e( 'Choose a reporting window', 'aggressive-ads' ); ?></h2>

	<?php
	/*
	 * The presets are links rather than a second control. A select beside two
	 * date inputs asks the reader which one wins; a link that fills the same
	 * range in answers that by not competing. The one that matches the window
	 * on screen is marked current.
	 */
	?>
	<ul class="aggr-range__presets">
		<?php foreach ( Reporting_Read::WINDOWS as $aggr_preset ) : ?>
			<li>
				<a
					href="<?php echo esc_url( add_query_arg( 'days', (int) $aggr_preset, $aggr_delivery_base ) ); ?>"
					<?php echo (int) $aggr_preset === (int) $aggr_window['days'] ? 'aria-current="true"' : ''; ?>
				>
					<?php
					printf(
						/* translators: %d: number of days. */
						esc_html( _n( '%d day', '%d days', (int) $aggr_preset, 'aggressive-ads' ) ),
						(int) $aggr_preset
					);
					?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<details class="aggr-range__custom">
		<summary><?php esc_html_e( 'Custom', 'aggressive-ads' ); ?></summary>
		<div class="aggr-range__fields">
			<div class="aggr-range__field">
				<label for="aggr-range-from"><?php esc_html_e( 'From (UTC)', 'aggressive-ads' ); ?></label>
				<input type="date" id="aggr-range-from" name="from" value="<?php echo esc_attr( $aggr_window['from'] ); ?>">
			</div>

			<div class="aggr-range__field">
				<label for="aggr-range-to"><?php esc_html_e( 'To (UTC)', 'aggressive-ads' ); ?></label>
				<input type="date" id="aggr-range-to" name="to" value="<?php echo esc_attr( $aggr_window['to'] ); ?>">
			</div>

			<button class="aggr-button aggr-button--secondary" type="submit"><?php esc_html_e( 'Show', 'aggressive-ads' ); ?></button>
		</div>
	</details>
</form>
</div>

	<?php if ( true === $aggr_window['rejected'] ) : ?>
		<?php
		/*
		 * Refused, not clamped. A screen that quietly reported a different
		 * period than the one asked for would look authoritative and answer a
		 * question nobody put to it.
		 */
		?>
	<p class="aggr-notice" role="status">
		<?php esc_html_e( 'That date range could not be used, so the default window is shown. A range must be two valid dates, in order, and no longer than 92 days.', 'aggressive-ads' ); ?>
	</p>
	<?php endif; ?>

<div class="aggr-stats">
	<?php foreach ( $aggr_delivery as $aggr_stat ) : ?>
		<div class="aggr-stat">
			<div class="aggr-stat__label"><?php echo esc_html( (string) $aggr_stat['label'] ); ?></div>
			<div class="aggr-stat__value"><?php echo esc_html( (string) $aggr_stat['value'] ); ?></div>
			<?php
			/*
			 * The sign and the unit are in the text, so the direction class
			 * only ever adds colour to something already legible without it.
			 * An empty change is a real state — nothing to compare against —
			 * and renders as nothing rather than as a zero.
			 */
			$aggr_change = (string) ( $aggr_stat['change'] ?? '' );
			?>
			<?php if ( '' !== $aggr_change ) : ?>
				<div class="aggr-stat__change aggr-stat__change--<?php echo esc_attr( (string) ( $aggr_stat['direction'] ?? 'flat' ) ); ?>">
					<?php echo esc_html( $aggr_change ); ?>
				</div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>

	<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/sparkline.php'; ?>

<p class="aggr-hint">
	<?php esc_html_e( 'Impressions and clicks from native delivery.', 'aggressive-ads' ); ?>
	<?php if ( '' !== $aggr_counting ) : ?>
		<?php
		$aggr_counting_ts = (int) strtotime( $aggr_counting . ' UTC' );
		?>
		<span
			class="aggr-hint__freshness"
			data-aggr-local="moment"
			data-aggr-datetime="<?php echo esc_attr( $aggr_counting . 'T00:00:00Z' ); ?>"
			data-aggr-local-format="<?php /* translators: %s: a date and time in the viewer's zone, e.g. Sep 16, 5:00 PM. */ esc_attr_e( 'Figures since %s (your time) are still coming in.', 'aggressive-ads' ); ?>"
		>
			<?php
			printf(
				/* translators: %s: a UTC date, e.g. September 17. */
				esc_html__( 'Figures from %s (UTC) onward are still coming in.', 'aggressive-ads' ),
				esc_html( (string) wp_date( 'F j', $aggr_counting_ts, new DateTimeZone( 'UTC' ) ) )
			);
			?>
		</span>
	<?php elseif ( '' !== $aggr_freshness ) : ?>
		<span class="aggr-hint__freshness"><?php echo esc_html( $aggr_freshness ); ?></span>
	<?php endif; ?>
</p>
</section>
	<?php
endif;
?>
