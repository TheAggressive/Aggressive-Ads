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
 * Extracted for the same reason as `Acting_Session`: it is a rule rather than
 * a mechanism, and rules belong in the layer that loads without WordPress so
 * they can be tested exhaustively instead of representatively.
 *
 * The rule decides how an edit is *recorded*, which is why getting it wrong is
 * quiet. Audit timelines are read to answer "who changed this", and an edit
 * mislabelled either way makes that answer misleading rather than absent.
 */
final class On_Behalf {

	/**
	 * Whether this actor is acting for an organization they do not belong to.
	 *
	 * Membership decides, not capability. A staff member who genuinely belongs
	 * to the owning organization is editing their own work, and recording that
	 * as on-behalf would make the timeline read as though an outsider had
	 * reached in.
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
