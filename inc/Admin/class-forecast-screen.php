<?php
/**
 * The staff inventory outlook screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Security\Capabilities;

/**
 * Shows what each placement is forecast to supply and what is booked against it.
 *
 * Gated on `MANAGE_PLACEMENTS`, the capability that already owns inventory —
 * and the same one `Booking_Service` requires, so a person who can see the
 * outlook is a person who could act on it.
 *
 * This is the surface P16 was missing. `Forecast_Recorder` and
 * `Booking_Service` were reachable only from tests until something in
 * production called them.
 */
final class Forecast_Screen implements Service {

	/** Submenu slug. */
	public const MENU_SLUG = 'aggr-forecast';

	/**
	 * Assembles the outlook.
	 *
	 * @var Forecast_Data
	 */
	private Forecast_Data $data;

	/**
	 * The screen's own hook suffix, captured at registration.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Reads the outlook this screen prints.
	 *
	 * @param Forecast_Data $data Outlook assembler.
	 */
	public function __construct( Forecast_Data $data ) {
		$this->data = $data;
	}

	/** Attaches the menu and the screen's bundle. */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Loads the screen's bundle, on this screen only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		Shared_Assets::register();

		$version = Shared_Assets::enqueue_bundle( 'aggr-forecast', 'forecast' );

		if ( '' === $version ) {
			return;
		}

		wp_enqueue_style( 'wp-components' );

		/*
		 * DataViews' stylesheet is named as a dependency rather than enqueued
		 * beside this one, so it loads first: this screen's rules restyle
		 * DataViews and would lose to it otherwise. A script dependency does
		 * not bring a stylesheet — WordPress resolves the two registries
		 * separately — so this is the only thing that puts it on the page.
		 */
		wp_enqueue_style(
			'aggr-forecast',
			AGGR_PLUGIN_URL . 'dist/admin/forecast.css',
			array( 'wp-components', Shared_Assets::DATAVIEWS ),
			$version
		);

		wp_style_add_data( 'aggr-forecast', 'rtl', 'replace' );
	}

	/** Registers a capability-owned submenu under Advertising. */
	public function register_menu(): void {
		$hook = add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Inventory outlook', 'aggressive-ads' ),
			__( 'Outlook', 'aggressive-ads' ),
			Capabilities::MANAGE_PLACEMENTS,
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		$this->hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/** Renders the outlook to an authorized reader. */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PLACEMENTS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'aggressive-ads' ),
				'',
				array( 'response' => 403 )
			);
		}

		if ( ! is_file( AGGR_PLUGIN_DIR . 'dist/admin/forecast.asset.php' ) ) {
			Screen_Shell::unbuilt(
				__( 'Inventory outlook', 'aggressive-ads' ),
				__( 'The outlook screen has not been built. Run “pnpm build” and reload.', 'aggressive-ads' ),
				Screen_Shell::section( 'inventory' )
			);

			return;
		}

		$window = $this->data->default_window();

		Screen_Shell::mount(
			__( 'Inventory outlook', 'aggressive-ads' ),
			__( 'How many impressions each placement is forecast to offer, how many are already booked, and what is left to sell.', 'aggressive-ads' ),
			'aggr-forecast-root',
			'data-aggr-forecast',
			$this->payload( $window ),
			__( 'This screen needs JavaScript.', 'aggressive-ads' ),
			Screen_Shell::section( 'inventory' )
		);
	}

	/**
	 * Everything the screen renders from.
	 *
	 * @param array{from: string, to: string} $window Default window.
	 * @return array<string, mixed>
	 */
	private function payload( array $window ): array {
		return array(
			'view' => $this->data->view( Opportunity::PAGE, $window['from'], $window['to'] ),
			'i18n' => array(
				'placement'  => __( 'Placement', 'aggressive-ads' ),
				'forecast'   => __( 'Forecast', 'aggressive-ads' ),
				'committed'  => __( 'Booked', 'aggressive-ads' ),
				'remaining'  => __( 'Remaining', 'aggressive-ads' ),
				'status'     => __( 'Status', 'aggressive-ads' ),
				'confidence' => __( 'Confidence', 'aggressive-ads' ),
				'window'     => __( 'Window', 'aggressive-ads' ),
				'placements' => __( 'Placements', 'aggressive-ads' ),
				'oversold'   => __( 'Oversold', 'aggressive-ads' ),
				'unforecast' => __( 'Not yet forecast', 'aggressive-ads' ),
				'empty'      => __( 'No active placements to forecast.', 'aggressive-ads' ),
				'region'     => __( 'Outlook for each placement', 'aggressive-ads' ),

				/* translators: shown instead of a number when a placement has never been forecast. */
				'noFigure'   => __( 'Not forecast', 'aggressive-ads' ),
				'available'  => __( 'Available', 'aggressive-ads' ),
				'oversell'   => __( 'Oversold', 'aggressive-ads' ),
				'unknown'    => __( 'Unmeasured', 'aggressive-ads' ),
				'none'       => __( 'None', 'aggressive-ads' ),
				'low'        => __( 'Low', 'aggressive-ads' ),
				'medium'     => __( 'Medium', 'aggressive-ads' ),
				'high'       => __( 'High', 'aggressive-ads' ),
			),
		);
	}
}
