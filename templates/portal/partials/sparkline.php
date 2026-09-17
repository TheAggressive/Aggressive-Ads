<?php
/**
 * Seven-day impression sparkline.
 *
 * @package Aggressive\Ads
 *
 * @var list<array{day: string, label: string, impressions: int, height: int}> $aggr_series Daily bars.
 * @var string                                                                  $aggr_range       The window in words, including its timezone.
 * @var int                                                                     $aggr_export_days Days the export will actually produce.
 * @var string                                                                  $aggr_export_from First UTC day of the export.
 * @var string                                                                  $aggr_export_to   Last UTC day of the export.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $aggr_series ) || array() === $aggr_series ) {
	return;
}

$aggr_first       = $aggr_series[0]['label'];
$aggr_last        = $aggr_series[ array_key_last( $aggr_series ) ]['label'];
$aggr_spark_label = sprintf(
	/* translators: 1: first day of the range. 2: last day of the range. */
	__( 'Daily impressions, %1$s to %2$s', 'aggressive-ads' ),
	$aggr_first,
	$aggr_last
);
$aggr_counting = isset( $aggr_counting ) && is_string( $aggr_counting ) ? $aggr_counting : '';

/*
 * A scale a person can read: the top of the chart is a round number — 20k,
 * not 17,318 — with a gridline at its half, so a bar's height is a figure
 * rather than a proportion of whatever the busiest day happened to be.
 */
$aggr_spark_peak = 0;

foreach ( $aggr_series as $aggr_bar ) {
	$aggr_spark_peak = max( $aggr_spark_peak, (int) $aggr_bar['impressions'] );
}

$aggr_spark_top = 10;

if ( $aggr_spark_peak > 0 ) {
	// A little headroom, so the busiest day does not sit on the top line.
	$aggr_spark_need      = (int) ceil( $aggr_spark_peak * 1.1 );
	$aggr_spark_magnitude = 10 ** (int) floor( log10( $aggr_spark_need ) );

	foreach ( array( 1, 2, 5, 10 ) as $aggr_spark_step ) {
		if ( $aggr_spark_step * $aggr_spark_magnitude >= $aggr_spark_need ) {
			$aggr_spark_top = $aggr_spark_step * $aggr_spark_magnitude;
			break;
		}
	}
}

$aggr_spark_scale = static function ( float $value ): string {
	$whole = abs( $value - round( $value ) ) < 0.001;

	return $value >= 1000
		? sprintf(
			/* translators: %s: a number of thousands, e.g. 20 for 20,000. */
			__( '%sk', 'aggressive-ads' ),
			number_format_i18n( $value / 1000, abs( fmod( $value, 1000 ) ) < 0.001 ? 0 : 1 )
		)
		: number_format_i18n( $value, $whole ? 0 : 1 );
};

