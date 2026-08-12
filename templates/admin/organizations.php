<?php
/**
 * Staff organization suspension screen.
 *
 * @package LAAO_Advertiser_Portal
 *
 * @var array{rows: array<int, array{id: int, name: string, state: string, active: bool, owner_name: string, members: int, campaigns: int}>} $laao_ads_view
 * @var array{type: string, message: string}|null $laao_ads_notice
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Admin\Organization_Screen;

?>
<div class="wrap laao-ads-portal laao-ads-admin">
	<header class="laao-ads-pagehead">
		<div>
			<h1 class="laao-ads-title"><?php esc_html_e( 'Organizations', 'laao-advertiser-portal' ); ?></h1>
			<p class="laao-ads-lede"><?php esc_html_e( 'Suspend an organization to block new submissions and membership growth. Existing campaigns keep their current status until staff change them.', 'laao-advertiser-portal' ); ?></p>
		</div>
	</header>

	<?php if ( is_array( $laao_ads_notice ) ) : ?>
		<div class="laao-ads-flash laao-ads-flash--<?php echo esc_attr( $laao_ads_notice['type'] ); ?>" role="status">
			<?php echo esc_html( $laao_ads_notice['message'] ); ?>
		</div>
	<?php endif; ?>

	<section class="laao-ads-panel" aria-labelledby="laao-ads-orgs-heading">
		<h2 id="laao-ads-orgs-heading" class="laao-ads-panel__head"><?php esc_html_e( 'Advertiser organizations', 'laao-advertiser-portal' ); ?></h2>

		<?php if ( array() === $laao_ads_view['rows'] ) : ?>
			<div class="laao-ads-empty">
				<h3 class="laao-ads-empty__title"><?php esc_html_e( 'No organizations yet.', 'laao-advertiser-portal' ); ?></h3>
				<p><?php esc_html_e( 'Organizations appear here after an advertiser signs up or is invited.', 'laao-advertiser-portal' ); ?></p>
			</div>
		<?php else : ?>
			<div class="laao-ads-tablewrap">
				<table class="laao-ads-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Organization', 'laao-advertiser-portal' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Owner', 'laao-advertiser-portal' ); ?></th>
							<th scope="col"><?php esc_html_e( 'People', 'laao-advertiser-portal' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Campaigns', 'laao-advertiser-portal' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'laao-advertiser-portal' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'laao-advertiser-portal' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $laao_ads_view['rows'] as $laao_ads_row ) : ?>
							<tr>
								<th scope="row" class="laao-ads-table__primary"><?php echo esc_html( $laao_ads_row['name'] ); ?></th>
								<td><?php echo esc_html( '' !== $laao_ads_row['owner_name'] ? $laao_ads_row['owner_name'] : '—' ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $laao_ads_row['members'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $laao_ads_row['campaigns'] ) ); ?></td>
								<td>
									<span class="laao-ads-pill laao-ads-pill--<?php echo $laao_ads_row['active'] ? 'live' : 'danger'; ?>">
										<?php
										echo $laao_ads_row['active']
											? esc_html__( 'Active', 'laao-advertiser-portal' )
											: esc_html__( 'Suspended', 'laao-advertiser-portal' );
										?>
									</span>
								</td>
								<td>
									<div class="laao-ads-actions">
										<?php if ( $laao_ads_row['active'] ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Screen::SUSPEND_ACTION ); ?>">
												<input type="hidden" name="org_id" value="<?php echo esc_attr( (string) $laao_ads_row['id'] ); ?>">
												<?php wp_nonce_field( Organization_Screen::nonce_action( Organization_Screen::SUSPEND_ACTION, $laao_ads_row['id'] ) ); ?>
												<button class="laao-ads-button laao-ads-button--secondary laao-ads-button--small" type="submit">
													<?php esc_html_e( 'Suspend', 'laao-advertiser-portal' ); ?>
												</button>
											</form>
										<?php else : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Screen::REACTIVATE_ACTION ); ?>">
												<input type="hidden" name="org_id" value="<?php echo esc_attr( (string) $laao_ads_row['id'] ); ?>">
												<?php wp_nonce_field( Organization_Screen::nonce_action( Organization_Screen::REACTIVATE_ACTION, $laao_ads_row['id'] ) ); ?>
												<button class="laao-ads-button laao-ads-button--small" type="submit">
													<?php esc_html_e( 'Reactivate', 'laao-advertiser-portal' ); ?>
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
