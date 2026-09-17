<?php
/**
 * Dashboard contents.
 *
 * Campaign-by-state tiles always ship. Impression, click and CTR tiles, the
 * daily chart and table impressions appear only when Reporting is on, and
 * they read `aggr_rollups` — never invented zeros. Spend stays absent until
 * billing has a source.
 *
 * The delivery tiles cover a bounded window and say which one, in UTC, along
 * with the first day whose figures may still move. Each also carries its
 * change against the equal window immediately before it, as text with a sign —
 * the colour is decoration over a figure that reads correctly without it.
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
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Reporting_Read;

$aggr_view      = Plugin::instance()->container()->get( View_Data::class );
$aggr_campaigns = $aggr_view->campaigns();
$aggr_delivery  = $aggr_view->delivery_counts();
$aggr_series    = $aggr_view->delivery_series();
$aggr_range     = $aggr_view->delivery_range_label();
$aggr_freshness = $aggr_view->delivery_freshness_note();
$aggr_counting  = $aggr_view->delivery_counting_from();
$aggr_window    = $aggr_view->delivery_window();

$aggr_export_days = $aggr_window['export_days'];
$aggr_export_from = $aggr_window['export_from'];
$aggr_export_to   = $aggr_window['export_to'];
$aggr_user        = wp_get_current_user();
$aggr_list_url    = Routes::url( Request::ROUTE_CAMPAIGNS );
$aggr_can_create  = current_user_can( Capabilities::SUBMIT_CAMPAIGN );

$aggr_tile_hints = array(
	Campaign_Filter::RUNNING   => __( 'Live or paused', 'aggressive-ads' ),
	Campaign_Filter::IN_REVIEW => __( 'With the review team', 'aggressive-ads' ),
	Campaign_Filter::ATTENTION => __( 'Changes requested', 'aggressive-ads' ),
);

// The first campaign that is waiting on the advertiser, with the review team's reason.
$aggr_attention = null;

foreach ( $aggr_campaigns['rows'] as $aggr_candidate ) {
	if ( in_array( (string) $aggr_candidate['status'], Campaign_Filter::statuses( Campaign_Filter::ATTENTION ), true ) && '' !== (string) ( $aggr_candidate['review_notes'] ?? '' ) ) {
		$aggr_attention = $aggr_candidate;
		break;
	}
}
?>
<div class="aggr-pagehead">
	<div>
		<p class="aggr-eyebrow"><?php esc_html_e( 'Overview', 'aggressive-ads' ); ?></p>
		<h1 class="aggr-title">
			<?php
			printf(
				/* translators: %s: the advertiser's display name. */
				esc_html__( 'Welcome back, %s', 'aggressive-ads' ),
				esc_html( $aggr_user->display_name )
			);
			?>
		</h1>
		<p class="aggr-lede">
			<?php
			echo array() === $aggr_delivery
				? esc_html__( 'Your campaigns and where each one has got to.', 'aggressive-ads' )
				: esc_html__( 'Your campaigns, and how they are delivering.', 'aggressive-ads' );
			?>
		</p>
	</div>

	<?php if ( $aggr_can_create ) : ?>
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
 * Where each campaign has got to — deliberately not `aggr-stat`.
 *
 * These are all-time pipeline counts and the tiles below them are delivery for
 * a chosen window. Rendering both as the same card put the window picker
 * between two rows that looked identical, so narrowing to seven days read as
 * "3 campaigns ran this week": a correct number answering a question nobody
 * asked, which is the same failure as a wrong one.
 *
 * Each count links to the campaigns it counted, and a zero is still a link.
 */
