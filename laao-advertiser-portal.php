<?php
/**
 * Plugin Name:       LAAO Advertiser Portal
 * Plugin URI:        https://laartsonline.com/
 * Update URI:        https://github.com/TheAggressive/LAAO-Advertiser-Portal
 * Description:       Self-service advertising portal for LAArtsOnline. Advertisers build their own campaigns; staff review and approve; approval publishes to AdSanity automatically.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP:      8.4
 * Author:            The Aggressive, LLC
 * Author URI:        https://theaggressive.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       laao-advertiser-portal
 * Domain Path:       /languages
 *
 * This file does four things and must never grow a fifth: declare the header,
 * define constants, guard the PHP/WordPress floor, and hand off to Plugin.
 * Anything else belongs in a service. See docs/architecture.md.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LAAO_ADS_VERSION', '0.1.0' );
define( 'LAAO_ADS_PLUGIN_FILE', __FILE__ );
define( 'LAAO_ADS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LAAO_ADS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LAAO_ADS_MIN_PHP', '8.4' );
define( 'LAAO_ADS_MIN_WP', '6.7' );

/**
 * Reports whether the environment meets the declared floor.
 *
 * The plugin header's `Requires PHP` and `Requires at least` stop WordPress
 * activating on an unsupported site, but they do nothing for a site that was
 * already running and then downgraded, or for a file-only deploy where the
 * activation path never runs. This check is the one that actually holds.
 *
 * @return string Empty when the environment is supported, otherwise the reason.
 */
function laao_ads_unmet_requirement(): string {
	if ( version_compare( PHP_VERSION, LAAO_ADS_MIN_PHP, '<' ) ) {
		return sprintf(
			/* translators: 1: required PHP version, 2: running PHP version. */
			__( 'LAAO Advertiser Portal requires PHP %1$s or newer. This site is running PHP %2$s.', 'laao-advertiser-portal' ),
			LAAO_ADS_MIN_PHP,
			PHP_VERSION
		);
	}

	$wp_version = get_bloginfo( 'version' );

	if ( version_compare( $wp_version, LAAO_ADS_MIN_WP, '<' ) ) {
		return sprintf(
			/* translators: 1: required WordPress version, 2: running WordPress version. */
			__( 'LAAO Advertiser Portal requires WordPress %1$s or newer. This site is running WordPress %2$s.', 'laao-advertiser-portal' ),
			LAAO_ADS_MIN_WP,
			$wp_version
		);
	}

	return '';
}

/**
 * Loads the plugin, or explains why it cannot load.
 *
 * A silent no-op on an unsupported environment produces a plugin that is
 * active, present in the list, and does nothing — which is diagnosed by
 * reading source code. An admin notice is diagnosed by reading the screen.
 *
 * @return void
 */
function laao_ads_bootstrap(): void {
	$unmet = laao_ads_unmet_requirement();

	if ( '' !== $unmet ) {
		add_action(
			'admin_notices',
			static function () use ( $unmet ): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html( $unmet )
				);
			}
		);

		return;
	}

	require_once __DIR__ . '/inc/class-autoloader.php';

	Autoloader::register( __DIR__ . '/inc' );

	Plugin::instance()->boot();
}

laao_ads_bootstrap();
