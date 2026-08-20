<?php
/**
 * Who may edit a campaign right now, and in whose name.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Domain\On_Behalf;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Capabilities;

/**
 * The edit window, resolved for the current user.
 *
 * Capability answers *whether* a user may touch a campaign; this answers
 * *when*. The two are separate because they fail differently: a capability
 * failure is a 403 and means the user should not be here, while a window
 * failure is a 409 and means not right now.
 *
 * Advertisers may edit a draft or a campaign with changes requested. Staff may
 * edit in any status, acting on the client's behalf — see
 * `Post_Statuses::staff_editable()` for why that is wider than the transition
 * rules.
 */
final class Edit_Window {

	/**
	 * Wires the repositories.
	 *
	 * @param Campaign_Repository $campaigns Campaign reads.
	 * @param Org_Repository      $orgs      Membership reads.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Org_Repository $orgs
	) {
	}

	/**
	 * Whether the current user holds the review capability.
	 *
	 * @return bool
	 */
	public function is_staff(): bool {
		return current_user_can( Capabilities::REVIEW_CAMPAIGNS );
	}

	/**
	 * The statuses the current user may edit in.
	 *
	 * @return array<int, string>
	 */
	public function statuses(): array {
		return Post_Statuses::editable_for( $this->is_staff() );
	}

	/**
	 * Whether the named campaign's status is inside the current user's window.
	 *
	 * This deliberately says nothing about capability. Callers check both.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return bool
	 */
	public function allows( int $campaign_id ): bool {
		return in_array( $this->campaigns->status( $campaign_id ), $this->statuses(), true );
	}

	/**
	 * Whether this edit is staff acting for a client rather than a member
	 * editing their own organization's work.
	 *
	 * Membership is the test, not capability. An administrator who genuinely
	 * belongs to the owning organization is editing their own campaign, and
	 * recording that as an on-behalf edit would make the audit timeline read
	 * as though an outsider had reached in.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return bool
	 */
	public function is_on_behalf( int $campaign_id ): bool {
		return On_Behalf::applies(
			$this->is_staff(),
			$this->campaigns->org_id( $campaign_id ),
			$this->orgs->org_ids_for_user( get_current_user_id() )
		);
	}
}
