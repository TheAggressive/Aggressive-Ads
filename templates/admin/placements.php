<?php
/**
 * Staff placement catalogue, in native WordPress admin markup.
 *
 * Same conventions as templates/admin/settings.php and packages.php: `wrap`,
 * `#poststuff`, `postbox`, `form-table`, `notice`, `description` and core
 * `button` classes, with no plugin stylesheet enqueued for the screen.
 *
 * `#poststuff` is the scope core hangs postbox padding and typography on, and
 * is not the same thing as `metabox-holder columns-2` — that pairs a main
 * column with a metabox sidebar this screen does not have.
 *
 * `aggr-admin` stays on the wrap purely as the e2e accessibility scope hook.
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
 * Size controls shared by create and edit, as form-table rows.
 *
 * @param array<string, string> $sizes  Common catalogue.
 * @param string                $preset Selected preset or custom.
 * @param int                   $width  Custom width.
 * @param int                   $height Custom height.
 * @param string                $suffix Unique id suffix.
 */
$aggr_size_fields = static function ( array $sizes, string $preset, int $width, int $height, string $suffix ): void {
	?>
	<tr>
		<th scope="row"><label for="aggr-size-<?php echo esc_attr( $suffix ); ?>"><?php esc_html_e( 'Size', 'aggressive-ads' ); ?></label></th>
		<td>
			<select id="aggr-size-<?php echo esc_attr( $suffix ); ?>" name="size_preset" required>
				<?php foreach ( $sizes as $aggr_key => $aggr_label ) : ?>
					<option value="<?php echo esc_attr( $aggr_key ); ?>" <?php selected( $preset, $aggr_key ); ?>><?php echo esc_html( $aggr_label ); ?></option>
				<?php endforeach; ?>
				<option value="<?php echo esc_attr( Ad_Sizes::CUSTOM ); ?>" <?php selected( $preset, Ad_Sizes::CUSTOM ); ?>><?php esc_html_e( 'Custom size', 'aggressive-ads' ); ?></option>
			</select>
			<p class="description"><?php esc_html_e( 'Custom width and height are used only when Custom size is selected. Creatives must match these pixels exactly.', 'aggressive-ads' ); ?></p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="aggr-size-w-<?php echo esc_attr( $suffix ); ?>"><?php esc_html_e( 'Custom width (px)', 'aggressive-ads' ); ?></label></th>
		<td><input id="aggr-size-w-<?php echo esc_attr( $suffix ); ?>" class="small-text" name="size_width" type="number" min="1" max="10000" value="<?php echo esc_attr( (string) max( 0, $width ) ); ?>"></td>
	</tr>
	<tr>
		<th scope="row"><label for="aggr-size-h-<?php echo esc_attr( $suffix ); ?>"><?php esc_html_e( 'Custom height (px)', 'aggressive-ads' ); ?></label></th>
		<td><input id="aggr-size-h-<?php echo esc_attr( $suffix ); ?>" class="small-text" name="size_height" type="number" min="1" max="10000" value="<?php echo esc_attr( (string) max( 0, $height ) ); ?>"></td>
	</tr>
	<?php
};
?>
<div class="wrap aggr-admin">
	<h1><?php esc_html_e( 'Inventory', 'aggressive-ads' ); ?></h1>
	<p><?php esc_html_e( 'Placements are the slots editors embed. The public slot id is the slug. Changing a slug after a theme has used it will empty that slot until the theme is updated.', 'aggressive-ads' ); ?></p>

	<?php if ( is_array( $aggr_notice ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'error' === $aggr_notice['type'] ? 'error' : 'success' ); ?>" role="<?php echo 'error' === $aggr_notice['type'] ? 'alert' : 'status'; ?>">
			<p><?php echo esc_html( $aggr_notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<div id="poststuff">

		<section class="postbox" aria-labelledby="aggr-placement-create-heading">
			<div class="postbox-header"><h2 id="aggr-placement-create-heading" class="hndle"><?php esc_html_e( 'New placement', 'aggressive-ads' ); ?></h2></div>
			<div class="inside">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( Placement_Screen::CREATE_ACTION ); ?>">
					<?php wp_nonce_field( Placement_Screen::CREATE_ACTION ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="aggr-placement-create-name"><?php esc_html_e( 'Name', 'aggressive-ads' ); ?></label></th>
							<td><input id="aggr-placement-create-name" class="regular-text" name="name" type="text" required maxlength="120"></td>
						</tr>
						<tr>
							<th scope="row"><label for="aggr-placement-create-slug"><?php esc_html_e( 'Slot slug', 'aggressive-ads' ); ?></label></th>
							<td>
								<input id="aggr-placement-create-slug" class="regular-text code" name="slug" type="text" maxlength="120" placeholder="header-728x90">
								<p class="description"><?php esc_html_e( 'Used in the placement block and aggr_placement(). Leave blank to generate from the name.', 'aggressive-ads' ); ?></p>
							</td>
						</tr>
						<?php $aggr_size_fields( $aggr_view['sizes'], '728x90', 728, 90, 'create' ); ?>
						<tr>
							<th scope="row"><label for="aggr-placement-create-sort"><?php esc_html_e( 'Sort order', 'aggressive-ads' ); ?></label></th>
							<td><input id="aggr-placement-create-sort" class="small-text" name="sort_order" type="number" min="0" max="9999" value="0"></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Availability', 'aggressive-ads' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="is_active" value="1" checked>
									<?php esc_html_e( 'Active — advertisers can select this slot', 'aggressive-ads' ); ?>
								</label>
							</td>
						</tr>
					</table>
					<p class="submit">
						<button class="button button-primary" type="submit"><?php esc_html_e( 'Create placement', 'aggressive-ads' ); ?></button>
					</p>
				</form>
			</div>
		</section>

		<?php if ( array() === $aggr_view['rows'] ) : ?>
			<div class="postbox">
				<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Placements', 'aggressive-ads' ); ?></h2></div>
				<div class="inside">
					<p><?php esc_html_e( 'No placements yet. Create a slot above, then add it to a package.', 'aggressive-ads' ); ?></p>
				</div>
			</div>
		<?php else : ?>
			<?php foreach ( $aggr_view['rows'] as $aggr_row ) : ?>
				<?php $aggr_id = (string) (int) $aggr_row['id']; ?>
				<div class="postbox">
					<div class="postbox-header">
						<h2 class="hndle">
							<?php echo esc_html( $aggr_row['name'] ); ?>
							<code><?php echo esc_html( $aggr_row['slug'] ); ?></code>
							<span class="description"><?php echo esc_html( $aggr_row['size'] ); ?></span>
							<?php if ( true !== $aggr_row['active'] ) : ?>
								<span class="description"><?php esc_html_e( '— inactive', 'aggressive-ads' ); ?></span>
							<?php endif; ?>
						</h2>
					</div>
					<div class="inside">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( Placement_Screen::UPDATE_ACTION ); ?>">
							<input type="hidden" name="placement_id" value="<?php echo esc_attr( $aggr_id ); ?>">
							<?php wp_nonce_field( Placement_Screen::nonce_action( $aggr_row['id'] ) ); ?>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><label for="aggr-name-<?php echo esc_attr( $aggr_id ); ?>"><?php esc_html_e( 'Name', 'aggressive-ads' ); ?></label></th>
									<td><input id="aggr-name-<?php echo esc_attr( $aggr_id ); ?>" class="regular-text" name="name" type="text" required maxlength="120" value="<?php echo esc_attr( $aggr_row['name'] ); ?>"></td>
								</tr>
								<tr>
									<th scope="row"><label for="aggr-slug-<?php echo esc_attr( $aggr_id ); ?>"><?php esc_html_e( 'Slot slug', 'aggressive-ads' ); ?></label></th>
									<td>
										<input id="aggr-slug-<?php echo esc_attr( $aggr_id ); ?>" class="regular-text code" name="slug" type="text" required maxlength="120" value="<?php echo esc_attr( $aggr_row['slug'] ); ?>">
										<p class="description"><?php esc_html_e( 'Changing this empties the slot in any theme still using the old id.', 'aggressive-ads' ); ?></p>
									</td>
								</tr>
								<?php $aggr_size_fields( $aggr_view['sizes'], $aggr_row['size_preset'], $aggr_row['size_width'], $aggr_row['size_height'], $aggr_id ); ?>
								<tr>
									<th scope="row"><label for="aggr-sort-<?php echo esc_attr( $aggr_id ); ?>"><?php esc_html_e( 'Sort order', 'aggressive-ads' ); ?></label></th>
									<td><input id="aggr-sort-<?php echo esc_attr( $aggr_id ); ?>" class="small-text" name="sort_order" type="number" min="0" max="9999" value="<?php echo esc_attr( (string) $aggr_row['sort_order'] ); ?>"></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Availability', 'aggressive-ads' ); ?></th>
									<td>
										<label>
											<input type="checkbox" name="is_active" value="1" <?php checked( $aggr_row['active'] ); ?>>
											<?php esc_html_e( 'Active', 'aggressive-ads' ); ?>
										</label>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="aggr-house-id-<?php echo esc_attr( $aggr_id ); ?>"><?php esc_html_e( 'House attachment ID', 'aggressive-ads' ); ?></label></th>
									<td>
										<input id="aggr-house-id-<?php echo esc_attr( $aggr_id ); ?>" class="small-text" name="house_attachment_id" type="number" min="0" value="<?php echo esc_attr( (string) $aggr_row['house_attachment_id'] ); ?>">
										<p class="description"><?php esc_html_e( 'Shown when no paid creative is live, if the Delivery house-ad policy allows it.', 'aggressive-ads' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="aggr-house-click-<?php echo esc_attr( $aggr_id ); ?>"><?php esc_html_e( 'House click URL', 'aggressive-ads' ); ?></label></th>
									<td><input id="aggr-house-click-<?php echo esc_attr( $aggr_id ); ?>" class="regular-text code" name="house_click_url" type="url" value="<?php echo esc_attr( $aggr_row['house_click_url'] ); ?>"></td>
								</tr>
								<tr>
									<th scope="row"><label for="aggr-house-alt-<?php echo esc_attr( $aggr_id ); ?>"><?php esc_html_e( 'House alt text', 'aggressive-ads' ); ?></label></th>
									<td><input id="aggr-house-alt-<?php echo esc_attr( $aggr_id ); ?>" class="regular-text" name="house_alt" type="text" value="<?php echo esc_attr( $aggr_row['house_alt'] ); ?>"></td>
								</tr>
							</table>
							<p class="submit">
								<button class="button button-primary" type="submit"><?php esc_html_e( 'Save placement', 'aggressive-ads' ); ?></button>
							</p>
						</form>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>

	</div>
</div>
