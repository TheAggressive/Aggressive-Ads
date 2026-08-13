<?php
/**
 * User lookup persistence.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Security\Roles;
use WP_Error;
use WP_User;

/**
 * Resolves users for capability-driven application work.
 */
final class User_Repository {
	/** Number of users held in memory while resolving capability filters. */
	private const BATCH_SIZE = 200;

	/** Pending self-service email change (HMAC hash + destination + expiry). */
	public const META_EMAIL_CHANGE = '_aggr_email_change';

	/**
	 * Whether an email address is already attached to a WordPress account.
	 *
	 * @param string $email Normalized email address.
	 * @return bool
	 */
	public function email_exists( string $email ): bool {
		return false !== email_exists( $email );
	}

	/**
	 * Whether another account already owns this address.
	 *
	 * @param string $email   Normalized email.
	 * @param int    $user_id User allowed to keep the address.
	 */
	public function email_taken_by_other( string $email, int $user_id ): bool {
		$existing = $this->by_email( $email );

		return null !== $existing && (int) $existing->ID !== $user_id;
	}

	/**
	 * Store one pending email-change challenge.
	 *
	 * @param int                  $user_id User id.
	 * @param array<string, mixed> $pending Hash, destination and expiry.
	 */
	public function store_email_change( int $user_id, array $pending ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		update_user_meta( $user_id, self::META_EMAIL_CHANGE, $pending );

		$stored = $this->email_change( $user_id );

		return is_array( $stored )
			&& (string) ( $stored['token_hash'] ?? '' ) === (string) ( $pending['token_hash'] ?? '' )
			&& (string) ( $stored['new_email'] ?? '' ) === (string) ( $pending['new_email'] ?? '' )
			&& (int) ( $stored['expires_at'] ?? 0 ) === (int) ( $pending['expires_at'] ?? 0 );
	}

	/**
	 * Read a pending email-change challenge.
	 *
	 * @param int $user_id User id.
	 * @return array{token_hash: string, new_email: string, expires_at: int}|null
	 */
	public function email_change( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}

		$raw = get_user_meta( $user_id, self::META_EMAIL_CHANGE, true );
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$hash    = (string) ( $raw['token_hash'] ?? '' );
		$email   = (string) ( $raw['new_email'] ?? '' );
		$expires = (int) ( $raw['expires_at'] ?? 0 );

		if ( '' === $hash || '' === $email || $expires <= 0 ) {
			return null;
		}

