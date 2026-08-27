<?php
/**
 * Whether every required placement has a creative that can actually run.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Placement_Repository;

/**
 * One definition of "eligible", so P3 does not invent a second.
 *
 * `classify()` names the state; the thresholds decide what each state is good
 * enough for. P3 adds its own threshold over the same states rather than its own
 * states — two answers to "may this creative run" is how a campaign passes
 * review and then serves nothing.
 *
 * Submission cannot require an *approved* assignment: approval follows review,
 * review follows submission.
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

	/** Withdrawn or finished: the row is history, not delivery. */
	public const STATE_RETIRED = 'retired';

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
	 * Read through the healing reader: a campaign the backfill has not reached
	 * has no assignments, and would otherwise report as covering nothing.
	 *
	 * Details come back alongside the state because `classify()` has already
	 * loaded them, and re-reading risks judging a different row than was
	 * classified.
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
	 * Looser than `usable`: a wrong-size or non-image creative is still attached,
	 * so the campaign reports "wrong size" rather than "no creative" — reporting
	 * both points at the wrong fix. Superseded, deleted and retired assignments
	 * are absent. P3's delivery threshold is `STATE_USABLE`.
	 *
	 * @param string $state One of the STATE_* constants.
	 * @return bool
	 */
	public static function covers_for_submission( string $state ): bool {
		return ! in_array(
			$state,
			array(
				self::STATE_SUPERSEDED,
				self::STATE_MISSING_REVISION,
				self::STATE_WRONG_CAMPAIGN,
				self::STATE_RETIRED,
			),
			true
		);
	}

	/**
	 * What state one assignment is in.
	 *
	 * Most-specific first, so the reported reason is the one a person can act on.
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

		/*
		 * A withdrawn assignment covers nothing, whatever its artwork is like.
		 * Checked before the revision, so unassigning the last creative on a
		 * placement reports that placement uncovered rather than reporting the
		 * artwork fine.
		 */
		if ( in_array( (string) ( $row['status'] ?? '' ), array( Assignment_Rules::CANCELLED, Assignment_Rules::COMPLETED ), true ) ) {
			return self::STATE_RETIRED;
		}

		$revision_id = (int) ( $row['revision_id'] ?? 0 );

		if ( null === $details ) {
			return self::STATE_MISSING_REVISION;
		}

		// Asked of the creative, not the assignment: a pending replacement
		// supersedes a revision without repointing the row, because the old one
		// is still what serves.
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