?>
<ul class="aggr-pipeline">
	<?php foreach ( $aggr_view->counts() as $aggr_stat ) : ?>
		<li class="aggr-pipeline__item">
			<a class="aggr-pipeline__link" href="<?php echo esc_url( add_query_arg( 'status', (string) $aggr_stat['filter'], $aggr_list_url ) ); ?>">
				<span class="aggr-pipeline__label"><?php echo esc_html( (string) $aggr_stat['label'] ); ?></span>
				<span class="aggr-pipeline__value"><?php echo esc_html( number_format_i18n( (int) $aggr_stat['value'] ) ); ?></span>
				<span class="aggr-pipeline__hint"><?php echo esc_html( $aggr_tile_hints[ (string) $aggr_stat['filter'] ] ?? '' ); ?></span>
			</a>
		</li>
	<?php endforeach; ?>
	<li class="aggr-pipeline__item">
		<a class="aggr-pipeline__link" href="<?php echo esc_url( $aggr_list_url ); ?>">
			<span class="aggr-pipeline__label"><?php esc_html_e( 'All campaigns', 'aggressive-ads' ); ?></span>
			<span class="aggr-pipeline__value"><?php echo esc_html( number_format_i18n( (int) $aggr_campaigns['total'] ) ); ?></span>
			<span class="aggr-pipeline__hint"><?php esc_html_e( 'Since you joined', 'aggressive-ads' ); ?></span>
		</a>
	</li>
</ul>

<?php
/*
 * The window picker sits in this section's header, not above it.
 *
 * Its scope is whatever it is next to. Floating between the pipeline counts
 * and these tiles, it claimed both, and the pipeline counts have no window.
 */
if ( array() !== $aggr_delivery ) :
	?>
<section class="aggr-delivery" aria-labelledby="aggr-delivery-heading aggr-delivery-window">
<div class="aggr-delivery__head">
	<div>
		<h2 id="aggr-delivery-heading" class="aggr-delivery__title">
			<?php esc_html_e( 'Native delivery', 'aggressive-ads' ); ?>
		</h2>
		<p id="aggr-delivery-window" class="aggr-delivery__window"><?php echo esc_html( $aggr_range ); ?></p>
		<?php
		/*
		 * Days are UTC, and midnight UTC is somebody's afternoon. The sentence
		 * is true as written; the local-time module restates when a day starts
		 * in the viewer's own zone.
		 */
		?>
		<p class="aggr-delivery__window">
			<span
				data-aggr-local="clock"
				data-aggr-datetime="<?php echo esc_attr( gmdate( 'Y-m-d' ) . 'T00:00:00Z' ); ?>"
				data-aggr-local-format="<?php /* translators: %s: midnight UTC as the viewer's local time, e.g. 5:00 PM. */ esc_attr_e( 'Each day starts at %s your time.', 'aggressive-ads' ); ?>"
			><?php esc_html_e( 'Each day runs midnight to midnight UTC.', 'aggressive-ads' ); ?></span>
		</p>
	</div>

<form class="aggr-range" method="get" action="<?php echo esc_url( Routes::url() ); ?>">
	<h2 class="aggr-sr"><?php esc_html_e( 'Choose a reporting window', 'aggressive-ads' ); ?></h2>

	<?php
	/*
	 * The presets are links rather than a second control. A select beside two
	 * date inputs asks the reader which one wins; a link that fills the same
	 * range in answers that by not competing. The one that matches the window
	 * on screen is marked current.
	 */
	?>
	<ul class="aggr-range__presets">
		<?php foreach ( Reporting_Read::WINDOWS as $aggr_preset ) : ?>
			<li>
				<a
					href="<?php echo esc_url( add_query_arg( 'days', (int) $aggr_preset, Routes::url() ) ); ?>"
					<?php echo (int) $aggr_preset === (int) $aggr_window['days'] ? 'aria-current="true"' : ''; ?>
				>
					<?php
					printf(
						/* translators: %d: number of days. */
						esc_html( _n( '%d day', '%d days', (int) $aggr_preset, 'aggressive-ads' ) ),
						(int) $aggr_preset
					);
					?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<details class="aggr-range__custom">
		<summary><?php esc_html_e( 'Custom', 'aggressive-ads' ); ?></summary>
		<div class="aggr-range__fields">
			<div class="aggr-range__field">
				<label for="aggr-range-from"><?php esc_html_e( 'From (UTC)', 'aggressive-ads' ); ?></label>
				<input type="date" id="aggr-range-from" name="from" value="<?php echo esc_attr( $aggr_window['from'] ); ?>">
			</div>

			<div class="aggr-range__field">
				<label for="aggr-range-to"><?php esc_html_e( 'To (UTC)', 'aggressive-ads' ); ?></label>
				<input type="date" id="aggr-range-to" name="to" value="<?php echo esc_attr( $aggr_window['to'] ); ?>">
			</div>

			<button class="aggr-button aggr-button--secondary" type="submit"><?php esc_html_e( 'Show', 'aggressive-ads' ); ?></button>
		</div>
	</details>
