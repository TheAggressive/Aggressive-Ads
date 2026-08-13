<?php
/**
 * Organization invitation and access-request form handling.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Workflow\Organization_Membership;
use WP_Error;

/**
 * Protects and dispatches organization membership mutations.
 */
final class Organization_Actions implements Service {

	public const INVITE_ACTION   = 'aggr_invite_org_member';
	public const APPROVE_ACTION  = 'aggr_approve_org_access';
	public const DENY_ACTION     = 'aggr_deny_org_access';
	public const REMOVE_ACTION   = 'aggr_remove_org_member';
	public const TRANSFER_ACTION = 'aggr_transfer_org_ownership';
	public const RENAME_ACTION   = 'aggr_rename_organization';

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
		add_action( 'admin_post_' . self::REMOVE_ACTION, array( $this, 'handle_remove' ) );
		add_action( 'admin_post_' . self::TRANSFER_ACTION, array( $this, 'handle_transfer' ) );
		add_action( 'admin_post_' . self::RENAME_ACTION, array( $this, 'handle_rename' ) );
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

	/** Remove an existing non-owner member. */
	public function handle_remove(): void {
		check_admin_referer( self::REMOVE_ACTION );

		$result = $this->memberships->remove(
			$this->current_org_id(),
			$this->post_id( 'user_id' ),
			get_current_user_id()
		);

		$this->redirect_result( $result, 'removed' );
	}

	/** Transfer ownership to an existing member. */
	public function handle_transfer(): void {
		check_admin_referer( self::TRANSFER_ACTION );

		$result = $this->memberships->transfer(
			$this->current_org_id(),
			$this->post_id( 'user_id' ),
			get_current_user_id()
		);

		$this->redirect_result( $result, 'transferred' );
	}

	/** Rename the authenticated user's organization. */
	public function handle_rename(): void {
		check_admin_referer( self::RENAME_ACTION );

		$result = $this->memberships->rename(
			$this->current_org_id(),
			$this->post_organization_name(),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) && 'aggr_duplicate_org_identity' === $result->get_error_code() ) {
			$this->redirect( 'name_taken' );
		}

		$this->redirect_result( $result, 'renamed' );
	}

	/** Read an allowlisted organization notice. */
	public static function request_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only PRG display state; authorizes nothing.
		$value = isset( $_GET['aggr_org_notice'] ) ? sanitize_key( wp_unslash( $_GET['aggr_org_notice'] ) ) : '';

		return in_array(
			$value,
			array( 'invited', 'approved', 'denied', 'removed', 'transferred', 'renamed', 'name_taken', 'rate_limited', 'error' ),
			true
		) ? $value : '';
	}

	/**
	 * Fixed organization notice text.
	 *
	 * @param string $notice Allowlisted notice code.
	 */
	public static function notice_message( string $notice ): string {
		return match ( $notice ) {
			'invited'      => __( 'Invitation sent.', 'aggressive-ads' ),
			'approved'     => __( 'Organization access approved.', 'aggressive-ads' ),
			'denied'       => __( 'The pending request was closed.', 'aggressive-ads' ),
			'removed'      => __( 'That person was removed from the organization.', 'aggressive-ads' ),
			'transferred'  => __( 'Organization ownership was transferred.', 'aggressive-ads' ),
			'renamed'      => __( 'Organization name updated.', 'aggressive-ads' ),
			'name_taken'   => __( 'That organization name is already in use.', 'aggressive-ads' ),
			'rate_limited' => __( 'Too many invitations were sent. Please wait before trying again.', 'aggressive-ads' ),
			default        => __( 'The organization change could not be completed.', 'aggressive-ads' ),
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

	/** Read and bound the organization display name. */
	private function post_organization_name(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- handle_rename() verifies its action nonce; the value is sanitized and length-bounded before the workflow sees it.
		$value = isset( $_POST['organization_name'] ) && is_string( $_POST['organization_name'] ) ? wp_unslash( $_POST['organization_name'] ) : '';

		return strlen( $value ) <= Org_Repository::MAX_NAME_LENGTH ? sanitize_text_field( $value ) : '';
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
			add_query_arg( 'aggr_org_notice', $notice, Routes::url( Request::ROUTE_ORGANIZATION ) )
		);

		exit;
	}
}
