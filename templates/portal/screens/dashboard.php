<?php
/**
 * Dashboard contents.
 *
 * Prioritises action over decoration. The design's impression, click, CTR and
 * spend tiles are deliberately absent: reporting is a later phase and there is
 * no data behind those numbers. A business dashboard showing invented figures
 * is worse than one showing fewer real ones — somebody will make a decision on
 * them.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\Campaign_Actions;
use LAAO_Advertiser_Portal\Portal\Request;
use LAAO_Advertiser_Portal\Portal\Routes;
use LAAO_Advertiser_Portal\Portal\View_Data;

$laao_ads_view      = Plugin::instance()->container()->get( View_Data::class );
$laao_ads_campaigns = $laao_ads_view->campaigns();
$laao_ads_user      = wp_get_current_user();
?>
<div class="laao-ads-pagehead">
	<div>
		<h1 class="laao-ads-title">
			<?php
			printf(
				/* translators: %s: the advertiser's display name. */
				esc_html__( 'Welcome back, %s', 'laao-advertiser-portal' ),
				esc_html( $laao_ads_user->display_name )
			);
			?>
		</h1>
		<p class="laao-ads-lede"><?php esc_html_e( 'Your campaigns and where each one has got to.', 'laao-advertiser-portal' ); ?></p>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::CREATE_ACTION ); ?>">
		<?php wp_nonce_field( Campaign_Actions::CREATE_ACTION ); ?>
		<button class="laao-ads-button" type="submit">
			<?php esc_html_e( 'Create campaign', 'laao-advertiser-portal' ); ?>
		</button>
	</form>
</div>

<div class="laao-ads-stats">
	<?php foreach ( $laao_ads_view->counts() as $laao_ads_stat ) : ?>
		<div class="laao-ads-stat">
			<div class="laao-ads-stat__label"><?php echo esc_html( (string) $laao_ads_stat['label'] ); ?></div>
			<div class="laao-ads-stat__value"><?php echo esc_html( number_format_i18n( (int) $laao_ads_stat['value'] ) ); ?></div>
		</div>
	<?php endforeach; ?>
</div>

<section class="laao-ads-panel" aria-labelledby="laao-ads-campaigns-heading">
	<h2 id="laao-ads-campaigns-heading" class="laao-ads-panel__head">
		<?php esc_html_e( 'Your campaigns', 'laao-advertiser-portal' ); ?>
	</h2>

	<?php
	$laao_ads_rows = $laao_ads_campaigns['rows'];

	require LAAO_ADS_PLUGIN_DIR . 'templates/portal/partials/campaign-table.php';
	?>
</section>