</form>
</div>

	<?php if ( true === $aggr_window['rejected'] ) : ?>
		<?php
		/*
		 * Refused, not clamped. A screen that quietly reported a different
		 * period than the one asked for would look authoritative and answer a
		 * question nobody put to it.
		 */
		?>
	<p class="aggr-notice" role="status">
		<?php esc_html_e( 'That date range could not be used, so the default window is shown. A range must be two valid dates, in order, and no longer than 92 days.', 'aggressive-ads' ); ?>
	</p>
	<?php endif; ?>

<div class="aggr-stats">
	<?php foreach ( $aggr_delivery as $aggr_stat ) : ?>
		<div class="aggr-stat">
			<div class="aggr-stat__label"><?php echo esc_html( (string) $aggr_stat['label'] ); ?></div>
			<div class="aggr-stat__value"><?php echo esc_html( (string) $aggr_stat['value'] ); ?></div>
			<?php
			/*
			 * The sign and the unit are in the text, so the direction class
			 * only ever adds colour to something already legible without it.
			 * An empty change is a real state — nothing to compare against —
			 * and renders as nothing rather than as a zero.
			 */
			$aggr_change = (string) ( $aggr_stat['change'] ?? '' );
			?>
			<?php if ( '' !== $aggr_change ) : ?>
				<div class="aggr-stat__change aggr-stat__change--<?php echo esc_attr( (string) ( $aggr_stat['direction'] ?? 'flat' ) ); ?>">
					<?php echo esc_html( $aggr_change ); ?>
				</div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>

	<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/sparkline.php'; ?>

<p class="aggr-hint">
	<?php esc_html_e( 'Impressions and clicks from native delivery.', 'aggressive-ads' ); ?>
	<?php if ( '' !== $aggr_counting ) : ?>
		<?php
		$aggr_counting_ts = (int) strtotime( $aggr_counting . ' UTC' );
		?>
		<span
			class="aggr-hint__freshness"
			data-aggr-local="moment"
			data-aggr-datetime="<?php echo esc_attr( $aggr_counting . 'T00:00:00Z' ); ?>"
			data-aggr-local-format="<?php /* translators: %s: a date and time in the viewer's zone, e.g. Sep 16, 5:00 PM. */ esc_attr_e( 'Figures since %s (your time) are still coming in.', 'aggressive-ads' ); ?>"
		>
			<?php
			printf(
				/* translators: %s: a UTC date, e.g. September 17. */
				esc_html__( 'Figures from %s (UTC) onward are still coming in.', 'aggressive-ads' ),
				esc_html( (string) wp_date( 'F j', $aggr_counting_ts, new DateTimeZone( 'UTC' ) ) )
			);
			?>
		</span>
	<?php elseif ( '' !== $aggr_freshness ) : ?>
		<span class="aggr-hint__freshness"><?php echo esc_html( $aggr_freshness ); ?></span>
	<?php endif; ?>
</p>
</section>
	<?php
endif;
?>

