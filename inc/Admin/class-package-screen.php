<?php
/**
 * Staff package catalogue screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Package_Manager;
use WP_Error;

/**
 * Delivers package create/update without exposing generic package post editing.
 */
final class Package_Screen implements Service {

	public const MENU_SLUG     = 'aggr-packages';
	public const CREATE_ACTION = 'aggr_create_package';
	public const UPDATE_ACTION = 'aggr_update_package';

	/**
	 * Hook suffix assigned by WordPress.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Package_Data    $data    Screen read model.
	 * @param Package_Manager $manager Package workflow.
	 */
	public function __construct(
		private readonly Package_Data $data,
		private readonly Package_Manager $manager
	) {
	}

	/**
	 * Attaches menu, assets, and the authenticated form handlers.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::CREATE_ACTION, array( $this, 'handle_create' ) );
		add_action( 'admin_post_' . self::UPDATE_ACTION, array( $this, 'handle_update' ) );
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

		$aggr_view   = $this->data->view();
		$aggr_notice = $this->request_notice();

		require AGGR_PLUGIN_DIR . 'templates/admin/packages.php';
	}

	/**
	 * Creates one package.
	 */
	public function handle_create(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PACKAGES ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'aggressive-ads' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::CREATE_ACTION );

		$result = $this->manager->create( $this->posted_fields() );
		$this->redirect_after( $result, 'package_created' );
	}

	/**
	 * Updates one existing package.
	 */
	public function handle_update(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PACKAGES ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'aggressive-ads' ),
				'',
				array( 'response' => 403 )
			);
		}

		$package_id = $this->posted_package_id();

		check_admin_referer( self::nonce_action( $package_id ) );

		$result = $this->manager->update( $package_id, $this->posted_fields() );
		$this->redirect_after( $result, 'package_saved' );
	}

	/**
	 * Package-scoped form nonce action.
	 *
	 * @param int $package_id Package post id.
	 */
	public static function nonce_action( int $package_id ): string {
		return self::UPDATE_ACTION . '_' . max( 0, $package_id );
	}

	/**
	 * Screen URL.
	 */
	public static function url(): string {
		return add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) );
	}

	/**
	 * Fixed notice selected from allowlisted redirect state.
	 *
	 * @param string $result success or error.
	 * @param string $code   Stable result code.
	 * @return array{type: string, message: string}|null
	 */
	public static function notice_for( string $result, string $code ): ?array {
		if ( 'success' === $result && in_array( $code, array( 'package_saved', 'package_created' ), true ) ) {
			return array(
				'type'    => 'success',
				'message' => 'package_created' === $code
					? __( 'Package created.', 'aggressive-ads' )
					: __( 'Package saved.', 'aggressive-ads' ),
			);
		}

		if ( 'error' !== $result ) {
			return null;
		}

		$message = match ( $code ) {
			'aggr_forbidden'                  => __( 'You do not have permission to manage packages.', 'aggressive-ads' ),
			'aggr_package_not_found'          => __( 'That package could not be found.', 'aggressive-ads' ),
			'aggr_package_not_saved'          => __( 'The package could not be saved. Try again.', 'aggressive-ads' ),
			'aggr_package_limit'              => __( 'The package catalogue is full.', 'aggressive-ads' ),
			'aggr_invalid_package_name'       => __( 'Enter a package name.', 'aggressive-ads' ),
			'aggr_invalid_package_duration'   => __( 'Enter a duration in days, or mark the package as advertiser-scheduled.', 'aggressive-ads' ),
			'aggr_invalid_package_price'      => __( 'Price must be an integer number of cents.', 'aggressive-ads' ),
			'aggr_invalid_package_currency'   => __( 'Currency must be a three-letter ISO 4217 code.', 'aggressive-ads' ),
			'aggr_invalid_package_default'    => __( 'Only an active package can be the catalogue default.', 'aggressive-ads' ),
			'aggr_invalid_package_placements' => __( 'An active package must include at least one active placement with a valid size.', 'aggressive-ads' ),
			default                           => __( 'The package could not be updated.', 'aggressive-ads' ),
		};

		return array(
			'type'    => 'error',
			'message' => $message,
		);
	}

	/**
	 * Posted package id.
	 */
	private function posted_package_id(): int {
		return isset( $_POST['package_id'] ) ? absint( wp_unslash( $_POST['package_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_update() verifies the package nonce before calling this.
	}

	/**
	 * Allowlisted fields from the form.
	 *
	 * @return array<string, mixed>
	 */
	private function posted_fields(): array {
		$placements = array();

		if ( isset( $_POST['placement_ids'] ) && is_array( $_POST['placement_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_*() verifies the nonce before calling this.
			foreach ( wp_unslash( $_POST['placement_ids'] ) as $raw ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- handle_*() verified the nonce; absint below.
				$placements[] = absint( $raw );
			}
		}

		return array(
			'name'            => isset( $_POST['name'] ) && is_string( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Workflow sanitizes.
			'placement_ids'   => $placements,
			'duration_days'   => isset( $_POST['duration_days'] ) ? absint( wp_unslash( $_POST['duration_days'] ) ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verifies the action nonce before reading these fields.
			'custom_duration' => ! empty( $_POST['custom_duration'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verifies the action nonce before reading these fields.
			'price_cents'     => isset( $_POST['price_cents'] ) ? (int) wp_unslash( $_POST['price_cents'] ) : -1, // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Workflow bounds the integer.
			'currency'        => isset( $_POST['currency'] ) && is_string( $_POST['currency'] ) ? wp_unslash( $_POST['currency'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Workflow sanitizes.
			'is_active'       => ! empty( $_POST['is_active'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verifies the action nonce before reading these fields.
			'is_default'      => ! empty( $_POST['is_default'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verifies the action nonce before reading these fields.
		);
	}

	/**
	 * Redirects after a verified write.
	 *
	 * @param true|int|WP_Error $result Workflow result.
	 * @param string            $ok     Success code.
	 * @return never
	 */
	private function redirect_after( bool|int|WP_Error $result, string $ok ): never {
		$is_error = is_wp_error( $result );
		$url      = add_query_arg(
			array(
				'aggr_result' => $is_error ? 'error' : 'success',
				'aggr_code'   => $is_error ? sanitize_key( (string) $result->get_error_code() ) : $ok,
			),
			self::url()
		);

		wp_safe_redirect( $url, 303 );
		exit;
	}

	/**
	 * Notice from the redirect query.
	 *
	 * @return array{type: string, message: string}|null
	 */
	private function request_notice(): ?array {
		$result = isset( $_GET['aggr_result'] ) ? sanitize_key( wp_unslash( $_GET['aggr_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flash from our own redirect.
		$code   = isset( $_GET['aggr_code'] ) ? sanitize_key( wp_unslash( $_GET['aggr_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flash from our own redirect.

		return self::notice_for( $result, $code );
	}

	/**
	 * Enqueues one local stylesheet with cache-safe versioning.
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
}
