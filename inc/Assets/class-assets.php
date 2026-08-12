<?php
/**
 * Enqueueing the portal's assets.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Assets;

use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Portal\Request;
use LAAO_Advertiser_Portal\Portal\Router;

/**
 * Loads portal styles and Interactivity script modules, on the portal only.
 *
 * **The plugin adds nothing to any other page on the site.** A plugin that
 * enqueues its stylesheet everywhere is a plugin that shows up in somebody
 * else's performance budget and, eventually, in somebody else's layout bug.
 *
 * Shared Interactivity modules are registered but not enqueued. Feature code
 * calls {@see self::enqueue_dialog()} so a screen with no dialog ships no
 * dialog JavaScript. See docs/interactivity-stores.md.
 *
 * Modules currently live under assets/interactivity/ as hand-authored ES
 * modules — the documented TypeScript → dist/ pipeline lands with the rest of
 * the frontend lane. Registration still no-ops when a file is missing.
 */
final class Assets implements Service {

	/**
	 * Stylesheet handle.
	 */
	public const HANDLE = 'laao-ads-portal';

	/**
	 * Script-module ids (import-map keys).
	 */
	public const MODULE_SCROLL_LOCK = '@laao-ads/scroll-lock';
	public const MODULE_HELPERS     = '@laao-ads/helpers';
	public const MODULE_DIALOG      = '@laao-ads/dialog';

	/**
	 * Interactivity store namespace for the shared dialog.
	 */
	public const DIALOG_STORE = 'laao-advertiser-portal/dialog';

	/**
	 * Whether shared modules have been registered this request.
	 *
	 * @var bool
	 */
	private bool $modules_registered = false;

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

		/*
		 * After themes and plugins have queued front-end chrome. The portal owns
		 * the document (ADR-0001); host theme scripts that expect .site-nav /
		 * .site-footer only produce console noise and wasted bytes here.
		 */
		add_action( 'wp_enqueue_scripts', array( $this, 'strip_host_chrome_assets' ), 9999 );
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
	 * Removes host-theme front-end scripts from portal requests.
	 *
	 * Classic `WP_Scripts` only — Script Modules (Interactivity) live on a
	 * separate registry and are untouched. Styles are left alone: stripping
	 * them is unnecessary for the GSAP console noise and risks removing a
	 * host rule the page still relies on.
	 *
	 * @return void
	 */
	public function strip_host_chrome_assets(): void {
		if ( null === $this->router->request() ) {
			return;
		}

		global $wp_scripts;

		if ( ! ( $wp_scripts instanceof \WP_Scripts ) ) {
			return;
		}

		foreach ( array_values( $wp_scripts->queue ) as $handle ) {
			if ( ! is_string( $handle ) || $this->keeps_classic_handle( $handle ) ) {
				continue;
			}

			wp_dequeue_script( $handle );
		}
	}

	/**
	 * Whether a classic script/style handle belongs on the portal document.
	 *
	 * @param string $handle Script or style handle.
	 * @return bool
	 */
	private function keeps_classic_handle( string $handle ): bool {
		if ( self::HANDLE === $handle || str_starts_with( $handle, 'laao-ads-' ) ) {
			return true;
		}

		// Core packages that might still be classic-enqueued by another plugin.
		return str_starts_with( $handle, 'wp-' )
			|| str_starts_with( $handle, 'jquery' );
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

		$this->register_interactivity_modules();

		/*
		 * Block themes print the import map in wp_head. Enqueue the dialog
		 * module here — not during template render — so @wordpress/interactivity
		 * and the shared @laao-ads/* modules are in that map before the browser
		 * evaluates dialog.js. Late enqueue still prints the <script type=module>
		 * tag, but bare-specifier imports then fail with "Failed to resolve
		 * module specifier".
		 */
		$request = $this->router->request();
		if (
			Request::ROUTE_CAMPAIGNS === $request->route
			&& $request->object_id > 0
			&& function_exists( 'wp_enqueue_script_module' )
			&& is_file( LAAO_ADS_PLUGIN_DIR . 'assets/interactivity/dialog.js' )
		) {
			wp_enqueue_script_module( '@wordpress/interactivity' );
			wp_enqueue_script_module( self::MODULE_DIALOG );
		}
	}