<?php
/*
 * **Choosing what to buy starts the campaign.** Each package posts the create
 * action with its id, and the draft opens on the dates. One form with a submit
 * button per package: the button pressed posts its own `package_id`, and one
 * nonce serves them all.
 */
$aggr_start_packages = $aggr_can_create ? $aggr_view->package_options() : array();
?>
<div class="aggr-columns aggr-dashboard">
	<section class="aggr-panel aggr-columns__main" aria-labelledby="aggr-campaigns-heading">
		<div class="aggr-panel__headrow">
			<h2 id="aggr-campaigns-heading" class="aggr-panel__head">
				<?php esc_html_e( 'Your campaigns', 'aggressive-ads' ); ?>
			</h2>
			<a class="aggr-panel__link" href="<?php echo esc_url( $aggr_list_url ); ?>"><?php esc_html_e( 'View all', 'aggressive-ads' ); ?></a>
		</div>

		<?php
		$aggr_rows          = array_slice( $aggr_campaigns['rows'], 0, 5 );
		$aggr_show_metrics  = ! empty( $aggr_campaigns['show_metrics'] );
		$aggr_table_compact = true;

		require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-table.php';
		?>
	</section>

	<aside class="aggr-columns__side">
		<?php if ( null !== $aggr_attention ) : ?>
			<section class="aggr-summary aggr-attention" aria-labelledby="aggr-attention-heading">
				<div class="aggr-summary__head">
					<h2 id="aggr-attention-heading" class="aggr-eyebrow"><?php esc_html_e( 'Needs your attention', 'aggressive-ads' ); ?></h2>
				</div>
				<p class="aggr-attention__name"><?php echo esc_html( (string) $aggr_attention['title'] ); ?></p>
				<p class="aggr-hint"><?php echo esc_html( (string) $aggr_attention['review_notes'] ); ?></p>
				<a class="aggr-button aggr-button--secondary" href="<?php echo esc_url( (string) $aggr_attention['url'] ); ?>"><?php esc_html_e( 'Make changes', 'aggressive-ads' ); ?><span class="aggr-sr">: <?php echo esc_html( (string) $aggr_attention['title'] ); ?></span></a>
			</section>
		<?php endif; ?>

		<?php if ( array() !== $aggr_start_packages ) : ?>
			<section class="aggr-summary" aria-labelledby="aggr-start-heading">
				<div>
					<h2 id="aggr-start-heading" class="aggr-eyebrow"><?php esc_html_e( 'Start a campaign', 'aggressive-ads' ); ?></h2>
					<p class="aggr-hint"><?php esc_html_e( 'Pick a package. Dates and ads come next.', 'aggressive-ads' ); ?></p>
				</div>

				<form class="aggr-start" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::CREATE_ACTION ); ?>">
					<?php wp_nonce_field( Campaign_Actions::CREATE_ACTION ); ?>
					<?php foreach ( $aggr_start_packages as $aggr_start_package ) : ?>
						<button class="aggr-start__choice" type="submit" name="package_id" value="<?php echo esc_attr( (string) $aggr_start_package['id'] ); ?>">
							<span class="aggr-start__text">
								<span class="aggr-start__name"><?php echo esc_html( (string) $aggr_start_package['name'] ); ?></span>
								<span class="aggr-start__meta">
									<?php
									printf(
										/* translators: 1: duration, e.g. 30 days. 2: number of ad sizes. */
										esc_html( _n( '%1$s · %2$d size', '%1$s · %2$d sizes', count( $aggr_start_package['sizes'] ), 'aggressive-ads' ) ),
										esc_html( (string) $aggr_start_package['duration'] ),
										(int) count( $aggr_start_package['sizes'] )
									);
									?>
								</span>
							</span>
							<span class="aggr-start__price"><?php echo esc_html( (string) $aggr_start_package['price'] ); ?></span>
						</button>
					<?php endforeach; ?>
				</form>
			</section>
		<?php endif; ?>
	</aside>
</div>
