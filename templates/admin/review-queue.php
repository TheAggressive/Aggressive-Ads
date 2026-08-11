<?php
/**
 * Staff campaign-review queue.
 *
 * @package LAAO_Advertiser_Portal
 *
 * @var string                                          $laao_ads_filter Active filter.
 * @var int                                             $laao_ads_page   Current page.
 * @var array{type: string, message: string}|null       $laao_ads_notice Result notice.
 * @var array<int, array{key: string, label: string, count: int}> $laao_ads_tabs Queue tabs.
 * @var array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int} $laao_ads_queue Queue data.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Admin\Review_Screen;
use LAAO_Advertiser_Portal\Admin\Review_Data;
?>
<div class="wrap laao-ads-portal laao-ads-admin">
	<header class="laao-ads-pagehead">
		<div>
			<h1 class="laao-ads-title"><?php esc_html_e( 'Campaign review', 'laao-advertiser-portal' ); ?></h1>
			<p class="laao-ads-lede"><?php esc_html_e( 'Review advertiser submissions, provide clear feedback, and publish approved creative to AdSanity.', 'laao-advertiser-portal' ); ?></p>
		</div>
	</header>

	<?php if ( is_array( $laao_ads_notice ) ) : ?>
		<div class="laao-ads-flash laao-ads-flash--<?php echo esc_attr( $laao_ads_notice['type'] ); ?>" role="status">
			<p class="laao-ads-flash__message"><?php echo esc_html( $laao_ads_notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<nav class="laao-ads-tabs" aria-label="<?php esc_attr_e( 'Review queue filters', 'laao-advertiser-portal' ); ?>">
		<?php foreach ( $laao_ads_tabs as $laao_ads_tab ) : ?>
			<a
				class="laao-ads-tab"
				href="<?php echo esc_url( Review_Screen::queue_url( $laao_ads_tab['key'] ) ); ?>"
				<?php
				if ( $laao_ads_tab['key'] === $laao_ads_filter ) :
					?>
					aria-current="page"<?php endif; ?>
			>
				<?php echo esc_html( $laao_ads_tab['label'] ); ?>
				<span class="laao-ads-tab__count"><?php echo esc_html( number_format_i18n( $laao_ads_tab['count'] ) ); ?></span>
			</a>
		<?php endforeach; ?>
	</nav>

	<section class="laao-ads-panel" aria-labelledby="laao-ads-queue-heading">
		<h2 id="laao-ads-queue-heading" class="laao-ads-panel__head">
			<?php
			printf(
				/* translators: %s: number of campaigns in the selected queue. */
				esc_html__( 'Campaigns (%s)', 'laao-advertiser-portal' ),
				esc_html( number_format_i18n( $laao_ads_queue['total'] ) )
			);
			?>
		</h2>

		<?php if ( array() === $laao_ads_queue['rows'] ) : ?>
			<div class="laao-ads-empty">
				<h3 class="laao-ads-empty__title"><?php esc_html_e( 'Nothing is waiting here.', 'laao-advertiser-portal' ); ?></h3>
				<p><?php esc_html_e( 'Campaigns will appear in this view as their status changes.', 'laao-advertiser-portal' ); ?></p>
			</div>
		<?php else : ?>
			<div class="laao-ads-tablewrap">
				<table class="laao-ads-table laao-ads-review-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Campaign', 'laao-advertiser-portal' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Advertiser', 'laao-advertiser-portal' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Placement', 'laao-advertiser-portal' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'laao-advertiser-portal' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Submitted', 'laao-advertiser-portal' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Reviewer', 'laao-advertiser-portal' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $laao_ads_queue['rows'] as $laao_ads_row ) : ?>
							<tr>
								<td class="laao-ads-table__primary">
									<a href="<?php echo esc_url( Review_Screen::campaign_url( (int) $laao_ads_row['id'], $laao_ads_filter, $laao_ads_page ) ); ?>">
										<?php echo esc_html( (string) $laao_ads_row['title'] ); ?>
									</a>
								</td>
								<td><?php echo esc_html( (string) $laao_ads_row['org_name'] ); ?></td>
								<td><?php echo esc_html( implode( ', ', $laao_ads_row['placements'] ) ); ?></td>
								<td>
									<span class="laao-ads-pill laao-ads-pill--<?php echo esc_attr( (string) $laao_ads_row['pill'] ); ?>">
										<?php echo esc_html( (string) $laao_ads_row['status_text'] ); ?>
									</span>
								</td>
								<td>
									<?php echo 0 === (int) $laao_ads_row['submitted_at'] ? '&mdash;' : esc_html( Review_Data::format_timestamp( (int) $laao_ads_row['submitted_at'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The only raw branch is the fixed &mdash; entity. ?>
								</td>
								<td><?php echo esc_html( '' === (string) $laao_ads_row['reviewer'] ? __( 'Unassigned', 'laao-advertiser-portal' ) : (string) $laao_ads_row['reviewer'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</section>

	<?php if ( $laao_ads_queue['pages'] > 1 ) : ?>
		<nav class="laao-ads-pagination" aria-label="<?php esc_attr_e( 'Campaign review pages', 'laao-advertiser-portal' ); ?>">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%', Review_Screen::queue_url( $laao_ads_filter ) ),
						'format'    => '',
						'current'   => $laao_ads_queue['page'],
						'total'     => $laao_ads_queue['pages'],
						'prev_text' => __( 'Previous', 'laao-advertiser-portal' ),
						'next_text' => __( 'Next', 'laao-advertiser-portal' ),
					)
				)
			);
			?>
		</nav>
	<?php endif; ?>
</div>
