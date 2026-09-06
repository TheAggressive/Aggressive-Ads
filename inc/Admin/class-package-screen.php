<?php
/**
 * Staff package catalogue screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Admin\Currency_Options;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\REST\Creative_File_Controller;
use Aggressive\Ads\Security\Capabilities;

/**
 * Delivers package create/update without exposing generic package post editing.
 */
final class Package_Screen implements Service {

	public const MENU_SLUG = 'aggr-packages';

	/**
	 * Constructor.
	 *
	 * @param Package_Data $data    Screen read model.
	 */
	public function __construct( private readonly Package_Data $data ) {
	}

	/**
	 * The screen's own hook suffix, captured at registration.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Attaches the menu and the screen's bundle.
	 *
	 * There are no admin-post handlers any more. Catalogue writes go to
	 * REST\Packages_Controller, which is thin over the same Package_Manager the
	 * handlers called — one authenticated path to the catalogue rather than two
	 * that have to be kept in agreement.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Loads the screen's bundle, on this screen only.
	 *
	 * Enqueuing belongs on this hook rather than inside the render callback: a
	 * callback runs after the document head has been sent, so a stylesheet asked
	 * for there survives only because core prints late styles in the footer, and
	 * flashes an unstyled screen while it does.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$asset = AGGR_PLUGIN_DIR . 'dist/admin/packages.asset.php';

		if ( ! is_file( $asset ) ) {
			return;
		}

		$meta = require $asset;

		wp_enqueue_script(
			'aggr-packages',
			AGGR_PLUGIN_URL . 'dist/admin/packages.js',
			is_array( $meta['dependencies'] ?? null ) ? $meta['dependencies'] : array(),
			is_string( $meta['version'] ?? null ) ? $meta['version'] : AGGR_VERSION,
			true
		);

		wp_enqueue_style( 'wp-components' );
	}

	/**
	 * Registers a capability-owned submenu under Advertising.
	 */
	public function register_menu(): void {
		$hook = add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Packages', 'aggressive-ads' ),
			__( 'Packages', 'aggressive-ads' ),
			Capabilities::MANAGE_PACKAGES,
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		$this->hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/*
	 * No stylesheet is enqueued here.
	 *
	 * This screen is native WordPress admin markup — poststuff, postbox,
	 * form-table, notice, button — so core already styles every part of it.
	 * Loading the plugin's design system would only give it something to fight.
	 */

	/**
	 * Renders the authorized catalogue.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PACKAGES ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'aggressive-ads' ),
				'',
				array( 'response' => 403 )
			);
		}

		$this->render_screen();
	}

	/**
	 * Prints the mount point and the catalogue it edits.
	 *
	 * @return void
	 */
	private function render_screen(): void {
		if ( ! is_file( AGGR_PLUGIN_DIR . 'dist/admin/packages.asset.php' ) ) {
			printf(
				'<div class="wrap"><h1>%1$s</h1><div class="notice notice-error"><p>%2$s</p></div></div>',
				esc_html__( 'Packages', 'aggressive-ads' ),
				esc_html__( 'The packages screen has not been built. Run “pnpm build” and reload.', 'aggressive-ads' )
			);

			return;
		}

		/*
		 * The currencies this site already prices in lead the list, and are the
		 * default when there is only one — the same catalogue the conversions
		 * screen offers, because a package priced in one currency and a
		 * conversion valued in another is a total nobody can add up.
		 */
		$priced = $this->data->currencies_in_use();

		$payload = array(
			'view'            => $this->data->view(),
			'restPath'        => '/' . Creative_File_Controller::NAMESPACE . '/packages',
			'currencies'      => Currency_Options::options(
				$priced,
				__( 'Choose a currency', 'aggressive-ads' )
			),
			'defaultCurrency' => Currency_Options::default_for( $priced ),
			'i18n'            => array(
				'newPackage'         => __( 'New package', 'aggressive-ads' ),
				'create'             => __( 'Create package', 'aggressive-ads' ),
				'save'               => __( 'Save package', 'aggressive-ads' ),
				'created'            => __( 'Package created.', 'aggressive-ads' ),
				'saved'              => __( 'Package saved.', 'aggressive-ads' ),
				'name'               => __( 'Name', 'aggressive-ads' ),
				'placements'         => __( 'Placements', 'aggressive-ads' ),
				'noPlacements'       => __( 'No placements exist yet. Create one under Placements first.', 'aggressive-ads' ),
				'inactive'           => __( 'inactive', 'aggressive-ads' ),
				'customDuration'     => __( 'Advertiser chooses the dates', 'aggressive-ads' ),
				'customDurationHelp' => __( 'Leave off to sell a fixed run length.', 'aggressive-ads' ),
				'durationDays'       => __( 'Duration (days)', 'aggressive-ads' ),
				'price'              => __( 'Price', 'aggressive-ads' ),
				'priceHelp'          => __( 'Stored as whole cents. Two decimal places.', 'aggressive-ads' ),
				'currency'           => __( 'Currency', 'aggressive-ads' ),
				'active'             => __( 'Active', 'aggressive-ads' ),
				'activeHelp'         => __( 'Inactive packages are hidden from advertisers.', 'aggressive-ads' ),
				'isDefault'          => __( 'Catalogue default', 'aggressive-ads' ),
				'isDefaultHelp'      => __( 'Pre-selected in the campaign wizard. Only one package can be the default.', 'aggressive-ads' ),
				'defaultTag'         => __( 'default', 'aggressive-ads' ),
				'statusPending'      => __( 'Not saved yet…', 'aggressive-ads' ),
				'statusSaving'       => __( 'Saving…', 'aggressive-ads' ),
				'statusSaved'        => __( 'Saved.', 'aggressive-ads' ),
				'statusError'        => __( 'Not saved.', 'aggressive-ads' ),
				'saveFailed'         => __( 'That package could not be saved.', 'aggressive-ads' ),
				'retry'              => __( 'Try again', 'aggressive-ads' ),
			),
		);

		printf(
			'<div class="wrap aggr-admin"><h1>%1$s</h1><noscript><div class="notice notice-error"><p>%2$s</p></div></noscript><div id="aggr-packages-root" data-aggr-packages="%3$s"></div></div>',
			esc_html__( 'Packages', 'aggressive-ads' ),
			esc_html__( 'The packages screen needs JavaScript enabled.', 'aggressive-ads' ),
			esc_attr( (string) wp_json_encode( $payload ) )
		);
	}




	/**
	 * Screen URL.
	 */
	public static function url(): string {
		return add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) );
	}
}
