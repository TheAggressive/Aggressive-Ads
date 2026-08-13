<?php
/**
 * Staff campaign-review queue.
 *
 * @package Aggressive\Ads
 *
 * @var string                                          $aggr_filter Active filter.
 * @var int                                             $aggr_page   Current page.
 * @var array{type: string, message: string}|null       $aggr_notice Result notice.
 * @var array<int, array{key: string, label: string, count: int}> $aggr_tabs Queue tabs.
 * @var array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int} $aggr_queue Queue data.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Admin\Review_Screen;
use Aggressive\Ads\Admin\Review_Data;
?>
<div class="wrap aggr-portal aggr-admin">
	<header class="aggr-pagehead">
		<div>
			<h1 class="aggr-title"><?php esc_html_e( 'Campaign review', 'aggressive-ads' ); ?></h1>
			<p class="aggr-lede"><?php esc_html_e( 'Review advertiser submissions, provide clear feedback, and approve campaigns into the live set.', 'aggressive-ads' ); ?></p>
		</div>
	</header>

	<?php if ( is_array( $aggr_notice ) ) : ?>
		<div class="aggr-flash aggr-flash--<?php echo esc_attr( $aggr_notice['type'] ); ?>" role="status">
			<p class="aggr-flash__message"><?php echo esc_html( $aggr_notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<nav class="aggr-tabs" aria-label="<?php esc_attr_e( 'Review queue filters', 'aggressive-ads' ); ?>">
		<?php foreach ( $aggr_tabs as $aggr_tab ) : ?>
			<a
				class="aggr-tab"
				href="<?php echo esc_url( Review_Screen::queue_url( $aggr_tab['key'] ) ); ?>"
				<?php
				if ( $aggr_tab['key'] === $aggr_filter ) :
					?>
					aria-current="page"<?php endif; ?>
			>
				<?php echo esc_html( $aggr_tab['label'] ); ?>
				<span class="aggr-tab__count"><?php echo esc_html( number_format_i18n( $aggr_tab['count'] ) ); ?></span>
			</a>
		<?php endforeach; ?>
	</nav>

	<section class="aggr-panel" aria-labelledby="aggr-queue-heading">
		<h2 id="aggr-queue-heading" class="aggr-panel__head">
			<?php
			printf(
				/* translators: %s: number of campaigns in the selected queue. */
				esc_html__( 'Campaigns (%s)', 'aggressive-ads' ),
				esc_html( number_format_i18n( $aggr_queue['total'] ) )
			);
			?>
		</h2>

		<?php if ( array() === $aggr_queue['rows'] ) : ?>
			<div class="aggr-empty">
				<h3 class="aggr-empty__title"><?php esc_html_e( 'Nothing is waiting here.', 'aggressive-ads' ); ?></h3>
				<p><?php esc_html_e( 'Campaigns will appear in this view as their status changes.', 'aggressive-ads' ); ?></p>
			</div>
		<?php else : ?>
			<div class="aggr-tablewrap">
				<table class="aggr-table aggr-review-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Campaign', 'aggressive-ads' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Advertiser', 'aggressive-ads' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Placement', 'aggressive-ads' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'aggressive-ads' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Submitted', 'aggressive-ads' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Reviewer', 'aggressive-ads' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Ad updates', 'aggressive-ads' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $aggr_queue['rows'] as $aggr_row ) : ?>
							<tr>
								<td class="aggr-table__primary">
									<a href="<?php echo esc_url( Review_Screen::campaign_url( (int) $aggr_row['id'], $aggr_filter, $aggr_page ) ); ?>">
										<?php echo esc_html( (string) $aggr_row['title'] ); ?>
									</a>
								</td>
								<td><?php echo esc_html( (string) $aggr_row['org_name'] ); ?></td>
								<td><?php echo esc_html( implode( ', ', $aggr_row['placements'] ) ); ?></td>
								<td>
									<span class="aggr-pill aggr-pill--<?php echo esc_attr( (string) $aggr_row['pill'] ); ?>">
										<?php echo esc_html( (string) $aggr_row['status_text'] ); ?>
									</span>
								</td>
								<td>
									<?php echo 0 === (int) $aggr_row['submitted_at'] ? '&mdash;' : esc_html( Review_Data::format_timestamp( (int) $aggr_row['submitted_at'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The only raw branch is the fixed &mdash; entity. ?>
								</td>
							<td><?php echo esc_html( '' === (string) $aggr_row['reviewer'] ? __( 'Unassigned', 'aggressive-ads' ) : (string) $aggr_row['reviewer'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $aggr_row['pending_updates'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</section>

	<?php if ( $aggr_queue['pages'] > 1 ) : ?>
		<nav class="aggr-pagination" aria-label="<?php esc_attr_e( 'Campaign review pages', 'aggressive-ads' ); ?>">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%', Review_Screen::queue_url( $aggr_filter ) ),
						'format'    => '',
						'current'   => $aggr_queue['page'],
						'total'     => $aggr_queue['pages'],
						'prev_text' => __( 'Previous', 'aggressive-ads' ),
						'next_text' => __( 'Next', 'aggressive-ads' ),
					)
				)
			);
			?>
		</nav>
	<?php endif; ?>
</div>
