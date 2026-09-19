<?php
/**
 * Enqueueing the portal's assets.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Assets;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Router;
use Aggressive\Ads\REST\Api;

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
 * Compiled assets live under dist/ (TypeScript / CSS from src/). Registration
 * no-ops when a built file is missing — run `pnpm build` before loading the
 * portal in development.
 */
final class Assets implements Service {

	/**
	 * Stylesheet handle.
	 */
	public const HANDLE = 'aggr-portal';

	/**
	 * Compiled portal stylesheet (relative to plugin root).
	 */
	public const STYLE_PORTAL = 'dist/styles/portal.css';

	/**
	 * Compiled admin stylesheet (relative to plugin root).
	 */
	public const STYLE_ADMIN = 'dist/styles/admin.css';

	/**
	 * Script-module ids (import-map keys).
	 */
	public const MODULE_SCROLL_LOCK = '@aggr/scroll-lock';
	public const MODULE_HELPERS     = '@aggr/helpers';
	public const MODULE_LOGIC       = '@aggr/logic';
	public const MODULE_DIALOG      = '@aggr/dialog';
	public const MODULE_WIZARD      = '@aggr/wizard';
	public const MODULE_AUTOSAVE    = '@aggr/autosave';
	public const MODULE_UPLOAD      = '@aggr/upload';
	public const MODULE_SAVE        = '@aggr/save';
	public const MODULE_LOCAL_TIME  = '@aggr/local-time';
	public const MODULE_LIST_MORE   = '@aggr/list-more';
	public const MODULE_CALENDAR    = '@aggr/calendar';
	public const MODULE_ZONE_NOTES  = '@aggr/zone-notes';
	public const MODULE_LINK_TOOLS  = '@aggr/link-tools';

	/**
	 * Interactivity store namespaces.
	 */
	public const DIALOG_STORE   = 'aggr/dialog';
	public const WIZARD_STORE   = 'aggr/wizard';
	public const AUTOSAVE_STORE = 'aggr/autosave';
	public const UPLOAD_STORE   = 'aggr/upload';
	public const SAVE_STORE     = 'aggr/save';

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
		 * the document; host theme scripts that expect .site-nav /
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
		if ( ! $this->router->draws_page() || has_site_icon() ) {
			return;
		}

		$relative = 'assets/icon.svg';

		if ( ! is_file( AGGR_PLUGIN_DIR . $relative ) ) {
			return;
		}

