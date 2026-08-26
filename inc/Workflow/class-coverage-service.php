<?php
/**
 * Whether every required placement has a creative that can actually run.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Placement_Repository;

/**
 * One definition of "eligible", so P3 does not have to invent a second.
 *
 * The contract is explicit that P3's decision engine reuses these definitions
 * rather than creating its own, which is the whole reason this is a service and
 * not a condition inside the validator. Two answers to "may this creative run"
 * is how a campaign passes review and then serves nothing.
 *
 * **Classification and threshold are separate on purpose.** `classify()` says
 * what state an assignment is in; `covers_for_submission()` says which of those
 * states are good enough to submit a campaign. P3 will add its own threshold
 * over the same states — a delivery decision cares about the window and the
 * approval, a submission does not.
 *
 * That split is what keeps this from breaking submission. Requiring an
 * *approved* assignment to submit would be circular: approval happens after
 * review, review happens after submission, so nothing could ever be sent. The
 * contract's "eligible approved assignment" describes the state a campaign
 * reaches on its way to serving, not the gate on the way in.
 */
final class Coverage_Service {

	/** The assignment names a revision that no longer exists. */
	public const STATE_MISSING_REVISION = 'missing_revision';

	/** A newer revision supersedes this one, so it is not the current artwork. */
	public const STATE_SUPERSEDED = 'superseded';

	/** The revision's dimensions do not fit the placement. */
	public const STATE_WRONG_SIZE = 'wrong_size';

	/** The revision is not an advertiser image creative. */
	public const STATE_WRONG_KIND = 'wrong_kind';

	/** The assignment belongs to a different campaign than the one asked about. */
	public const STATE_WRONG_CAMPAIGN = 'wrong_campaign';

	/** Usable: the current artwork, correctly sized, on the right campaign. */
	public const STATE_USABLE = 'usable';

	/**
	 * Builds the service.
	 *
	 * @param Assigned_Creatives             $assigned    Healing assignment reader.
	 * @param Creative_Assignment_Repository $assignments Assignment persistence.
	 * @param Creative_Repository            $creatives   Creative persistence.
	 * @param Placement_Repository           $placements  Placement persistence.
	 */
	public function __construct(
		private readonly Assigned_Creatives $assigned,
		private readonly Creative_Assignment_Repository $assignments,
		private readonly Creative_Repository $creatives,
		private readonly Placement_Repository $placements
	) {
	}

	/**
	 * Every assignment on a campaign, classified.
	 *
	 * Read through the healing reader rather than the repository directly. A
	 * campaign the backfill has not reached yet has no assignments, and asking
	 * the table straight would report it as covering nothing — an advertiser
	 * would be told their artwork is missing during an upgrade they cannot see.
	 *
	 * The creative details are returned alongside the state because `classify()`
	 * has already loaded them. A caller that needs both — the validator does,
	 * for its per-creative messages — would otherwise read every creative twice
	 * and, worse, could read a different one than the state was computed from.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, array{revision_id: int, placement_id: int, state: string, creative: array<string, mixed>|null}>
	 */
	public function assess( int $campaign_id ): array {
		if ( $campaign_id <= 0 ) {
			return array();
		}

		// Heals first, so the rows below exist for a campaign mid-migration.
		$this->assigned->revision_ids( $campaign_id );

		$assessed = array();

		foreach ( $this->assignments->for_campaign( $campaign_id ) as $row ) {
			$revision_id = (int) ( $row['revision_id'] ?? 0 );
			$details     = $this->creatives->details( $revision_id );

			$assessed[] = array(
				'revision_id'  => $revision_id,
				'placement_id' => (int) ( $row['placement_id'] ?? 0 ),
				'state'        => $this->classify( $row, $campaign_id, $details ),
				'creative'     => $details,
			);
		}

		return $assessed;
	}

	/**
	 * Placement ids a campaign currently covers well enough to submit.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, int>
	 */
	public function covered_placements( int $campaign_id ): array {
		$covered = array();

		foreach ( $this->assess( $campaign_id ) as $entry ) {
			if ( self::covers_for_submission( $entry['state'] ) && ! in_array( $entry['placement_id'], $covered, true ) ) {
				$covered[] = $entry['placement_id'];
			}
		}

		return $covered;
	}

	/**
	 * Which states count as *present* on a placement.
	 *
	 * Deliberately looser than `usable`, and the difference is worth being
	 * exact about because getting it wrong changes what an advertiser is told.
	 *
	 * A wrongly sized or non-image creative is still attached to its placement:
	 * the campaign reports "this creative is the wrong size", not "this
	 * placement has no creative". Telling somebody both is telling them the
	 * same problem twice and sending them to fix the wrong one.
	 *
	 * A superseded or deleted revision is genuinely absent — the old validator
	 * never saw them either, because it read only active creatives — so those
	 * do leave a placement uncovered.
	 *
	 * P3's threshold is `STATE_USABLE` and is stricter than this on purpose: a
	 * wrongly sized creative may be submitted with an error against it, and may
	 * never be served.
	 *
	 * @param string $state One of the STATE_* constants.
	 * @return bool
	 */
	public static function covers_for_submission( string $state ): bool {
		return ! in_array(
			$state,
			array( self::STATE_SUPERSEDED, self::STATE_MISSING_REVISION, self::STATE_WRONG_CAMPAIGN ),
			true
		);
	}

	/**
	 * What state one assignment is in.
	 *
	 * Ordered most-specific first, so the reported reason is the one a person
	 * can act on: "this artwork was replaced" is more useful than "wrong size"
	 * for a superseded revision that also happens not to fit.
	 *
	 * @param array<string, mixed>      $row         Assignment row.
	 * @param int                       $campaign_id Campaign being asked about.
	 * @param array<string, mixed>|null $details     Already-loaded creative details.
	 * @return string
	 */
	private function classify( array $row, int $campaign_id, ?array $details ): string {
		if ( (int) ( $row['campaign_id'] ?? 0 ) !== $campaign_id ) {
			return self::STATE_WRONG_CAMPAIGN;
		}

		$revision_id = (int) ( $row['revision_id'] ?? 0 );

		if ( null === $details ) {
			return self::STATE_MISSING_REVISION;
		}

		/*
		 * A superseded revision is not the current artwork.
		 *
		 * Asked of the creative rather than the assignment because the
		 * replacement flow supersedes revisions without touching assignments —
		 * `Revision_Policy` repoints the compatibility row, but a pending
		 * replacement deliberately does not, since the old one is still what
		 * serves.
		 */
		if ( ! $this->creatives->is_active( $revision_id ) ) {
			return self::STATE_SUPERSEDED;
		}

		if ( Campaign_Rules::ADVERTISER_CREATIVE_KIND !== (string) ( $details['kind'] ?? '' ) ) {
			return self::STATE_WRONG_KIND;
		}

		$placement_id = (int) ( $row['placement_id'] ?? 0 );
		$expected     = $this->placements->size( $placement_id );

		if (
			'' !== $expected
			&& ! Campaign_Rules::size_matches( (int) $details['width'], (int) $details['height'], $expected )
		) {
			return self::STATE_WRONG_SIZE;
		}

		return self::STATE_USABLE;
	}
}
