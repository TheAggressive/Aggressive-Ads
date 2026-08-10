<?php
/**
 * A table of campaigns.
 *
 * Shared by the dashboard and the campaigns screen so the two cannot drift
 * into showing the same data differently.
 *
 * @package LAAO_Advertiser_Portal
 *
 * @var array<int, array<string, mixed>> $laao_ads_rows Campaign rows.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$laao_ads_rows = isset( $laao_ads_rows ) && is_array( $laao_ads_rows ) ? $laao_ads_rows : array();
?>
<?php if ( array() === $laao_ads_rows ) : ?>
	<div class="laao-ads-empty">
		<p class="laao-ads-empty__title"><?php esc_html_e( 'No campaigns yet', 'laao-advertiser-portal' ); ?></p>
		<p><?php esc_html_e( 'When you create a campaign it will appear here with its status.', 'laao-advertiser-portal' ); ?></p>
	</div>
<?php else : ?>
	<div class="laao-ads-tablewrap">
		<table class="laao-ads-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Campaign', 'laao-advertiser-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Placement', 'laao-advertiser-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'laao-advertiser-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Dates', 'laao-advertiser-portal' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $laao_ads_rows as $laao_ads_row ) : ?>
					<tr>
						<td class="laao-ads-table__primary">
							<a href="<?php echo esc_url( (string) $laao_ads_row['url'] ); ?>">
								<?php echo esc_html( (string) $laao_ads_row['title'] ); ?>
							</a>
						</td>
						<td>
							<?php
							echo esc_html(
								array() === $laao_ads_row['placements']
									? __( 'None selected', 'laao-advertiser-portal' )
									: implode( ', ', $laao_ads_row['placements'] )
							);
							?>
						</td>
						<td>
							<span class="laao-ads-pill laao-ads-pill--<?php echo esc_attr( (string) $laao_ads_row['pill'] ); ?>">
								<?php echo esc_html( (string) $laao_ads_row['status_text'] ); ?>
							</span>
						</td>
						<td><?php echo esc_html( (string) $laao_ads_row['dates'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
