<?php
/**
 * The organization screen's pending invitations and access requests.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Domain\Member_Name_Match;
use Aggressive\Ads\Repository\Org_Access_Repository;

/**
 * Out of `View_Data`, which holds the organization's members and had no room
 * for the reasoning that goes with each pending row.
 *
 * **What a row may say about people is decided here, once.** An owner deciding
 * on a request wants to know whether it is a colleague on another address; one
 * revoking an invitation wants to know who sent it. Both answers name people,
 * and a name that belongs to someone outside the organization must never reach
 * this screen — so the only names used are the viewer ("you") and members, and
 * anyone else, such as the site's staff, is "the site team".
 *
 * A name match is a *suggestion*. It is worded as one by the template and read
 * by nothing else: approving a request is still a person pressing a button.
 */
final class Pending_Access_View_Data {

	/**
	 * Who sent an invitation, when it was not the viewer or a member.
	 */
	public const INVITER_SITE_TEAM = 'site_team';

	/**
	 * Constructor.
	 *
	 * @param Org_Access_Repository $access Organization access persistence.
	 */
	public function __construct( private readonly Org_Access_Repository $access ) {
	}

	/**
	 * Pending rows for one organization, each with why it is there.
	 *
	 * @param int                                                                                   $org_id    Organization id.
	 * @param int                                                                                   $viewer_id The signed-in user.
	 * @param array<int, array{id: int, name: string, email: string, is_you: bool, is_owner: bool}> $members   The organization's members.
	 * @return array<int, array{id: int, email: string, kind: string, expires_at_ts: int, invited_by: string, invited_by_you: bool, name_match: string}>
	 */
	public function rows( int $org_id, int $viewer_id, array $members ): array {
		$names = array();

		foreach ( $members as $member ) {
			$names[ $member['id'] ] = $member['name'];
		}

		$rows = array();

		foreach ( $this->access->pending_for_org( $org_id ) as $row ) {
			$is_request = Org_Access_Repository::KIND_REQUEST === (string) ( $row['kind'] ?? '' );

			$rows[] = array(
				'id'             => (int) ( $row['id'] ?? 0 ),
				'email'          => (string) ( $row['email'] ?? '' ),
				'kind'           => (string) ( $row['kind'] ?? '' ),
				'expires_at_ts'  => (int) ( $row['expires_at_ts'] ?? 0 ),
				'invited_by'     => $is_request ? '' : $this->inviter( (int) ( $row['created_by_user_id'] ?? 0 ), $viewer_id, $names ),
				'invited_by_you' => ! $is_request && $viewer_id > 0 && (int) ( $row['created_by_user_id'] ?? 0 ) === $viewer_id,
				'name_match'     => $is_request ? $this->match( (int) ( $row['request_user_id'] ?? 0 ), (string) ( $row['email'] ?? '' ), $names ) : '',
			);
		}

		return $rows;
	}

	/**
	 * The name to show for whoever sent an invitation.
	 *
	 * @param int                $creator_id The inviting user, 0 when none was recorded.
	 * @param int                $viewer_id  The signed-in user.
	 * @param array<int, string> $names      Member display names by user id.
	 * @return string A member's name, the site-team marker, or empty when unknown or the viewer.
	 */
	private function inviter( int $creator_id, int $viewer_id, array $names ): string {
		if ( $creator_id <= 0 || $creator_id === $viewer_id ) {
			return '';
		}

		return $names[ $creator_id ] ?? self::INVITER_SITE_TEAM;
	}

	/**
	 * The member a request looks like, by name.
	 *
	 * @param int                $user_id The requesting user.
	 * @param string             $email   The address on the request.
	 * @param array<int, string> $names   Member display names by user id.
	 * @return string The member's name, or empty.
	 */
	private function match( int $user_id, string $email, array $names ): string {
		$requester = $user_id > 0 ? get_userdata( $user_id ) : false;

		// Someone who is already a member is not asking to join.
		if ( false === $requester || isset( $names[ (int) $requester->ID ] ) ) {
			return '';
		}

		return Member_Name_Match::find( (string) $requester->display_name, $email, array_values( $names ) ) ?? '';
	}
}