// About seven dates along the bottom, whatever the window.
$aggr_spark_every = max( 1, (int) ceil( count( $aggr_series ) / 7 ) );
?>
<section class="aggr-spark" aria-labelledby="aggr-spark-heading">
	<div class="aggr-spark__head">
		<h3 id="aggr-spark-heading" class="aggr-spark__title"><?php esc_html_e( 'Impressions per day', 'aggressive-ads' ); ?></h3>
		<?php if ( '' !== $aggr_counting ) : ?>
			<span class="aggr-spark__key" aria-hidden="true"><?php esc_html_e( 'Still being counted', 'aggressive-ads' ); ?></span>
		<?php endif; ?>
	</div>

	<div class="aggr-spark__plot" style="--aggr-spark-bars: <?php echo esc_attr( (string) count( $aggr_series ) ); ?>">
		<div class="aggr-spark__scale" aria-hidden="true">
			<span><?php echo esc_html( $aggr_spark_scale( (float) $aggr_spark_top ) ); ?></span>
			<span><?php echo esc_html( $aggr_spark_scale( $aggr_spark_top / 2 ) ); ?></span>
			<span>0</span>
		</div>

		<div class="aggr-spark__chart">
			<ol class="aggr-spark__track" aria-label="<?php echo esc_attr( $aggr_spark_label ); ?>">
				<?php foreach ( $aggr_series as $aggr_bar ) : ?>
					<?php
					$aggr_bar_count    = (int) $aggr_bar['impressions'];
					$aggr_bar_counting = '' !== $aggr_counting && (string) $aggr_bar['day'] >= $aggr_counting;
					$aggr_bar_text     = sprintf(
						/* translators: 1: weekday. 2: impression count. */
						_n( '%1$s: %2$s impression', '%1$s: %2$s impressions', $aggr_bar_count, 'aggressive-ads' ),
						(string) $aggr_bar['label'],
						number_format_i18n( $aggr_bar_count )
					);
					?>
					<li class="aggr-spark__day<?php echo $aggr_bar_counting ? ' aggr-spark__day--counting' : ''; ?>">
						<span class="aggr-spark__bar" style="height: <?php echo esc_attr( (string) round( 100 * $aggr_bar_count / $aggr_spark_top, 2 ) ); ?>%"></span>
						<span class="aggr-sr"><?php echo esc_html( $aggr_bar_text ); ?></span>
						<span class="aggr-spark__tip" aria-hidden="true"><?php echo esc_html( $aggr_bar_text ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>

			<?php
			/*
			 * A label every few bars rather than one per bar or only the two
			 * ends: a per-bar label is a smear over a quarter, and two ends leave
			 * the middle to guesswork. The per-day figures are announced from the
			 * list above, so thinning these loses nothing for a screen reader.
			 */
			?>
			<div class="aggr-spark__axis" aria-hidden="true">
				<?php foreach ( $aggr_series as $aggr_index => $aggr_bar ) : ?>
					<?php $aggr_tick = 0 === $aggr_index % $aggr_spark_every ? intdiv( $aggr_index, $aggr_spark_every ) : -1; ?>
					<?php // Every other date stands down on a phone, where seven do not fit. ?>
					<span <?php echo $aggr_tick > 0 && 1 === $aggr_tick % 2 ? 'class="aggr-spark__tick--minor"' : ''; ?>><?php echo $aggr_tick >= 0 ? esc_html( (string) $aggr_bar['label'] ) : ''; ?></span>
				<?php endforeach; ?>
			</div>
		</div>
	</div>

	<?php
	/*
	 * The export lives here, beside the chart, because this partial renders
	 * only when Reporting is on — so the control cannot outlive the surface it
	 * belongs to. A POST rather than a link: it is a nonce-protected action,
	 * and a GET download is a link a browser or a prefetcher may follow on its
	 * own.
	 */
	?>
	<form class="aggr-spark__export" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( \Aggressive\Ads\Portal\Report_Actions::EXPORT_ACTION ); ?>">
		<?php
		/*
		 * The export follows the window on screen rather than a fixed length,
		 * so "download" means "download what I am looking at". Its own cap is
		 * tighter — it assembles the whole document in memory — so the button
		 * below names the number of days it will actually produce.
		 */
		?>
		<input type="hidden" name="days" value="<?php echo esc_attr( (string) $aggr_export_days ); ?>">
		<input type="hidden" name="from" value="<?php echo esc_attr( $aggr_export_from ); ?>">
		<input type="hidden" name="to" value="<?php echo esc_attr( $aggr_export_to ); ?>">
		<?php wp_nonce_field( \Aggressive\Ads\Portal\Report_Actions::EXPORT_ACTION ); ?>
		<button type="submit" class="aggr-button aggr-button--secondary aggr-button--small">
			<?php
			printf(
				/* translators: %d: number of days covered by the export. */
				esc_html__( 'Download %d days (CSV)', 'aggressive-ads' ),
				(int) $aggr_export_days
			);
			?>
		</button>
	</form>
</section>
