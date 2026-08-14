<?php
/**
 * The portal's navigation rail.
 *
 * A <nav> with a real list and a real current-page marker. aria-current is what
 * a screen reader announces, and the visual treatment keys off that same
 * attribute rather than a second class — so the two cannot disagree.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Router;
use Aggressive\Ads\Portal\Routes;

$aggr_request = Plugin::instance()->container()->get( Router::class )->request();
$aggr_current = null !== $aggr_request ? $aggr_request->route : '';

$aggr_items = array(
	Request::ROUTE_DASHBOARD    => __( 'Dashboard', 'aggressive-ads' ),
	Request::ROUTE_CAMPAIGNS    => __( 'Campaigns', 'aggressive-ads' ),
	Request::ROUTE_ORGANIZATION => __( 'Organization', 'aggressive-ads' ),
	Request::ROUTE_ACCOUNT      => __( 'Account', 'aggressive-ads' ),
	Request::ROUTE_HELP         => __( 'Help', 'aggressive-ads' ),
);
?>
<div class="aggr-rail">
	<a class="aggr-brand" href="<?php echo esc_url( Routes::url() ); ?>">
		<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/brand.php'; ?>
	</a>

	<nav aria-label="<?php esc_attr_e( 'Portal', 'aggressive-ads' ); ?>">
		<ul class="aggr-nav__list">
			<?php foreach ( $aggr_items as $aggr_route => $aggr_label ) : ?>
				<?php
				$aggr_is_current = $aggr_route === $aggr_current;
				$aggr_href       = Routes::canonical( $aggr_route );
				?>
				<li>
					<a
						class="aggr-nav__link"
						href="<?php echo esc_url( $aggr_href ); ?>"
						<?php echo $aggr_is_current ? 'aria-current="page"' : ''; ?>
					>
						<span class="aggr-nav__icon">
							<?php
							$aggr_icon = $aggr_route;

							require AGGR_PLUGIN_DIR . 'templates/portal/partials/icon.php';
							?>
						</span>
						<span><?php echo esc_html( $aggr_label ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>
</div>
