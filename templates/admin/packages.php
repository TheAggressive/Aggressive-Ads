<?php
/**
 * Staff package catalogue, in native WordPress admin markup.
 *
 * Same conventions as templates/admin/settings.php: `wrap`, `#poststuff`,
 * `postbox`, `form-table`, `notice`, `description` and core `button` classes,
 * with no plugin stylesheet enqueued for the screen.
 *
 * `#poststuff` is the scope core hangs postbox padding and typography on — most
 * of those rules are written as `#poststuff .postbox …`. It is not the same
 * thing as `metabox-holder columns-2`, which pairs a main column with a metabox
 * sidebar this screen does not have.
 *
 * `aggr-admin` stays on the wrap purely as the e2e accessibility scope hook.
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
	<fieldset>
		<legend class="screen-reader-text"><span><?php echo esc_html( $legend ); ?></span></legend>
		<?php if ( array() === $placements ) : ?>
			<p class="description"><?php esc_html_e( 'Create placements in Inventory before offering a package.', 'aggressive-ads' ); ?></p>
		<?php else : ?>
			<?php foreach ( $placements as $placement ) : ?>
				<label>
					<input type="checkbox" name="placement_ids[]" value="<?php echo esc_attr( (string) $placement['id'] ); ?>" <?php checked( in_array( $placement['id'], $selected, true ) ); ?>>
					<?php echo esc_html( $placement['name'] ); ?>
					<span class="description"><?php echo esc_html( $placement['size'] . ( $placement['active'] ? '' : ' — ' . __( 'inactive', 'aggressive-ads' ) ) ); ?></span>
				</label>
				<br>
			<?php endforeach; ?>
		<?php endif; ?>
	</fieldset>
	<?php
};

/**
 * The editable fields of one package, as native form-table rows.
 *
 * Shared by the create form and every edit form so the two cannot drift: a
 * field added to one and forgotten in the other is the defect this closure
 * exists to make impossible.
 *
 * @param string                    $id_prefix Unique per form, for label/input pairing.
 * @param array<string, mixed>|null $row       Existing package, or null when creating.
 * @param callable                  $checklist Placement checklist renderer.
 * @param array<int, mixed>         $placements Placement catalogue.
 */
