<?php
/**
 * Dashboard contents.
 *
 * Campaign-by-state tiles always ship. Impression, click and CTR tiles, the
 * daily chart and table impressions appear only when Reporting is on, and
 * they read `aggr_rollups` — never invented zeros. Spend stays absent until
 * billing has a source.
 *
 * The delivery tiles cover a bounded window and say which one, in UTC, along
 * with the first day whose figures may still move. Each also carries its
 * change against the equal window immediately before it, as text with a sign —
 * the colour is decoration over a figure that reads correctly without it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Domain\Campaign_Filter;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Security\Capabilities;

$aggr_view      = Plugin::instance()->container()->get( View_Data::class );
$aggr_campaigns = $aggr_view->campaigns( 1, '', '', 5 );
$aggr_delivery  = $aggr_view->delivery_counts();
$aggr_series    = $aggr_view->delivery_series();
$aggr_range     = $aggr_view->delivery_range_label();
$aggr_freshness = $aggr_view->delivery_freshness_note();
$aggr_counting  = $aggr_view->delivery_counting_from();
$aggr_window    = $aggr_view->delivery_window();

$aggr_user       = wp_get_current_user();
$aggr_list_url   = Routes::url( Request::ROUTE_CAMPAIGNS );
$aggr_can_create = current_user_can( Capabilities::SUBMIT_CAMPAIGN );

$aggr_tile_hints = array(
	Campaign_Filter::RUNNING   => __( 'Live or paused', 'aggressive-ads' ),
	Campaign_Filter::IN_REVIEW => __( 'With the review team', 'aggressive-ads' ),
	Campaign_Filter::ATTENTION => __( 'Changes requested', 'aggressive-ads' ),
);

// Every campaign waiting on the advertiser, the first few named with why.
$aggr_attention = $aggr_view->attention();
?>
<div class="aggr-pagehead">
	<div>
		<p class="aggr-eyebrow"><?php esc_html_e( 'Overview', 'aggressive-ads' ); ?></p>
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

	<?php if ( $aggr_can_create ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::CREATE_ACTION ); ?>">
			<?php wp_nonce_field( Campaign_Actions::CREATE_ACTION ); ?>
			<button class="aggr-button" type="submit">
				<?php esc_html_e( 'New campaign', 'aggressive-ads' ); ?>
			</button>
		</form>
	<?php endif; ?>
</div>

<?php
/*
 * Where each campaign has got to — deliberately not `aggr-stat`.
 *
 * These are all-time pipeline counts and the tiles below them are delivery for
 * a chosen window. Rendering both as the same card put the window picker
 * between two rows that looked identical, so narrowing to seven days read as
 * "3 campaigns ran this week": a correct number answering a question nobody
 * asked, which is the same failure as a wrong one.
 *
 * Each count links to the campaigns it counted, and a zero is still a link.
 */
?>
<ul class="aggr-pipeline">
	<?php foreach ( $aggr_view->counts() as $aggr_stat ) : ?>
		<li class="aggr-pipeline__item">
			<a class="aggr-pipeline__link" href="<?php echo esc_url( add_query_arg( 'status', (string) $aggr_stat['filter'], $aggr_list_url ) ); ?>">
				<span class="aggr-pipeline__label"><?php echo esc_html( (string) $aggr_stat['label'] ); ?></span>
				<span class="aggr-pipeline__value"><?php echo esc_html( number_format_i18n( (int) $aggr_stat['value'] ) ); ?></span>
				<span class="aggr-pipeline__hint"><?php echo esc_html( $aggr_tile_hints[ (string) $aggr_stat['filter'] ] ?? '' ); ?></span>
			</a>
		</li>
	<?php endforeach; ?>
	<li class="aggr-pipeline__item">
		<a class="aggr-pipeline__link" href="<?php echo esc_url( $aggr_list_url ); ?>">
			<span class="aggr-pipeline__label"><?php esc_html_e( 'All campaigns', 'aggressive-ads' ); ?></span>
			<span class="aggr-pipeline__value"><?php echo esc_html( number_format_i18n( (int) $aggr_campaigns['total'] ) ); ?></span>
			<span class="aggr-pipeline__hint"><?php esc_html_e( 'Since you joined', 'aggressive-ads' ); ?></span>
		</a>
	</li>
</ul>

<?php
$aggr_delivery_base   = Routes::url();
$aggr_delivery_title  = __( 'Native delivery', 'aggressive-ads' );
$aggr_export_campaign = 0;

require AGGR_PLUGIN_DIR . 'templates/portal/partials/delivery-card.php';
?>

