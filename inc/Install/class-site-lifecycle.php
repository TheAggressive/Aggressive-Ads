<?php
/**
 * Per-site install and teardown on a WordPress network.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

use Aggressive\Ads\Core\Service;
use Closure;
use WP_Site;

/**
 * Network activation does not run the activation hook on sites that do not
 * exist yet. A new site's first request can be public fill, which is too
 * late to create aggr_events. This service installs on wp_initialize_site
 * when the plugin is network-active, and drops plugin tables on
 * wp_uninitialize_site because core does not.
 *
 * See docs/data-schema.md.
 */
final class Site_Lifecycle implements Service {

	/**
	 * Constructor.
	 *
	 * @param Upgrader  $upgrader       Version-driven install.
	 * @param Installer $installer      Schema, roles, options.
	 * @param Closure   $flush_rewrites Portal and click-hop rules.
	 */
	public function __construct(
		private readonly Upgrader $upgrader,
		private readonly Installer $installer,
		private readonly Closure $flush_rewrites
	) {
	}

	/**
	 * Attaches site create and delete hooks.
	 */
	public function init(): void {
		add_action( 'wp_initialize_site', array( $this, 'initialize_site' ) );
		add_action( 'wp_uninitialize_site', array( $this, 'uninitialize_site' ) );
	}

	/**
	 * Installs schema on a newly created site when the plugin is network-active.
	 *
	 * Per-site activation never reaches a brand-new blog; that site self-heals
	 * on its first request through maybe_upgrade(). Do not install onto every
	 * new site merely because this file is loaded.
	 *
	 * @param WP_Site $site New site.
	 */
	public function initialize_site( WP_Site $site ): void {
		if ( ! $this->is_network_active() ) {
			return;
		}

		Uninstaller::on_site(
			(int) $site->blog_id,
			function (): void {
				$this->upgrader->maybe_upgrade();
				$this->installer->install();
				( $this->flush_rewrites )();
			}
		);
	}

	/**
	 * Drops plugin tables before core drops the site's default tables.
	 *
	 * @param WP_Site $site Site being removed.
	 */
	public function uninitialize_site( WP_Site $site ): void {
		Uninstaller::on_site(
			(int) $site->blog_id,
			static function (): void {
				Uninstaller::run_for_current_site();
			}
		);
	}

	/**
	 * Whether this plugin is active for the whole network.
	 */
	private function is_network_active(): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active_for_network( plugin_basename( AGGR_PLUGIN_FILE ) );
	}
}
