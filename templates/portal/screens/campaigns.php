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
use LAAO_Advertiser_Portal\Portal\Campaign_Actions;
use LAAO_Advertiser_Portal\Portal\View_Data;
use LAAO_Advertiser_Portal\Security\Capabilities;

$laao_ads_view      = Plugin::instance()->container()->get( View_Data::class );
$laao_ads_campaigns = $laao_ads_view->campaigns();
$laao_ads_notice    = Campaign_Actions::request_notice();
$laao_ads_error     = Campaign_Actions::request_error_code();
?>
<?php if ( 'error' === $laao_ads_notice ) : ?>
	<div class="laao-ads-alert laao-ads-alert--error" role="alert">
		<p><?php echo esc_html( Campaign_Actions::error_message( $laao_ads_error ) ); ?></p>
	</div>
<?php endif; ?>

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

	<?php if ( current_user_can( Capabilities::SUBMIT_CAMPAIGN ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::CREATE_ACTION ); ?>">
			<?php wp_nonce_field( Campaign_Actions::CREATE_ACTION ); ?>
			<button class="laao-ads-button" type="submit">
				<?php esc_html_e( 'Create campaign', 'laao-advertiser-portal' ); ?>
			</button>
		</form>
	<?php endif; ?>
</div>

<section class="laao-ads-panel">
	<?php
	$laao_ads_rows = $laao_ads_campaigns['rows'];

	require LAAO_ADS_PLUGIN_DIR . 'templates/portal/partials/campaign-table.php';
	?>
</section>
