<?php
/**
 * Portal navigation.
 *
 * A <nav> with a real list and a real current-page marker. aria-current is what
 * a screen reader announces; the visual treatment keys off the same attribute
 * rather than a second class, so the two cannot disagree.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Portal\Request;
use LAAO_Advertiser_Portal\Portal\Router;
use LAAO_Advertiser_Portal\Portal\Routes;

$laao_ads_request = LAAO_Advertiser_Portal\Plugin::instance()->container()->get( Router::class )->request();
$laao_ads_current = null !== $laao_ads_request ? $laao_ads_request->route : '';

$laao_ads_items = array(
	Request::ROUTE_DASHBOARD    => __( 'Dashboard', 'laao-advertiser-portal' ),
	Request::ROUTE_CAMPAIGNS    => __( 'Campaigns', 'laao-advertiser-portal' ),
	Request::ROUTE_ORGANIZATION => __( 'Organization', 'laao-advertiser-portal' ),
	Request::ROUTE_ACCOUNT      => __( 'Account', 'laao-advertiser-portal' ),
	Request::ROUTE_HELP         => __( 'Help', 'laao-advertiser-portal' ),
);
?>
<nav class="laao-ads-nav" aria-label="<?php esc_attr_e( 'Portal', 'laao-advertiser-portal' ); ?>">
	<ul class="laao-ads-nav__list">
		<?php foreach ( $laao_ads_items as $laao_ads_route => $laao_ads_label ) : ?>
			<?php $laao_ads_is_current = $laao_ads_route === $laao_ads_current; ?>
			<li class="laao-ads-nav__item">
				<a
					class="laao-ads-nav__link"
					href="<?php echo esc_url( Request::ROUTE_DASHBOARD === $laao_ads_route ? Routes::url() : Routes::url( $laao_ads_route ) ); ?>"
					<?php echo $laao_ads_is_current ? 'aria-current="page"' : ''; ?>
				>
					<?php echo esc_html( $laao_ads_label ); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
