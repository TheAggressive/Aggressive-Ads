<?php
/**
 * Staff settings: modules and brand.
 *
 * @package Aggressive\Ads
 *
 * @var array{modules: array<string, bool>, brand: array<string, string>, delivery: array{fill_ttl: int, house_policy: string}, tracking: array{retention_days: int}} $aggr_settings
 * @var array{type: string, message: string}|null $aggr_notice
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Admin\Settings_Screen;
use Aggressive\Ads\Domain\Settings_Schema;

$aggr_modules  = $aggr_settings['modules'];
$aggr_brand    = $aggr_settings['brand'];
$aggr_delivery = $aggr_settings['delivery'];
$aggr_tracking = $aggr_settings['tracking'];
?>
<div class="wrap aggr-portal aggr-admin">
	<header class="aggr-pagehead">
		<div>
			<h1 class="aggr-title"><?php esc_html_e( 'Settings', 'aggressive-ads' ); ?></h1>
			<p class="aggr-lede"><?php esc_html_e( 'Modules hide surfaces entirely when off. Brand colours are rejected if they fail WCAG AA.', 'aggressive-ads' ); ?></p>
		</div>
	</header>

	<?php if ( is_array( $aggr_notice ) ) : ?>
		<div class="aggr-flash aggr-flash--<?php echo esc_attr( $aggr_notice['type'] ); ?>" role="<?php echo 'error' === $aggr_notice['type'] ? 'alert' : 'status'; ?>">
			<?php echo esc_html( $aggr_notice['message'] ); ?>
		</div>
	<?php endif; ?>

	<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( Settings_Screen::ACTION ); ?>">
		<?php wp_nonce_field( Settings_Screen::ACTION ); ?>

		<section class="aggr-panel" aria-labelledby="aggr-settings-modules-heading">
			<h2 id="aggr-settings-modules-heading" class="aggr-panel__head"><?php esc_html_e( 'Modules', 'aggressive-ads' ); ?></h2>
			<p class="aggr-hint"><?php esc_html_e( 'Off means the route, menu, or field is not registered. Billing has no surface yet. Reporting tiles appear only when Reporting is on.', 'aggressive-ads' ); ?></p>

			<div class="aggr-field">
				<label>
					<input type="checkbox" name="modules[<?php echo esc_attr( Settings_Schema::MODULE_PUBLIC_SIGNUP ); ?>]" value="1" <?php checked( $aggr_modules[ Settings_Schema::MODULE_PUBLIC_SIGNUP ] ); ?>>
					<?php esc_html_e( 'Public signup', 'aggressive-ads' ); ?>
				</label>
				<p class="aggr-hint"><?php esc_html_e( 'WordPress “Anyone can register” must also be on. Invitations still work when this is off.', 'aggressive-ads' ); ?></p>
			</div>
			<div class="aggr-field">
				<label>
					<input type="checkbox" name="modules[<?php echo esc_attr( Settings_Schema::MODULE_BILLING ); ?>]" value="1" <?php checked( $aggr_modules[ Settings_Schema::MODULE_BILLING ] ); ?>>
					<?php esc_html_e( 'Billing UI', 'aggressive-ads' ); ?>
				</label>
			</div>
			<div class="aggr-field">
				<label>
					<input type="checkbox" name="modules[<?php echo esc_attr( Settings_Schema::MODULE_REPORTING ); ?>]" value="1" <?php checked( $aggr_modules[ Settings_Schema::MODULE_REPORTING ] ); ?>>
					<?php esc_html_e( 'Reporting', 'aggressive-ads' ); ?>
				</label>
				<p class="aggr-hint"><?php esc_html_e( 'Advertiser impression, click and CTR tiles. Native fill is always recording; this switch only shows the numbers.', 'aggressive-ads' ); ?></p>
			</div>
		</section>

		<section class="aggr-panel" aria-labelledby="aggr-settings-brand-heading">
			<h2 id="aggr-settings-brand-heading" class="aggr-panel__head"><?php esc_html_e( 'Brand', 'aggressive-ads' ); ?></h2>
			<p class="aggr-hint"><?php esc_html_e( 'Advertiser-facing name and colours. The code prefix never changes.', 'aggressive-ads' ); ?></p>

			<div class="aggr-field">
				<label for="aggr-brand-name"><?php esc_html_e( 'Product name', 'aggressive-ads' ); ?></label>
				<input id="aggr-brand-name" name="brand[product_name]" type="text" required maxlength="<?php echo esc_attr( (string) Settings_Schema::MAX_PRODUCT_NAME ); ?>" value="<?php echo esc_attr( $aggr_brand['product_name'] ); ?>">
			</div>

			<div class="aggr-field">
				<label for="aggr-brand-tagline"><?php esc_html_e( 'Tagline', 'aggressive-ads' ); ?></label>
				<input id="aggr-brand-tagline" name="brand[tagline]" type="text" maxlength="<?php echo esc_attr( (string) Settings_Schema::MAX_TAGLINE ); ?>" value="<?php echo esc_attr( $aggr_brand['tagline'] ); ?>">
				<p class="aggr-hint"><?php esc_html_e( 'Optional. Shown under the name on the portal rail and sign-in screens.', 'aggressive-ads' ); ?></p>
			</div>

			<div class="aggr-field">
				<label for="aggr-brand-logo"><?php esc_html_e( 'Logo URL', 'aggressive-ads' ); ?></label>
				<input id="aggr-brand-logo" name="brand[logo_url]" type="url" maxlength="<?php echo esc_attr( (string) Settings_Schema::MAX_LOGO_URL ); ?>" value="<?php echo esc_attr( $aggr_brand['logo_url'] ); ?>">
				<p class="aggr-hint"><?php esc_html_e( 'Optional https URL. Empty uses the product name as the mark.', 'aggressive-ads' ); ?></p>
			</div>

			<div class="aggr-formgrid">
				<div class="aggr-field">
					<label for="aggr-brand-accent"><?php esc_html_e( 'Accent', 'aggressive-ads' ); ?></label>
					<input id="aggr-brand-accent" name="brand[accent]" type="text" value="<?php echo esc_attr( $aggr_brand['accent'] ); ?>" pattern="#[0-9A-Fa-f]{6}" required>
				</div>
				<div class="aggr-field">
					<label for="aggr-brand-accent-strong"><?php esc_html_e( 'Accent (text on buttons)', 'aggressive-ads' ); ?></label>
					<input id="aggr-brand-accent-strong" name="brand[accent_strong]" type="text" value="<?php echo esc_attr( $aggr_brand['accent_strong'] ); ?>" pattern="#[0-9A-Fa-f]{6}" required>
				</div>
				<div class="aggr-field">
					<label for="aggr-brand-canvas"><?php esc_html_e( 'Canvas', 'aggressive-ads' ); ?></label>
					<input id="aggr-brand-canvas" name="brand[canvas]" type="text" value="<?php echo esc_attr( $aggr_brand['canvas'] ); ?>" pattern="#[0-9A-Fa-f]{6}" required>
				</div>
				<div class="aggr-field">
					<label for="aggr-brand-surface"><?php esc_html_e( 'Surface', 'aggressive-ads' ); ?></label>
					<input id="aggr-brand-surface" name="brand[surface]" type="text" value="<?php echo esc_attr( $aggr_brand['surface'] ); ?>" pattern="#[0-9A-Fa-f]{6}" required>
				</div>
				<div class="aggr-field">
					<label for="aggr-brand-text"><?php esc_html_e( 'Text', 'aggressive-ads' ); ?></label>
					<input id="aggr-brand-text" name="brand[text]" type="text" value="<?php echo esc_attr( $aggr_brand['text'] ); ?>" pattern="#[0-9A-Fa-f]{6}" required>
				</div>
			</div>
		</section>

		<section class="aggr-panel" aria-labelledby="aggr-settings-delivery-heading">
			<h2 id="aggr-settings-delivery-heading" class="aggr-panel__head"><?php esc_html_e( 'Delivery', 'aggressive-ads' ); ?></h2>
			<p class="aggr-hint"><?php esc_html_e( 'Native fill cache and house-ad policy. This plugin is the source of truth for which creative fills each slot.', 'aggressive-ads' ); ?></p>

			<div class="aggr-formgrid">
				<div class="aggr-field">
					<label for="aggr-fill-ttl"><?php esc_html_e( 'Fill cache TTL (seconds)', 'aggressive-ads' ); ?></label>
					<input id="aggr-fill-ttl" name="delivery[fill_ttl]" type="number" min="<?php echo esc_attr( (string) Settings_Schema::MIN_FILL_TTL ); ?>" max="<?php echo esc_attr( (string) Settings_Schema::MAX_FILL_TTL ); ?>" value="<?php echo esc_attr( (string) $aggr_delivery['fill_ttl'] ); ?>" required>
				</div>
				<div class="aggr-field">
					<label for="aggr-house-policy"><?php esc_html_e( 'House ads', 'aggressive-ads' ); ?></label>
					<select id="aggr-house-policy" name="delivery[house_policy]">
						<option value="<?php echo esc_attr( Settings_Schema::HOUSE_WHEN_EMPTY ); ?>" <?php selected( $aggr_delivery['house_policy'], Settings_Schema::HOUSE_WHEN_EMPTY ); ?>><?php esc_html_e( 'When no paid creative is live', 'aggressive-ads' ); ?></option>
						<option value="<?php echo esc_attr( Settings_Schema::HOUSE_NEVER ); ?>" <?php selected( $aggr_delivery['house_policy'], Settings_Schema::HOUSE_NEVER ); ?>><?php esc_html_e( 'Never', 'aggressive-ads' ); ?></option>
					</select>
				</div>
			</div>
		</section>

		<section class="aggr-panel" aria-labelledby="aggr-settings-tracking-heading">
			<h2 id="aggr-settings-tracking-heading" class="aggr-panel__head"><?php esc_html_e( 'Tracking', 'aggressive-ads' ); ?></h2>
			<p class="aggr-hint"><?php esc_html_e( 'Beacon events are stored hashed. Advertiser metric tiles stay absent unless Reporting is on.', 'aggressive-ads' ); ?></p>

			<div class="aggr-field">
				<label for="aggr-retention-days"><?php esc_html_e( 'Event retention (days)', 'aggressive-ads' ); ?></label>
				<input id="aggr-retention-days" name="tracking[retention_days]" type="number" min="<?php echo esc_attr( (string) Settings_Schema::MIN_RETENTION_DAYS ); ?>" max="<?php echo esc_attr( (string) Settings_Schema::MAX_RETENTION_DAYS ); ?>" value="<?php echo esc_attr( (string) $aggr_tracking['retention_days'] ); ?>" required>
			</div>
		</section>

		<div class="aggr-form__actions">
			<button class="aggr-button" type="submit"><?php esc_html_e( 'Save settings', 'aggressive-ads' ); ?></button>
		</div>
	</form>
</div>
