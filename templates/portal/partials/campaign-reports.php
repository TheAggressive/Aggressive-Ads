<?php
/**
 * Shared campaign goal summaries for the authenticated dashboard.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Campaign_Report_View_Data;
use Aggressive\Ads\Portal\Delivery_View_Data;
use Aggressive\Ads\Portal\View_Data;

$aggr_report_services = Plugin::instance()->container();
$aggr_campaign_report = $aggr_report_services->get( Campaign_Report_View_Data::class )->for_org(
	$aggr_report_services->get( View_Data::class )->org_id(),
	$aggr_report_services->get( Delivery_View_Data::class )->period(),
	time(),
	Campaign_Report_View_Data::requested_cursor()
);
if ( array() === $aggr_campaign_report ) {
	return;
}
?>
<section class="aggr-panel aggr-panel--padded aggr-campaign-reports" aria-labelledby="aggr-campaign-goals">
	<h2 id="aggr-campaign-goals" class="aggr-panel__head"><?php esc_html_e( 'Campaign goals and delivery', 'aggressive-ads' ); ?></h2>
	<?php if ( is_wp_error( $aggr_campaign_report ) ) : ?>
		<p role="alert"><?php echo esc_html( $aggr_campaign_report->get_error_message() ); ?></p>
	<?php else : ?>
		<p class="aggr-hint"><?php echo esc_html( $aggr_campaign_report['snapshot'] ); ?></p>
		<p class="aggr-hint"><?php echo esc_html( $aggr_campaign_report['period'] ); ?></p>
		<p class="aggr-hint"><?php echo esc_html( $aggr_campaign_report['freshness'] ); ?></p>
		<?php foreach ( $aggr_campaign_report['notes'] as $aggr_campaign_note ) : ?>
			<p class="aggr-hint"><?php echo esc_html( $aggr_campaign_note ); ?></p>
		<?php endforeach; ?>
		<?php if ( array() === $aggr_campaign_report['rows'] ) : ?>
			<p><?php esc_html_e( 'No delivery plans are available in this summary.', 'aggressive-ads' ); ?></p>
		<?php endif; ?>
		<?php foreach ( $aggr_campaign_report['rows'] as $aggr_campaign_row ) : ?>
			<details>
				<summary><?php echo esc_html( $aggr_campaign_row['campaign'] . ' — ' . $aggr_campaign_row['plan'] ); ?></summary>
				<dl class="aggr-plan-summary">
					<?php foreach ( $aggr_campaign_row['fields'] as $aggr_goal_field ) : ?>
						<div><dt><?php echo esc_html( $aggr_goal_field['label'] ); ?></dt><dd><?php echo esc_html( $aggr_goal_field['value'] ); ?></dd></div>
					<?php endforeach; ?>
				</dl>
				<p class="aggr-hint"><?php echo esc_html( $aggr_campaign_report['period'] ); ?></p>
				<dl class="aggr-plan-summary">
					<?php foreach ( $aggr_campaign_row['metrics'] as $aggr_period_metric ) : ?>
						<div><dt><?php echo esc_html( $aggr_period_metric['label'] ); ?></dt><dd><?php echo esc_html( $aggr_period_metric['value'] ); ?></dd></div>
					<?php endforeach; ?>
				</dl>
				<p><a href="<?php echo esc_url( $aggr_campaign_row['url'] ); ?>"><?php esc_html_e( 'Open campaign', 'aggressive-ads' ); ?></a></p>
			</details>
		<?php endforeach; ?>
		<?php if ( '' !== $aggr_campaign_report['limit_note'] ) : ?>
			<p class="aggr-hint"><?php echo esc_html( $aggr_campaign_report['limit_note'] ); ?></p>
			<a class="aggr-button aggr-button--secondary" href="<?php echo esc_url( $aggr_campaign_report['next_url'] ); ?>"><?php esc_html_e( 'Older delivery plans', 'aggressive-ads' ); ?></a>
		<?php endif; ?>
		<?php if ( '' !== $aggr_campaign_report['reset_url'] ) : ?>
			<a class="aggr-button aggr-button--secondary" href="<?php echo esc_url( $aggr_campaign_report['reset_url'] ); ?>"><?php esc_html_e( 'Newest delivery plans', 'aggressive-ads' ); ?></a>
		<?php endif; ?>
	<?php endif; ?>
</section>