		return array(
			'token_hash' => $hash,
			'new_email'  => $email,
			'expires_at' => $expires,
		);
	}

	/**
	 * Clear a pending email-change challenge.
	 *
	 * @param int $user_id User id.
	 */
	public function clear_email_change( int $user_id ): void {
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::META_EMAIL_CHANGE );
		}
	}

	/**
	 * Persist a confirmed email address through core.
	 *
	 * @param int    $user_id User id.
	 * @param string $email   Normalized destination address.
	 * @return true|WP_Error
	 */
	public function update_email( int $user_id, string $email ): bool|WP_Error {
		if ( $user_id <= 0 || ! is_email( $email ) ) {
			return new WP_Error( 'aggr_invalid_email' );
		}

		$updated = wp_update_user(
			array(
				'ID'         => $user_id,
				'user_email' => $email,
			)
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$user = $this->by_id( $user_id );
		if ( null === $user || strtolower( (string) $user->user_email ) !== strtolower( $email ) ) {
			return new WP_Error( 'aggr_email_not_saved' );
		}

		return true;
	}

	/**
	 * Creates the deliberately unprivileged half of a registration.
	 *
	 * The advertiser role is assigned only after the organization has been
	 * created and read back successfully. A callback running on user_register
	 * therefore sees a subscriber, never a portal account with no ownership
	 * context. The generated login is intentionally unrelated to the email;
	 * people sign in with their email address and no public response reveals the
	 * internal identifier.
	 *
	 * @param array{email: string, first_name: string, last_name: string} $fields Validated account fields.
	 * @return int|WP_Error
	 */
	public function create_registration_account( array $fields ): int|WP_Error {
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$user_id = wp_insert_user(
				array(
					'user_login'   => 'laao_' . strtolower( wp_generate_password( 20, false, false ) ),
					'user_pass'    => wp_generate_password( 64, true, true ),
					'user_email'   => $fields['email'],
					'first_name'   => $fields['first_name'],
					'last_name'    => $fields['last_name'],
					'display_name' => trim( $fields['first_name'] . ' ' . $fields['last_name'] ),
					'role'         => 'subscriber',
				)
			);

			if ( ! is_wp_error( $user_id ) || 'existing_user_login' !== $user_id->get_error_code() ) {
				return $user_id;
			}
		}

		return new WP_Error( 'aggr_user_collision', __( 'The account could not be created.', 'aggressive-ads' ) );
	}

	/**
	 * Grants portal access after every ownership record exists.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public function grant_advertiser_role( int $user_id ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return false;
		}

		$user->set_role( Roles::ADVERTISER );

		return in_array( Roles::ADVERTISER, $user->roles, true );
	}

	/**
	 * Add portal access without removing an existing WordPress role.
	 *
	 * Used for an explicitly invited existing account. Replacing all roles
	 * would silently demote an editor or administrator who also advertises.
	 *
	 * @param int $user_id User id.
	 */
	public function add_advertiser_role( int $user_id ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return false;
		}

		$user->add_role( Roles::ADVERTISER );

		return in_array( Roles::ADVERTISER, $user->roles, true );
	}

	/**
	 * Whether the user already has the advertiser role.
	 *
	 * Compensation must not remove a role that predated an invitation attempt.
	 *
	 * @param int $user_id User id.
	 */
	public function has_advertiser_role( int $user_id ): bool {
		$user = get_userdata( $user_id );

		return $user instanceof WP_User && in_array( Roles::ADVERTISER, $user->roles, true );
	}

	/**
	 * Remove only the advertiser role during membership compensation.
	 *
	 * @param int $user_id User id.
	 */
	public function remove_advertiser_role( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( $user instanceof WP_User ) {
			$user->remove_role( Roles::ADVERTISER );
		}
	}

	/**
	 * Loads the identity used to issue a core password-reset key.
	 *
	 * @param int $user_id User id.
	 * @return WP_User|null
	 */
	public function by_id( int $user_id ): ?WP_User {
		$user = get_userdata( $user_id );

		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Loads an identity by its normalized email address.
	 *
	 * @param string $email Email address.
	 * @return WP_User|null
	 */
	public function by_email( string $email ): ?WP_User {
		$user = get_user_by( 'email', $email );

		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Removes an incomplete public registration.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public function delete_registration_account( int $user_id ): bool {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		return (bool) wp_delete_user( $user_id );
	}

	/**
	 * Every current user who actually holds a capability.
	 *
	 * Resolution deliberately runs user_can() for every account, in bounded
	 * batches. WP_User_Query's capability argument sees stored roles and direct
	 * grants but cannot see a capability supplied by the `user_has_cap` filter;
	 * narrowing on it would silently omit a legitimate dynamically granted
	 * reviewer. Submission is not a hot read path, so correctness wins here.
	 *
	 * @param string $capability Primitive capability.
	 * @return array<int, array{id: int, email: string}>
	 */
	public function with_capability( string $capability ): array {
		if ( '' === trim( $capability ) ) {
			return array();
		}

		$rows   = array();
		$offset = 0;
		$count  = 0;

		do {
			$users = get_users(
				array(
					'number'  => self::BATCH_SIZE,
					'offset'  => $offset,
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);

			foreach ( $users as $user ) {
				if ( ! $user instanceof WP_User || $user->ID <= 0 || ! user_can( $user, $capability ) ) {
					continue;
				}

				$rows[] = array(
					'id'    => (int) $user->ID,
					'email' => (string) $user->user_email,
				);
			}

			$count   = count( $users );
			$offset += $count;
		} while ( self::BATCH_SIZE === $count );

		return $rows;
	}
}
