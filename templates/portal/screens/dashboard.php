<?php
/**
 * Dashboard contents.
 *
 * Campaign-by-state tiles always ship. Impression, click and CTR tiles, a
 * seven-day sparkline, and table CTR appear only when Reporting is on, and
 * they read `aggr_rollups` — never invented zeros. Spend stays absent until
 * billing has a source.
 *
 * The delivery tiles cover a bounded window and say which one, in UTC, along
 * with the first day whose figures may still move. They used to be all-time
 * totals with neither statement attached.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\View_Data;

$aggr_view      = Plugin::instance()->container()->get( View_Data::class );
$aggr_campaigns = $aggr_view->campaigns();
$aggr_delivery  = $aggr_view->delivery_counts();
$aggr_series    = $aggr_view->delivery_series();
$aggr_range     = $aggr_view->delivery_range_label();
$aggr_freshness = $aggr_view->delivery_freshness_note();
$aggr_user      = wp_get_current_user();
?>
<div class="aggr-pagehead">
	<div>
		<h1 class="aggr-title">
			<?php
			printf(
				/* translators: %s: the advertiser's display name. */
				esc_html__( 'Welcome back, %s', 'aggressive-ads' ),
				esc_html( $aggr_user->display_name )
			);
			?>
		</h1>
		<p class="aggr-lede">
			<?php
			echo array() === $aggr_delivery
				? esc_html__( 'Your campaigns and where each one has got to.', 'aggressive-ads' )
				: esc_html__( 'Your campaigns, and how they are delivering.', 'aggressive-ads' );
			?>
		</p>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::CREATE_ACTION ); ?>">
		<?php wp_nonce_field( Campaign_Actions::CREATE_ACTION ); ?>
		<button class="aggr-button" type="submit">
			<?php esc_html_e( 'Create campaign', 'aggressive-ads' ); ?>
		</button>
	</form>
</div>

<div class="aggr-stats">
	<?php foreach ( $aggr_view->counts() as $aggr_stat ) : ?>
		<div class="aggr-stat">
			<div class="aggr-stat__label"><?php echo esc_html( (string) $aggr_stat['label'] ); ?></div>
			<div class="aggr-stat__value"><?php echo esc_html( number_format_i18n( (int) $aggr_stat['value'] ) ); ?></div>
		</div>
	<?php endforeach; ?>
</div>

<?php
if ( array() !== $aggr_delivery ) :
	?>
<div class="aggr-stats" aria-labelledby="aggr-delivery-heading">
	<h2 id="aggr-delivery-heading" class="aggr-sr">
		<?php
		printf(
			/* translators: %s: the window the figures cover, e.g. Last 30 days (UTC). */
			esc_html__( 'Native delivery, %s', 'aggressive-ads' ),
			esc_html( $aggr_range )
		);
		?>
	</h2>
	<?php foreach ( $aggr_delivery as $aggr_stat ) : ?>
		<div class="aggr-stat">
			<div class="aggr-stat__label"><?php echo esc_html( (string) $aggr_stat['label'] ); ?></div>
			<div class="aggr-stat__value"><?php echo esc_html( (string) $aggr_stat['value'] ); ?></div>
		</div>
	<?php endforeach; ?>
</div>
<p class="aggr-hint">
	<?php
	printf(
		/* translators: %s: the window the figures cover, e.g. Last 30 days (UTC). */
		esc_html__( 'Impressions and clicks from native delivery. %s.', 'aggressive-ads' ),
		esc_html( $aggr_range )
	);
	?>
	<?php if ( '' !== $aggr_freshness ) : ?>
		<span class="aggr-hint__freshness"><?php echo esc_html( $aggr_freshness ); ?></span>
	<?php endif; ?>
</p>
	<?php
endif;
?>

<div class="<?php echo array() === $aggr_series ? 'aggr-dashboard' : 'aggr-dashboard aggr-dashboard--split'; ?>">
	<section class="aggr-panel" aria-labelledby="aggr-campaigns-heading">
		<h2 id="aggr-campaigns-heading" class="aggr-panel__head">
			<?php esc_html_e( 'Your campaigns', 'aggressive-ads' ); ?>
		</h2>

		<?php
		$aggr_rows         = $aggr_campaigns['rows'];
		$aggr_show_metrics = ! empty( $aggr_campaigns['show_metrics'] );

		require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-table.php';
		?>
	</section>

	<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/sparkline.php'; ?>
</div>
