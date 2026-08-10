<?php
/**
 * Campaign list contents.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\View_Data;

$laao_ads_view      = Plugin::instance()->container()->get( View_Data::class );
$laao_ads_campaigns = $laao_ads_view->campaigns();
?>
<div class="laao-ads-pagehead">
	<div>
		<h1 class="laao-ads-title"><?php esc_html_e( 'Campaigns', 'laao-advertiser-portal' ); ?></h1>
		<p class="laao-ads-lede">
			<?php
			printf(
				/* translators: %s: number of campaigns. */
				esc_html( _n( '%s campaign', '%s campaigns', (int) $laao_ads_campaigns['total'], 'laao-advertiser-portal' ) ),
				esc_html( number_format_i18n( (int) $laao_ads_campaigns['total'] ) )
			);
			?>
		</p>
	</div>
</div>

<section class="laao-ads-panel">
	<?php
	$laao_ads_rows = $laao_ads_campaigns['rows'];

	require LAAO_ADS_PLUGIN_DIR . 'templates/portal/partials/campaign-table.php';
	?>
</section>
