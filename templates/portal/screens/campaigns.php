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
		<p class="aggr-eyebrow"><?php esc_html_e( 'Campaigns', 'aggressive-ads' ); ?></p>
		<h1 class="aggr-title"><?php esc_html_e( 'Campaigns', 'aggressive-ads' ); ?></h1>
		<p class="aggr-lede"><?php esc_html_e( 'Every campaign, and where each one has got to.', 'aggressive-ads' ); ?></p>

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
			<p class="aggr-filter aggr-sr" role="status">
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
				<?php esc_html_e( 'New campaign', 'aggressive-ads' ); ?>
			</button>
		</form>
	<?php endif; ?>
</div>

<?php
/*
 * The same slices the dashboard counts, as tabs. Links, not buttons: each is a
 * URL somebody can keep, and the one on screen is marked current. Searching
 * the list is #296, drawn now where the design puts it.
 */
$aggr_all_total = (int) $aggr_view->campaigns( 1 )['total'];
$aggr_tabs      = array(
	array(
		'filter' => '',
		'label'  => __( 'All', 'aggressive-ads' ),
		'value'  => $aggr_all_total,
	),
);

foreach ( $aggr_view->counts() as $aggr_count ) {
	$aggr_tabs[] = array(
		'filter' => (string) $aggr_count['filter'],
		'label'  => (string) $aggr_count['label'],
		'value'  => (int) $aggr_count['value'],
	);
}
?>
<div class="aggr-listbar">
	<nav class="aggr-tabs" aria-label="<?php esc_attr_e( 'Filter campaigns', 'aggressive-ads' ); ?>">
		<?php foreach ( $aggr_tabs as $aggr_tab ) : ?>
			<a
				class="aggr-tabs__tab"
				href="<?php echo esc_url( '' === $aggr_tab['filter'] ? Routes::url( Request::ROUTE_CAMPAIGNS ) : add_query_arg( 'status', $aggr_tab['filter'], Routes::url( Request::ROUTE_CAMPAIGNS ) ) ); ?>"
				<?php echo $aggr_tab['filter'] === $aggr_filter ? 'aria-current="page"' : ''; ?>
			>
				<?php echo esc_html( $aggr_tab['label'] ); ?>
				<span class="aggr-tabs__count"><?php echo esc_html( number_format_i18n( $aggr_tab['value'] ) ); ?></span>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="aggr-search">
		<label class="aggr-sr" for="aggr-campaign-search"><?php esc_html_e( 'Search campaigns', 'aggressive-ads' ); ?></label>
		<input id="aggr-campaign-search" type="search" placeholder="<?php esc_attr_e( 'Search campaigns', 'aggressive-ads' ); ?>" disabled aria-describedby="aggr-search-note">
		<span id="aggr-search-note" class="aggr-sr"><?php esc_html_e( 'Searching is coming soon.', 'aggressive-ads' ); ?></span>
	</div>
</div>

<section class="aggr-panel">
	<?php
	$aggr_rows          = $aggr_campaigns['rows'];
	$aggr_show_metrics  = ! empty( $aggr_campaigns['show_metrics'] );
	$aggr_table_compact = false;

	require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-table.php';
	?>

	<?php if ( array() !== $aggr_rows ) : ?>
		<div class="aggr-panel__footrow">
			<span class="aggr-table__mono">
				<?php
				printf(
					/* translators: 1: campaigns on this page. 2: campaigns in this view. */
					esc_html__( 'Showing %1$s of %2$s', 'aggressive-ads' ),
					esc_html( number_format_i18n( count( $aggr_rows ) ) ),
					esc_html( number_format_i18n( (int) $aggr_campaigns['total'] ) )
				);
				?>
			</span>
			<?php if ( $aggr_show_metrics ) : ?>
				<span class="aggr-hint"><?php esc_html_e( 'Delivery figures are all-time, from native delivery.', 'aggressive-ads' ); ?></span>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</section>
