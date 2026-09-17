<?php
/**
 * A table of campaigns.
 *
 * Shared by the dashboard and the campaigns screen so the two cannot drift
 * into showing the same data differently. The dashboard's is compact — name,
 * status, schedule and impressions — and the list's has every column and the
 * one next action each campaign asks for.
 *
 * @package Aggressive\Ads
 *
 * @var array<int, array<string, mixed>> $aggr_rows          Campaign rows.
 * @var bool                             $aggr_show_metrics  Whether impression/click columns render.
 * @var bool                             $aggr_table_compact Whether this is the dashboard's short table.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Nonces;
use Aggressive\Ads\Security\Capabilities;

$aggr_rows          = isset( $aggr_rows ) && is_array( $aggr_rows ) ? $aggr_rows : array();
$aggr_show_metrics  = ! empty( $aggr_show_metrics );
$aggr_table_compact = ! empty( $aggr_table_compact );
$aggr_can_renew     = current_user_can( Capabilities::SUBMIT_CAMPAIGN );
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
					<th scope="col"><?php esc_html_e( 'Status', 'aggressive-ads' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Schedule', 'aggressive-ads' ); ?></th>
					<?php if ( ! $aggr_table_compact ) : ?>
					<th scope="col"><?php esc_html_e( 'Sizes', 'aggressive-ads' ); ?></th>
					<?php endif; ?>
					<?php if ( $aggr_show_metrics ) : ?>
					<th scope="col"><?php esc_html_e( 'Impressions', 'aggressive-ads' ); ?></th>
						<?php if ( ! $aggr_table_compact ) : ?>
					<th scope="col"><?php esc_html_e( 'Clicks', 'aggressive-ads' ); ?></th>
					<th scope="col"><?php esc_html_e( 'CTR', 'aggressive-ads' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Conversions', 'aggressive-ads' ); ?></th>
						<?php endif; ?>
					<?php endif; ?>
					<?php if ( ! $aggr_table_compact ) : ?>
					<th scope="col"><span class="aggr-sr"><?php esc_html_e( 'Next step', 'aggressive-ads' ); ?></span></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $aggr_rows as $aggr_row ) : ?>
					<?php $aggr_row_status = (string) $aggr_row['status']; ?>
					<tr>
						<td class="aggr-table__primary">
							<a href="<?php echo esc_url( (string) $aggr_row['url'] ); ?>">
								<?php echo esc_html( (string) $aggr_row['title'] ); ?>
							</a>
							<?php if ( '' !== (string) ( $aggr_row['package'] ?? '' ) ) : ?>
								<span class="aggr-table__sub"><?php echo esc_html( (string) $aggr_row['package'] ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<span class="aggr-pill aggr-pill--<?php echo esc_attr( (string) $aggr_row['pill'] ); ?>">
								<?php echo esc_html( (string) $aggr_row['status_text'] ); ?>
							</span>
						</td>
						<td class="aggr-table__mono"><?php echo esc_html( (string) ( $aggr_row['schedule'] ?? $aggr_row['dates'] ) ); ?></td>
						<?php if ( ! $aggr_table_compact ) : ?>
						<td class="aggr-table__mono"><?php echo esc_html( number_format_i18n( (int) ( $aggr_row['sizes'] ?? 0 ) ) ); ?></td>
						<?php endif; ?>
						<?php if ( $aggr_show_metrics ) : ?>
						<td class="aggr-table__num"><?php echo esc_html( number_format_i18n( (int) ( $aggr_row['impressions'] ?? 0 ) ) ); ?></td>
							<?php if ( ! $aggr_table_compact ) : ?>
						<td class="aggr-table__num"><?php echo esc_html( number_format_i18n( (int) ( $aggr_row['clicks'] ?? 0 ) ) ); ?></td>
						<td class="aggr-table__num">
								<?php
								$aggr_ctr = $aggr_row['ctr'] ?? null;
								echo esc_html(
									is_float( $aggr_ctr )
										? sprintf(
											/* translators: %s: click-through rate as a percentage, e.g. 1.2. */
											__( '%s%%', 'aggressive-ads' ),
											number_format_i18n( $aggr_ctr * 100, 2 )
										)
										: __( '—', 'aggressive-ads' )
								);
								?>
						</td>
						<td class="aggr-table__num">
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
						<?php endif; ?>
						<?php if ( ! $aggr_table_compact ) : ?>
						<td class="aggr-table__next">
							<?php
							/*
							 * The one thing each campaign asks for next, where it
							 * asks for anything. A finished campaign is most often
							 * run again as it was; the copy keeps the package,
							 * artwork and links and asks only for new dates.
							 */
							?>
							<?php if ( Post_Statuses::DRAFT === $aggr_row_status ) : ?>
								<a href="<?php echo esc_url( (string) $aggr_row['url'] ); ?>"><?php esc_html_e( 'Continue setup', 'aggressive-ads' ); ?><span class="aggr-sr">: <?php echo esc_html( (string) $aggr_row['title'] ); ?></span></a>
							<?php elseif ( Post_Statuses::CHANGES === $aggr_row_status ) : ?>
								<a href="<?php echo esc_url( (string) $aggr_row['url'] ); ?>"><?php esc_html_e( 'Make changes', 'aggressive-ads' ); ?><span class="aggr-sr">: <?php echo esc_html( (string) $aggr_row['title'] ); ?></span></a>
							<?php elseif ( $aggr_can_renew && Post_Statuses::COMPLETE === $aggr_row_status ) : ?>
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
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
