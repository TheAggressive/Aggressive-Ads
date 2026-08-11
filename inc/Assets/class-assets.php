<?php
/**
 * Enqueueing the portal's assets.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Assets;

use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Portal\Router;

/**
 * Loads the portal stylesheet, on the portal only.
 *
 * **The plugin adds nothing to any other page on the site.** A plugin that
 * enqueues its stylesheet everywhere is a plugin that shows up in somebody
 * else's performance budget and, eventually, in somebody else's layout bug.
 *
 * There is no build step yet. The stylesheet is plain CSS with cascade layers
 * and custom properties, all of which browsers understand directly — adding
 * webpack before there is anything to compile would buy a manifest to read and
 * a build to forget to run.
 */
final class Assets implements Service {

	/**
	 * Stylesheet handle.
	 */
	public const HANDLE = 'laao-ads-portal';

	/**
	 * Constructor.
	 *
	 * @param Router $router The portal router.
	 */
	public function __construct( private readonly Router $router ) {
	}

	/**
	 * Attaches the enqueue.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_head', array( $this, 'print_icon' ) );
	}

	/**
	 * Gives the portal its own tab icon.
	 *
	 * WordPress serves its own W logo from /favicon.ico when no site icon is
	 * set, so a portal that emits nothing shows the WordPress logo in the tab —
	 * on a screen whose whole purpose is to look like it belongs to this
	 * publication rather than to WordPress.
	 *
	 * **Deferred to the site icon whenever there is one.** If somebody has set
	 * Settings → General → Site Icon, that is a deliberate branding decision
	 * and wp_head() has already emitted it; printing ours on top would override
	 * a choice the site owner made on purpose.
	 *
	 * @return void
	 */
	public function print_icon(): void {
		if ( null === $this->router->request() || has_site_icon() ) {
			return;
		}

		$relative = 'assets/icon.svg';

		if ( ! is_file( LAAO_ADS_PLUGIN_DIR . $relative ) ) {
			return;
		}

		printf(
			'<link rel="icon" href="%s" sizes="any" type="image/svg+xml">' . "\n",
			esc_url( LAAO_ADS_PLUGIN_URL . $relative )
		);
	}

	/**
	 * Enqueues the stylesheet when this is a portal request.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( null === $this->router->request() ) {
			return;
		}

		$relative = 'assets/portal.css';
		$path     = LAAO_ADS_PLUGIN_DIR . $relative;

		if ( ! is_file( $path ) ) {
			return;
		}

		// The file's own mtime, so a deploy busts the cache without anybody
		// remembering to bump a version. Falls back to the plugin version rather
		// than to null: null appends the WordPress version, which does not change
		// on a plugin release and would serve the old file to everyone who had it.
		$mtime = filemtime( $path );

		wp_enqueue_style(
			self::HANDLE,
			LAAO_ADS_PLUGIN_URL . $relative,
			array(),
			false === $mtime ? LAAO_ADS_VERSION : (string) $mtime
		);
	}
}
