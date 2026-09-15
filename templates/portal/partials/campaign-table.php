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

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Nonces;
use Aggressive\Ads\Security\Capabilities;

$aggr_rows         = isset( $aggr_rows ) && is_array( $aggr_rows ) ? $aggr_rows : array();
$aggr_show_metrics = ! empty( $aggr_show_metrics );
$aggr_can_renew    = current_user_can( Capabilities::SUBMIT_CAMPAIGN );
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
					<th scope="col"><?php esc_html_e( 'Conversions', 'aggressive-ads' ); ?></th>
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
							<?php
							/*
							 * A finished campaign is most often run again as it
							 * was, and that used to mean opening it to find the
							 * renew button. The copy keeps the package, artwork
							 * and links and asks only for new dates.
							 */
							?>
							<?php if ( $aggr_can_renew && Post_Statuses::COMPLETE === (string) $aggr_row['status'] ) : ?>
								<form class="aggr-table__action" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::COPY_ACTION ); ?>">
									<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) (int) $aggr_row['id'] ); ?>">
									<?php wp_nonce_field( Campaign_Nonces::copy_nonce_action( (int) $aggr_row['id'] ) ); ?>
									<button type="submit">
										<?php esc_html_e( 'Run again', 'aggressive-ads' ); ?><span class="aggr-sr">: <?php echo esc_html( (string) $aggr_row['title'] ); ?></span>
									</button>
								</form>
							<?php endif; ?>
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
						<td>
							<?php
							/*
							 * Null is "nobody was counting" — every day before
							 * conversions shipped. Printing 0 there would read
							 * as a campaign that delivered and converted
							 * nobody, which is a different and worse claim.
							 */
							$aggr_conversions = $aggr_row['conversions'] ?? null;
							echo esc_html(
								is_int( $aggr_conversions )
									? number_format_i18n( $aggr_conversions )
									: __( 'Not measured', 'aggressive-ads' )
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
