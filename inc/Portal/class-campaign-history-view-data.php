<?php
/**
 * A campaign's own history, as its advertiser may read it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Domain\Advertiser_Events;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Security\Roles;

/**
 * The audit trail, read back to the person whose campaign it is.
 *
 * `Domain\Advertiser_Events` decides what may be shown; this says it in the
 * reader's language and dates it. The split is deliberate: the decision about
 * disclosure is testable without WordPress, and the wording can change
 * without anybody revisiting what is safe.
 *
 * **Scoped by organization, not by campaign alone.** `for_object()` takes the
 * org id and every row carries one, so a campaign id from another tenant
 * returns nothing rather than somebody else's history.
 *
 * **Who did it, never who they are.** A row names a user; an advertiser is
 * told "You", "Your team" or "The review team". A reviewer's name is the
 * publisher's business, and naming them invites the advertiser to take a
 * decision up with a person rather than with the publisher.
 */
final class Campaign_History_View_Data {

	/**
	 * Entries kept for the screen.
	 *
	 * Enough to cover a campaign's life — created, submitted, reviewed,
	 * approved, scheduled, live, plus the uploads along the way — without
	 * turning a status page into a log file.
	 */
	public const SHOWN = 20;

	/**
	 * The role `Audit_Repository` records when nobody did it.
	 */
	private const SYSTEM_ROLE = 'system';

	/**
	 * Rows read before filtering.
	 *
	 * Filtering happens after the read, so the read has to be wider than the
	 * screen: a campaign whose last twenty rows are all notification retries
	 * would otherwise show an empty activity list.
	 */
	private const SCANNED = 100;

	/**
	 * Constructor.
	 *
	 * @param Audit_Repository $audit The append-only trail.
	 */
	public function __construct( private readonly Audit_Repository $audit ) {
	}

	/**
	 * What has happened to a campaign, newest first, and when each stage began.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $org_id      The organization that owns it.
	 * @return array{items: array<int, array{code: string, text: string, who: string, at: int}>, stages: array<string, int>}
	 */
	public function history( int $campaign_id, int $org_id ): array {
		$items  = array();
		$stages = array();

		if ( $campaign_id <= 0 || $org_id <= 0 ) {
			return array(
				'items'  => $items,
				'stages' => $stages,
			);
		}

		$rows    = $this->audit->for_object( 'campaign', $campaign_id, $org_id, self::SCANNED );
		$strings = $this->strings();

		foreach ( $rows as $row ) {
			$code = Advertiser_Events::code(
				(string) $row['event'],
				(string) $row['outcome'],
				(string) $row['from_state'],
				(string) $row['to_state']
			);

			if ( '' === $code || ! isset( $strings[ $code ] ) ) {
				continue;
			}

			$at    = (int) $row['created_at_ts'];
			$stage = 'campaign.transitioned' === (string) $row['event']
				? Advertiser_Events::stage( (string) $row['to_state'] )
				: '';

			/*
			 * Rows arrive newest first, so a later pass over the same stage is
			 * an earlier one: a campaign sent back and resubmitted should date
			 * "Submitted" from the first time, which is what the timeline of a
			 * campaign's life means.
			 */
			if ( '' !== $stage && $at > 0 ) {
				$stages[ $stage ] = $at;
			}

			if ( count( $items ) < self::SHOWN ) {
				$items[] = array(
					'code' => $code,
					'text' => $strings[ $code ],
					'who'  => $this->who( (int) $row['actor_user_id'], (string) $row['actor_role'] ),
					'at'   => $at,
				);
			}
		}

		return array(
			'items'  => $items,
			'stages' => $stages,
		);
	}

	/**
	 * What each code says on the screen.
	 *
	 * Written as things that happened to the campaign rather than as commands
	 * somebody executed, because that is how the person reading thinks of it.
	 *
	 * @return array<string, string>
	 */
	private function strings(): array {
		return array(
			Advertiser_Events::CAMPAIGN_MADE => __( 'Campaign created', 'aggressive-ads' ),
			Advertiser_Events::SUBMITTED     => __( 'Submitted for review', 'aggressive-ads' ),
			Advertiser_Events::IN_REVIEW     => __( 'Review started', 'aggressive-ads' ),
			Advertiser_Events::CHANGES       => __( 'Changes requested', 'aggressive-ads' ),
			Advertiser_Events::WITHDRAWN     => __( 'Withdrawn for editing', 'aggressive-ads' ),
			Advertiser_Events::APPROVED      => __( 'Approved', 'aggressive-ads' ),
			Advertiser_Events::REJECTED      => __( 'Not approved', 'aggressive-ads' ),
			Advertiser_Events::SCHEDULED     => __( 'Scheduled to start', 'aggressive-ads' ),
			Advertiser_Events::LIVE          => __( 'Started running', 'aggressive-ads' ),
			Advertiser_Events::PAUSED        => __( 'Paused', 'aggressive-ads' ),
			Advertiser_Events::RESUMED       => __( 'Running again', 'aggressive-ads' ),
			Advertiser_Events::COMPLETE      => __( 'Finished', 'aggressive-ads' ),
			Advertiser_Events::CANCELLED     => __( 'Cancelled', 'aggressive-ads' ),
			Advertiser_Events::AD_UPLOADED   => __( 'An ad was uploaded', 'aggressive-ads' ),
			Advertiser_Events::AD_REPLACED   => __( 'An ad was replaced', 'aggressive-ads' ),
			Advertiser_Events::AD_REMOVED    => __( 'An ad was removed', 'aggressive-ads' ),
			Advertiser_Events::LINK_CHANGED  => __( 'A destination link changed', 'aggressive-ads' ),
		);
	}

	/**
	 * Who did it, in the only three terms an advertiser needs.
	 *
	 * @param int    $user_id Actor recorded on the row.
	 * @param string $role    Role recorded on the row.
	 * @return string
	 */
	private function who( int $user_id, string $role ): string {
		/*
		 * The clock, not a person. `Audit_Repository` records "system" for
		 * user zero, and reading that as a role would have told advertisers
		 * the review team started their campaign on its start date.
		 */
		if ( 0 === $user_id || self::SYSTEM_ROLE === $role ) {
			return __( 'Automatically', 'aggressive-ads' );
		}

		if ( get_current_user_id() === $user_id ) {
			return __( 'You', 'aggressive-ads' );
		}

		/*
		 * Anyone who is not the site's own staff is in the organization these
		 * rows belong to, because the read is scoped to it.
		 */
		return '' !== $role && Roles::ADVERTISER !== $role
			? __( 'The review team', 'aggressive-ads' )
			: __( 'Your team', 'aggressive-ads' );
	}
}
