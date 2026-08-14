<?php
/**
 * Public advertiser registration.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Notification\Password_Notification;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\User_Repository;
use WP_Error;

/**
 * Creates an account and its ownership context as one recoverable workflow.
 *
 * WordPress has no application-level transaction spanning users, posts, meta
 * and mail. The safe substitute is ordered privilege plus compensation: the
 * user begins as a subscriber, the organization is created and verified, the
 * advertiser role is granted, and any failure removes both records. A setup
 * email is last, so no usable credentials exist for a partial account.
 */
final class Advertiser_Registration {

	public const MAX_PERSON_NAME = 100;
	public const MAX_ORG_NAME    = Org_Repository::MAX_NAME_LENGTH;
	public const MAX_EMAIL       = 100;

	/**
	 * Constructor.
	 *
	 * @param User_Repository         $users        User persistence.
	 * @param Org_Repository          $organizations Organization persistence.
	 * @param Organization_Membership $memberships Organization access workflow.
	 * @param Password_Notification   $notification Activation email.
	 * @param Audit_Repository        $audit         Audit persistence.
	 * @param Settings                $settings      Module flags.
	 */
	public function __construct(
		private readonly User_Repository $users,
		private readonly Org_Repository $organizations,
		private readonly Organization_Membership $memberships,
		private readonly Password_Notification $notification,
		private readonly Audit_Repository $audit,
		private readonly Settings $settings
	) {
	}

