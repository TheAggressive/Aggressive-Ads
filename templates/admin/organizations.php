<?php
/**
 * Staff organization suspension screen.
 *
 * @package Aggressive\Ads
 *
 * @var array{rows: array<int, array{id: int, name: string, state: string, active: bool, owner_name: string, members: int, campaigns: int}>} $aggr_view
 * @var array{type: string, message: string}|null $aggr_notice
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Admin\Organization_Screen;

?>
<div class="wrap aggr-portal aggr-admin">
	<header class="aggr-pagehead">
		<div>
			<h1 class="aggr-title"><?php esc_html_e( 'Organizations', 'aggressive-ads' ); ?></h1>
			<p class="aggr-lede"><?php esc_html_e( 'Suspend an organization to block new submissions and membership growth. Existing campaigns keep their current status until staff change them.', 'aggressive-ads' ); ?></p>
		</div>
	</header>

	<?php if ( is_array( $aggr_notice ) ) : ?>
		<div class="aggr-flash aggr-flash--<?php echo esc_attr( $aggr_notice['type'] ); ?>" role="status">
			<?php echo esc_html( $aggr_notice['message'] ); ?>
		</div>
	<?php endif; ?>

	<section class="aggr-panel" aria-labelledby="aggr-orgs-heading">
		<h2 id="aggr-orgs-heading" class="aggr-panel__head"><?php esc_html_e( 'Advertiser organizations', 'aggressive-ads' ); ?></h2>

		<?php if ( array() === $aggr_view['rows'] ) : ?>
			<div class="aggr-empty">
				<h3 class="aggr-empty__title"><?php esc_html_e( 'No organizations yet.', 'aggressive-ads' ); ?></h3>
				<p><?php esc_html_e( 'Organizations appear here after an advertiser signs up or is invited.', 'aggressive-ads' ); ?></p>
			</div>
		<?php else : ?>
			<div class="aggr-tablewrap" role="region" aria-label="<?php esc_attr_e( 'Organizations table', 'aggressive-ads' ); ?>" tabindex="0">
				<table class="aggr-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Organization', 'aggressive-ads' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Owner', 'aggressive-ads' ); ?></th>
							<th scope="col"><?php esc_html_e( 'People', 'aggressive-ads' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Campaigns', 'aggressive-ads' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'aggressive-ads' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'aggressive-ads' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $aggr_view['rows'] as $aggr_row ) : ?>
							<tr>
								<th scope="row" class="aggr-table__primary"><?php echo esc_html( $aggr_row['name'] ); ?></th>
								<td><?php echo esc_html( '' !== $aggr_row['owner_name'] ? $aggr_row['owner_name'] : '—' ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $aggr_row['members'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $aggr_row['campaigns'] ) ); ?></td>
								<td>
									<span class="aggr-pill aggr-pill--<?php echo $aggr_row['active'] ? 'live' : 'danger'; ?>">
										<?php
										echo $aggr_row['active']
											? esc_html__( 'Active', 'aggressive-ads' )
											: esc_html__( 'Suspended', 'aggressive-ads' );
										?>
									</span>
								</td>
								<td>
									<div class="aggr-actions">
										<?php if ( $aggr_row['active'] ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Screen::SUSPEND_ACTION ); ?>">
												<input type="hidden" name="org_id" value="<?php echo esc_attr( (string) $aggr_row['id'] ); ?>">
												<?php wp_nonce_field( Organization_Screen::nonce_action( Organization_Screen::SUSPEND_ACTION, $aggr_row['id'] ) ); ?>
												<button class="aggr-button aggr-button--secondary aggr-button--small" type="submit">
													<?php esc_html_e( 'Suspend', 'aggressive-ads' ); ?>
												</button>
											</form>
										<?php else : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Screen::REACTIVATE_ACTION ); ?>">
												<input type="hidden" name="org_id" value="<?php echo esc_attr( (string) $aggr_row['id'] ); ?>">
												<?php wp_nonce_field( Organization_Screen::nonce_action( Organization_Screen::REACTIVATE_ACTION, $aggr_row['id'] ) ); ?>
												<button class="aggr-button aggr-button--small" type="submit">
													<?php esc_html_e( 'Reactivate', 'aggressive-ads' ); ?>
												</button>
											</form>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</section>
</div>
