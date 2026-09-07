<?php
/**
 * Whether an actor is working on someone else's behalf.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * The on-behalf rule.
 *
 * Decides how an edit is *recorded*, which is why getting it wrong is quiet:
 * audit timelines answer "who changed this", and a mislabelled edit makes that
 * answer misleading rather than absent.
 */
final class On_Behalf {

	/**
	 * Whether this actor is acting for an organization they do not belong to.
	 *
	 * Membership decides, not capability: a staff member who belongs to the
	 * owning organization is editing their own work, and recording that as
	 * on-behalf reads as though an outsider reached in.
	 *
	 * @param bool            $is_staff       Whether the actor holds the review capability.
	 * @param int             $org_id         The organization owning the object.
	 * @param array<int, int> $member_org_ids Organizations the actor belongs to.
	 * @return bool
	 */
	public static function applies( bool $is_staff, int $org_id, array $member_org_ids ): bool {
		if ( ! $is_staff || $org_id <= 0 ) {
			return false;
		}

		return ! in_array( $org_id, $member_org_ids, true );
	}
}
