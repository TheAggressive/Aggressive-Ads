<?php
/**
 * Daily impressions as a smooth line, against the previous equal window.
 *
 * @package Aggressive\Ads
 *
 * @var list<array{day: string, label: string, date: string, impressions: int, clicks: int, height: int, previous: int, previous_clicks: int, previous_date: string}> $aggr_series Daily points.
 * @var string                                                                  $aggr_range       The window in words, including its timezone.
 * @var int                                                                     $aggr_export_days Days the export will actually produce.
 * @var string                                                                  $aggr_export_from First UTC day of the export.
 * @var string                                                                  $aggr_export_to   Last UTC day of the export.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Domain\Chart_Path;
use Aggressive\Ads\Domain\Chart_Scale;

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
 * A scale a person can read, fitted to the numbers: round gridlines, three to
 * five of them, with the smallest top that clears the busiest day of either
 * period. See Domain\Chart_Scale.
 */
$aggr_spark_peak     = 0;
$aggr_spark_has_prev = false;

foreach ( $aggr_series as $aggr_bar ) {
	$aggr_spark_peak     = max( $aggr_spark_peak, (int) $aggr_bar['impressions'], (int) ( $aggr_bar['previous'] ?? 0 ) );
	$aggr_spark_has_prev = $aggr_spark_has_prev || (int) ( $aggr_bar['previous'] ?? 0 ) > 0;
}

$aggr_spark_fit   = Chart_Scale::fit( $aggr_spark_peak );
$aggr_spark_top   = $aggr_spark_fit['top'];
$aggr_spark_ticks = array_reverse( $aggr_spark_fit['ticks'] );

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

/*
 * The geometry, in a 1000 × 100 box the SVG stretches to the chart's width.
 * Each day sits at the middle of its own column, which is where its hover
 * target and its date label are, so the line, the dots and the dates agree.
 */
$aggr_spark_days   = count( $aggr_series );
$aggr_spark_x      = static fn ( int $index ): float => ( $index + 0.5 ) * 1000 / $aggr_spark_days;
$aggr_spark_y      = static fn ( int $value ): float => 100 - 100 * $value / $aggr_spark_top;
$aggr_spark_pos    = static fn ( float $value ): string => Chart_Path::number( 100 - 100 * $value / $aggr_spark_top );
$aggr_spark_now    = array();
$aggr_spark_before = array();
$aggr_spark_split  = $aggr_spark_days;

foreach ( $aggr_series as $aggr_index => $aggr_bar ) {
	$aggr_spark_now[]    = array( $aggr_spark_x( $aggr_index ), $aggr_spark_y( (int) $aggr_bar['impressions'] ) );
	$aggr_spark_before[] = array( $aggr_spark_x( $aggr_index ), $aggr_spark_y( (int) ( $aggr_bar['previous'] ?? 0 ) ) );

	if ( $aggr_spark_split === $aggr_spark_days && '' !== $aggr_counting && (string) $aggr_bar['day'] >= $aggr_counting ) {
		$aggr_spark_split = $aggr_index;
	}
}

$aggr_spark_move     = static fn ( array $point ): string => 'M ' . Chart_Path::number( $point[0] ) . ' ' . Chart_Path::number( $point[1] );
$aggr_spark_curve    = Chart_Path::segments( $aggr_spark_now );
$aggr_spark_last     = $aggr_spark_now[ $aggr_spark_days - 1 ];
$aggr_spark_line     = $aggr_spark_move( $aggr_spark_now[0] ) . ' ' . implode( ' ', array_slice( $aggr_spark_curve, 0, max( 0, $aggr_spark_split - 1 ) ) );
$aggr_spark_dashed   = $aggr_spark_split < $aggr_spark_days && $aggr_spark_days > 1
	? $aggr_spark_move( $aggr_spark_now[ max( 0, $aggr_spark_split - 1 ) ] ) . ' ' . implode( ' ', array_slice( $aggr_spark_curve, max( 0, $aggr_spark_split - 1 ) ) )
	: '';
$aggr_spark_area     = $aggr_spark_move( $aggr_spark_now[0] ) . ' ' . implode( ' ', $aggr_spark_curve ) . ' L ' . Chart_Path::number( $aggr_spark_last[0] ) . ' 100 L ' . Chart_Path::number( $aggr_spark_now[0][0] ) . ' 100 Z';
$aggr_spark_previous = $aggr_spark_has_prev ? $aggr_spark_move( $aggr_spark_before[0] ) . ' ' . implode( ' ', Chart_Path::segments( $aggr_spark_before ) ) : '';