	/**
	 * Whether public account creation is on.
	 *
	 * The module is a kill-switch: off means this returns false even if
	 * WordPress would allow registration. The filter wraps the core switch so
	 * an identity policy can close signup without turning the module off
	 * (invitations still need the route). The filter cannot reopen a disabled
	 * module.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		if ( ! $this->settings->module_enabled( Settings_Schema::MODULE_PUBLIC_SIGNUP ) ) {
			return false;
		}

		/**
		 * Filters whether public advertiser signup is enabled.
		 *
		 * @param bool $enabled WordPress's Anyone can register setting, after the module flag.
		 */
		return (bool) apply_filters( 'aggr_signup_enabled', (bool) get_option( 'users_can_register', false ) );
	}

	/**
	 * Whether the public signup route exists at all.
	 *
	 * Distinct from is_enabled(): WordPress may close registration while the
	 * module stays on (form shows “unavailable”). The module off means 404.
	 *
	 * @return bool
	 */
	public function is_route_available(): bool {
		return $this->settings->module_enabled( Settings_Schema::MODULE_PUBLIC_SIGNUP );
	}

	/**
	 * Registers one advertiser, returning no account-existence signal.
	 *
	 * An existing email returns the same success as a newly queued activation.
	 * Sending another email would let this unauthenticated endpoint be used as
	 * a mail-bombing primitive, so duplicates are deliberately silent.
	 *
	 * @param array<string, string> $input Raw submitted fields.
	 * @return true|WP_Error
	 */
	public function register( array $input ): bool|WP_Error {
		$invite = is_string( $input['invite_token'] ?? null ) ? $input['invite_token'] : '';

		if ( ! $this->is_enabled() && 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/', $invite ) ) {
			return new WP_Error( 'aggr_registration_closed', __( 'Account registration is not available.', 'aggressive-ads' ) );
		}

		$fields = $this->validated_fields( $input );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		if ( $this->users->email_exists( $fields['email'] ) ) {
			$user = $this->users->by_email( $fields['email'] );

			if ( '' !== $fields['invite_token'] ) {
				return null !== $user
					? $this->memberships->accept_existing_invitation( $fields['invite_token'], $fields['email'], $user->ID )
					: true;
			}

			/*
			 * Ordinary existing accounts remain silent. The narrow exception is
			 * a subscriber retrying the same expired organization request: without
			 * this, expiry would permanently strand the address because public
			 * registration cannot create a second WordPress account for it.
			 */
			$matching_org = $this->organizations->matching_org_id( $fields['organization_name'] );
			if (
				null !== $user
				&& $matching_org > 0
				&& array( 'subscriber' ) === array_values( $user->roles )
				&& array() === $this->organizations->org_ids_for_user( $user->ID )
				&& $this->organizations->is_active( $matching_org )
				&& $this->organizations->has_expired_access_request( $user->ID, $matching_org )
			) {
				$this->memberships->request_access( $matching_org, $fields['email'], $user->ID );
			}

			return true;
		}

		$user_id = $this->users->create_registration_account( $fields );

		if ( is_wp_error( $user_id ) ) {
			if ( 'existing_user_email' === $user_id->get_error_code() ) {
				return true;
			}

			$this->record_failure( 0, 0, 'user_create' );

			return new WP_Error( 'aggr_registration_failed', __( 'The account could not be created.', 'aggressive-ads' ) );
		}

		if ( '' !== $fields['invite_token'] ) {
			$result = $this->memberships->accept_new_invitation( $fields['invite_token'], $fields['email'], $user_id );

			if ( is_wp_error( $result ) ) {
				$this->users->delete_registration_account( $user_id );
			}

			return $result;
		}

		$matching_org = $this->organizations->matching_org_id( $fields['organization_name'] );
		if ( $matching_org > 0 ) {
			$result = $this->memberships->request_access( $matching_org, $fields['email'], $user_id );

			if ( is_wp_error( $result ) ) {
				$this->users->delete_registration_account( $user_id );
			}

			return $result;
		}

		$org_id = $this->organizations->create_for_owner( $fields['organization_name'], $user_id );

		if ( is_wp_error( $org_id ) ) {
			$error_data    = $org_id->get_error_data( 'aggr_duplicate_org_identity' );
			$duplicate_org = 'aggr_duplicate_org_identity' === $org_id->get_error_code() && is_array( $error_data )
				? (int) ( $error_data['org_id'] ?? 0 )
				: 0;

			if ( $duplicate_org > 0 ) {
				$result = $this->memberships->request_access( $duplicate_org, $fields['email'], $user_id );

				if ( ! is_wp_error( $result ) ) {
					return true;
				}
			}

			$user_removed = $this->users->delete_registration_account( $user_id );
			$this->record_failure( $user_id, 0, 'organization_create', array( 'user_removed' => $user_removed ) );

			return new WP_Error( 'aggr_registration_failed', __( 'The account could not be created.', 'aggressive-ads' ) );
		}

		if ( ! $this->users->grant_advertiser_role( $user_id ) ) {
			$this->rollback( $user_id, $org_id, 'role_assignment' );

			return new WP_Error( 'aggr_registration_failed', __( 'The account could not be created.', 'aggressive-ads' ) );
		}

		if ( ! $this->notification->send_setup( $user_id ) ) {
			$this->rollback( $user_id, $org_id, 'activation_email' );

			return new WP_Error( 'aggr_registration_failed', __( 'The account could not be created.', 'aggressive-ads' ) );
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'advertiser.registered',
				object_type: 'user',
				object_id: $user_id,
				org_id: $org_id,
				message: 'Created an advertiser account and sent its activation email.'
			)
		);

		return true;
	}

	/**
	 * Sanitizes and validates the public field allowlist.
	 *
	 * @param array<string, string> $input Raw input.
	 * @return array{email: string, first_name: string, last_name: string, organization_name: string, invite_token: string}|WP_Error
	 */
	private function validated_fields( array $input ): array|WP_Error {
		if (
			strlen( $input['first_name'] ?? '' ) > self::MAX_PERSON_NAME
			|| strlen( $input['last_name'] ?? '' ) > self::MAX_PERSON_NAME
			|| strlen( $input['organization_name'] ?? '' ) > self::MAX_ORG_NAME
			|| strlen( $input['email'] ?? '' ) > self::MAX_EMAIL
		) {
			return new WP_Error( 'aggr_invalid_registration', __( 'Enter a valid name, organization and email address.', 'aggressive-ads' ) );
		}

		$first_name        = sanitize_text_field( $input['first_name'] ?? '' );
		$last_name         = sanitize_text_field( $input['last_name'] ?? '' );
		$organization_name = sanitize_text_field( $input['organization_name'] ?? '' );
		$organization_name = function_exists( 'mb_strtoupper' )
			? mb_strtoupper( $organization_name, 'UTF-8' )
			: strtoupper( $organization_name );
		$email             = strtolower( sanitize_email( $input['email'] ?? '' ) );
		$invite_token      = sanitize_text_field( $input['invite_token'] ?? '' );

		if (
			'' === $first_name || '' === $last_name || ( '' === $organization_name && '' === $invite_token ) || '' === $email
			|| strlen( $first_name ) > self::MAX_PERSON_NAME
			|| strlen( $last_name ) > self::MAX_PERSON_NAME
			|| strlen( $organization_name ) > self::MAX_ORG_NAME
			|| strlen( $email ) > self::MAX_EMAIL
			|| ! is_email( $email )
			|| ( '' !== $invite_token && 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/', $invite_token ) )
		) {
			return new WP_Error( 'aggr_invalid_registration', __( 'Enter a valid name, organization and email address.', 'aggressive-ads' ) );
		}

		return array(
			'email'             => $email,
			'first_name'        => $first_name,
			'last_name'         => $last_name,
			'organization_name' => $organization_name,
			'invite_token'      => $invite_token,
		);
	}

	/**
	 * Compensates for a workflow failure after both records exist.
	 *
	 * @param int    $user_id User id.
	 * @param int    $org_id  Organization id.
	 * @param string $stage   Failed stage.
	 * @return void
	 */
	private function rollback( int $user_id, int $org_id, string $stage ): void {
		$org_removed  = $this->organizations->delete_registration_org( $org_id );
		$user_removed = $this->users->delete_registration_account( $user_id );

		$this->record_failure(
			$user_id,
			$org_id,
			$stage,
			array(
				'org_removed'  => $org_removed,
				'user_removed' => $user_removed,
			)
		);
	}

	/**
	 * Records infrastructure failure without names, addresses or tokens.
	 *
	 * @param int                  $user_id User id, if allocated.
	 * @param int                  $org_id  Organization id, if allocated.
	 * @param string               $stage   Failed stage.
	 * @param array<string, mixed> $context Safe recovery detail.
	 * @return void
	 */
	private function record_failure( int $user_id, int $org_id, string $stage, array $context = array() ): void {
		$this->audit->insert(
			new Audit_Event(
				event: 'advertiser.registration_failed',
				outcome: Audit_Event::OUTCOME_FAILED,
				object_type: 'user',
				object_id: $user_id,
				org_id: $org_id,
				message: 'Advertiser registration failed during ' . $stage . '.',
				context: array_merge( array( 'stage' => $stage ), $context )
			)
		);
	}
}
