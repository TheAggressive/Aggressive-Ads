<?php
/**
 * Read model for staff organization administration.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\User_Repository;

/**
 * Assembles organization rows for the staff suspension screen.
 */
final class Organization_Data {

	/**
	 * Constructor.
	 *
	 * @param Org_Repository      $organizations Organization persistence.
	 * @param Campaign_Repository $campaigns     Campaign counts.
	 * @param User_Repository     $users         Owner identity lookup.
	 */
	public function __construct(
		private readonly Org_Repository $organizations,
		private readonly Campaign_Repository $campaigns,
		private readonly User_Repository $users
	) {
	}

	/**
	 * Complete organizations-screen state.
	 *
	 * @return array{rows: array<int, array<string, mixed>>}
	 */
	public function view(): array {
		$rows = array();

		foreach ( $this->organizations->all_ids() as $org_id ) {
			$owner_id = $this->organizations->owner_user_id( $org_id );
			$owner    = $owner_id > 0 ? $this->users->by_id( $owner_id ) : null;
			$list     = $this->campaigns->for_org( $org_id, 1 );
			$state    = $this->organizations->state( $org_id );

			/*
			 * The roster is listed, not counted.
			 *
			 * Staff need to act on a specific person — transfer ownership to
			 * them, or remove them — and a count identifies nobody. The owner is
			 * marked here rather than compared in the browser, because the rule
			 * that an owner cannot be removed is the server's and the screen
			 * should not be re-deriving it.
			 */
			$members = array();

			foreach ( $this->organizations->user_ids_for_org( $org_id ) as $member_id ) {
				$member = $this->users->by_id( $member_id );

				if ( null === $member ) {
					continue;
				}

				$members[] = array(
					'id'       => $member_id,
					'name'     => (string) $member->display_name,
					'email'    => (string) $member->user_email,
					'is_owner' => $member_id === $owner_id,
				);
			}

			$rows[] = array(
				'id'          => $org_id,
				'name'        => $this->organizations->name( $org_id ),
				'state'       => $state,
				'active'      => Org_Repository::STATE_ACTIVE === $state,
				'owner_id'    => $owner_id,
				'owner_name'  => null !== $owner ? (string) $owner->display_name : '',
				'member_list' => $members,
				'members'     => count( $members ),
				'campaigns'   => (int) ( $list['total'] ?? 0 ),
			);
		}

		return array( 'rows' => $rows );
	}
}
