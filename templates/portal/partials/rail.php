<?php
/**
 * The portal's navigation rail.
 *
 * A <nav> with a real list and a real current-page marker. aria-current is what
 * a screen reader announces, and the visual treatment keys off that same
 * attribute rather than a second class — so the two cannot disagree.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\Request;
use LAAO_Advertiser_Portal\Portal\Router;
use LAAO_Advertiser_Portal\Portal\Routes;

$laao_ads_request = Plugin::instance()->container()->get( Router::class )->request();
$laao_ads_current = null !== $laao_ads_request ? $laao_ads_request->route : '';

$laao_ads_items = array(
	Request::ROUTE_DASHBOARD    => __( 'Dashboard', 'laao-advertiser-portal' ),
	Request::ROUTE_CAMPAIGNS    => __( 'Campaigns', 'laao-advertiser-portal' ),
	Request::ROUTE_ORGANIZATION => __( 'Organization', 'laao-advertiser-portal' ),
	Request::ROUTE_ACCOUNT      => __( 'Account', 'laao-advertiser-portal' ),
	Request::ROUTE_HELP         => __( 'Help', 'laao-advertiser-portal' ),
);
?>
<div class="laao-ads-rail">
	<a class="laao-ads-brand" href="<?php echo esc_url( Routes::url() ); ?>">
		<span class="laao-ads-brand__mark">LAAO</span>
		<span class="laao-ads-brand__sub"><?php esc_html_e( 'Advertiser Portal', 'laao-advertiser-portal' ); ?></span>
	</a>

	<nav aria-label="<?php esc_attr_e( 'Portal', 'laao-advertiser-portal' ); ?>">
		<ul class="laao-ads-nav__list">
			<?php foreach ( $laao_ads_items as $laao_ads_route => $laao_ads_label ) : ?>
				<?php
				$laao_ads_is_current = $laao_ads_route === $laao_ads_current;
				$laao_ads_href       = Routes::canonical( $laao_ads_route );
				?>
				<li>
					<a
						class="laao-ads-nav__link"
						href="<?php echo esc_url( $laao_ads_href ); ?>"
						<?php echo $laao_ads_is_current ? 'aria-current="page"' : ''; ?>
					>
						<span class="laao-ads-nav__icon">
							<?php
							$laao_ads_icon = $laao_ads_route;

							require LAAO_ADS_PLUGIN_DIR . 'templates/portal/partials/icon.php';
							?>
						</span>
						<span><?php echo esc_html( $laao_ads_label ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>
</div>
