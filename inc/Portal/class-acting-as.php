<?php
/**
 * Staff acting for an advertiser, as an explicit session.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\User_Repository;
use Aggressive\Ads\Security\Capabilities;

/**
 * The acting-as session.
 *
 * **This changes scope, never permission.** A session tells the portal which
 * organization's data to show; it grants nothing. Every capability check and
 * every `Security\Ownership` decision is unchanged while one is open, so a
 * staff member acting for a client can do exactly what their own capabilities
 * already allowed against that client's objects — no more.
 *
 * That distinction is the whole safety argument. If entering a session ever
 * granted anything, it would become a privilege-escalation primitive that any
 * reviewer could point at any organization.
 *
 * The session is deliberately explicit at both ends. Staff enter it by
 * choosing an advertiser and leave it by saying so, because the failure mode
 * of an implicit one is a staff member editing a client's campaign believing
 * it is their own — and the portal looks identical either way.
 */
final class Acting_As {

	/**
	 * How long a session lasts without being renewed.
	 *
	 * Long enough for a support call, short enough that a forgotten session
	 * does not persist across days. Expiry is enforced on read.
	 */
	private const LIFETIME = 4 * HOUR_IN_SECONDS;

	/**
	 * Memoized per request, because the portal rail, the view data and the
	 * route guard all ask on the same page load.
	 *
	 * @var array<int, int>
	 */
	private array $resolved = array();

	/**
	 * Wires the repositories.
	 *
	 * @param User_Repository  $users User meta persistence.
	 * @param Org_Repository   $orgs  Organization lookups.
	 * @param Audit_Repository $audit Audit persistence.
	 */
	public function __construct(
		private readonly User_Repository $users,
		private readonly Org_Repository $orgs,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Begins a session for the current user.
	 *
	 * @param int $org_id Organization to act for.
	 * @return bool Whether the session started.
	 */
	public function enter( int $org_id ): bool {
		$user_id = get_current_user_id();

		if ( ! $this->may_act( $user_id ) || $org_id <= 0 || ! $this->orgs->exists( $org_id ) ) {
			return false;
		}

		$started = $this->users->store_acting_as( $user_id, $org_id, time() + self::LIFETIME );

		if ( ! $started ) {
			return false;
		}

		unset( $this->resolved[ $user_id ] );

		$this->audit->insert(
			new Audit_Event(
				event: 'onbehalf.session_started',
				object_type: 'organization',
				object_id: $org_id,
				org_id: $org_id,
				message: 'Staff began acting for the organization.',
				actor_user_id: $user_id
			)
		);

		return true;
	}

	/**
	 * Ends the current user's session.
	 *
	 * @return void
	 */
	public function leave(): void {
		$user_id = get_current_user_id();
		$org_id  = $this->users->acting_as( $user_id );

		$this->users->clear_acting_as( $user_id );
		unset( $this->resolved[ $user_id ] );

		if ( $org_id <= 0 ) {
			return;
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'onbehalf.session_ended',
				object_type: 'organization',
				object_id: $org_id,
				org_id: $org_id,
				message: 'Staff stopped acting for the organization.',
				actor_user_id: $user_id
			)
		);
	}

	/**
	 * The organization the current user is acting for, or 0.
	 *
	 * The capability is re-checked here rather than trusted from `enter()`.
	 * A stored session outlives the grant that allowed it, so a reviewer whose
	 * capability is withdrawn must stop acting immediately rather than at the
	 * next expiry.
	 *
	 * @return int
	 */
	public function org_id(): int {
		$user_id = get_current_user_id();

		if ( array_key_exists( $user_id, $this->resolved ) ) {
			return $this->resolved[ $user_id ];
		}

		$org_id = $this->may_act( $user_id ) ? $this->users->acting_as( $user_id ) : 0;

		// An organization deleted mid-session would otherwise scope the portal
		// to an id that resolves to nothing, which reads as an empty account
		// rather than as a session that should have ended.
		if ( $org_id > 0 && ! $this->orgs->exists( $org_id ) ) {
			$org_id = 0;
		}

		$this->resolved[ $user_id ] = $org_id;

		return $org_id;
	}

	/**
	 * Whether a session is open.
	 *
	 * @return bool
	 */
	public function active(): bool {
		return $this->org_id() > 0;
	}

	/**
	 * The name of the organization being acted for.
	 *
	 * @return string
	 */
	public function org_name(): string {
		$org_id = $this->org_id();

		return $org_id > 0 ? $this->orgs->name( $org_id ) : '';
	}

	/**
	 * Whether a user may act for an organization at all.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private function may_act( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, Capabilities::REVIEW_CAMPAIGNS );
	}
}
