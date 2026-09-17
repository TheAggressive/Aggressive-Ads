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
use Aggressive\Ads\Repository\Campaign_Repository;
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

/*
 * Search and page are the same kind of state: read-only, the caller's own
 * campaigns only, and part of a URL somebody can keep. `search` and
 * `list_page` rather than `s` and `paged`, which WordPress reads as its own
 * site search and archive paging before the portal is reached.
 */
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list search and page, bounded below.
$aggr_search = isset( $_GET['search'] ) ? View_Data::search_term( sanitize_text_field( wp_unslash( $_GET['search'] ) ) ) : '';
$aggr_page   = isset( $_GET['list_page'] ) ? max( 1, absint( wp_unslash( $_GET['list_page'] ) ) ) : 1;
// phpcs:enable

$aggr_view      = Plugin::instance()->container()->get( View_Data::class );
$aggr_campaigns = $aggr_view->campaigns( $aggr_page, $aggr_filter, $aggr_search );
$aggr_list_base = Routes::url( Request::ROUTE_CAMPAIGNS );
$aggr_list_args = array_filter(
	array(
		'status' => $aggr_filter,
		'search' => $aggr_search,
	)
);
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
 * URL somebody can keep, and the one on screen is marked current. A search in
 * progress is kept when the slice changes; the counts are of the whole slice.
 */
$aggr_all_total = (int) $aggr_view->campaigns( 1, '', '', 1 )['total'];
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
				href="
				<?php
				echo esc_url(
					add_query_arg(
						array_filter(
							array(
								'status' => $aggr_tab['filter'],
								'search' => $aggr_search,
							)
						),
						$aggr_list_base
					)
				);
				?>
						"
				<?php echo $aggr_tab['filter'] === $aggr_filter ? 'aria-current="page"' : ''; ?>
			>
				<?php echo esc_html( $aggr_tab['label'] ); ?>
				<span class="aggr-tabs__count"><?php echo esc_html( number_format_i18n( $aggr_tab['value'] ) ); ?></span>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php // A GET form, so a search is a URL and works without script; Enter submits it. ?>
	<form class="aggr-search" role="search" method="get" action="<?php echo esc_url( $aggr_list_base ); ?>">
		<?php if ( '' !== $aggr_filter ) : ?>
			<input type="hidden" name="status" value="<?php echo esc_attr( $aggr_filter ); ?>">
		<?php endif; ?>
		<label class="aggr-sr" for="aggr-campaign-search"><?php esc_html_e( 'Search campaigns by name', 'aggressive-ads' ); ?></label>
		<input id="aggr-campaign-search" name="search" type="search" maxlength="100" value="<?php echo esc_attr( $aggr_search ); ?>" placeholder="<?php esc_attr_e( 'Search campaigns', 'aggressive-ads' ); ?>">
		<button class="aggr-button aggr-button--secondary" type="submit"><?php esc_html_e( 'Search', 'aggressive-ads' ); ?></button>
	</form>
</div>

<?php if ( '' !== $aggr_search ) : ?>
	<p class="aggr-filter" role="status">
		<?php
		printf(
			/* translators: 1: number of campaigns found. 2: the words searched for. */
			esc_html( _n( '%1$s campaign named like “%2$s”.', '%1$s campaigns named like “%2$s”.', (int) $aggr_campaigns['total'], 'aggressive-ads' ) ),
			esc_html( number_format_i18n( (int) $aggr_campaigns['total'] ) ),
			esc_html( $aggr_search )
		);
		?>
		<a href="<?php echo esc_url( add_query_arg( array_filter( array( 'status' => $aggr_filter ) ), $aggr_list_base ) ); ?>"><?php esc_html_e( 'Clear search', 'aggressive-ads' ); ?></a>
	</p>
<?php endif; ?>

