<?php
/**
 * Staff placement-to-provider mapping screen.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Admin;

use LAAO_Advertiser_Portal\Assets\Assets;
use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Security\Capabilities;
use LAAO_Advertiser_Portal\Workflow\Placement_Mapping_Manager;
use WP_Error;

/**
 * Delivers the mapping UI without exposing generic placement post editing.
 */
final class Placement_Mapping_Screen implements Service {

	public const MENU_SLUG = 'laao-ads-placement-mapping';
	public const ACTION    = 'laao_ads_update_placement_mapping';

	/**
	 * Hook suffix assigned by WordPress.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Placement_Mapping_Data    $data    Screen read model.
	 * @param Placement_Mapping_Manager $manager Mapping workflow.
	 */
	public function __construct(
		private readonly Placement_Mapping_Data $data,
		private readonly Placement_Mapping_Manager $manager
	) {
	}

	/**
	 * Attaches menu, assets, and the authenticated form handler.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_update' ) );
	}

	/**
	 * Registers a capability-owned top-level screen.
	 *
	 * Keeping this separate from the review menu means a future operations role
	 * can manage delivery mappings without receiving campaign-review access.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$hook = add_menu_page(
			__( 'Ad delivery mappings', 'laao-advertiser-portal' ),
			__( 'Ad delivery', 'laao-advertiser-portal' ),
			Capabilities::MANAGE_PLACEMENTS,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-location-alt',
			27
		);

		$this->hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Loads the shared staff design system only on this screen.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$this->enqueue_style( Assets::HANDLE, Assets::STYLE_PORTAL );
		$this->enqueue_style( 'laao-ads-admin', Assets::STYLE_ADMIN, array( Assets::HANDLE ) );
	}

	/**
	 * Renders the authorized mapping table.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PLACEMENTS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'laao-advertiser-portal' ),
				'',
				array( 'response' => 403 )
			);
		}

		$laao_ads_view   = $this->data->view();
		$laao_ads_notice = $this->request_notice();

		require LAAO_ADS_PLUGIN_DIR . 'templates/admin/placement-mapping.php';
	}

	/**
	 * Verifies and applies one placement-scoped mapping form.
	 *
	 * @return void
	 */
	public function handle_update(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PLACEMENTS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'laao-advertiser-portal' ),
				'',
				array( 'response' => 403 )
			);
		}

		$placement_id = $this->posted_placement_id();

		check_admin_referer( self::nonce_action( $placement_id ) );

		$term_id = isset( $_POST['adgroup_term_id'] ) ? absint( wp_unslash( $_POST['adgroup_term_id'] ) ) : 0;
		$result  = $this->process_update( $placement_id, $term_id );

		$this->redirect_after( $result );
	}

	/**
	 * Testable delivery boundary for one mapping change.
	 *
	 * @param int $placement_id Placement post id.
	 * @param int $term_id      Provider term id, or zero.
	 * @return true|WP_Error
	 */
	public function process_update( int $placement_id, int $term_id ) {
		return $this->manager->update( $placement_id, $term_id );
	}

	/**
	 * Placement-scoped form nonce action.
	 *
	 * @param int $placement_id Placement post id.
	 * @return string
	 */
	public static function nonce_action( int $placement_id ): string {
		return self::ACTION . '_' . max( 0, $placement_id );
	}

	/**
	 * Screen URL.
	 *
	 * @return string
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
		if ( 'success' === $result && 'mapping_saved' === $code ) {
			return array(
				'type'    => 'success',
				'message' => __( 'Placement mapping saved.', 'laao-advertiser-portal' ),
			);
		}

		if ( 'error' !== $result ) {
			return null;
		}

		$message = match ( $code ) {
			'laao_ads_forbidden'             => __( 'You do not have permission to change placement mappings.', 'laao-advertiser-portal' ),
			'laao_ads_placement_not_found'   => __( 'That placement could not be found.', 'laao-advertiser-portal' ),
			'laao_ads_invalid_adgroup'       => __( 'Choose an existing AdSanity ad group.', 'laao-advertiser-portal' ),
			'laao_ads_provider_unavailable'  => __( 'AdSanity is not active. No mappings were changed.', 'laao-advertiser-portal' ),
			'laao_ads_mapping_not_saved'     => __( 'The mapping could not be saved. Try again.', 'laao-advertiser-portal' ),
			default                          => __( 'The mapping could not be updated. No approval behavior changed.', 'laao-advertiser-portal' ),
		};

		return array(
			'type'    => 'error',
			'message' => $message,
		);
	}

	/**
	 * Enqueues one local stylesheet with cache-safe versioning.
	 *
	 * @param string             $handle       Style handle.
	 * @param string             $relative     Plugin-relative path.
	 * @param array<int, string> $dependencies Style dependencies.
	 * @return void
	 */
	private function enqueue_style( string $handle, string $relative, array $dependencies = array() ): void {
		$path = LAAO_ADS_PLUGIN_DIR . $relative;

		if ( ! is_file( $path ) ) {
			return;
		}

		$mtime = filemtime( $path );

		wp_enqueue_style(
			$handle,
			LAAO_ADS_PLUGIN_URL . $relative,
			$dependencies,
			false === $mtime ? LAAO_ADS_VERSION : (string) $mtime
		);
	}

	/**
	 * Redirects after a verified write.
	 *
	 * @param true|WP_Error $result Workflow result.
	 * @return never
	 */
	private function redirect_after( bool|WP_Error $result ): never {
		$is_error = is_wp_error( $result );
		$url      = add_query_arg(
			array(
				'laao_ads_result' => $is_error ? 'error' : 'success',
				'laao_ads_code'   => $is_error ? sanitize_key( (string) $result->get_error_code() ) : 'mapping_saved',
			),
			self::url()
		);

		wp_safe_redirect( $url, 303 );

		exit;
	}

	/**
	 * Read-only redirect notice.
	 *
	 * @return array{type: string, message: string}|null
	 */
	private function request_notice(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, allowlisted state selecting fixed copy.
		$result = isset( $_GET['laao_ads_result'] ) ? sanitize_key( wp_unslash( $_GET['laao_ads_result'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, allowlisted state selecting fixed copy.
		$code = isset( $_GET['laao_ads_code'] ) ? sanitize_key( wp_unslash( $_GET['laao_ads_code'] ) ) : '';

		return self::notice_for( $result, $code );
	}

	/**
	 * Placement id used only to select its nonce action before verification.
	 *
	 * @return int
	 */
	private function posted_placement_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Used only to select the per-placement nonce checked by the caller.
		return isset( $_POST['placement_id'] ) ? absint( wp_unslash( $_POST['placement_id'] ) ) : 0;
	}
}
