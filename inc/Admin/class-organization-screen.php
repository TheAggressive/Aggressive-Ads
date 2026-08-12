<?php
/**
 * Staff organization suspension screen.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Admin;

use LAAO_Advertiser_Portal\Assets\Assets;
use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Security\Capabilities;
use LAAO_Advertiser_Portal\Workflow\Organization_State_Manager;
use WP_Error;

/**
 * Delivers suspension controls without exposing the generic organization editor.
 */
final class Organization_Screen implements Service {

	public const MENU_SLUG         = 'laao-ads-organizations';
	public const SUSPEND_ACTION    = 'laao_ads_suspend_organization';
	public const REACTIVATE_ACTION = 'laao_ads_reactivate_organization';

	/**
	 * Hook suffix assigned by WordPress.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Organization_Data          $data    Screen read model.
	 * @param Organization_State_Manager $manager State workflow.
	 */
	public function __construct(
		private readonly Organization_Data $data,
		private readonly Organization_State_Manager $manager
	) {
	}

	/** Attach menu, assets, and authenticated form handlers. */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::SUSPEND_ACTION, array( $this, 'handle_suspend' ) );
		add_action( 'admin_post_' . self::REACTIVATE_ACTION, array( $this, 'handle_reactivate' ) );
	}

	/**
	 * Registers a capability-owned top-level screen.
	 *
	 * Kept separate from the review menu so organization operations do not
	 * require campaign-review access.
	 */
	public function register_menu(): void {
		$hook = add_menu_page(
			__( 'Organizations', 'laao-advertiser-portal' ),
			__( 'Organizations', 'laao-advertiser-portal' ),
			Capabilities::MANAGE_ORGS,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-building',
			28
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

		$this->enqueue_style( Assets::HANDLE, 'assets/portal.css' );
		$this->enqueue_style( 'laao-ads-admin', 'assets/admin.css', array( Assets::HANDLE ) );
	}

	/** Renders the authorized organization table. */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ORGS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'laao-advertiser-portal' ),
				'',
				array( 'response' => 403 )
			);
		}

		$laao_ads_view   = $this->data->view();
		$laao_ads_notice = $this->request_notice();

		require LAAO_ADS_PLUGIN_DIR . 'templates/admin/organizations.php';
	}

	/** Verifies and suspends one organization. */
	public function handle_suspend(): void {
		$this->handle_state_change( self::SUSPEND_ACTION, Org_Repository::STATE_SUSPENDED );
	}

	/** Verifies and reactivates one organization. */
	public function handle_reactivate(): void {
		$this->handle_state_change( self::REACTIVATE_ACTION, Org_Repository::STATE_ACTIVE );
	}

	/**
	 * Testable delivery boundary for one state change.
	 *
	 * @param int    $org_id Organization id.
	 * @param string $state  Active or suspended.
	 * @return true|WP_Error
	 */
	public function process_state_change( int $org_id, string $state ): bool|WP_Error {
		return Org_Repository::STATE_SUSPENDED === $state
			? $this->manager->suspend( $org_id )
			: $this->manager->reactivate( $org_id );
	}

	/**
	 * Organization-scoped form nonce action.
	 *
	 * @param string $action Form action.
	 * @param int    $org_id Organization id.
	 */
	public static function nonce_action( string $action, int $org_id ): string {
		return $action . '_' . max( 0, $org_id );
	}

	/** Screen URL. */
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
		if ( 'success' === $result ) {
			$message = match ( $code ) {
				'organization_suspended'   => __( 'Organization suspended.', 'laao-advertiser-portal' ),
				'organization_reactivated' => __( 'Organization reactivated.', 'laao-advertiser-portal' ),
				default                    => __( 'Organization updated.', 'laao-advertiser-portal' ),
			};

			return array(
				'type'    => 'success',
				'message' => $message,
			);
		}

		if ( 'error' !== $result ) {
			return null;
		}

		$message = match ( $code ) {
			'laao_ads_forbidden'            => __( 'You do not have permission to manage organizations.', 'laao-advertiser-portal' ),
			'laao_ads_org_not_found'        => __( 'That organization could not be found.', 'laao-advertiser-portal' ),
			'laao_ads_org_state_not_saved'  => __( 'The organization state could not be saved. Try again.', 'laao-advertiser-portal' ),
			default                         => __( 'The organization could not be updated.', 'laao-advertiser-portal' ),
		};

		return array(
			'type'    => 'error',
			'message' => $message,
		);
	}

	/**
	 * Shared authenticated handler for suspend and reactivate.
	 *
	 * @param string $action Form action constant.
	 * @param string $state  Target state.
	 */
	private function handle_state_change( string $action, string $state ): void {
		if ( ! current_user_can( Capabilities::MANAGE_ORGS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'laao-advertiser-portal' ),
				'',
				array( 'response' => 403 )
			);
		}

		$org_id = $this->posted_org_id();
		check_admin_referer( self::nonce_action( $action, $org_id ) );

		$result = $this->process_state_change( $org_id, $state );
		$this->redirect_after( $result, $state );
	}

	/**
	 * Enqueues one local stylesheet with cache-safe versioning.
	 *
	 * @param string             $handle       Style handle.
	 * @param string             $relative     Plugin-relative path.
	 * @param array<int, string> $dependencies Style dependencies.
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
	 * @param string        $state  Target state.
	 * @return never
	 */
	private function redirect_after( bool|WP_Error $result, string $state ): never {
		$is_error = is_wp_error( $result );
		$code     = $is_error
			? sanitize_key( (string) $result->get_error_code() )
			: ( Org_Repository::STATE_SUSPENDED === $state ? 'organization_suspended' : 'organization_reactivated' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'laao_ads_result' => $is_error ? 'error' : 'success',
					'laao_ads_code'   => $code,
				),
				self::url()
			),
			303
		);

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

	/** Organization id used only to select its nonce action before verification. */
	private function posted_org_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Used only to select the per-organization nonce checked by the caller.
		return isset( $_POST['org_id'] ) ? absint( wp_unslash( $_POST['org_id'] ) ) : 0;
	}
}
