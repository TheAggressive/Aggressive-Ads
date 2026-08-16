<?php
/**
 * Staff organization suspension screen, in native WordPress admin markup.
 *
 * Same conventions as settings.php, packages.php and placements.php: `wrap`,
 * `#poststuff`, `postbox`, `wp-list-table`, `notice` and core `button`
 * classes, with no plugin stylesheet enqueued for the screen.
 *
 * The panel stays a `<section aria-labelledby>` even though a core postbox is a
 * plain div: the landmark is how a screen-reader user reaches this table, and
 * `postbox` supplies looks rather than semantics. The same applies to the
 * notice's live-region role — core's `notice` class does not announce itself.
 *
 * `aggr-admin` stays on the wrap purely as the e2e accessibility scope hook.
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
<div class="wrap aggr-admin">
	<h1><?php esc_html_e( 'Organizations', 'aggressive-ads' ); ?></h1>
	<p><?php esc_html_e( 'Suspend an organization to block new submissions and membership growth. Existing campaigns keep their current status until staff change them.', 'aggressive-ads' ); ?></p>

	<?php if ( is_array( $aggr_notice ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'error' === $aggr_notice['type'] ? 'error' : 'success' ); ?>" role="<?php echo 'error' === $aggr_notice['type'] ? 'alert' : 'status'; ?>">
			<p><?php echo esc_html( $aggr_notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<div id="poststuff">
		<section class="postbox" aria-labelledby="aggr-orgs-heading">
			<div class="postbox-header">
				<h2 id="aggr-orgs-heading" class="hndle"><?php esc_html_e( 'Advertiser organizations', 'aggressive-ads' ); ?></h2>
			</div>
			<div class="inside">
				<?php if ( array() === $aggr_view['rows'] ) : ?>
					<p><?php esc_html_e( 'No organizations yet. They appear here after an advertiser signs up or is invited.', 'aggressive-ads' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat striped">
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
									<th scope="row"><strong><?php echo esc_html( $aggr_row['name'] ); ?></strong></th>
									<td><?php echo esc_html( '' !== $aggr_row['owner_name'] ? $aggr_row['owner_name'] : '—' ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $aggr_row['members'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $aggr_row['campaigns'] ) ); ?></td>
									<td>
										<?php
										/*
										 * Text, not a coloured pill. A suspended organization is a
										 * state a reader must be able to identify without relying on
										 * colour, and core gives no pill component to borrow.
										 */
										echo $aggr_row['active']
											? esc_html__( 'Active', 'aggressive-ads' )
											: '<strong>' . esc_html__( 'Suspended', 'aggressive-ads' ) . '</strong>';
										?>
									</td>
									<td>
										<?php if ( $aggr_row['active'] ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Screen::SUSPEND_ACTION ); ?>">
												<input type="hidden" name="org_id" value="<?php echo esc_attr( (string) $aggr_row['id'] ); ?>">
												<?php wp_nonce_field( Organization_Screen::nonce_action( Organization_Screen::SUSPEND_ACTION, $aggr_row['id'] ) ); ?>
												<button class="button button-link-delete" type="submit">
													<?php
													printf(
														/* translators: %s: organization name. */
														esc_html__( 'Suspend %s', 'aggressive-ads' ),
														esc_html( $aggr_row['name'] )
													);
													?>
												</button>
											</form>
										<?php else : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Screen::REACTIVATE_ACTION ); ?>">
												<input type="hidden" name="org_id" value="<?php echo esc_attr( (string) $aggr_row['id'] ); ?>">
												<?php wp_nonce_field( Organization_Screen::nonce_action( Organization_Screen::REACTIVATE_ACTION, $aggr_row['id'] ) ); ?>
												<button class="button" type="submit">
													<?php
													printf(
														/* translators: %s: organization name. */
														esc_html__( 'Reactivate %s', 'aggressive-ads' ),
														esc_html( $aggr_row['name'] )
													);
													?>
												</button>
											</form>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</section>
	</div>
</div>
