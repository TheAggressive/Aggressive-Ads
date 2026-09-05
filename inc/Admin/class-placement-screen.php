<?php
/**
 * Staff placement catalogue screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\REST\Creative_File_Controller;
use Aggressive\Ads\Security\Capabilities;

/**
 * Delivers placement create/update without exposing generic placement editing.
 */
final class Placement_Screen implements Service {

	public const MENU_SLUG = 'aggr-placement-mapping';

	/**
	 * Constructor.
	 *
	 * @param Placement_Data $data Screen read model.
	 */
	public function __construct( private readonly Placement_Data $data ) {
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
	 * REST\Placements_Controller, which is thin over the same Placement_Manager
	 * the handlers called — one authenticated path to the catalogue rather than
	 * two that have to be kept in agreement.
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

		$asset = AGGR_PLUGIN_DIR . 'dist/admin/inventory.asset.php';

		if ( ! is_file( $asset ) ) {
			return;
		}

		$meta    = require $asset;
		$version = is_string( $meta['version'] ?? null ) ? $meta['version'] : AGGR_VERSION;

		// The bundle's .asset.php names aggr-dataviews as a dependency, because
		// the build rewrote its @wordpress/dataviews import onto the shared
		// copy. Registering it here is what lets WordPress resolve that.
		Shared_Assets::register();

		wp_enqueue_script(
			'aggr-inventory',
			AGGR_PLUGIN_URL . 'dist/admin/inventory.js',
			is_array( $meta['dependencies'] ?? null ) ? $meta['dependencies'] : array(),
			$version,
			true
		);

		wp_enqueue_style( 'wp-components' );

		/*
		 * The shared DataViews stylesheet, named as a dependency rather than
		 * enqueued beside this one, so it always loads first: this screen's
		 * rules restyle DataViews components and would lose to them otherwise.
		 *
		 * A script dependency does not bring a stylesheet — WordPress resolves
		 * script and style handles separately — so this is the only thing that
		 * puts it on the page.
		 */
		wp_enqueue_style(
			'aggr-inventory',
			AGGR_PLUGIN_URL . 'dist/admin/inventory.css',
			array( 'wp-components', Shared_Assets::DATAVIEWS ),
			$version
		);

		// The build emits inventory-rtl.css beside it; core swaps the file
		// wholesale rather than appending overrides.
		wp_style_add_data( 'aggr-inventory', 'rtl', 'replace' );
	}