<?php
/*
 * **Choosing what to buy starts the campaign.** Each package posts the create
 * action with its id, and the draft opens on the dates. One form with a submit
 * button per package: the button pressed posts its own `package_id`, and one
 * nonce serves them all.
 */
$aggr_start_packages = $aggr_can_create ? $aggr_view->package_options() : array();
?>
<div class="aggr-columns aggr-dashboard">
	<section class="aggr-panel aggr-columns__main" aria-labelledby="aggr-campaigns-heading">
		<div class="aggr-panel__headrow">
			<h2 id="aggr-campaigns-heading" class="aggr-panel__head">
				<?php esc_html_e( 'Your campaigns', 'aggressive-ads' ); ?>
			</h2>
			<a class="aggr-panel__link" href="<?php echo esc_url( $aggr_list_url ); ?>"><?php esc_html_e( 'View all', 'aggressive-ads' ); ?></a>
		</div>

		<?php
		$aggr_rows          = $aggr_campaigns['rows'];
		$aggr_show_metrics  = ! empty( $aggr_campaigns['show_metrics'] );
		$aggr_table_compact = true;

		require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-table.php';
		?>
	</section>

	<aside class="aggr-columns__side">
		<?php if ( $aggr_attention['total'] > 0 ) : ?>
			<section class="aggr-summary aggr-attention" aria-labelledby="aggr-attention-heading">
				<div class="aggr-summary__head">
					<h2 id="aggr-attention-heading" class="aggr-eyebrow"><?php esc_html_e( 'Needs your attention', 'aggressive-ads' ); ?></h2>
					<span class="aggr-pill aggr-pill--pending"><?php echo esc_html( number_format_i18n( $aggr_attention['total'] ) ); ?></span>
				</div>
				<ul class="aggr-attention__list">
					<?php foreach ( array_slice( $aggr_attention['rows'], 0, 5 ) as $aggr_waiting ) : ?>
						<li class="aggr-attention__item">
							<span class="aggr-attention__name"><?php echo esc_html( $aggr_waiting['title'] ); ?></span>
							<span class="aggr-hint"><?php echo esc_html( $aggr_waiting['reason'] ); ?></span>
							<a class="aggr-attention__action" href="<?php echo esc_url( $aggr_waiting['url'] ); ?>"><?php echo esc_html( $aggr_waiting['action'] ); ?><span class="aggr-sr">: <?php echo esc_html( $aggr_waiting['title'] ); ?></span></a>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $aggr_attention['total'] > 5 ) : ?>
					<a class="aggr-panel__link" href="<?php echo esc_url( add_query_arg( 'status', Campaign_Filter::ATTENTION, $aggr_list_url ) ); ?>">
						<?php
						printf(
							/* translators: %s: number of further campaigns needing attention. */
							esc_html( _n( 'And %s more', 'And %s more', $aggr_attention['total'] - 5, 'aggressive-ads' ) ),
							esc_html( number_format_i18n( $aggr_attention['total'] - 5 ) )
						);
						?>
					</a>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php if ( array() !== $aggr_start_packages ) : ?>
			<section class="aggr-summary" aria-labelledby="aggr-start-heading">
				<div>
					<h2 id="aggr-start-heading" class="aggr-eyebrow"><?php esc_html_e( 'Start a campaign', 'aggressive-ads' ); ?></h2>
					<p class="aggr-hint"><?php esc_html_e( 'Pick a package. Dates and ads come next.', 'aggressive-ads' ); ?></p>
				</div>

				<form class="aggr-start" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::CREATE_ACTION ); ?>">
					<?php wp_nonce_field( Campaign_Actions::CREATE_ACTION ); ?>
					<?php foreach ( $aggr_start_packages as $aggr_start_package ) : ?>
						<button class="aggr-start__choice" type="submit" name="package_id" value="<?php echo esc_attr( (string) $aggr_start_package['id'] ); ?>">
							<span class="aggr-start__text">
								<span class="aggr-start__name"><?php echo esc_html( (string) $aggr_start_package['name'] ); ?></span>
								<span class="aggr-start__meta">
									<?php
									printf(
										/* translators: 1: duration, e.g. 30 days. 2: number of ad sizes. */
										esc_html( _n( '%1$s · %2$d size', '%1$s · %2$d sizes', count( $aggr_start_package['sizes'] ), 'aggressive-ads' ) ),
										esc_html( (string) $aggr_start_package['duration'] ),
										(int) count( $aggr_start_package['sizes'] )
									);
									?>
								</span>
							</span>
							<span class="aggr-start__price"><?php echo esc_html( (string) $aggr_start_package['price'] ); ?></span>
						</button>
					<?php endforeach; ?>
				</form>
			</section>
		<?php endif; ?>
	</aside>
</div>
