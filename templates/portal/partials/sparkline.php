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
?>
<section class="aggr-panel" aria-labelledby="aggr-spark-heading">
	<h2 id="aggr-spark-heading" class="aggr-panel__head"><?php esc_html_e( 'Impressions', 'aggressive-ads' ); ?></h2>
	<p class="aggr-spark__lede"><?php echo esc_html( $aggr_range ); ?></p>
	<ol class="aggr-spark__track" aria-label="<?php echo esc_attr( $aggr_spark_label ); ?>">
		<?php foreach ( $aggr_series as $aggr_bar ) : ?>
			<li
				class="aggr-spark__bar"
				style="height: <?php echo esc_attr( (string) (int) $aggr_bar['height'] ); ?>%"
			>
				<span class="aggr-sr">
					<?php
					printf(
						/* translators: 1: weekday. 2: impression count. */
						esc_html( _n( '%1$s: %2$s impression', '%1$s: %2$s impressions', (int) $aggr_bar['impressions'], 'aggressive-ads' ) ),
						esc_html( (string) $aggr_bar['label'] ),
						esc_html( number_format_i18n( (int) $aggr_bar['impressions'] ) )
					);
					?>
				</span>
			</li>
		<?php endforeach; ?>
	</ol>
	<?php
	/*
	 * A label per bar reads well over a week and is unreadable over a quarter,
	 * where ninety of them collide into a grey smear. Past a fortnight only the
	 * ends are drawn — the per-bar figures are still announced to a screen
	 * reader from the list above, which is where the accessible equivalent
	 * lives, so nothing is lost by thinning what is decoration.
	 */
	?>
	<div class="aggr-spark__axis<?php echo count( $aggr_series ) > 14 ? ' aggr-spark__axis--ends' : ''; ?>" aria-hidden="true">
		<?php if ( count( $aggr_series ) > 14 ) : ?>
			<span><?php echo esc_html( (string) $aggr_series[0]['label'] ); ?></span>
			<span><?php echo esc_html( (string) $aggr_series[ array_key_last( $aggr_series ) ]['label'] ); ?></span>
		<?php else : ?>
			<?php foreach ( $aggr_series as $aggr_bar ) : ?>
				<span><?php echo esc_html( (string) $aggr_bar['label'] ); ?></span>
			<?php endforeach; ?>
		<?php endif; ?>
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
