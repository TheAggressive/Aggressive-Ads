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
 * totals with neither statement attached. Each also carries its change against
 * the equal window immediately before it, as text with a sign — the colour is
 * decoration over a figure that reads correctly without it.
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
$aggr_window    = $aggr_view->delivery_window();

$aggr_export_days = $aggr_window['export_days'];
$aggr_export_from = $aggr_window['export_from'];
$aggr_export_to   = $aggr_window['export_to'];
$aggr_user        = wp_get_current_user();
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
<form class="aggr-range" method="get" action="<?php echo esc_url( \Aggressive\Ads\Portal\Routes::url() ); ?>">
	<h2 class="aggr-sr"><?php esc_html_e( 'Choose a reporting window', 'aggressive-ads' ); ?></h2>

	<div class="aggr-range__field">
		<label for="aggr-range-from"><?php esc_html_e( 'From (UTC)', 'aggressive-ads' ); ?></label>
		<input type="date" id="aggr-range-from" name="from" value="<?php echo esc_attr( $aggr_window['from'] ); ?>">
	</div>

	<div class="aggr-range__field">
		<label for="aggr-range-to"><?php esc_html_e( 'To (UTC)', 'aggressive-ads' ); ?></label>
		<input type="date" id="aggr-range-to" name="to" value="<?php echo esc_attr( $aggr_window['to'] ); ?>">
	</div>

	<button class="aggr-button aggr-button--secondary" type="submit"><?php esc_html_e( 'Show', 'aggressive-ads' ); ?></button>

	<?php
	/*
	 * The presets are links rather than a second control. A select beside two
	 * date inputs asks the reader which one wins; a link that fills the same
	 * range in answers that by not competing.
	 */
	?>
	<ul class="aggr-range__presets">
		<?php foreach ( \Aggressive\Ads\Workflow\Reporting_Read::WINDOWS as $aggr_preset ) : ?>
			<li>
				<a href="<?php echo esc_url( add_query_arg( 'days', (int) $aggr_preset, \Aggressive\Ads\Portal\Routes::url() ) ); ?>">
					<?php
					printf(
						/* translators: %d: number of days. */
						esc_html( _n( 'Last %d day', 'Last %d days', (int) $aggr_preset, 'aggressive-ads' ) ),
						(int) $aggr_preset
					);
					?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</form>

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
<p class="aggr-hint">
	<?php
	printf(
		/* translators: %s: the window the figures cover, e.g. 1 August to 30 August 2026 (UTC). */
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
