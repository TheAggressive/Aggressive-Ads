<?php
/**
 * Who may review advertising, granted per user rather than per role.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\User_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use WP_Error;

/**
 * The roster behind Advertising → Settings → Access.
 *
 * Capabilities are granted **directly to the user**, additively, rather than by
 * changing their role. WordPress's own UI treats a role as single-valued, so
 * making an existing Editor into an Ad Reviewer takes Editor away from them —
 * which is why "just change their role" is not the answer even though a
 * reviewer role exists.
 *
 * The stored roster is an index, not a permission. Every authorization question
 * in this plugin is answered by `current_user_can()`, and that stays true: this
 * class writes real user capabilities and the option only records who was
 * granted them here, so the list can be rendered and reconciled. Anything that
 * consulted the option to decide access would be a second permission system,
 * free to disagree with the first.
 */
final class Reviewer_Access {

	/**
	 * User ids granted review access through this screen.
	 */
	public const OPTION = 'aggr_reviewers';

	/**
	 * Constructor.
	 *
	 * @param User_Repository  $users User lookups.
	 * @param Audit_Repository $audit Audit persistence.
	 */
	public function __construct(
		private readonly User_Repository $users,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * The capabilities this grant carries.
	 *
	 * Exactly the Ad Reviewer set, so a user granted here and a user holding
	 * the role are indistinguishable to every check in the plugin. Deliberately
	 * not the manage-* capabilities: reviewing campaigns is a daily job, while
	 * changing inventory or settings decides what serves on the public site.
	 *
	 * @return array<int, string>
	 */
	public static function capabilities(): array {
		return array_keys( Roles::reviewer_capability_map() );
	}

	/**
	 * User ids granted through this screen, in order.
	 *
	 * @return array<int, int>
	 */
	public function granted_ids(): array {
		$stored = get_option( self::OPTION, array() );
		$ids    = array();

		foreach ( is_array( $stored ) ? $stored : array() as $id ) {
			$id = (int) $id;

			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * The roster, ready to render.
	 *
	 * @return array<int, array{id: int, name: string, email: string, roles: string, is_admin: bool}>
	 */
	public function roster(): array {
		$rows = array();

		foreach ( $this->granted_ids() as $user_id ) {
			$user = get_userdata( $user_id );

			if ( false === $user ) {
				continue;
			}

			$rows[] = array(
				'id'       => $user_id,
				'name'     => (string) $user->display_name,
				'email'    => (string) $user->user_email,
				'roles'    => implode( ', ', array_map( 'translate_user_role', array_map( 'ucfirst', (array) $user->roles ) ) ),
				'is_admin' => user_can( $user_id, 'manage_options' ),
			);
		}

		return $rows;
	}

	/**
	 * Grants review access to one user.
	 *
	 * @param int $user_id User id.
	 * @return true|WP_Error
	 */
	public function grant( int $user_id ): bool|WP_Error {
		$authorized = $this->authorize();

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$user = get_userdata( $user_id );

		if ( false === $user ) {
			return new WP_Error( 'aggr_user_not_found', __( 'No user was found with that name or email address.', 'aggressive-ads' ), array( 'status' => 404 ) );
		}

		// An advertiser is somebody else's customer, and reviewing is reading
		// every organization's unpublished creative. Promoting one from this
		// screen would be a two-click tenancy breach.
		if ( in_array( Roles::ADVERTISER, (array) $user->roles, true ) ) {
			return new WP_Error( 'aggr_user_is_advertiser', __( 'That account is an advertiser. Advertisers cannot be given review access.', 'aggressive-ads' ), array( 'status' => 422 ) );
		}

		foreach ( self::capabilities() as $capability ) {
			$user->add_cap( $capability );
		}

		$ids = $this->granted_ids();

		if ( ! in_array( $user_id, $ids, true ) ) {
			$ids[] = $user_id;
			update_option( self::OPTION, $ids, true );
		}

		$this->log( 'reviewer.granted', $user_id, 'Review access granted.' );

		return true;
	}

	/**
	 * Removes review access from one user.
	 *
	 * @param int $user_id User id.
	 * @return true|WP_Error
	 */
	public function revoke( int $user_id ): bool|WP_Error {
		$authorized = $this->authorize();

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$user = get_userdata( $user_id );

		if ( false !== $user ) {
			foreach ( self::capabilities() as $capability ) {
				$user->remove_cap( $capability );
			}
		}

		update_option(
			self::OPTION,
			array_values( array_diff( $this->granted_ids(), array( $user_id ) ) ),
			true
		);

		$this->log( 'reviewer.revoked', $user_id, 'Review access removed.' );

		return true;
	}

	/**
	 * Finds a user by login or email, for the add control.
	 *
	 * @param string $identifier Login or email address.
	 * @return int User id, or 0.
	 */
	public function find( string $identifier ): int {
		$identifier = trim( $identifier );

		if ( '' === $identifier ) {
			return 0;
		}

		return $this->users->id_for_login_or_email( $identifier );
	}

	/**
	 * Only somebody who can already change advertising settings may hand out
	 * review access, because handing it out is a settings-grade decision.
	 *
	 * @return true|WP_Error
	 */
	private function authorize() {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return new WP_Error( 'aggr_forbidden', __( 'You do not have permission to change advertising access.', 'aggressive-ads' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Records the change against the user, not a campaign.
	 *
	 * @param string $event   Audit event name.
	 * @param int    $user_id Subject user.
	 * @param string $message Human-readable summary.
	 * @return void
	 */
	private function log( string $event, int $user_id, string $message ): void {
		$this->audit->insert(
			new Audit_Event(
				event: $event,
				object_type: 'user',
				object_id: $user_id,
				org_id: 0,
				message: $message,
				outcome: Audit_Event::OUTCOME_OK,
				context: array(),
				actor_user_id: get_current_user_id()
			)
		);
	}
}
