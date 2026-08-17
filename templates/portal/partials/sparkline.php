<?php
/**
 * Seven-day impression sparkline.
 *
 * @package Aggressive\Ads
 *
 * @var list<array{day: string, label: string, impressions: int, height: int}> $aggr_series Daily bars.
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
	/* translators: 1: first weekday. 2: last weekday. */
	__( 'Daily impressions, %1$s to %2$s', 'aggressive-ads' ),
	$aggr_first,
	$aggr_last
);
?>
<section class="aggr-panel" aria-labelledby="aggr-spark-heading">
	<h2 id="aggr-spark-heading" class="aggr-panel__head"><?php esc_html_e( 'Impressions', 'aggressive-ads' ); ?></h2>
	<p class="aggr-spark__lede"><?php esc_html_e( 'Last 7 days', 'aggressive-ads' ); ?></p>
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
	<div class="aggr-spark__axis" aria-hidden="true">
		<?php foreach ( $aggr_series as $aggr_bar ) : ?>
			<span><?php echo esc_html( (string) $aggr_bar['label'] ); ?></span>
		<?php endforeach; ?>
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
		<input type="hidden" name="days" value="<?php echo esc_attr( (string) \Aggressive\Ads\Portal\Report_Actions::MAX_DAYS ); ?>">
		<?php wp_nonce_field( \Aggressive\Ads\Portal\Report_Actions::EXPORT_ACTION ); ?>
		<button type="submit" class="aggr-button aggr-button--secondary aggr-button--small">
			<?php
			printf(
				/* translators: %d: number of days covered by the export. */
				esc_html__( 'Download last %d days (CSV)', 'aggressive-ads' ),
				(int) \Aggressive\Ads\Portal\Report_Actions::MAX_DAYS
			);
			?>
		</button>
	</form>
</section>
