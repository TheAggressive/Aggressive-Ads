<?php
/**
 * Browse packages, assess a dated request, and continue through campaign review.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Inventory_Actions;
use Aggressive\Ads\Portal\Inventory_View_Data;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only query fields are strictly typed and validated by the view's shared parser; GET never creates a campaign or reservation.
$aggr_inventory = Plugin::instance()->container()->get( Inventory_View_Data::class )->view( $_GET );
?>
<div class="aggr-pagehead">
	<div><p class="aggr-eyebrow"><?php esc_html_e( 'Inventory', 'aggressive-ads' ); ?></p>
	<h1 class="aggr-title"><?php esc_html_e( 'Browse advertising', 'aggressive-ads' ); ?></h1>
	<p class="aggr-lede"><?php esc_html_e( 'Choose a package and dates, then prepare a campaign request for review.', 'aggressive-ads' ); ?></p></div>
</div>
<?php if ( is_wp_error( $aggr_inventory ) ) : ?>
	<p role="alert"><?php echo esc_html( $aggr_inventory->get_error_message() ); ?></p>
<?php elseif ( array() === $aggr_inventory['packages'] ) : ?>
	<div class="aggr-empty"><p class="aggr-empty__title"><?php esc_html_e( 'No packages are available', 'aggressive-ads' ); ?></p>
	<p><?php esc_html_e( 'Please get in touch with the publisher to discuss advertising.', 'aggressive-ads' ); ?></p></div>
<?php else : ?>
	<section class="aggr-panel aggr-panel--padded" aria-labelledby="aggr-inventory-request">
		<h2 id="aggr-inventory-request"><?php esc_html_e( 'Check your dates', 'aggressive-ads' ); ?></h2>
		<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict equality allowlists one fixed PRG display flag; no user-supplied sentence is rendered. ?>
		<?php if ( '1' === ( $_GET['inventory_error'] ?? '' ) ) : ?>
			<p role="alert"><?php esc_html_e( 'Your request could not be started. Check the package and dates, or try again later.', 'aggressive-ads' ); ?></p>
		<?php endif; ?>
		<?php
		if ( '' !== $aggr_inventory['error'] ) :
			?>
			<p role="alert"><?php echo esc_html( $aggr_inventory['error'] ); ?></p><?php endif; ?>
		<form class="aggr-form" method="get" action="<?php echo esc_url( Routes::url( Request::ROUTE_INVENTORY ) ); ?>">
			<input type="hidden" name="check" value="1">
			<div class="aggr-field"><label for="aggr-inventory-package"><?php esc_html_e( 'Package', 'aggressive-ads' ); ?></label>
			<select id="aggr-inventory-package" name="package_id" required>
				<?php foreach ( $aggr_inventory['packages'] as $aggr_offer ) : ?>
					<option value="<?php echo esc_attr( (string) $aggr_offer['id'] ); ?>" <?php selected( $aggr_inventory['selected']['id'] ?? 0, $aggr_offer['id'] ); ?>><?php echo esc_html( $aggr_offer['name'] . ' — ' . $aggr_offer['price'] . ' — ' . $aggr_offer['duration'] ); ?></option>
				<?php endforeach; ?>
			</select></div>
			<p class="aggr-hint" id="aggr-inventory-zone"><?php printf( /* translators: %s: site timezone. */ esc_html__( 'Dates use %s. Fixed packages calculate their own end date; custom packages require an end date.', 'aggressive-ads' ), esc_html( $aggr_inventory['timezone'] ) ); ?></p>
			<div class="aggr-field"><label for="aggr-inventory-start"><?php esc_html_e( 'Start date', 'aggressive-ads' ); ?></label>
			<input id="aggr-inventory-start" type="date" name="start_date" value="<?php echo esc_attr( $aggr_inventory['start_date'] ); ?>" min="<?php echo esc_attr( $aggr_inventory['min_date'] ); ?>" required aria-describedby="aggr-inventory-zone"></div>
			<div class="aggr-field"><label for="aggr-inventory-end"><?php esc_html_e( 'End date', 'aggressive-ads' ); ?></label>
			<input id="aggr-inventory-end" type="date" name="end_date" value="<?php echo esc_attr( $aggr_inventory['end_date'] ); ?>" min="<?php echo esc_attr( $aggr_inventory['min_date'] ); ?>" aria-describedby="aggr-inventory-zone"></div>
			<button class="aggr-button aggr-button--secondary" type="submit"><?php esc_html_e( 'Check availability', 'aggressive-ads' ); ?></button>
		</form>
		<?php if ( null !== $aggr_inventory['plan'] ) : ?>
			<div class="aggr-inventory-result">
				<h3><?php echo esc_html( $aggr_inventory['selected']['name'] ); ?></h3>
				<p><?php echo esc_html( $aggr_inventory['start_date'] . ' — ' . $aggr_inventory['end_date'] . ' (' . $aggr_inventory['timezone'] . ')' ); ?></p>
				<?php
				if ( '' !== $aggr_inventory['availability'] ) :
					?>
					<p role="status"><strong><?php echo esc_html( $aggr_inventory['availability'] ); ?></strong></p><?php endif; ?>
				<p class="aggr-hint"><?php esc_html_e( 'Availability is advisory and can change. Limited means the publisher needs to confirm these dates. A request does not hold inventory; reservations are arranged by staff.', 'aggressive-ads' ); ?></p>
				<p class="aggr-hint"><?php esc_html_e( 'You can ask the publisher to review a request even when availability needs confirmation. No impression volume or reach is promised here.', 'aggressive-ads' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( Inventory_Actions::CREATE_ACTION ); ?>">
					<input type="hidden" name="package_id" value="<?php echo esc_attr( (string) $aggr_inventory['plan']['package_id'] ); ?>">
					<input type="hidden" name="start_date" value="<?php echo esc_attr( $aggr_inventory['start_date'] ); ?>">
					<input type="hidden" name="end_date" value="<?php echo esc_attr( $aggr_inventory['end_date'] ); ?>">
					<?php wp_nonce_field( Inventory_Actions::CREATE_ACTION ); ?>
					<button class="aggr-button" type="submit"><?php esc_html_e( 'Prepare campaign request', 'aggressive-ads' ); ?></button>
				</form>
			</div>
		<?php endif; ?>
	</section>
	<?php if ( array() !== $aggr_inventory['placements'] ) : ?>
		<section aria-labelledby="aggr-inventory-placements">
			<h2 id="aggr-inventory-placements"><?php esc_html_e( 'Placement examples and requirements', 'aggressive-ads' ); ?></h2>
			<p class="aggr-hint"><?php printf( /* translators: %s: supported image formats. */ esc_html__( 'Supported image formats: %s.', 'aggressive-ads' ), esc_html( $aggr_inventory['formats'] ) ); ?></p>
			<div class="aggr-package__sizes" aria-hidden="true">
				<?php foreach ( $aggr_inventory['selected']['sizes'] as $aggr_size ) : ?>
					<span class="aggr-package__size"><span class="aggr-package__shape" style="width: <?php echo esc_attr( (string) $aggr_size['width'] ); ?>px; height: <?php echo esc_attr( (string) $aggr_size['height'] ); ?>px"></span><?php echo esc_html( $aggr_size['label'] ); ?></span>
				<?php endforeach; ?>
			</div>
			<div class="aggr-inventory-grid">
				<?php foreach ( $aggr_inventory['placements'] as $aggr_place ) : ?>
					<article class="aggr-panel aggr-panel--padded">
						<h3><?php echo esc_html( $aggr_place['name'] ); ?></h3>
						<p><?php echo esc_html( $aggr_place['size'] . ' px · ' . $aggr_place['max_size'] ); ?></p>
						<?php if ( '' !== $aggr_place['image'] ) : ?>
							<img class="aggr-inventory-example" src="<?php echo esc_url( $aggr_place['image'] ); ?>" alt="<?php esc_attr_e( 'Publisher example', 'aggressive-ads' ); ?>" loading="lazy">
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>
	<section aria-labelledby="aggr-inventory-packages">
		<h2 id="aggr-inventory-packages"><?php esc_html_e( 'Packages', 'aggressive-ads' ); ?></h2>
		<div class="aggr-inventory-grid">
			<?php foreach ( $aggr_inventory['packages'] as $aggr_offer ) : ?>
				<article class="aggr-panel aggr-panel--padded">
					<h3><?php echo esc_html( $aggr_offer['name'] ); ?></h3>
					<p class="aggr-package__price"><?php echo esc_html( $aggr_offer['price'] ); ?></p>
					<p><?php echo esc_html( $aggr_offer['duration'] ); ?></p>
					<p><?php echo esc_html( implode( ', ', $aggr_offer['placements'] ) ); ?></p>
					<a class="aggr-button aggr-button--secondary" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'package_id' => $aggr_offer['id'],
								'start_date' => $aggr_inventory['start_date'],
								'end_date'   => $aggr_inventory['end_date'],
							),
							Routes::url( Request::ROUTE_INVENTORY )
						) . '#aggr-inventory-request'
					);
					?>
																		"><?php printf( /* translators: %s: package name. */ esc_html__( 'Choose %s', 'aggressive-ads' ), esc_html( $aggr_offer['name'] ) ); ?></a>
				</article>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>
