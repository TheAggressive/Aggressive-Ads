<?php
/**
 * Staff placement catalogue screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Placement_Manager;
use WP_Error;

/**
 * Delivers placement create/update without exposing generic placement editing.
 */
final class Placement_Screen implements Service {

	public const MENU_SLUG     = 'aggr-placement-mapping';
	public const CREATE_ACTION = 'aggr_create_placement';
	public const UPDATE_ACTION = 'aggr_update_placement';

	/**
	 * Constructor.
	 *
	 * @param Placement_Data    $data    Screen read model.
	 * @param Placement_Manager $manager Placement workflow.
	 */
	public function __construct(
		private readonly Placement_Data $data,
		private readonly Placement_Manager $manager
	) {
	}

	/**
	 * Attaches menu, assets, and the authenticated form handlers.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::CREATE_ACTION, array( $this, 'handle_create' ) );
		add_action( 'admin_post_' . self::UPDATE_ACTION, array( $this, 'handle_update' ) );
	}

	/**
	 * Registers a capability-owned submenu under Advertising.
	 */
	public function register_menu(): void {
		add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Inventory', 'aggressive-ads' ),
			__( 'Inventory', 'aggressive-ads' ),
			Capabilities::MANAGE_PLACEMENTS,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/*
	 * No stylesheet is enqueued here: the screen is native WordPress admin
	 * markup, so core already styles every part of it.
	 */

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

		$aggr_view   = $this->data->view();
		$aggr_notice = $this->request_notice();

		require AGGR_PLUGIN_DIR . 'templates/admin/placements.php';
	}

	/**
	 * Creates one placement.
	 */
	public function handle_create(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PLACEMENTS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'aggressive-ads' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::CREATE_ACTION );

		$result = $this->manager->create( $this->posted_fields() );
		$this->redirect_after( $result, 'placement_created' );
	}

	/**
	 * Updates one existing placement.
	 */
	public function handle_update(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PLACEMENTS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'aggressive-ads' ),
				'',
				array( 'response' => 403 )
			);
		}

		$placement_id = $this->posted_placement_id();

		check_admin_referer( self::nonce_action( $placement_id ) );

		$result = $this->manager->update( $placement_id, $this->posted_fields() );
		$this->redirect_after( $result, 'placement_saved' );
	}

	/**
	 * Placement-scoped form nonce action.
	 *
	 * @param int $placement_id Placement post id.
	 */
	public static function nonce_action( int $placement_id ): string {
		return self::UPDATE_ACTION . '_' . max( 0, $placement_id );
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
		if ( 'success' === $result && in_array( $code, array( 'placement_saved', 'placement_created' ), true ) ) {
			return array(
				'type'    => 'success',
				'message' => 'placement_created' === $code
					? __( 'Placement created.', 'aggressive-ads' )
					: __( 'Placement saved.', 'aggressive-ads' ),
			);
		}

		if ( 'error' !== $result ) {
			return null;
		}

		$message = match ( $code ) {
			'aggr_forbidden'                 => __( 'You do not have permission to manage placements.', 'aggressive-ads' ),
			'aggr_placement_not_found'       => __( 'That placement could not be found.', 'aggressive-ads' ),
			'aggr_placement_not_saved'       => __( 'The placement could not be saved. Try again.', 'aggressive-ads' ),
			'aggr_placement_limit'           => __( 'The placement catalogue is full.', 'aggressive-ads' ),
			'aggr_invalid_placement_name'    => __( 'Enter a placement name.', 'aggressive-ads' ),
			'aggr_invalid_placement_slug'    => __( 'Enter a slot slug.', 'aggressive-ads' ),
			'aggr_placement_slug_taken'      => __( 'That slot slug is already in use.', 'aggressive-ads' ),
			'aggr_invalid_placement_size'    => __( 'Choose a common size or enter a custom width and height in pixels.', 'aggressive-ads' ),
			'aggr_invalid_placement_sort'    => __( 'Sort order must be between 0 and 9999.', 'aggressive-ads' ),
			'aggr_invalid_house_attachment'  => __( 'House creative must be a JPEG, PNG, GIF, or WebP image.', 'aggressive-ads' ),
			'aggr_invalid_house_url'         => __( 'House destination must be an http or https URL without credentials.', 'aggressive-ads' ),
			'aggr_house_not_saved'           => __( 'The house creative could not be saved.', 'aggressive-ads' ),
			default                          => __( 'The placement could not be updated.', 'aggressive-ads' ),
		};

		return array(
			'type'    => 'error',
			'message' => $message,
		);
	}

	/**
	 * Posted placement id.
	 */
	private function posted_placement_id(): int {
		return isset( $_POST['placement_id'] ) ? absint( wp_unslash( $_POST['placement_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_update() verifies the placement nonce before calling this.
	}

	/**
	 * Allowlisted fields from the form.
	 *
	 * @return array<string, mixed>
	 */
	private function posted_fields(): array {
		return array(
			'name'                => isset( $_POST['name'] ) && is_string( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Workflow sanitizes.
			'slug'                => isset( $_POST['slug'] ) && is_string( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Workflow sanitizes.
			'size_preset'         => isset( $_POST['size_preset'] ) && is_string( $_POST['size_preset'] ) ? wp_unslash( $_POST['size_preset'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Workflow allowlists.
			'size_width'          => isset( $_POST['size_width'] ) ? (int) wp_unslash( $_POST['size_width'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Workflow bounds the integer.
			'size_height'         => isset( $_POST['size_height'] ) ? (int) wp_unslash( $_POST['size_height'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Workflow bounds the integer.
			'sort_order'          => isset( $_POST['sort_order'] ) ? (int) wp_unslash( $_POST['sort_order'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Workflow bounds the integer.
			'is_active'           => ! empty( $_POST['is_active'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verifies the action nonce before reading these fields.
			'house_attachment_id' => isset( $_POST['house_attachment_id'] ) ? absint( wp_unslash( $_POST['house_attachment_id'] ) ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verifies the action nonce before reading these fields.
			'house_click_url'     => isset( $_POST['house_click_url'] ) && is_string( $_POST['house_click_url'] ) ? wp_unslash( $_POST['house_click_url'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Workflow validates the URL.
			'house_alt'           => isset( $_POST['house_alt'] ) && is_string( $_POST['house_alt'] ) ? wp_unslash( $_POST['house_alt'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Workflow sanitizes.
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
}
