<?php
/**
 * Staff settings: modules and brand.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Security\Capabilities;
use WP_Error;

/**
 * The first surface that uses aggr_manage_settings.
 */
final class Settings_Screen implements Service {

	public const MENU_SLUG = 'aggr-settings';
	public const ACTION    = 'aggr_save_settings';

	/**
	 * Hook suffix assigned by WordPress.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings document.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Attaches menu, assets, and the save handler.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * Registers Settings under the Advertising parent.
	 */
	public function register_menu(): void {
		$hook = add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Settings', 'aggressive-ads' ),
			__( 'Settings', 'aggressive-ads' ),
			Capabilities::MANAGE_SETTINGS,
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		$this->hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Loads the shared staff design system only on this screen.
	 *
	 * @param string $hook_suffix Current admin screen.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$this->enqueue_style( Assets::HANDLE, Assets::STYLE_PORTAL );
		$this->enqueue_style( 'aggr-admin', Assets::STYLE_ADMIN, array( Assets::HANDLE ) );
	}

	/**
	 * Renders modules and brand.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'aggressive-ads' ),
				'',
				array( 'response' => 403 )
			);
		}

		$aggr_settings = $this->settings->get();
		$aggr_notice   = $this->request_notice();

		require AGGR_PLUGIN_DIR . 'templates/admin/settings.php';
	}

	/**
	 * Verifies and saves the document.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'aggressive-ads' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::ACTION );

		$result = $this->settings->save(
			array(
				'modules'  => $this->posted_modules(),
				'brand'    => $this->posted_brand(),
				'delivery' => $this->posted_delivery(),
				'tracking' => $this->posted_tracking(),
			)
		);

		$this->redirect_after( $result );
	}

	/**
	 * Screen URL.
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}

	/**
	 * Enqueues a plugin stylesheet when the file exists.
	 *
	 * @param string             $handle       Style handle.
	 * @param string             $relative     Plugin-relative path.
	 * @param array<int, string> $dependencies Style dependencies.
	 */
	private function enqueue_style( string $handle, string $relative, array $dependencies = array() ): void {
		$path = AGGR_PLUGIN_DIR . $relative;

		if ( ! is_file( $path ) ) {
			return;
		}

		$mtime = filemtime( $path );

		wp_enqueue_style(
			$handle,
			AGGR_PLUGIN_URL . $relative,
			$dependencies,
			false === $mtime ? AGGR_VERSION : (string) $mtime
		);
	}

	/**
	 * Redirects back to Settings with a success or error flag.
	 *
	 * @param true|WP_Error $result Save result.
	 * @return never
	 */
	private function redirect_after( bool|WP_Error $result ): never {
		$is_error = is_wp_error( $result );
		$url      = add_query_arg(
			array(
				'aggr_result' => $is_error ? 'error' : 'success',
			),
			self::url()
		);

		wp_safe_redirect( $url, 303 );

		exit;
	}

	/**
	 * Flash copy from the redirect query string.
	 *
	 * @return array{type: string, message: string}|null
	 */
	private function request_notice(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, allowlisted result state used only to select a fixed message.
		$result = isset( $_GET['aggr_result'] ) ? sanitize_key( wp_unslash( (string) $_GET['aggr_result'] ) ) : '';

		if ( 'success' === $result ) {
			return array(
				'type'    => 'success',
				'message' => __( 'Settings saved.', 'aggressive-ads' ),
			);
		}

		if ( 'error' === $result ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Those settings cannot be saved. Check the colours still meet WCAG AA, and that the name and logo URL are valid.', 'aggressive-ads' ),
			);
		}

		return null;
	}

	/**
	 * Checkbox presence against the schema allowlist.
	 *
	 * @return array<string, bool>
	 */
	private function posted_modules(): array {
		$raw = array();

		if ( isset( $_POST['modules'] ) && is_array( $_POST['modules'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_save() verifies the action nonce before calling this.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Keys are compared to Settings_Schema::module_keys(); values are presence-only.
			$raw = wp_unslash( $_POST['modules'] );
		}

		$modules = array();

		foreach ( Settings_Schema::module_keys() as $key ) {
			$modules[ $key ] = isset( $raw[ $key ] );
		}

		return $modules;
	}

	/**
	 * Brand fields, each sanitized before schema validation.
	 *
	 * @return array<string, string>
	 */
	private function posted_brand(): array {
		$raw = array();

		if ( isset( $_POST['brand'] ) && is_array( $_POST['brand'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_save() verifies the action nonce before calling this.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Each allowlisted field is sanitized below.
			$raw = wp_unslash( $_POST['brand'] );
		}

		$text = static function ( string $key ) use ( $raw ): string {
			return isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? sanitize_text_field( $raw[ $key ] ) : '';
		};

		return array(
			'product_name'  => $text( 'product_name' ),
			'tagline'       => $text( 'tagline' ),
			'logo_url'      => isset( $raw['logo_url'] ) && is_string( $raw['logo_url'] ) ? esc_url_raw( $raw['logo_url'] ) : '',
			'accent'        => $text( 'accent' ),
			'accent_strong' => $text( 'accent_strong' ),
			'canvas'        => $text( 'canvas' ),
			'surface'       => $text( 'surface' ),
			'text'          => $text( 'text' ),
		);
	}

	/**
	 * Delivery fields.
	 *
	 * @return array<string, int|string>
	 */
	private function posted_delivery(): array {
		$raw = array();

		if ( isset( $_POST['delivery'] ) && is_array( $_POST['delivery'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_save() verifies the action nonce before calling this.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Each allowlisted field is sanitized below.
			$raw = wp_unslash( $_POST['delivery'] );
		}

		return array(
			'fill_ttl'     => isset( $raw['fill_ttl'] ) && is_numeric( $raw['fill_ttl'] ) ? (int) $raw['fill_ttl'] : 0,
			'house_policy' => isset( $raw['house_policy'] ) && is_string( $raw['house_policy'] ) ? sanitize_key( $raw['house_policy'] ) : '',
		);
	}

	/**
	 * Tracking fields.
	 *
	 * @return array<string, int>
	 */
	private function posted_tracking(): array {
		$raw = array();

		if ( isset( $_POST['tracking'] ) && is_array( $_POST['tracking'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_save() verifies the action nonce before calling this.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Each allowlisted field is sanitized below.
			$raw = wp_unslash( $_POST['tracking'] );
		}

		return array(
			'retention_days' => isset( $raw['retention_days'] ) && is_numeric( $raw['retention_days'] ) ? (int) $raw['retention_days'] : 0,
		);
	}
}