	/**
	 * Enqueues the shared dialog module and hydrates per-instance state.
	 *
	 * Call from a portal screen that renders dialogs. Safe to call more than
	 * once; later calls merge additional dialog ids into state.
	 *
	 * @param array<string, array{isOpen?: bool, animationDuration?: int}> $dialogs Dialog id → state.
	 * @return void
	 */
	public function enqueue_dialog( array $dialogs ): void {
		if ( null === $this->router->request() ) {
			return;
		}

		if ( ! $this->register_interactivity_modules() ) {
			return;
		}

		if ( ! function_exists( 'wp_enqueue_script_module' ) || ! function_exists( 'wp_interactivity_state' ) ) {
			return;
		}

		// Idempotent if already enqueued from enqueue(); keeps non-campaign callers working.
		wp_enqueue_script_module( '@wordpress/interactivity' );
		wp_enqueue_script_module( self::MODULE_DIALOG );

		$normalized = array();

		foreach ( $dialogs as $id => $config ) {
			if ( ! is_string( $id ) || '' === $id || ! is_array( $config ) ) {
				continue;
			}

			$normalized[ $id ] = array(
				'isOpen'            => ! empty( $config['isOpen'] ),
				'animationDuration' => isset( $config['animationDuration'] )
					? max( 0, (int) $config['animationDuration'] )
					: 200,
			);
		}

		if ( array() === $normalized ) {
			return;
		}

		wp_interactivity_state(
			self::DIALOG_STORE,
			array(
				'dialogs' => $normalized,
				'i18n'    => array(
					'opened' => __( 'Dialog opened', 'laao-advertiser-portal' ),
					'closed' => __( 'Dialog closed', 'laao-advertiser-portal' ),
				),
			)
		);
	}

	/**
	 * Registers shared modules without enqueuing them.
	 *
	 * @return bool True when the Script Modules API exists and dialog registered.
	 */
	private function register_interactivity_modules(): bool {
		if ( ! function_exists( 'wp_register_script_module' ) ) {
			return false;
		}

		if ( ! is_file( LAAO_ADS_PLUGIN_DIR . 'assets/interactivity/dialog.js' ) ) {
			return false;
		}

		if ( $this->modules_registered ) {
			return true;
		}

		$this->modules_registered = true;

		$ok = $this->register_module( self::MODULE_SCROLL_LOCK, 'scroll-lock', array() );
		$ok = $this->register_module( self::MODULE_HELPERS, 'helpers', array() ) && $ok;
		$ok = $this->register_module(
			self::MODULE_DIALOG,
			'dialog',
			array(
				'@wordpress/interactivity',
				self::MODULE_SCROLL_LOCK,
				self::MODULE_HELPERS,
			)
		) && $ok;

		return $ok;
	}

	/**
	 * Registers one module when its file exists.
	 *
	 * @param string             $module_id Module id.
	 * @param string             $basename  File basename under assets/interactivity/.
	 * @param array<int, string> $deps      Module dependency ids.
	 * @return bool
	 */
	private function register_module( string $module_id, string $basename, array $deps ): bool {
		$relative = 'assets/interactivity/' . $basename . '.js';
		$path     = LAAO_ADS_PLUGIN_DIR . $relative;

		if ( ! is_file( $path ) ) {
			return false;
		}

		$mtime = filemtime( $path );

		// Stubs type deps as id/import shapes; string ids remain valid at runtime.
		$normalized = array_map(
			static fn( string $id ): array => array(
				'id'     => $id,
				'import' => 'static',
			),
			$deps
		);

		wp_register_script_module(
			$module_id,
			LAAO_ADS_PLUGIN_URL . $relative,
			$normalized,
			false === $mtime ? LAAO_ADS_VERSION : (string) $mtime
		);

		return true;
	}
}
