<?php
/**
 * Staff organization suspension screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Organization_State_Manager;
use WP_Error;

/**
 * Delivers suspension controls without exposing the generic organization editor.
 */
final class Organization_Screen implements Service {

	public const MENU_SLUG         = 'aggr-organizations';
	public const SUSPEND_ACTION    = 'aggr_suspend_organization';
	public const REACTIVATE_ACTION = 'aggr_reactivate_organization';

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
		add_action( 'admin_post_' . self::SUSPEND_ACTION, array( $this, 'handle_suspend' ) );
		add_action( 'admin_post_' . self::REACTIVATE_ACTION, array( $this, 'handle_reactivate' ) );
	}

	/**
	 * Registers a capability-owned submenu under Advertising.
	 */
	public function register_menu(): void {
		add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Organizations', 'aggressive-ads' ),
			__( 'Organizations', 'aggressive-ads' ),
			Capabilities::MANAGE_ORGS,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/*
	 * No stylesheet is enqueued here: the screen is native WordPress admin
	 * markup, so core already styles every part of it.
	 */

	/** Renders the authorized organization table. */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ORGS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'aggressive-ads' ),
				'',
				array( 'response' => 403 )
			);
		}

		$aggr_view   = $this->data->view();
		$aggr_notice = $this->request_notice();

		require AGGR_PLUGIN_DIR . 'templates/admin/organizations.php';
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
				'organization_suspended'   => __( 'Organization suspended.', 'aggressive-ads' ),
				'organization_reactivated' => __( 'Organization reactivated.', 'aggressive-ads' ),
				default                    => __( 'Organization updated.', 'aggressive-ads' ),
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
			'aggr_forbidden'            => __( 'You do not have permission to manage organizations.', 'aggressive-ads' ),
			'aggr_org_not_found'        => __( 'That organization could not be found.', 'aggressive-ads' ),
			'aggr_org_state_not_saved'  => __( 'The organization state could not be saved. Try again.', 'aggressive-ads' ),
			default                         => __( 'The organization could not be updated.', 'aggressive-ads' ),
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
				esc_html__( 'You do not have permission to do that.', 'aggressive-ads' ),
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
	 * Returns to the list with a success or error flag.
	 *
	 * @param true|WP_Error $result Outcome.
	 * @param string        $state  Requested state.
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
					'aggr_result' => $is_error ? 'error' : 'success',
					'aggr_code'   => $code,
				),
				self::url()
			),
			303
		);

		exit;
	}

	/**
	 * Flash copy from the redirect query string.
	 *
	 * @return array{type: string, message: string}|null
	 */
	private function request_notice(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, allowlisted state selecting fixed copy.
		$result = isset( $_GET['aggr_result'] ) ? sanitize_key( wp_unslash( $_GET['aggr_result'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, allowlisted state selecting fixed copy.
		$code = isset( $_GET['aggr_code'] ) ? sanitize_key( wp_unslash( $_GET['aggr_code'] ) ) : '';

		return self::notice_for( $result, $code );
	}

	/** Organization id used only to select its nonce action before verification. */
	private function posted_org_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Used only to select the per-organization nonce checked by the caller.
		return isset( $_POST['org_id'] ) ? absint( wp_unslash( $_POST['org_id'] ) ) : 0;
	}
}
