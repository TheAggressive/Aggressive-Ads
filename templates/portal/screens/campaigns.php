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

use Aggressive\Ads\Domain\Campaign_Filter;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Portal_Notice;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Security\Capabilities;

/*
 * The slice the dashboard sent the reader here to see.
 *
 * Read-only navigation state, so no nonce: this selects which of the caller's
 * own campaigns are listed and can do nothing else. `Campaign_Filter` widens an
 * unknown slug to the whole list rather than narrowing it to nothing, so a
 * stale or hand-typed URL shows the advertiser their campaigns instead of an
 * empty page that reads as "you have none".
 */
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter, validated against a closed vocabulary below.
$aggr_requested = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
$aggr_filter    = Campaign_Filter::is_valid( $aggr_requested ) ? $aggr_requested : '';

$aggr_view      = Plugin::instance()->container()->get( View_Data::class );
$aggr_campaigns = $aggr_view->campaigns( 1, $aggr_filter );
$aggr_notice    = Campaign_Actions::request_notice();
$aggr_error     = Campaign_Actions::request_error_code();
?>
<?php
if ( 'error' === $aggr_notice ) {
	Portal_Notice::add( Campaign_Actions::error_message( $aggr_error ), 'error' );
} elseif ( 'cancelled' === $aggr_notice ) {
	Portal_Notice::add(
		__( 'Campaign ended. It stays in your list as cancelled, along with anything it delivered.', 'aggressive-ads' ),
		'success'
	);
}
?>

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

		<?php if ( '' !== $aggr_filter ) : ?>
			<?php
			/*
			 * The filter says what it is doing and how to stop.
			 *
			 * A list quietly showing a subset is the same defect as a wrong
			 * number: the reader counts what is in front of them and believes
			 * it is everything.
			 */
			?>
			<p class="aggr-filter" role="status">
				<?php
				printf(
					/* translators: %s: the slice being shown, e.g. Needs your attention. */
					esc_html__( 'Showing only: %s.', 'aggressive-ads' ),
					esc_html( $aggr_view->filter_label( $aggr_filter ) )
				);
				?>
				<a href="<?php echo esc_url( Routes::url( Request::ROUTE_CAMPAIGNS ) ); ?>">
					<?php esc_html_e( 'Show all campaigns', 'aggressive-ads' ); ?>
				</a>
			</p>
		<?php endif; ?>
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
