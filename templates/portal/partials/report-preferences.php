<?php
/**
 * Personal report delivery controls on Account settings.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Report_Preference_Actions;

$aggr_report_preferences = Plugin::instance()->container()->get( Report_Preference_Actions::class )->view_data();
Report_Preference_Actions::notice();
if ( null === $aggr_report_preferences ) {
	return;
}
?>
<section class="aggr-panel aggr-panel--padded" aria-labelledby="aggr-report-emails">
	<h2 id="aggr-report-emails" class="aggr-step-card__title"><?php esc_html_e( 'Report emails', 'aggressive-ads' ); ?></h2>
	<?php if ( $aggr_report_preferences['unavailable'] ) : ?>
		<p class="aggr-hint"><?php esc_html_e( 'Report preferences could not be loaded. Please try again.', 'aggressive-ads' ); ?></p>
	<?php else : ?>
		<p class="aggr-hint">
			<?php printf( /* translators: 1: organization name. 2: subscriber email address. */ esc_html__( 'Send me a delivery summary for %1$s at %2$s.', 'aggressive-ads' ), esc_html( $aggr_report_preferences['org'] ), esc_html( $aggr_report_preferences['email'] ) ); ?>
		</p>
		<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Report_Preference_Actions::SAVE_ACTION ); ?>">
			<?php wp_nonce_field( Report_Preference_Actions::SAVE_ACTION ); ?>
			<div class="aggr-field">
				<label for="aggr-report-cadence"><?php esc_html_e( 'Report frequency', 'aggressive-ads' ); ?></label>
				<select id="aggr-report-cadence" name="cadence" aria-describedby="aggr-report-timing">
					<option value="off" <?php selected( $aggr_report_preferences['cadence'], 'off' ); ?>><?php esc_html_e( 'Off', 'aggressive-ads' ); ?></option>
					<option value="weekly" <?php selected( $aggr_report_preferences['cadence'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'aggressive-ads' ); ?></option>
					<option value="monthly" <?php selected( $aggr_report_preferences['cadence'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'aggressive-ads' ); ?></option>
				</select>
			</div>
			<p id="aggr-report-timing" class="aggr-hint">
				<?php printf( /* translators: %s: site timezone. */ esc_html__( 'Weekly reports cover Monday–Sunday and are scheduled on Monday after 9:00 a.m. Monthly reports cover the previous calendar month and are scheduled on the first after 9:00 a.m. Times follow %s; actual delivery can be later.', 'aggressive-ads' ), esc_html( $aggr_report_preferences['timezone'] ) ); ?>
			</p>
			<button class="aggr-button aggr-button--secondary" type="submit"><?php esc_html_e( 'Save report preferences', 'aggressive-ads' ); ?></button>
		</form>
		<p class="aggr-hint"><?php echo esc_html( $aggr_report_preferences['status'] ); ?></p>
		<?php if ( '' !== $aggr_report_preferences['next'] ) : ?>
			<p class="aggr-hint"><?php printf( /* translators: %s: next scheduled action time. */ esc_html__( 'Next scheduled run: %s', 'aggressive-ads' ), esc_html( $aggr_report_preferences['next'] ) ); ?></p>
		<?php endif; ?>
		<?php if ( '' !== $aggr_report_preferences['last_queued'] ) : ?>
			<p class="aggr-hint"><?php printf( /* translators: %s: last transport-accepted report time. */ esc_html__( 'Last queued for delivery: %s', 'aggressive-ads' ), esc_html( $aggr_report_preferences['last_queued'] ) ); ?></p>
		<?php endif; ?>
		<p class="aggr-hint"><?php esc_html_e( 'You can turn these emails off here at any time. Report and CSV links require signing in.', 'aggressive-ads' ); ?></p>
	<?php endif; ?>
</section>
