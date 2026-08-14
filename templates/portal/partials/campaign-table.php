<?php
/**
 * A table of campaigns.
 *
 * Shared by the dashboard and the campaigns screen so the two cannot drift
 * into showing the same data differently.
 *
 * @package Aggressive\Ads
 *
 * @var array<int, array<string, mixed>> $aggr_rows          Campaign rows.
 * @var bool                             $aggr_show_metrics  Whether impression/click columns render.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_rows         = isset( $aggr_rows ) && is_array( $aggr_rows ) ? $aggr_rows : array();
$aggr_show_metrics = ! empty( $aggr_show_metrics );
?>
<?php if ( array() === $aggr_rows ) : ?>
	<div class="aggr-empty">
		<p class="aggr-empty__title"><?php esc_html_e( 'No campaigns yet', 'aggressive-ads' ); ?></p>
		<p><?php esc_html_e( 'When you create a campaign it will appear here with its status.', 'aggressive-ads' ); ?></p>
	</div>
<?php else : ?>
	<div class="aggr-tablewrap" role="region" aria-label="<?php esc_attr_e( 'Campaigns table', 'aggressive-ads' ); ?>" tabindex="0">
		<table class="aggr-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Campaign', 'aggressive-ads' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Placement', 'aggressive-ads' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'aggressive-ads' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Dates', 'aggressive-ads' ); ?></th>
					<?php if ( $aggr_show_metrics ) : ?>
					<th scope="col"><?php esc_html_e( 'Impressions', 'aggressive-ads' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Clicks', 'aggressive-ads' ); ?></th>
					<th scope="col"><?php esc_html_e( 'CTR', 'aggressive-ads' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $aggr_rows as $aggr_row ) : ?>
					<tr>
						<td class="aggr-table__primary">
							<a href="<?php echo esc_url( (string) $aggr_row['url'] ); ?>">
								<?php echo esc_html( (string) $aggr_row['title'] ); ?>
							</a>
						</td>
						<td>
							<?php
							echo esc_html(
								array() === $aggr_row['placements']
									? __( 'None selected', 'aggressive-ads' )
									: implode( ', ', $aggr_row['placements'] )
							);
							?>
						</td>
						<td>
							<span class="aggr-pill aggr-pill--<?php echo esc_attr( (string) $aggr_row['pill'] ); ?>">
								<?php echo esc_html( (string) $aggr_row['status_text'] ); ?>
							</span>
						</td>
						<td><?php echo esc_html( (string) $aggr_row['dates'] ); ?></td>
						<?php if ( $aggr_show_metrics ) : ?>
						<td><?php echo esc_html( number_format_i18n( (int) ( $aggr_row['impressions'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $aggr_row['clicks'] ?? 0 ) ) ); ?></td>
						<td>
							<?php
							$aggr_ctr = $aggr_row['ctr'] ?? null;
							echo esc_html(
								is_float( $aggr_ctr )
									? sprintf(
										/* translators: %s: click-through rate as a percentage, e.g. 1.2. */
										__( '%s%%', 'aggressive-ads' ),
										number_format_i18n( $aggr_ctr * 100, 1 )
									)
									: __( '—', 'aggressive-ads' )
							);
							?>
						</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