	/**
	 * Registers a capability-owned submenu under Advertising.
	 */
	public function register_menu(): void {
		$hook = add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Placements', 'aggressive-ads' ),
			__( 'Placements', 'aggressive-ads' ),
			Capabilities::MANAGE_PLACEMENTS,
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		$this->hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Renders the authorized catalogue.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PLACEMENTS ) ) {
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
		if ( ! is_file( AGGR_PLUGIN_DIR . 'dist/admin/inventory.asset.php' ) ) {
			printf(
				'<div class="wrap"><h1>%1$s</h1><div class="notice notice-error"><p>%2$s</p></div></div>',
				esc_html__( 'Placements', 'aggressive-ads' ),
				esc_html__( 'The placements screen has not been built. Run “pnpm build” and reload.', 'aggressive-ads' )
			);

			return;
		}

		$payload = array(
			'view'     => $this->data->view(),
			'restPath' => '/' . Creative_File_Controller::NAMESPACE . '/placements',
			'i18n'     => array(
				'newPlacement'        => __( 'New placement', 'aggressive-ads' ),
				'editPlacement'       => __( 'Edit placement', 'aggressive-ads' ),
				'create'              => __( 'Create placement', 'aggressive-ads' ),
				'save'                => __( 'Save placement', 'aggressive-ads' ),
				'created'             => __( 'Placement created.', 'aggressive-ads' ),
				'saved'               => __( 'Placement saved.', 'aggressive-ads' ),
				'edit'                => __( 'Edit', 'aggressive-ads' ),
				'cancel'              => __( 'Cancel', 'aggressive-ads' ),
				'search'              => __( 'Search placements', 'aggressive-ads' ),
				'none'                => __( 'No placements yet.', 'aggressive-ads' ),
				'status'              => __( 'Status', 'aggressive-ads' ),
				'refreshOn'           => __( 'Allowed', 'aggressive-ads' ),
				'refreshOff'          => __( 'Off', 'aggressive-ads' ),
				'name'                => __( 'Name', 'aggressive-ads' ),
				'slug'                => __( 'Slot slug', 'aggressive-ads' ),
				'slugHelp'            => __( 'Used by the placement block to choose this slot. Lowercase letters, numbers and hyphens.', 'aggressive-ads' ),
				'size'                => __( 'Size', 'aggressive-ads' ),
				'chooseSize'          => __( 'Choose a size', 'aggressive-ads' ),
				'customSize'          => __( 'Custom size', 'aggressive-ads' ),
				'customWidth'         => __( 'Custom width (px)', 'aggressive-ads' ),
				'customHeight'        => __( 'Custom height (px)', 'aggressive-ads' ),
				'sortOrder'           => __( 'Sort order', 'aggressive-ads' ),
				'sortOrderHelp'       => __( 'Lower numbers appear first in the advertiser wizard.', 'aggressive-ads' ),
				'active'              => __( 'Active', 'aggressive-ads' ),
				'activeHelp'          => __( 'Inactive placements are hidden from advertisers and stop being filled.', 'aggressive-ads' ),
				'inactive'            => __( 'inactive', 'aggressive-ads' ),
				'refresh'             => __( 'Refresh', 'aggressive-ads' ),
				'refreshEnabled'      => __( 'Allow refresh', 'aggressive-ads' ),
				'refreshEnabledHelp'  => __( 'When on, a slot on this placement may replace its advertisement on a timer. The block still has to ask. Off is the default: a new placement is not inventory somebody chose to multiply.', 'aggressive-ads' ),
				'refreshSeconds'      => __( 'Shortest interval (seconds)', 'aggressive-ads' ),
				'refreshSecondsHelp'  => __( 'A block asking to rotate faster than this gets this number. A slower request is honoured.', 'aggressive-ads' ),
				'refreshMax'          => __( 'Refreshes per page view', 'aggressive-ads' ),
				'refreshMaxHelp'      => __( 'How many times one slot may refresh after the first fill. Zero keeps refresh on but starts no timer.', 'aggressive-ads' ),
				'house'               => __( 'House advertisement', 'aggressive-ads' ),
				'houseAttachment'     => __( 'House attachment ID', 'aggressive-ads' ),
				'houseAttachmentHelp' => __( 'Shown when no paid creative is live, if the Delivery house-ad policy allows it. Leave at 0 for none.', 'aggressive-ads' ),
				'houseMissing'        => __( 'With no house advertisement, this placement shows nothing when it is unsold — and nothing at all to visitors without JavaScript, whose slot is removed from the page rather than left as an empty box.', 'aggressive-ads' ),
				'houseUrl'            => __( 'House click URL', 'aggressive-ads' ),
				'houseAlt'            => __( 'House alt text', 'aggressive-ads' ),
				'statusPending'       => __( 'Not saved yet…', 'aggressive-ads' ),
				'statusSaving'        => __( 'Saving…', 'aggressive-ads' ),
				'statusSaved'         => __( 'Saved.', 'aggressive-ads' ),
				'statusError'         => __( 'Not saved.', 'aggressive-ads' ),
				'saveFailed'          => __( 'That placement could not be saved.', 'aggressive-ads' ),
				'retry'               => __( 'Try again', 'aggressive-ads' ),
			),
		);

		printf(
			'<div class="wrap aggr-admin"><h1>%1$s</h1><noscript><div class="notice notice-error"><p>%2$s</p></div></noscript><div id="aggr-inventory-root" data-aggr-inventory="%3$s"></div></div>',
			esc_html__( 'Placements', 'aggressive-ads' ),
			esc_html__( 'The placements screen needs JavaScript enabled.', 'aggressive-ads' ),
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
