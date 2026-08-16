<?php
/**
 * Campaign list contents.
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
use Aggressive\Ads\Security\Capabilities;

$aggr_view      = Plugin::instance()->container()->get( View_Data::class );
$aggr_campaigns = $aggr_view->campaigns();
$aggr_notice    = Campaign_Actions::request_notice();
$aggr_error     = Campaign_Actions::request_error_code();
?>
<?php if ( 'error' === $aggr_notice ) : ?>
	<div class="aggr-alert aggr-alert--error" role="alert">
		<p><?php echo esc_html( Campaign_Actions::error_message( $aggr_error ) ); ?></p>
	</div>
<?php elseif ( 'cancelled' === $aggr_notice ) : ?>
	<div class="aggr-alert aggr-alert--success" role="status">
		<p><?php esc_html_e( 'Campaign ended. It stays in your list as cancelled, along with anything it delivered.', 'aggressive-ads' ); ?></p>
	</div>
<?php endif; ?>

<div class="aggr-pagehead">
	<div>
		<h1 class="aggr-title"><?php esc_html_e( 'Campaigns', 'aggressive-ads' ); ?></h1>
		<p class="aggr-lede">
			<?php
			printf(
				/* translators: %s: number of campaigns. */
				esc_html( _n( '%s campaign', '%s campaigns', (int) $aggr_campaigns['total'], 'aggressive-ads' ) ),
				esc_html( number_format_i18n( (int) $aggr_campaigns['total'] ) )
			);
			?>
		</p>
	</div>

	<?php if ( current_user_can( Capabilities::SUBMIT_CAMPAIGN ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::CREATE_ACTION ); ?>">
			<?php wp_nonce_field( Campaign_Actions::CREATE_ACTION ); ?>
			<button class="aggr-button" type="submit">
				<?php esc_html_e( 'Create campaign', 'aggressive-ads' ); ?>
			</button>
		</form>
	<?php endif; ?>
</div>

<section class="aggr-panel">
	<?php
	$aggr_rows         = $aggr_campaigns['rows'];
	$aggr_show_metrics = ! empty( $aggr_campaigns['show_metrics'] );

	require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-table.php';
	?>
</section>