/*
 * End labels beside each line's last point. When the two ends are close the
 * lower label steps down, because two labels on one line read as neither.
 */
$aggr_end_now  = 100 - 100 * (int) $aggr_series[ $aggr_spark_days - 1 ]['impressions'] / $aggr_spark_top;
$aggr_end_prev = 100 - 100 * (int) ( $aggr_series[ $aggr_spark_days - 1 ]['previous'] ?? 0 ) / $aggr_spark_top;

if ( $aggr_spark_has_prev && abs( $aggr_end_now - $aggr_end_prev ) < 14 ) {
	// The higher label keeps its place and the lower one steps down — unless
	// that would leave the chart, in which case the higher one steps up. On a
	// tie "Now" is the lower, so it stays beside this period's dot.
	$aggr_end_high = min( $aggr_end_now, $aggr_end_prev );
	$aggr_end_low  = min( 100, $aggr_end_high + 14 );
	$aggr_end_high = $aggr_end_low - 14;

	if ( $aggr_end_now < $aggr_end_prev ) {
		$aggr_end_now  = $aggr_end_high;
		$aggr_end_prev = $aggr_end_low;
	} else {
		$aggr_end_prev = $aggr_end_high;
		$aggr_end_now  = $aggr_end_low;
	}
}

// About seven dates along the bottom, whatever the window.
$aggr_spark_every = max( 1, (int) ceil( $aggr_spark_days / 7 ) );
?>
<section class="aggr-spark" aria-labelledby="aggr-spark-heading">
	<div class="aggr-spark__head">
		<h3 id="aggr-spark-heading" class="aggr-spark__title"><?php esc_html_e( 'Impressions per day', 'aggressive-ads' ); ?></h3>
		<ul class="aggr-spark__legend" aria-hidden="true">
			<li class="aggr-spark__key"><?php esc_html_e( 'This period', 'aggressive-ads' ); ?></li>
			<?php if ( $aggr_spark_has_prev ) : ?>
				<li class="aggr-spark__key aggr-spark__key--previous"><?php esc_html_e( 'Previous period', 'aggressive-ads' ); ?></li>
			<?php endif; ?>
			<?php if ( '' !== $aggr_counting ) : ?>
				<li class="aggr-spark__key aggr-spark__key--counting"><?php esc_html_e( 'Still being counted', 'aggressive-ads' ); ?></li>
			<?php endif; ?>
		</ul>
	</div>

	<div class="aggr-spark__plot" style="--aggr-spark-bars: <?php echo esc_attr( (string) $aggr_spark_days ); ?>">
		<div class="aggr-spark__scale" aria-hidden="true">
			<?php foreach ( $aggr_spark_ticks as $aggr_spark_tick ) : ?>
				<span style="top: <?php echo esc_attr( $aggr_spark_pos( $aggr_spark_tick ) ); ?>%"><?php echo esc_html( $aggr_spark_scale( $aggr_spark_tick ) ); ?></span>
			<?php endforeach; ?>
		</div>

		<div class="aggr-spark__chart">
			<div class="aggr-spark__canvas">
				<?php foreach ( $aggr_spark_ticks as $aggr_spark_tick ) : ?>
					<span class="aggr-spark__grid<?php echo 0.0 === $aggr_spark_tick ? ' aggr-spark__grid--base' : ''; ?>" style="top: <?php echo esc_attr( $aggr_spark_pos( $aggr_spark_tick ) ); ?>%" aria-hidden="true"></span>
				<?php endforeach; ?>
				<svg class="aggr-spark__svg" viewBox="0 0 1000 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
					<defs>
						<linearGradient id="aggr-spark-fill" x1="0" y1="0" x2="0" y2="1">
							<stop offset="0" class="aggr-spark__fill-top"/>
							<stop offset="1" class="aggr-spark__fill-bottom"/>
						</linearGradient>
					</defs>
					<path d="<?php echo esc_attr( $aggr_spark_area ); ?>" fill="url(#aggr-spark-fill)"/>
					<?php if ( '' !== $aggr_spark_previous ) : ?>
						<path class="aggr-spark__line aggr-spark__line--previous" d="<?php echo esc_attr( $aggr_spark_previous ); ?>" vector-effect="non-scaling-stroke"/>
					<?php endif; ?>
					<?php if ( $aggr_spark_split > 1 ) : ?>
						<path class="aggr-spark__line" d="<?php echo esc_attr( $aggr_spark_line ); ?>" vector-effect="non-scaling-stroke"/>
					<?php endif; ?>
					<?php if ( '' !== $aggr_spark_dashed ) : ?>
						<path class="aggr-spark__line aggr-spark__line--counting" d="<?php echo esc_attr( $aggr_spark_dashed ); ?>" vector-effect="non-scaling-stroke"/>
					<?php endif; ?>
				</svg>

				<ol class="aggr-spark__track" aria-label="<?php echo esc_attr( $aggr_spark_label ); ?>">
					<?php foreach ( $aggr_series as $aggr_index => $aggr_bar ) : ?>
						<?php
						$aggr_bar_count = (int) $aggr_bar['impressions'];
						$aggr_bar_line  = static fn ( int $shown, int $clicked ): string => sprintf(
							/* translators: 1: impressions count. 2: clicks count. */
							__( 'Impressions: %1$s · Clicks: %2$s', 'aggressive-ads' ),
							number_format_i18n( $shown ),
							number_format_i18n( $clicked )
						);
						$aggr_bar_now   = $aggr_bar_line( $aggr_bar_count, (int) $aggr_bar['clicks'] );
						$aggr_bar_then  = $aggr_spark_has_prev ? $aggr_bar_line( (int) $aggr_bar['previous'], (int) $aggr_bar['previous_clicks'] ) : '';
						$aggr_bar_text  = (string) $aggr_bar['date'] . ': ' . $aggr_bar_now
							. ( '' === $aggr_bar_then ? '' : '. ' . sprintf(
								/* translators: 1: the compared day, e.g. Fri, Sep 4. 2: its impressions and clicks. */
								__( 'Previous period, %1$s: %2$s', 'aggressive-ads' ),
								(string) $aggr_bar['previous_date'],
								$aggr_bar_then
							) );
						?>
						<li class="aggr-spark__day<?php echo $aggr_index === $aggr_spark_days - 1 ? ' aggr-spark__day--last' : ''; ?>">
							<span class="aggr-spark__dot" style="bottom: <?php echo esc_attr( Chart_Path::number( 100 * $aggr_bar_count / $aggr_spark_top ) ); ?>%" aria-hidden="true"></span>
							<span class="aggr-sr"><?php echo esc_html( $aggr_bar_text ); ?></span>
							<span class="aggr-spark__tip" aria-hidden="true">
								<span class="aggr-spark__tip-date"><?php echo esc_html( (string) $aggr_bar['date'] ); ?></span>
								<span class="aggr-spark__tip-row">
									<span class="aggr-spark__tip-name"><?php esc_html_e( 'This period', 'aggressive-ads' ); ?></span>
									<span><?php echo esc_html( $aggr_bar_now ); ?></span>
								</span>
								<?php if ( '' !== $aggr_bar_then ) : ?>
									<span class="aggr-spark__tip-row aggr-spark__tip-row--previous">
										<span class="aggr-spark__tip-name"><?php echo esc_html( (string) $aggr_bar['previous_date'] ); ?></span>
										<span><?php echo esc_html( $aggr_bar_then ); ?></span>
									</span>
								<?php endif; ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ol>
			</div>

			<?php
			/*
			 * A label every few days rather than one per day or only the two
			 * ends: a per-day label is a smear over a quarter, and two ends leave
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

		<div class="aggr-spark__ends" aria-hidden="true">
			<span class="aggr-spark__end" style="top: <?php echo esc_attr( Chart_Path::number( $aggr_end_now ) ); ?>%"><?php esc_html_e( 'Now', 'aggressive-ads' ); ?></span>
			<?php if ( $aggr_spark_has_prev ) : ?>
				<span class="aggr-spark__end aggr-spark__end--previous" style="top: <?php echo esc_attr( Chart_Path::number( $aggr_end_prev ) ); ?>%"><?php esc_html_e( 'Before', 'aggressive-ads' ); ?></span>
			<?php endif; ?>
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
		<?php if ( isset( $aggr_export_campaign ) && $aggr_export_campaign > 0 ) : ?>
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) (int) $aggr_export_campaign ); ?>">
		<?php endif; ?>
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
