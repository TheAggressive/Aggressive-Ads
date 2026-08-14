<?php
/**
 * Staff placement catalogue.
 *
 * @package Aggressive\Ads
 *
 * @var array{sizes: array<string, string>, rows: array<int, array{id: int, name: string, slug: string, size: string, size_preset: string, size_width: int, size_height: int, active: bool, sort_order: int, house_attachment_id: int, house_click_url: string, house_alt: string}>} $aggr_view
 * @var array{type: string, message: string}|null $aggr_notice
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Admin\Placement_Screen;
use Aggressive\Ads\Domain\Ad_Sizes;

/**
 * Size controls shared by create and edit.
 *
 * @param array<string, string> $sizes   Common catalogue.
 * @param string                $preset  Selected preset or custom.
 * @param int                   $width   Custom width.
 * @param int                   $height  Custom height.
 * @param string                $suffix  Unique id suffix.
 */
$aggr_size_fields = static function ( array $sizes, string $preset, int $width, int $height, string $suffix ): void {
	?>
	<div class="aggr-field">
		<label for="aggr-size-<?php echo esc_attr( $suffix ); ?>"><?php esc_html_e( 'Size', 'aggressive-ads' ); ?></label>
		<select id="aggr-size-<?php echo esc_attr( $suffix ); ?>" name="size_preset" required>
			<?php foreach ( $sizes as $aggr_key => $aggr_label ) : ?>
				<option value="<?php echo esc_attr( $aggr_key ); ?>" <?php selected( $preset, $aggr_key ); ?>><?php echo esc_html( $aggr_label ); ?></option>
			<?php endforeach; ?>
			<option value="<?php echo esc_attr( Ad_Sizes::CUSTOM ); ?>" <?php selected( $preset, Ad_Sizes::CUSTOM ); ?>><?php esc_html_e( 'Custom size', 'aggressive-ads' ); ?></option>
		</select>
		<p class="aggr-hint"><?php esc_html_e( 'Custom width and height are used only when Custom size is selected. Creatives must match these pixels exactly.', 'aggressive-ads' ); ?></p>
	</div>
	<div class="aggr-field">
		<label for="aggr-size-w-<?php echo esc_attr( $suffix ); ?>"><?php esc_html_e( 'Custom width (px)', 'aggressive-ads' ); ?></label>
		<input id="aggr-size-w-<?php echo esc_attr( $suffix ); ?>" name="size_width" type="number" min="1" max="10000" value="<?php echo esc_attr( (string) max( 0, $width ) ); ?>">
	</div>
	<div class="aggr-field">
		<label for="aggr-size-h-<?php echo esc_attr( $suffix ); ?>"><?php esc_html_e( 'Custom height (px)', 'aggressive-ads' ); ?></label>
		<input id="aggr-size-h-<?php echo esc_attr( $suffix ); ?>" name="size_height" type="number" min="1" max="10000" value="<?php echo esc_attr( (string) max( 0, $height ) ); ?>">
	</div>
	<?php
};
?>
<div class="wrap aggr-portal aggr-admin">
	<header class="aggr-pagehead">
		<div>
			<h1 class="aggr-title"><?php esc_html_e( 'Inventory', 'aggressive-ads' ); ?></h1>
			<p class="aggr-lede"><?php esc_html_e( 'Placements are the slots editors embed. The public slot id is the slug. Changing a slug after a theme has used it will empty that slot until the theme is updated.', 'aggressive-ads' ); ?></p>
		</div>
	</header>

	<?php if ( is_array( $aggr_notice ) ) : ?>
		<div class="aggr-flash aggr-flash--<?php echo esc_attr( $aggr_notice['type'] ); ?>" role="<?php echo 'error' === $aggr_notice['type'] ? 'alert' : 'status'; ?>">
			<?php echo esc_html( $aggr_notice['message'] ); ?>
		</div>
	<?php endif; ?>

	<section class="aggr-panel" aria-labelledby="aggr-placement-create-heading">
		<h2 id="aggr-placement-create-heading" class="aggr-panel__head"><?php esc_html_e( 'New placement', 'aggressive-ads' ); ?></h2>
		<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Placement_Screen::CREATE_ACTION ); ?>">
			<?php wp_nonce_field( Placement_Screen::CREATE_ACTION ); ?>
			<div class="aggr-field">
				<label for="aggr-placement-create-name"><?php esc_html_e( 'Name', 'aggressive-ads' ); ?></label>
				<input id="aggr-placement-create-name" name="name" type="text" required maxlength="120">
			</div>
			<div class="aggr-field">
				<label for="aggr-placement-create-slug"><?php esc_html_e( 'Slot slug', 'aggressive-ads' ); ?></label>
				<input id="aggr-placement-create-slug" name="slug" type="text" maxlength="120" placeholder="header-728x90">
				<p class="aggr-hint"><?php esc_html_e( 'Used in the placement block and aggr_placement(). Leave blank to generate from the name.', 'aggressive-ads' ); ?></p>
			</div>
			<?php $aggr_size_fields( $aggr_view['sizes'], '728x90', 728, 90, 'create' ); ?>
			<div class="aggr-field">
				<label for="aggr-placement-create-sort"><?php esc_html_e( 'Sort order', 'aggressive-ads' ); ?></label>
				<input id="aggr-placement-create-sort" name="sort_order" type="number" min="0" max="9999" value="0">
			</div>
			<div class="aggr-field">
				<label>
					<input type="checkbox" name="is_active" value="1" checked>
					<?php esc_html_e( 'Active — advertisers can select this slot', 'aggressive-ads' ); ?>
				</label>
			</div>
			<button class="aggr-button" type="submit"><?php esc_html_e( 'Create placement', 'aggressive-ads' ); ?></button>
		</form>
	</section>

	<section class="aggr-panel" aria-labelledby="aggr-placements-heading">
		<h2 id="aggr-placements-heading" class="aggr-panel__head"><?php esc_html_e( 'Placements', 'aggressive-ads' ); ?></h2>

		<?php if ( array() === $aggr_view['rows'] ) : ?>
			<div class="aggr-empty">
				<p class="aggr-empty__title"><?php esc_html_e( 'No placements yet', 'aggressive-ads' ); ?></p>
				<p><?php esc_html_e( 'Create a slot above, then add it to a package.', 'aggressive-ads' ); ?></p>
			</div>
		<?php else : ?>
			<?php foreach ( $aggr_view['rows'] as $aggr_row ) : ?>
				<form class="aggr-form aggr-form--stacked" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( Placement_Screen::UPDATE_ACTION ); ?>">
					<input type="hidden" name="placement_id" value="<?php echo esc_attr( (string) $aggr_row['id'] ); ?>">
					<?php wp_nonce_field( Placement_Screen::nonce_action( $aggr_row['id'] ) ); ?>
					<h3><?php echo esc_html( $aggr_row['name'] ); ?> <code><?php echo esc_html( $aggr_row['slug'] ); ?></code></h3>
					<div class="aggr-field">
						<label for="aggr-name-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>"><?php esc_html_e( 'Name', 'aggressive-ads' ); ?></label>
						<input id="aggr-name-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>" name="name" type="text" required maxlength="120" value="<?php echo esc_attr( $aggr_row['name'] ); ?>">
					</div>
					<div class="aggr-field">
						<label for="aggr-slug-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>"><?php esc_html_e( 'Slot slug', 'aggressive-ads' ); ?></label>
						<input id="aggr-slug-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>" name="slug" type="text" required maxlength="120" value="<?php echo esc_attr( $aggr_row['slug'] ); ?>">
					</div>
					<?php $aggr_size_fields( $aggr_view['sizes'], $aggr_row['size_preset'], $aggr_row['size_width'], $aggr_row['size_height'], (string) $aggr_row['id'] ); ?>
					<div class="aggr-field">
						<label for="aggr-sort-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>"><?php esc_html_e( 'Sort order', 'aggressive-ads' ); ?></label>
						<input id="aggr-sort-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>" name="sort_order" type="number" min="0" max="9999" value="<?php echo esc_attr( (string) $aggr_row['sort_order'] ); ?>">
					</div>
					<div class="aggr-field">
						<label>
							<input type="checkbox" name="is_active" value="1" <?php checked( $aggr_row['active'] ); ?>>
							<?php esc_html_e( 'Active', 'aggressive-ads' ); ?>
						</label>
					</div>
					<div class="aggr-field">
						<label for="aggr-house-id-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>"><?php esc_html_e( 'House attachment ID', 'aggressive-ads' ); ?></label>
						<input id="aggr-house-id-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>" name="house_attachment_id" type="number" min="0" value="<?php echo esc_attr( (string) $aggr_row['house_attachment_id'] ); ?>">
					</div>
					<div class="aggr-field">
						<label for="aggr-house-click-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>"><?php esc_html_e( 'House click URL', 'aggressive-ads' ); ?></label>
						<input id="aggr-house-click-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>" name="house_click_url" type="url" value="<?php echo esc_attr( $aggr_row['house_click_url'] ); ?>">
					</div>
					<div class="aggr-field">
						<label for="aggr-house-alt-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>"><?php esc_html_e( 'House alt text', 'aggressive-ads' ); ?></label>
						<input id="aggr-house-alt-<?php echo esc_attr( (string) $aggr_row['id'] ); ?>" name="house_alt" type="text" value="<?php echo esc_attr( $aggr_row['house_alt'] ); ?>">
					</div>
					<button class="aggr-button aggr-button--secondary" type="submit"><?php esc_html_e( 'Save placement', 'aggressive-ads' ); ?></button>
				</form>
			<?php endforeach; ?>
		<?php endif; ?>
	</section>
</div>
