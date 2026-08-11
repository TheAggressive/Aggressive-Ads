<?php
/**
 * Staff placement-to-AdSanity-group mapping screen.
 *
 * @package LAAO_Advertiser_Portal
 *
 * @var array{groups: array<int, array{id: int, name: string}>, rows: array<int, array{id: int, name: string, size: string, active: bool, term_id: int, group_name: string, state: string}>, provider_error: WP_Error|null} $laao_ads_view
 * @var array{type: string, message: string}|null $laao_ads_notice
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Admin\Placement_Mapping_Screen;

$laao_ads_can_edit = null === $laao_ads_view['provider_error'] && array() !== $laao_ads_view['groups'];
?>
<div class="wrap laao-ads-portal laao-ads-admin">
	<header class="laao-ads-pagehead">
		<div>
			<h1 class="laao-ads-title"><?php esc_html_e( 'Ad delivery mappings', 'laao-advertiser-portal' ); ?></h1>
			<p class="laao-ads-lede"><?php esc_html_e( 'Choose the exact AdSanity ad group used by each placement. Approval fails closed when a placement is unmapped or points to a deleted group.', 'laao-advertiser-portal' ); ?></p>
		</div>
	</header>

	<?php if ( is_array( $laao_ads_notice ) ) : ?>
		<div class="laao-ads-flash laao-ads-flash--<?php echo esc_attr( $laao_ads_notice['type'] ); ?>" role="status">
			<?php echo esc_html( $laao_ads_notice['message'] ); ?>
		</div>
	<?php endif; ?>

	<?php if ( $laao_ads_view['provider_error'] instanceof WP_Error ) : ?>
		<div class="laao-ads-provider-state laao-ads-provider-state--error" role="alert">
			<h2><?php esc_html_e( 'AdSanity is unavailable', 'laao-advertiser-portal' ); ?></h2>
			<p><?php echo esc_html( $laao_ads_view['provider_error']->get_error_message() ); ?></p>
		</div>
	<?php elseif ( array() === $laao_ads_view['groups'] ) : ?>
		<div class="laao-ads-provider-state laao-ads-provider-state--error" role="alert">
			<h2><?php esc_html_e( 'No AdSanity ad groups exist', 'laao-advertiser-portal' ); ?></h2>
			<p><?php esc_html_e( 'Create the delivery groups in AdSanity before assigning placements. Existing mappings remain unchanged.', 'laao-advertiser-portal' ); ?></p>
		</div>
	<?php else : ?>
		<div class="laao-ads-provider-state" role="status">
			<?php
			printf(
				/* translators: %s: number of available AdSanity groups. */
				esc_html__( '%s AdSanity groups are available. Mappings use immutable term IDs, not editable names.', 'laao-advertiser-portal' ),
				esc_html( number_format_i18n( count( $laao_ads_view['groups'] ) ) )
			);
			?>
		</div>
	<?php endif; ?>

	<section class="laao-ads-panel" aria-labelledby="laao-ads-mappings-heading">
		<h2 id="laao-ads-mappings-heading" class="laao-ads-panel__head"><?php esc_html_e( 'Placement mappings', 'laao-advertiser-portal' ); ?></h2>

		<?php if ( array() === $laao_ads_view['rows'] ) : ?>
			<div class="laao-ads-empty">
				<h3 class="laao-ads-empty__title"><?php esc_html_e( 'No placements are configured.', 'laao-advertiser-portal' ); ?></h3>
				<p><?php esc_html_e( 'Create the placement catalogue before assigning delivery groups.', 'laao-advertiser-portal' ); ?></p>
			</div>
		<?php else : ?>
			<div class="laao-ads-tablewrap">
				<table class="laao-ads-table laao-ads-mapping-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Placement', 'laao-advertiser-portal' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Size', 'laao-advertiser-portal' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Catalogue', 'laao-advertiser-portal' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Mapping status', 'laao-advertiser-portal' ); ?></th>
							<th scope="col"><?php esc_html_e( 'AdSanity group', 'laao-advertiser-portal' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $laao_ads_view['rows'] as $laao_ads_row ) : ?>
							<tr>
								<th scope="row" class="laao-ads-table__primary"><?php echo esc_html( $laao_ads_row['name'] ); ?></th>
								<td><?php echo esc_html( $laao_ads_row['size'] ); ?></td>
								<td>
									<span class="laao-ads-pill laao-ads-pill--<?php echo $laao_ads_row['active'] ? 'live' : 'neutral'; ?>">
										<?php echo esc_html( $laao_ads_row['active'] ? __( 'Active', 'laao-advertiser-portal' ) : __( 'Inactive', 'laao-advertiser-portal' ) ); ?>
									</span>
								</td>
								<td>
									<?php if ( 'mapped' === $laao_ads_row['state'] ) : ?>
										<span class="laao-ads-pill laao-ads-pill--live"><?php esc_html_e( 'Mapped', 'laao-advertiser-portal' ); ?></span>
									<?php elseif ( 'dangling' === $laao_ads_row['state'] ) : ?>
										<span class="laao-ads-pill laao-ads-pill--danger"><?php esc_html_e( 'Deleted group', 'laao-advertiser-portal' ); ?></span>
									<?php elseif ( 'unmapped' === $laao_ads_row['state'] ) : ?>
										<span class="laao-ads-pill laao-ads-pill--pending"><?php esc_html_e( 'Unmapped', 'laao-advertiser-portal' ); ?></span>
									<?php else : ?>
										<span class="laao-ads-pill laao-ads-pill--neutral"><?php esc_html_e( 'Not checked', 'laao-advertiser-portal' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $laao_ads_can_edit ) : ?>
										<form class="laao-ads-mapping-control" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="<?php echo esc_attr( Placement_Mapping_Screen::ACTION ); ?>">
											<input type="hidden" name="placement_id" value="<?php echo esc_attr( (string) $laao_ads_row['id'] ); ?>">
											<?php wp_nonce_field( Placement_Mapping_Screen::nonce_action( $laao_ads_row['id'] ) ); ?>
											<label class="screen-reader-text" for="laao-ads-group-<?php echo esc_attr( (string) $laao_ads_row['id'] ); ?>">
												<?php
												printf(
													/* translators: %s: placement name. */
													esc_html__( 'AdSanity group for %s', 'laao-advertiser-portal' ),
													esc_html( $laao_ads_row['name'] )
												);
												?>
											</label>
											<select id="laao-ads-group-<?php echo esc_attr( (string) $laao_ads_row['id'] ); ?>" name="adgroup_term_id">
												<option value="0" <?php selected( 0, $laao_ads_row['term_id'] ); ?>><?php esc_html_e( 'Not mapped — approval blocked', 'laao-advertiser-portal' ); ?></option>
												<?php if ( 'dangling' === $laao_ads_row['state'] ) : ?>
													<option value="" selected disabled>
														<?php
														printf(
															/* translators: %d: deleted AdSanity term id. */
															esc_html__( 'Deleted group ID %d — choose a replacement', 'laao-advertiser-portal' ),
															(int) $laao_ads_row['term_id']
														);
														?>
													</option>
												<?php endif; ?>
												<?php foreach ( $laao_ads_view['groups'] as $laao_ads_group ) : ?>
													<option value="<?php echo esc_attr( (string) $laao_ads_group['id'] ); ?>" <?php selected( $laao_ads_group['id'], $laao_ads_row['term_id'] ); ?>>
														<?php echo esc_html( $laao_ads_group['name'] ); ?> (ID <?php echo esc_html( (string) $laao_ads_group['id'] ); ?>)
													</option>
												<?php endforeach; ?>
											</select>
											<button class="laao-ads-button laao-ads-button--secondary" type="submit"><?php esc_html_e( 'Save mapping', 'laao-advertiser-portal' ); ?></button>
										</form>
									<?php elseif ( 'mapped' === $laao_ads_row['state'] ) : ?>
										<?php echo esc_html( $laao_ads_row['group_name'] ); ?> (ID <?php echo esc_html( (string) $laao_ads_row['term_id'] ); ?>)
									<?php else : ?>
										<?php esc_html_e( 'Mapping controls unavailable.', 'laao-advertiser-portal' ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</section>
</div>
