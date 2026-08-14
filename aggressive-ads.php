<?php
/**
 * Plugin Name:       Aggressive Ads
 * Plugin URI:        https://theaggressive.com
 * Update URI:        https://github.com/TheAggressive/Aggressive-Ads
 * Description:       Live means live. Advertisers build campaigns; staff review; approval publishes.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP:      8.4
 * Author:            The Aggressive, LLC
 * Author URI:        https://theaggressive.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aggressive-ads
 * Domain Path:       /languages
 *
 * This file does four things and must never grow a fifth: declare the header,
 * define constants, guard the PHP/WordPress floor, and hand off to Plugin.
 * Anything else belongs in a service. See docs/architecture.md.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGGR_VERSION', '0.1.0' );
define( 'AGGR_PLUGIN_FILE', __FILE__ );
define( 'AGGR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGGR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AGGR_MIN_PHP', '8.4' );
define( 'AGGR_MIN_WP', '6.7' );

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
function aggr_unmet_requirement(): string {
	if ( version_compare( PHP_VERSION, AGGR_MIN_PHP, '<' ) ) {
		return sprintf(
			/* translators: 1: required PHP version, 2: running PHP version. */
			__( 'Aggressive Ads requires PHP %1$s or newer. This site is running PHP %2$s.', 'aggressive-ads' ),
			AGGR_MIN_PHP,
			PHP_VERSION
		);
	}

	$wp_version = get_bloginfo( 'version' );

	if ( version_compare( $wp_version, AGGR_MIN_WP, '<' ) ) {
		return sprintf(
			/* translators: 1: required WordPress version, 2: running WordPress version. */
			__( 'Aggressive Ads requires WordPress %1$s or newer. This site is running WordPress %2$s.', 'aggressive-ads' ),
			AGGR_MIN_WP,
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
function aggr_bootstrap(): void {
	$unmet = aggr_unmet_requirement();

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

aggr_bootstrap();