$aggr_package_fields = static function ( string $id_prefix, ?array $row, callable $checklist, array $placements ): void {
	$aggr_selected = null === $row ? array() : (array) $row['placement_ids'];
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id_prefix ); ?>-name"><?php esc_html_e( 'Name', 'aggressive-ads' ); ?></label></th>
			<td>
				<input
					id="<?php echo esc_attr( $id_prefix ); ?>-name"
					class="regular-text"
					name="name"
					type="text"
					required
					maxlength="120"
					value="<?php echo esc_attr( null === $row ? '' : (string) $row['name'] ); ?>"
				>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Placements', 'aggressive-ads' ); ?></th>
			<td><?php $checklist( $placements, $aggr_selected, __( 'Placements', 'aggressive-ads' ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id_prefix ); ?>-duration"><?php esc_html_e( 'Duration (days)', 'aggressive-ads' ); ?></label></th>
			<td>
				<input
					id="<?php echo esc_attr( $id_prefix ); ?>-duration"
					class="small-text"
					name="duration_days"
					type="number"
					min="<?php echo esc_attr( null === $row ? '1' : '0' ); ?>"
					max="3650"
					value="<?php echo esc_attr( null === $row ? '30' : (string) $row['duration_days'] ); ?>"
				>
				<p class="description">
					<label>
						<input type="checkbox" name="custom_duration" value="1" <?php checked( null !== $row && (bool) $row['custom_duration'] ); ?>>
						<?php esc_html_e( 'Advertiser chooses the schedule', 'aggressive-ads' ); ?>
					</label>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id_prefix ); ?>-price"><?php esc_html_e( 'Price (integer cents)', 'aggressive-ads' ); ?></label></th>
			<td>
				<input
					id="<?php echo esc_attr( $id_prefix ); ?>-price"
					class="small-text"
					name="price_cents"
					type="number"
					min="0"
					max="999999999"
					value="<?php echo esc_attr( null === $row ? '0' : (string) $row['price_cents'] ); ?>"
					required
				>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id_prefix ); ?>-currency"><?php esc_html_e( 'Currency', 'aggressive-ads' ); ?></label></th>
			<td>
				<input
					id="<?php echo esc_attr( $id_prefix ); ?>-currency"
					class="small-text code"
					name="currency"
					type="text"
					maxlength="3"
					value="<?php echo esc_attr( null === $row ? 'USD' : (string) $row['currency'] ); ?>"
					required
				>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Availability', 'aggressive-ads' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><span><?php esc_html_e( 'Availability', 'aggressive-ads' ); ?></span></legend>
					<label>
						<input type="checkbox" name="is_active" value="1" <?php checked( null === $row ? true : (bool) $row['is_active'] ); ?>>
						<?php esc_html_e( 'Offered to advertisers', 'aggressive-ads' ); ?>
					</label>
					<br>
					<label>
						<input type="checkbox" name="is_default" value="1" <?php checked( null !== $row && (bool) $row['is_default'] ); ?>>
						<?php esc_html_e( 'Catalogue default', 'aggressive-ads' ); ?>
					</label>
				</fieldset>
			</td>
		</tr>
	</table>
	<?php
};
?>
<div class="wrap aggr-admin">
	<h1><?php esc_html_e( 'Packages', 'aggressive-ads' ); ?></h1>
	<p><?php esc_html_e( 'Packages are the catalogue advertisers choose from. Editing a package never changes campaigns that already selected it.', 'aggressive-ads' ); ?></p>

	<?php if ( is_array( $aggr_notice ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'error' === $aggr_notice['type'] ? 'error' : 'success' ); ?>">
			<p><?php echo esc_html( $aggr_notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<div id="poststuff">

		<div class="postbox">
			<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'New package', 'aggressive-ads' ); ?></h2></div>
			<div class="inside">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( Package_Screen::CREATE_ACTION ); ?>">
					<?php wp_nonce_field( Package_Screen::CREATE_ACTION ); ?>
					<?php $aggr_package_fields( 'aggr-package-create', null, $aggr_placement_checklist, $aggr_view['placements'] ); ?>
					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Create package', 'aggressive-ads' ); ?></button>
					</p>
				</form>
			</div>
		</div>

		<?php if ( array() === $aggr_view['rows'] ) : ?>
			<div class="postbox">
				<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Catalogue', 'aggressive-ads' ); ?></h2></div>
				<div class="inside">
					<p><?php esc_html_e( 'No packages yet. Create the first one above — advertisers only see active, complete packages.', 'aggressive-ads' ); ?></p>
				</div>
			</div>
		<?php else : ?>
			<?php foreach ( $aggr_view['rows'] as $aggr_row ) : ?>
				<?php $aggr_prefix = 'aggr-package-' . (int) $aggr_row['id']; ?>
				<div class="postbox">
					<div class="postbox-header">
						<h2 class="hndle">
							<?php echo esc_html( $aggr_row['name'] ); ?>
							<?php if ( true === $aggr_row['is_default'] ) : ?>
								<span class="description"><?php esc_html_e( '— catalogue default', 'aggressive-ads' ); ?></span>
							<?php endif; ?>
							<?php if ( true !== $aggr_row['is_active'] ) : ?>
								<span class="description"><?php esc_html_e( '— not offered', 'aggressive-ads' ); ?></span>
							<?php endif; ?>
						</h2>
					</div>
					<div class="inside">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( Package_Screen::UPDATE_ACTION ); ?>">
							<input type="hidden" name="package_id" value="<?php echo esc_attr( (string) $aggr_row['id'] ); ?>">
							<?php wp_nonce_field( Package_Screen::nonce_action( $aggr_row['id'] ) ); ?>
							<?php $aggr_package_fields( $aggr_prefix, $aggr_row, $aggr_placement_checklist, $aggr_view['placements'] ); ?>
							<p class="submit">
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Save package', 'aggressive-ads' ); ?></button>
							</p>
						</form>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>

	</div>
</div>
