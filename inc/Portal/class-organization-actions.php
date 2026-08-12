<?php
/**
 * Organization invitation and access-request form handling.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Portal;

use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Security\Rate_Limiter;
use LAAO_Advertiser_Portal\Workflow\Organization_Membership;
use WP_Error;

/**
 * Protects and dispatches organization membership mutations.
 */
final class Organization_Actions implements Service {

	public const INVITE_ACTION  = 'laao_ads_invite_org_member';
	public const APPROVE_ACTION = 'laao_ads_approve_org_access';
	public const DENY_ACTION    = 'laao_ads_deny_org_access';

	/**
	 * Constructor.
	 *
	 * @param Organization_Membership $memberships Membership workflow.
	 * @param Org_Repository          $organizations Organization persistence.
	 * @param Rate_Limiter            $limiter     Invitation abuse bound.
	 */
	public function __construct(
		private readonly Organization_Membership $memberships,
		private readonly Org_Repository $organizations,
		private readonly Rate_Limiter $limiter
	) {
	}

	/** Attach authenticated form actions. */
	public function init(): void {
		add_action( 'admin_post_' . self::INVITE_ACTION, array( $this, 'handle_invite' ) );
		add_action( 'admin_post_' . self::APPROVE_ACTION, array( $this, 'handle_approve' ) );
		add_action( 'admin_post_' . self::DENY_ACTION, array( $this, 'handle_deny' ) );
	}

	/** Send an expiring invitation. */
	public function handle_invite(): void {
		check_admin_referer( self::INVITE_ACTION );

		$user_id = get_current_user_id();
		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_ORG_INVITE, $user_id );
		if ( is_wp_error( $allowed ) ) {
			$this->redirect( 'rate_limited' );
		}

		$result = $this->memberships->invite(
			$this->current_org_id(),
			$this->post_email(),
			$user_id
		);

		$this->redirect_result( $result, 'invited' );
	}

	/** Approve a pending duplicate-name request. */
	public function handle_approve(): void {
		check_admin_referer( self::APPROVE_ACTION );

		$result = $this->memberships->approve(
			$this->post_id( 'access_id' ),
			$this->current_org_id(),
			get_current_user_id()
		);

		$this->redirect_result( $result, 'approved' );
	}

	/** Deny a request or revoke a pending invitation. */
	public function handle_deny(): void {
		check_admin_referer( self::DENY_ACTION );

		$result = $this->memberships->deny(
			$this->post_id( 'access_id' ),
			$this->current_org_id(),
			get_current_user_id()
		);

		$this->redirect_result( $result, 'denied' );
	}

	/** Read an allowlisted organization notice. */
	public static function request_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only PRG display state; authorizes nothing.
		$value = isset( $_GET['laao_ads_org_notice'] ) ? sanitize_key( wp_unslash( $_GET['laao_ads_org_notice'] ) ) : '';

		return in_array( $value, array( 'invited', 'approved', 'denied', 'rate_limited', 'error' ), true ) ? $value : '';
	}

	/**
	 * Fixed organization notice text.
	 *
	 * @param string $notice Allowlisted notice code.
	 */
	public static function notice_message( string $notice ): string {
		return match ( $notice ) {
			'invited'      => __( 'Invitation sent.', 'laao-advertiser-portal' ),
			'approved'     => __( 'Organization access approved.', 'laao-advertiser-portal' ),
			'denied'       => __( 'The pending request was closed.', 'laao-advertiser-portal' ),
			'rate_limited' => __( 'Too many invitations were sent. Please wait before trying again.', 'laao-advertiser-portal' ),
			default        => __( 'The organization change could not be completed.', 'laao-advertiser-portal' ),
		};
	}

	/**
	 * Read one positive integer from the nonce-protected POST.
	 *
	 * @param string $key POST field name.
	 */
	private function post_id( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Each public handler verifies its action nonce before calling this bounded reader.
		$value = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0;

		return $value;
	}

	/** Derive the tenant from the authenticated user, never request input. */
	private function current_org_id(): int {
		$org_ids = $this->organizations->org_ids_for_user( get_current_user_id() );

		return $org_ids[0] ?? 0;
	}

	/** Read and normalize the invited address. */
	private function post_email(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- handle_invite() verifies its action nonce; the bounded value is immediately passed through sanitize_email().
		$value = isset( $_POST['email'] ) && is_string( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '';

		return strlen( $value ) <= 100 ? strtolower( sanitize_email( $value ) ) : '';
	}

	/**
	 * Redirect a workflow result.
	 *
	 * @param true|WP_Error $result  Workflow result.
	 * @param string        $success Success notice code.
	 */
	private function redirect_result( true|WP_Error $result, string $success ): never {
		$this->redirect( true === $result ? $success : 'error' );
	}

	/**
	 * Return to the organization screen with no submitted data.
	 *
	 * @param string $notice Notice code.
	 */
	private function redirect( string $notice ): never {
		wp_safe_redirect(
			add_query_arg( 'laao_ads_org_notice', $notice, Routes::url( Request::ROUTE_ORGANIZATION ) )
		);

		exit;
	}
}
