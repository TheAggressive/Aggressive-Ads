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

	/** Rows per page when the client does not say. */
	public const DEFAULT_PER_PAGE = 25;

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
	 * One page of organization rows.
	 *
	 * Rows carry a member *count*, not the roster. The roster is a list of
	 * people with their email addresses, and shipping every organization's
	 * roster to render a table that shows none of them put the whole directory
	 * into an HTML attribute on every page load. It is fetched per organization
	 * by `organization()`, when a staff member actually opens one.
	 *
	 * @param int    $page     One-based page number.
	 * @param int    $per_page Rows per page.
	 * @param string $search   Free-text match against the organization name.
	 * @param string $state    Org_Repository::STATE_* or '' for all.
	 * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, perPage: int}
	 */
	public function view( int $page = 1, int $per_page = self::DEFAULT_PER_PAGE, string $search = '', string $state = '' ): array {
		$found = $this->organizations->page( $page, $per_page, $search, $state );
		$rows  = array();

		foreach ( $found['ids'] as $org_id ) {
			$rows[] = $this->row( $org_id );
		}

		return array(
			'rows'    => $rows,
			'total'   => $found['total'],
			'page'    => max( 1, $page ),
			'perPage' => $per_page,
		);
	}

	/**
	 * One organization, with the roster staff act on.
	 *
	 * @param int $org_id Organization post id.
	 * @return array<string, mixed>|null Null when no such organization exists.
	 */
	public function organization( int $org_id ): ?array {
		if ( ! $this->organizations->exists( $org_id ) ) {
			return null;
		}

		$row      = $this->row( $org_id );
		$owner_id = (int) $row['owner_id'];

		/*
		 * The roster is listed, not counted.
		 *
		 * Staff need to act on a specific person — transfer ownership to them,
		 * or remove them — and a count identifies nobody. The owner is marked
		 * here rather than compared in the browser, because the rule that an
		 * owner cannot be removed is the server's and the screen should not be
		 * re-deriving it.
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

		$row['member_list'] = $members;

		return $row;
	}

	/**
	 * The list-level fields for one organization.
	 *
	 * @param int $org_id Organization post id.
	 * @return array<string, mixed>
	 */
	private function row( int $org_id ): array {
		$owner_id = $this->organizations->owner_user_id( $org_id );
		$owner    = $owner_id > 0 ? $this->users->by_id( $owner_id ) : null;
		$list     = $this->campaigns->for_org( $org_id, 1 );
		$state    = $this->organizations->state( $org_id );

		return array(
			'id'         => $org_id,
			'name'       => $this->organizations->name( $org_id ),
			'state'      => $state,
			'active'     => Org_Repository::STATE_ACTIVE === $state,
			'owner_id'   => $owner_id,
			'owner_name' => null !== $owner ? (string) $owner->display_name : '',
			'members'    => count( $this->organizations->user_ids_for_org( $org_id ) ),
			'campaigns'  => (int) ( $list['total'] ?? 0 ),
		);
	}
}
