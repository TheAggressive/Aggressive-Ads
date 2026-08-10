<?php
/**
 * Shown for a campaign id that resolves to nothing the caller may read.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Portal\Request;
use LAAO_Advertiser_Portal\Portal\Routes;

?>
<div class="laao-ads-pagehead">
	<div>
		<h1 class="laao-ads-title"><?php esc_html_e( 'Campaign not found', 'laao-advertiser-portal' ); ?></h1>
		<p class="laao-ads-lede"><?php esc_html_e( 'This campaign does not exist, or it is not one of yours.', 'laao-advertiser-portal' ); ?></p>
	</div>
</div>

<p>
	<a class="laao-ads-button" href="<?php echo esc_url( Routes::url( Request::ROUTE_CAMPAIGNS ) ); ?>">
		<?php esc_html_e( 'Back to campaigns', 'laao-advertiser-portal' ); ?>
	</a>
</p>