<section class="aggr-panel">
	<?php
	$aggr_rows          = $aggr_campaigns['rows'];
	$aggr_show_metrics  = ! empty( $aggr_campaigns['show_metrics'] );
	$aggr_table_compact = false;
	?>

	<?php if ( array() === $aggr_rows && ( '' !== $aggr_search || $aggr_page > 1 ) ) : ?>
		<?php // Not "no campaigns yet": they have some, and none match what they asked for. ?>
		<div class="aggr-empty">
			<p class="aggr-empty__title"><?php esc_html_e( 'No campaigns match', 'aggressive-ads' ); ?></p>
			<p><a href="<?php echo esc_url( $aggr_list_base ); ?>"><?php esc_html_e( 'Show all campaigns', 'aggressive-ads' ); ?></a></p>
		</div>
	<?php else : ?>
		<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-table.php'; ?>
	<?php endif; ?>

	<?php if ( array() !== $aggr_rows ) : ?>
		<?php
		$aggr_page_size = Campaign_Repository::PAGE_SIZE;
		$aggr_first_row = ( (int) $aggr_campaigns['page'] - 1 ) * $aggr_page_size + 1;
		$aggr_next_url  = $aggr_page < (int) $aggr_campaigns['pages'] ? add_query_arg( array_merge( $aggr_list_args, array( 'list_page' => $aggr_page + 1 ) ), $aggr_list_base ) : '';

		/*
		 * The data `@aggr/list-more` needs to turn the page links into "Show
		 * more campaigns": where the next page is, how many there are, and every
		 * sentence it says, translated here rather than written in script.
		 */
		?>
		<div
			class="aggr-panel__footrow"
			data-aggr-list-more
			data-aggr-next="<?php echo esc_url( $aggr_next_url ); ?>"
			data-aggr-total="<?php echo esc_attr( (string) (int) $aggr_campaigns['total'] ); ?>"
			data-aggr-first="<?php echo esc_attr( (string) $aggr_first_row ); ?>"
			data-aggr-label-more="<?php esc_attr_e( 'Show more campaigns', 'aggressive-ads' ); ?>"
			data-aggr-label-loading="<?php esc_attr_e( 'Loading more campaigns…', 'aggressive-ads' ); ?>"
			data-aggr-label-error="<?php esc_attr_e( 'More campaigns could not be loaded. Try again, or use the page links.', 'aggressive-ads' ); ?>"
			data-aggr-label-count="<?php /* translators: 1: first campaign shown. 2: last campaign shown. 3: campaigns in this view. */ esc_attr_e( 'Showing %1$s–%2$s of %3$s', 'aggressive-ads' ); ?>"
			data-aggr-label-loaded="<?php /* translators: 1: campaigns just added. 2: campaigns now shown. 3: campaigns in this view. */ esc_attr_e( '%1$s more campaigns loaded. Showing %2$s of %3$s.', 'aggressive-ads' ); ?>"
			data-aggr-label-done="<?php /* translators: %s: number of campaigns shown. */ esc_attr_e( 'All %s campaigns shown.', 'aggressive-ads' ); ?>"
		>
			<span class="aggr-table__mono" data-aggr-list-count>
				<?php
				printf(
					/* translators: 1: first campaign shown. 2: last campaign shown. 3: campaigns in this view. */
					esc_html__( 'Showing %1$s–%2$s of %3$s', 'aggressive-ads' ),
					esc_html( number_format_i18n( $aggr_first_row ) ),
					esc_html( number_format_i18n( $aggr_first_row + count( $aggr_rows ) - 1 ) ),
					esc_html( number_format_i18n( (int) $aggr_campaigns['total'] ) )
				);
				?>
			</span>
			<?php if ( (int) $aggr_campaigns['pages'] > 1 ) : ?>
				<nav class="aggr-pager" aria-label="<?php esc_attr_e( 'Campaign pages', 'aggressive-ads' ); ?>">
					<?php if ( $aggr_page > 1 ) : ?>
						<a href="<?php echo esc_url( add_query_arg( array_merge( $aggr_list_args, 2 === $aggr_page ? array() : array( 'list_page' => $aggr_page - 1 ) ), $aggr_list_base ) ); ?>"><?php esc_html_e( 'Previous', 'aggressive-ads' ); ?></a>
					<?php endif; ?>
					<span class="aggr-table__mono">
						<?php
						printf(
							/* translators: 1: current page. 2: number of pages. */
							esc_html__( 'Page %1$s of %2$s', 'aggressive-ads' ),
							esc_html( number_format_i18n( $aggr_page ) ),
							esc_html( number_format_i18n( (int) $aggr_campaigns['pages'] ) )
						);
						?>
					</span>
					<?php if ( $aggr_page < (int) $aggr_campaigns['pages'] ) : ?>
						<a href="<?php echo esc_url( add_query_arg( array_merge( $aggr_list_args, array( 'list_page' => $aggr_page + 1 ) ), $aggr_list_base ) ); ?>"><?php esc_html_e( 'Next', 'aggressive-ads' ); ?></a>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
			<?php if ( $aggr_show_metrics ) : ?>
				<span class="aggr-hint"><?php esc_html_e( 'Delivery figures are all-time, from native delivery.', 'aggressive-ads' ); ?></span>
			<?php endif; ?>
			<p class="aggr-sr" role="status" aria-live="polite" data-aggr-list-status></p>
		</div>
	<?php endif; ?>
</section>
