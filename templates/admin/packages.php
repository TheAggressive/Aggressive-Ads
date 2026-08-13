<?php
/**
 * Staff package catalogue.
 *
 * @package Aggressive\Ads
 *
 * @var array{default_id: int, placements: array<int, array{id: int, name: string, size: string, active: bool}>, rows: array<int, array{id: int, name: string, placement_ids: array<int, int>, duration_days: int, custom_duration: bool, price_cents: int, currency: string, is_active: bool, is_default: bool}>} $aggr_view
 * @var array{type: string, message: string}|null $aggr_notice
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Admin\Package_Screen;

/**
 * Placement checklist for one package form.
 *
 * @param array<int, array{id: int, name: string, size: string, active: bool}> $placements Catalogue.
 * @param array<int, int>                                                     $selected   Selected ids.
 * @param string                                                              $legend     Fieldset legend.
 */
$aggr_placement_checklist = static function ( array $placements, array $selected, string $legend ): void {
	?>
	<fieldset class="aggr-fieldset">
		<legend><?php echo esc_html( $legend ); ?></legend>
		<?php if ( array() === $placements ) : ?>
			<p class="aggr-hint"><?php esc_html_e( 'Create placements in Inventory before offering a package.', 'aggressive-ads' ); ?></p>
		<?php else : ?>
			<?php foreach ( $placements as $placement ) : ?>
				<label>
					<input type="checkbox" name="placement_ids[]" value="<?php echo esc_attr( (string) $placement['id'] ); ?>" <?php checked( in_array( $placement['id'], $selected, true ) ); ?>>
					<?php echo esc_html( $placement['name'] ); ?>
					<span class="aggr-hint"><?php echo esc_html( $placement['size'] . ( $placement['active'] ? '' : ' — ' . __( 'inactive', 'aggressive-ads' ) ) ); ?></span>
				</label>
			<?php endforeach; ?>
		<?php endif; ?>
	</fieldset>
	<?php
};
?>
<div class="wrap aggr-portal aggr-admin">
	<header class="aggr-pagehead">
		<div>
			<h1 class="aggr-title"><?php esc_html_e( 'Packages', 'aggressive-ads' ); ?></h1>
			<p class="aggr-lede"><?php esc_html_e( 'Packages are the catalogue advertisers choose from. Editing a package never changes campaigns that already selected it.', 'aggressive-ads' ); ?></p>
		</div>
	</header>

	<?php if ( is_array( $aggr_notice ) ) : ?>
		<div class="aggr-flash aggr-flash--<?php echo esc_attr( $aggr_notice['type'] ); ?>" role="<?php echo 'error' === $aggr_notice['type'] ? 'alert' : 'status'; ?>">
			<?php echo esc_html( $aggr_notice['message'] ); ?>
		</div>
	<?php endif; ?>

	<section class="aggr-panel" aria-labelledby="aggr-package-create-heading">
		<h2 id="aggr-package-create-heading" class="aggr-panel__head"><?php esc_html_e( 'New package', 'aggressive-ads' ); ?></h2>
		<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Package_Screen::CREATE_ACTION ); ?>">
			<?php wp_nonce_field( Package_Screen::CREATE_ACTION ); ?>
			<div class="aggr-field">
				<label for="aggr-package-create-name"><?php esc_html_e( 'Name', 'aggressive-ads' ); ?></label>
				<input id="aggr-package-create-name" name="name" type="text" required maxlength="120">
			</div>
			<?php $aggr_placement_checklist( $aggr_view['placements'], array(), __( 'Placements', 'aggressive-ads' ) ); ?>
			<div class="aggr-field">
				<label for="aggr-package-create-duration"><?php esc_html_e( 'Duration (days)', 'aggressive-ads' ); ?></label>
				<input id="aggr-package-create-duration" name="duration_days" type="number" min="1" max="3650" value="30">
			</div>
			<div class="aggr-field">
				<label>
					<input type="checkbox" name="custom_duration" value="1">
					<?php esc_html_e( 'Advertiser chooses the schedule', 'aggressive-ads' ); ?>
				</label>
			</div>
			<div class="aggr-field">
				<label for="aggr-package-create-price"><?php esc_html_e( 'Price (integer cents)', 'aggressive-ads' ); ?></label>
				<input id="aggr-package-create-price" name="price_cents" type="number" min="0" max="999999999" value="0" required>
			</div>
			<div class="aggr-field">
				<label for="aggr-package-create-currency"><?php esc_html_e( 'Currency', 'aggressive-ads' ); ?></label>
				<input id="aggr-package-create-currency" name="currency" type="text" value="USD" maxlength="3" required>
			</div>
			<div class="aggr-field">
				<label>
					<input type="checkbox" name="is_active" value="1" checked>
					<?php esc_html_e( 'Offered to advertisers', 'aggressive-ads' ); ?>
				</label>
			</div>
			<div class="aggr-field">
				<label>
					<input type="checkbox" name="is_default" value="1">
					<?php esc_html_e( 'Catalogue default', 'aggressive-ads' ); ?>
				</label>
			</div>
			<button type="submit" class="aggr-button"><?php esc_html_e( 'Create package', 'aggressive-ads' ); ?></button>
		</form>
	</section>

	<section class="aggr-panel" aria-labelledby="aggr-package-list-heading">
		<h2 id="aggr-package-list-heading" class="aggr-panel__head"><?php esc_html_e( 'Catalogue', 'aggressive-ads' ); ?></h2>
		<?php if ( array() === $aggr_view['rows'] ) : ?>
			<div class="aggr-empty">
				<h3 class="aggr-empty__title"><?php esc_html_e( 'No packages yet.', 'aggressive-ads' ); ?></h3>
				<p><?php esc_html_e( 'Create the first package above. Advertisers only see active, complete packages.', 'aggressive-ads' ); ?></p>
			</div>
		<?php else : ?>
			<?php foreach ( $aggr_view['rows'] as $aggr_row ) : ?>
				<form class="aggr-form aggr-package-row" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( Package_Screen::UPDATE_ACTION ); ?>">
					<input type="hidden" name="package_id" value="<?php echo esc_attr( (string) $aggr_row['id'] ); ?>">
					<?php wp_nonce_field( Package_Screen::nonce_action( $aggr_row['id'] ) ); ?>
					<h3><?php echo esc_html( $aggr_row['name'] ); ?></h3>
					<div class="aggr-field">
						<label for="aggr-package-name-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>"><?php esc_html_e( 'Name', 'aggressive-ads' ); ?></label>
						<input id="aggr-package-name-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>" name="name" type="text" required maxlength="120" value="<?php echo esc_attr( $aggr_row['name'] ); ?>">
					</div>
					<?php $aggr_placement_checklist( $aggr_view['placements'], $aggr_row['placement_ids'], __( 'Placements', 'aggressive-ads' ) ); ?>
					<div class="aggr-field">
						<label for="aggr-package-duration-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>"><?php esc_html_e( 'Duration (days)', 'aggressive-ads' ); ?></label>
						<input id="aggr-package-duration-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>" name="duration_days" type="number" min="0" max="3650" value="<?php echo esc_attr( (string) $aggr_row['duration_days'] ); ?>">
					</div>
					<div class="aggr-field">
						<label>
							<input type="checkbox" name="custom_duration" value="1" <?php checked( $aggr_row['custom_duration'] ); ?>>
							<?php esc_html_e( 'Advertiser chooses the schedule', 'aggressive-ads' ); ?>
						</label>
					</div>
					<div class="aggr-field">
						<label for="aggr-package-price-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>"><?php esc_html_e( 'Price (integer cents)', 'aggressive-ads' ); ?></label>
						<input id="aggr-package-price-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>" name="price_cents" type="number" min="0" max="999999999" value="<?php echo esc_attr( (string) $aggr_row['price_cents'] ); ?>" required>
					</div>
					<div class="aggr-field">
						<label for="aggr-package-currency-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>"><?php esc_html_e( 'Currency', 'aggressive-ads' ); ?></label>
						<input id="aggr-package-currency-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>" name="currency" type="text" maxlength="3" value="<?php echo esc_attr( $aggr_row['currency'] ); ?>" required>
					</div>
					<div class="aggr-field">
						<label>
							<input type="checkbox" name="is_active" value="1" <?php checked( $aggr_row['is_active'] ); ?>>
							<?php esc_html_e( 'Offered to advertisers', 'aggressive-ads' ); ?>
						</label>
					</div>
					<div class="aggr-field">
						<label>
							<input type="checkbox" name="is_default" value="1" <?php checked( $aggr_row['is_default'] ); ?>>
							<?php esc_html_e( 'Catalogue default', 'aggressive-ads' ); ?>
						</label>
					</div>
					<button type="submit" class="aggr-button"><?php esc_html_e( 'Save package', 'aggressive-ads' ); ?></button>
				</form>
			<?php endforeach; ?>
		<?php endif; ?>
	</section>
</div>