		printf(
			'<link rel="icon" href="%s" sizes="any" type="image/svg+xml">' . "\n",
			esc_url( AGGR_PLUGIN_URL . $relative )
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
		if ( ! $this->router->draws_page() ) {
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
		if ( self::HANDLE === $handle || str_starts_with( $handle, 'aggr-' ) ) {
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
		// Every page the portal draws, its own 404 included.
		if ( ! $this->router->draws_page() ) {
			return;
		}

		$this->enqueue_style( self::HANDLE, self::STYLE_PORTAL );
		$this->register_interactivity_modules();

		/*
		 * On every portal screen, because UTC moments are stated on more than
		 * one. It depends on nothing, so it needs no gate beyond its own file.
		 */
		if ( function_exists( 'wp_register_script_module' ) && is_file( AGGR_PLUGIN_DIR . 'dist/interactivity/local-time.js' ) ) {
			$this->register_module( self::MODULE_LOCAL_TIME, 'local-time', array() );
			wp_enqueue_script_module( self::MODULE_LOCAL_TIME );
		}

		// The campaigns list only: it is the one screen with pages to load.
		$list_request = $this->router->request();

		if (
			null !== $list_request
			&& Request::ROUTE_CAMPAIGNS === $list_request->route
			&& 0 === $list_request->object_id
			&& function_exists( 'wp_register_script_module' )
			&& is_file( AGGR_PLUGIN_DIR . 'dist/interactivity/list-more.js' )
		) {
			$this->register_module( self::MODULE_LIST_MORE, 'list-more', array() );
			wp_enqueue_script_module( self::MODULE_LIST_MORE );
		}

		/*
		 * Block themes print the import map in wp_head. Enqueue the dialog
		 * module here — not during template render — so @wordpress/interactivity
		 * and the shared @aggr/* modules are in that map before the browser
		 * evaluates dialog.js. Late enqueue still prints the <script type=module>
		 * tag, but bare-specifier imports then fail with "Failed to resolve
		 * module specifier".
		 */
		$request = $this->router->request();
		if (
			null !== $request
			&& Request::ROUTE_CAMPAIGNS === $request->route
			&& $request->object_id > 0
			&& function_exists( 'wp_enqueue_script_module' )
			&& is_file( AGGR_PLUGIN_DIR . 'dist/interactivity/dialog.js' )
		) {
			wp_enqueue_script_module( '@wordpress/interactivity' );
			wp_enqueue_script_module( self::MODULE_DIALOG );

			$has_logic = is_file( AGGR_PLUGIN_DIR . 'dist/interactivity/logic.js' );

			if ( $has_logic && is_file( AGGR_PLUGIN_DIR . 'dist/interactivity/wizard.js' ) ) {
				wp_enqueue_script_module( self::MODULE_WIZARD );
			}

			if ( $has_logic && is_file( AGGR_PLUGIN_DIR . 'dist/interactivity/autosave.js' ) ) {
				wp_enqueue_script_module( self::MODULE_AUTOSAVE );
			}

			if ( $has_logic && is_file( AGGR_PLUGIN_DIR . 'dist/interactivity/upload.js' ) ) {
				wp_enqueue_script_module( self::MODULE_UPLOAD );
			}

			/*
			 * No `$has_logic` gate: saving asynchronously shares nothing with
			 * the creative-checking logic module, and requiring it would make
			 * every portal write fall back to a page reload whenever that one
			 * file failed to build.
			 */
			if ( is_file( AGGR_PLUGIN_DIR . 'dist/interactivity/save.js' ) ) {
				wp_enqueue_script_module( self::MODULE_SAVE );
			}

			// Self-contained: the date fields work without it, so nothing gates it.
			if ( is_file( AGGR_PLUGIN_DIR . 'dist/interactivity/calendar.js' ) ) {
				wp_enqueue_script_module( self::MODULE_CALENDAR );
			}

			if ( is_file( AGGR_PLUGIN_DIR . 'dist/interactivity/zone-notes.js' ) ) {
				wp_enqueue_script_module( self::MODULE_ZONE_NOTES );
			}

			// Also self-contained: the link saves and the step finishes without it.
			if ( is_file( AGGR_PLUGIN_DIR . 'dist/interactivity/link-tools.js' ) ) {
				wp_enqueue_script_module( self::MODULE_LINK_TOOLS );
			}
		}
	}

	/**
	 * Enqueues a compiled stylesheet when the file exists.
	 *
	 * @param string             $handle       Style handle.
	 * @param string             $relative     Path relative to the plugin root.
	 * @param array<int, string> $dependencies Style dependencies.
	 * @return void
	 */
	public function enqueue_style( string $handle, string $relative, array $dependencies = array() ): void {
		$path = AGGR_PLUGIN_DIR . $relative;

		if ( ! is_file( $path ) ) {
			return;
		}

		wp_enqueue_style(
			$handle,
			AGGR_PLUGIN_URL . $relative,
			$dependencies,
			$this->asset_version( $relative )
		);
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
					'opened' => __( 'Dialog opened', 'aggressive-ads' ),
					'closed' => __( 'Dialog closed', 'aggressive-ads' ),
				),
			)
		);
	}

	/**
	 * Hydrates wizard, autosave and upload stores for an editable campaign.
	 *
	 * Modules are enqueued from enqueue() so the import map prints in wp_head.
	 * This only writes Interactivity state. Safe to call when the APIs are absent.
	 *
	 * @param array{id: int, wizard_step: string, autosave_rev: int, step_label: string, slots: array<int, array{id: int, size: string, max_bytes: int, max_size: string}>} $campaign Campaign view data.
	 * @return void
	 */
	public function hydrate_campaign_editor( array $campaign ): void {
		if ( null === $this->router->request() ) {
			return;
		}

		if ( ! function_exists( 'wp_interactivity_state' ) ) {
			return;
		}

		$wizard_id   = 'campaign-' . $campaign['id'];
		$autosave_id = $wizard_id;

		wp_interactivity_state(
			self::WIZARD_STORE,
			array(
				'wizards' => array(
					$wizard_id => array(
						'current' => $campaign['wizard_step'],
					),
				),
				'i18n'    => array(
					'step' => $campaign['step_label'],
				),
			)
		);

		wp_interactivity_state(
			self::AUTOSAVE_STORE,
			array(
				'autosaves' => array(
					$autosave_id => array(
						'restUrl'  => rest_url( Api::NAMESPACE . '/campaigns/' . $campaign['id'] ),
						'nonce'    => wp_create_nonce( 'wp_rest' ),
						'revision' => $campaign['autosave_rev'],
						'status'   => 'idle',
					),
				),
				'i18n'      => array(
					'idle'        => '',
					'saving'      => __( 'Saving…', 'aggressive-ads' ),
					'saved'       => __( 'Draft saved.', 'aggressive-ads' ),
					'error'       => __( 'Could not save the draft. Your last change may not be stored.', 'aggressive-ads' ),
					'conflict'    => __( 'This campaign was saved elsewhere. Refresh to continue from the latest draft.', 'aggressive-ads' ),
					'rename'      => __( 'Rename campaign', 'aggressive-ads' ),
					'nameLabel'   => __( 'Campaign name', 'aggressive-ads' ),
					'nameSaved'   => __( 'Campaign renamed.', 'aggressive-ads' ),
					'nameEmpty'   => __( 'A campaign needs a name. The previous name was kept.', 'aggressive-ads' ),
					'nameError'   => __( 'The name could not be saved. The previous name was kept.', 'aggressive-ads' ),
					'linkSaved'   => __( 'Link saved.', 'aggressive-ads' ),
					'linkInvalid' => __( 'That is not a link we can use. Enter a web address, such as https://example.com.', 'aggressive-ads' ),
				),
			)
		);

		/*
		 * One sentence per notice key, because the client is told which key
		 * it got rather than being handed a sentence to display. A message
		 * built on the client is a message that cannot be translated by the
		 * site's own catalogue.
		 */
		wp_interactivity_state(
			self::SAVE_STORE,
			array(
				'i18n' => array(
					'saved'                      => __( 'Saved.', 'aggressive-ads' ),
					'dismiss'                    => __( 'Dismiss this notice', 'aggressive-ads' ),
					'level_success'              => __( 'Success', 'aggressive-ads' ),
					'level_error'                => __( 'Error', 'aggressive-ads' ),
					'level_warning'              => __( 'Warning', 'aggressive-ads' ),
					'level_info'                 => __( 'Notice', 'aggressive-ads' ),
					'creative_uploaded'          => __( 'Creative uploaded.', 'aggressive-ads' ),
					'creative_removed'           => __( 'Creative removed.', 'aggressive-ads' ),
					'creative_destination_saved' => __( 'Destination saved.', 'aggressive-ads' ),
					'creative_artwork_replaced'  => __( 'Artwork replaced.', 'aggressive-ads' ),
					'creative_window_saved'      => __( 'Dates saved.', 'aggressive-ads' ),
					'creative_weight_saved'      => __( 'Share saved.', 'aggressive-ads' ),
					'creative_paused'            => __( 'Paused.', 'aggressive-ads' ),
					'creative_resumed'           => __( 'Resumed.', 'aggressive-ads' ),
					'creative_update_requested'  => __( 'Update sent for review.', 'aggressive-ads' ),
					'creative_update_withdrawn'  => __( 'Update withdrawn.', 'aggressive-ads' ),
				),
			)
		);

		$this->hydrate_uploads( $campaign['slots'] );
	}

	/**
	 * Hydrates the upload store for the upload forms on the page.
	 *
	 * Its own method because the wizard is not the only place an ad is
	 * uploaded: a running campaign's size with no ad takes one from the edit
	 * flow and the campaign page. Those forms rendered and the store knew
	 * nothing about them — no size, no limit, no messages — so choosing a file
	 * did nothing at all. The browser test caught it; the markup test could not.
	 *
	 * @param array<int, array{id: int, size: string, max_bytes: int, max_size: string, name?: string, active?: bool, creatives?: array<int, mixed>}> $slots Placements that take an upload.
	 * @return void
	 */
	public function hydrate_uploads( array $slots ): void {
		if ( null === $this->router->request() || ! function_exists( 'wp_interactivity_state' ) ) {
			return;
		}

		$uploads = array();

		foreach ( $slots as $slot ) {
			$uploads[ (string) $slot['id'] ] = array(
				'expectedSize' => $slot['size'],
				'maxBytes'     => $slot['max_bytes'],
				'maxPixels'    => Upload_Rules::MAX_PIXELS,
				'allowedMime'  => Upload_Rules::ALLOWED_MIME,

				// For the drop zone, which matches every file against every
				// placement: what to call one, and whether it wants a file.
				'name'         => (string) ( $slot['name'] ?? '' ),
				'open'         => false !== ( $slot['active'] ?? true ) && array() === ( $slot['creatives'] ?? array() ),

				/*
				 * The refusal sentence, per placement, because the number in it
				 * is per placement. The shared `i18n` map cannot hold this one:
				 * it said "larger than 2 MB" on every slot, which was the
				 * ceiling rather than anything a placement enforced.
				 */
				'sizeMessage'  => sprintf(
					/* translators: %s: this placement's maximum file size, e.g. 150 KB. */
					__( 'That file is larger than %s. Choose a smaller ad creative.', 'aggressive-ads' ),
					$slot['max_size']
				),
			);
		}

		if ( array() !== $uploads ) {
			wp_interactivity_state(
				self::UPLOAD_STORE,
				array(
					'uploads' => $uploads,
					'i18n'    => array(
						'ready'           => __( 'File selected. Add a destination URL to upload it.', 'aggressive-ads' ),
						'uploading'       => __( 'Uploading the creative.', 'aggressive-ads' ),
						/* translators: %s: the file's name, e.g. skyscraper-160x600.png. */
						'uploadingFile'   => __( 'Uploading %s.', 'aggressive-ads' ),
						'uploaded'        => __( 'Uploaded. Showing your ad…', 'aggressive-ads' ),
						'uploadFailed'    => __( 'The upload did not finish. Check your connection and try again.', 'aggressive-ads' ),
						'uploadCancelled' => __( 'Upload cancelled. Choose the file again when you are ready.', 'aggressive-ads' ),
						'uploadTimedOut'  => __( 'The upload took too long and was stopped. Try again, or try a smaller file.', 'aggressive-ads' ),
						/* translators: %s: the file's name, e.g. skyscraper-160x600.png. */
						'matched'         => __( '%s · matched by size', 'aggressive-ads' ),
						'needsUrl'        => __( 'Enter a complete destination URL to finish the upload.', 'aggressive-ads' ),
						'empty'           => __( 'Choose an ad creative file to upload.', 'aggressive-ads' ),
						'type'            => __( 'Use a JPEG, PNG, GIF, or WebP image.', 'aggressive-ads' ),
						'pixels'          => __( 'That ad creative is too large in pixels to process safely. Choose a smaller one.', 'aggressive-ads' ),
						'dimensions'      => __( 'The ad creative must match the required pixel size for this placement.', 'aggressive-ads' ),
						/* translators: %s: a placement's name, e.g. Header. */
						'bulkGoesTo'      => __( 'Goes to %s.', 'aggressive-ads' ),
						'bulkChoose'      => __( 'More than one placement is this size. Choose where it goes.', 'aggressive-ads' ),
						/* translators: %s: a file name, e.g. banner-728x90.png. */
						'bulkChooseLabel' => __( 'Placement for %s', 'aggressive-ads' ),
						'bulkPick'        => __( 'Choose a placement', 'aggressive-ads' ),
						'bulkAll'         => __( 'All of them', 'aggressive-ads' ),
						/* translators: 1: the file's dimensions, e.g. 300 × 250. 2: the sizes still needed, e.g. 728 × 90, 160 × 600. */
						'bulkNone'        => __( 'This file is %1$s. The sizes still needed are %2$s.', 'aggressive-ads' ),
						/* translators: %s: the file's dimensions, e.g. 300 × 250. */
						'bulkTaken'       => __( 'Every %s placement already has an ad or a file.', 'aggressive-ads' ),
						'bulkUnreadable'  => __( 'This file is not an image that can be read. Use a JPEG, PNG, GIF, or WebP image.', 'aggressive-ads' ),
						'bulkNeedsUrl'    => __( 'Add the address your ads link to, above, before uploading.', 'aggressive-ads' ),
						'bulkWaiting'     => __( 'Waiting to upload.', 'aggressive-ads' ),
						'bulkSent'        => __( 'Uploaded.', 'aggressive-ads' ),
						'bulkRefused'     => __( 'Not uploaded. The reason is shown once the others finish.', 'aggressive-ads' ),
						'bulkPartial'     => __( 'Some files did not finish uploading. Choose them again to try once more.', 'aggressive-ads' ),
						/* translators: %d: how many files were uploaded. */
						'bulkDone'        => __( 'Uploaded %d. Showing your ads…', 'aggressive-ads' ),
					),
				)
			);
		}
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

		if ( ! is_file( AGGR_PLUGIN_DIR . 'dist/interactivity/dialog.js' ) ) {
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

		$this->register_module( self::MODULE_LOGIC, 'logic', array() );
		$this->register_module(
			self::MODULE_WIZARD,
			'wizard',
			array(
				'@wordpress/interactivity',
				self::MODULE_LOGIC,
			)
		);
		$this->register_module(
			self::MODULE_AUTOSAVE,
			'autosave',
			array(
				'@wordpress/interactivity',
				self::MODULE_LOGIC,
			)
		);
		$this->register_module(
			self::MODULE_UPLOAD,
			'upload',
			array(
				'@wordpress/interactivity',
				self::MODULE_LOGIC,
			)
		);
		$this->register_module(
			self::MODULE_SAVE,
			'save',
			array( '@wordpress/interactivity' )
		);
		$this->register_module( self::MODULE_CALENDAR, 'calendar', array() );
		$this->register_module( self::MODULE_ZONE_NOTES, 'zone-notes', array() );
		$this->register_module( self::MODULE_LINK_TOOLS, 'link-tools', array() );

		return $ok;
	}

	/**
	 * Registers one module when its compiled file exists.
	 *
	 * @param string             $module_id Module id.
	 * @param string             $basename  File basename under dist/interactivity/.
	 * @param array<int, string> $deps      Module dependency ids (merged with .asset.php).
	 * @return bool
	 */
	private function register_module( string $module_id, string $basename, array $deps ): bool {
		$relative = 'dist/interactivity/' . $basename . '.js';
		$path     = AGGR_PLUGIN_DIR . $relative;

		if ( ! is_file( $path ) ) {
			return false;
		}

		$asset  = $this->read_asset_php( 'dist/interactivity/' . $basename . '.asset.php' );
		$merged = array_values(
			array_unique(
				array_merge(
					is_array( $asset['dependencies'] ?? null )
						? array_map( 'strval', $asset['dependencies'] )
						: array(),
					$deps
				)
			)
		);

		// Stubs type deps as id/import shapes; string ids remain valid at runtime.
		$normalized = array_map(
			static fn( string $id ): array => array(
				'id'     => $id,
				'import' => 'static',
			),
			$merged
		);

		wp_register_script_module(
			$module_id,
			AGGR_PLUGIN_URL . $relative,
			$normalized,
			$this->asset_version( $relative, $asset )
		);

		return true;
	}

	/**
	 * Cache-busting version from .asset.php, else file mtime, else plugin version.
	 *
	 * @param string               $relative Path relative to the plugin root.
	 * @param array<string, mixed> $asset    Optional already-loaded .asset.php payload.
	 * @return string
	 */
	private function asset_version( string $relative, array $asset = array() ): string {
		if ( array() === $asset ) {
			$asset_php = preg_replace( '/\.(css|js)$/', '.asset.php', $relative );
			if ( is_string( $asset_php ) ) {
				$asset = $this->read_asset_php( $asset_php );
			}
		}

		if ( isset( $asset['version'] ) && is_scalar( $asset['version'] ) && '' !== (string) $asset['version'] ) {
			return (string) $asset['version'];
		}

		$path  = AGGR_PLUGIN_DIR . $relative;
		$mtime = is_file( $path ) ? filemtime( $path ) : false;

		return false === $mtime ? AGGR_VERSION : (string) $mtime;
	}

	/**
	 * Reads a webpack DependencyExtractionWebpackPlugin manifest.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return array<string, mixed>
	 */
	private function read_asset_php( string $relative ): array {
		$path = AGGR_PLUGIN_DIR . $relative;

		if ( ! is_file( $path ) ) {
			return array();
		}

		$asset = include $path;

		return is_array( $asset ) ? $asset : array();
	}
}
