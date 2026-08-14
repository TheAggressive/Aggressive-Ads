<?php
/**
 * Shown for a campaign id that resolves to nothing the caller may read.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;

?>
<div class="aggr-pagehead">
	<div>
		<h1 class="aggr-title"><?php esc_html_e( 'Campaign not found', 'aggressive-ads' ); ?></h1>
		<p class="aggr-lede"><?php esc_html_e( 'This campaign does not exist, or it is not one of yours.', 'aggressive-ads' ); ?></p>
	</div>
</div>

<p>
	<a class="aggr-button" href="<?php echo esc_url( Routes::url( Request::ROUTE_CAMPAIGNS ) ); ?>">
		<?php esc_html_e( 'Back to campaigns', 'aggressive-ads' ); ?>
	</a>
</p>
