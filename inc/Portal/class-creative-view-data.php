<?php
/**
 * Advertiser-facing creative rows for the portal campaign screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\REST\Creative_File_Controller;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Creative_Revision_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Workflow\Assigned_Creatives;
use Aggressive\Ads\Workflow\Creative_Approval;

/**
 * Keeps creative shaping out of the multi-screen `View_Data` coordinator, the
 * way `Catalogue_View_Data` already keeps the catalogue out of it.
 *
 * One job: turning a campaign's creatives, its staged replacements and its
 * empty slots into rows a template can render. `View_Data` asks for the three
 * lists and does not need to know that a preview URL depends on whether the
 * artwork was ever approved, or that a slot is "empty" only relative to what
 * the placement expects.
 */
final class Creative_View_Data {

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository          $campaigns  Campaign persistence.
	 * @param Creative_Repository          $creatives  Creative persistence.
	 * @param Creative_Revision_Repository $revisions  Replacement lifecycle persistence.
	 * @param Placement_Repository         $placements Placement persistence.
	 * @param Assigned_Creatives           $assigned   What is assigned where.
	 * @param Creative_Approval            $approvals  Creative review decisions.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Creative_Revision_Repository $revisions,
		private readonly Placement_Repository $placements,
		private readonly Assigned_Creatives $assigned,
		private readonly Creative_Approval $approvals
	) {
	}

	/**
	 * Where to load a creative's image from.
	 *
	 * Approval promotes the artwork into the Media Library and deletes the
	 * private original, so the authenticated file route answers 404 for
	 * everything approved — correctly, because there is nothing left to stream.
	 * Pointing at it regardless meant every approved creative on the campaign
	 * screen rendered as a broken image.
	 *
	 * The attachment URL is public, which is not a leak: it is the same file
	 * the ad is already serving to every visitor. Unapproved artwork has no
	 * attachment and keeps the authenticated route.
	 *
	 * @param int $creative_id Creative post id.
	 * @return string
	 */
	private function creative_preview( int $creative_id ): string {
		$promoted = $this->creatives->attachment_url( $creative_id );

		if ( '' !== $promoted ) {
			return $promoted;
		}

		return add_query_arg(
			'_wpnonce',
			wp_create_nonce( 'wp_rest' ),
			rest_url( Creative_File_Controller::NAMESPACE . '/creatives/' . $creative_id . '/file' )
		);
	}

	/**
	 * The campaign's creatives, shaped for display.
	 *
	 * No file path, no storage token and no checksum: those describe where the
	 * bytes live on disk, and a private-storage path is not something a browser
	 * ever needs to be told.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, array{id: int, placement_id: int, placement: string, size: string, dimensions: string, click_url: string, alt_text: string, approved: bool, rejected: bool, state_text: string, notes: string, name: string, bytes: int, preview: string}>
	 */
	public function creative_rows( int $campaign_id ): array {
		$rows = array();

		/*
		 * Structure from the assignment table, values from the revision.
		 *
		 * The assignment answers which revisions are assigned to this campaign;
		 * `details()` answers what each one contains. The assignment's own
		 * `click_url` and `alt_text` are deliberately not read here — see
		 * `Assigned_Creatives` for why a snapshot is right for serving and
		 * wrong for an editing screen.
		 */
		foreach ( $this->assigned->revision_ids( $campaign_id ) as $revision_id ) {
			$creative = $this->creatives->details( $revision_id );

			if ( null === $creative || ! $this->creatives->is_active( $revision_id ) ) {
				continue;
			}

			$stored   = $this->creatives->storage_details( $creative['id'] );
			$approved = $this->creatives->has_attachment( $creative['id'] );
			$rejected = $this->creatives->is_rejected( $creative['id'] );

			$rows[] = array(
				'id'           => $creative['id'],
				'placement_id' => $creative['placement_id'],
				'placement'    => $this->placements->name( $creative['placement_id'] ),
				'size'         => $creative['size'],
				'dimensions'   => $creative['width'] > 0 && $creative['height'] > 0
					? $creative['width'] . '×' . $creative['height']
					: '',
				'click_url'    => $creative['click_url'],
				'alt_text'     => $creative['alt_text'],
				'approved'     => $approved,
				'rejected'     => $rejected,
				'state_text'   => $this->creative_state_text( $approved, $rejected ),

				// Empty unless this creative was turned down. The decision owns
				// the reason; the shared meta key carries two of them.
				'notes'        => $this->approvals->rejection_notes( $creative['id'] ),
				'name'         => null === $stored ? '' : $stored['name'],
				'bytes'        => null === $stored ? 0 : $stored['bytes'],
				'preview'      => $this->creative_preview( $creative['id'] ),
			);
		}

		return $rows;
	}

	/**
	 * What the advertiser is told about one creative's review state.
	 *
	 * Three states, and before this there was one. A creative that had been
	 * turned down looked exactly like one that had been approved: same card,
	 * same preview, same Update action, and nothing anywhere saying it would
	 * never be served. Staff are required to give a reason for exactly that
	 * person, and it was stored and shown to nobody.
	 *
	 * `has_attachment()` is the approved question rather than
	 * `META_REVIEW_STATE`, for the reason recorded in open-work.md: promotion
	 * does not maintain that meta, so a creative serving for weeks still reads
	 * `pending` there.
	 *
	 * @param bool $approved Whether promotion has produced a public attachment.
	 * @param bool $rejected Whether a reviewer turned it down.
	 * @return string
	 */
	private function creative_state_text( bool $approved, bool $rejected ): string {
		if ( $rejected ) {
			return __( 'Not approved', 'aggressive-ads' );
		}

		return $approved
			? __( 'Running', 'aggressive-ads' )
			: __( 'Waiting for review', 'aggressive-ads' );
	}

	/**
	 * Published-ad revisions visible to the owning advertiser.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<int, array<string, mixed>>
	 */
	public function creative_update_rows( int $campaign_id ): array {
		$rows = array();

		foreach ( $this->revisions->replacements_for_campaign( $campaign_id ) as $creative ) {
			$state = $this->revisions->change_state( $creative['id'] );

			if ( ! in_array( $state, array( Creative_Repository::CHANGE_PENDING, Creative_Repository::CHANGE_REJECTED ), true ) ) {
				continue;
			}

			$rows[] = array(
				'id'           => $creative['id'],
				'creative_id'  => $this->creatives->replacement_target_id( $creative['id'] ),
				'placement_id' => $creative['placement_id'],
				'placement'    => $this->placements->name( $creative['placement_id'] ),
				'dimensions'   => $creative['width'] . '×' . $creative['height'],
				'click_url'    => $creative['click_url'],
				'alt_text'     => $creative['alt_text'],
				'state'        => $state,
				'state_text'   => Creative_Repository::CHANGE_PENDING === $state
					? __( 'Waiting for review', 'aggressive-ads' )
					: __( 'Changes needed', 'aggressive-ads' ),
				'notes'        => $this->creatives->change_notes( $creative['id'] ),
				'requested_at' => $this->revisions->requested_at( $creative['id'] ),
				'preview'      => add_query_arg(
					'_wpnonce',
					wp_create_nonce( 'wp_rest' ),
					rest_url( Creative_File_Controller::NAMESPACE . '/creatives/' . $creative['id'] . '/file' )
				),
			);
		}

		return $rows;
	}

	/**
	 * Selected placements paired with any creative already covering them.
	 *
	 * @param int                              $campaign_id Campaign post id.
	 * @param array<int, array<string, mixed>> $creatives   Render-ready creative rows.
	 * @return array<int, array{id: int, name: string, size: string, active: bool, creatives: array<int, array<string, mixed>>}>
	 */
	public function creative_slots( int $campaign_id, array $creatives ): array {
		$slots = array();

		foreach ( $this->campaigns->placement_ids( $campaign_id ) as $placement_id ) {
			$matching = array();

			foreach ( $creatives as $creative ) {
				if ( (int) ( $creative['placement_id'] ?? 0 ) === $placement_id ) {
					$matching[] = $creative;
				}
			}

			$slots[] = array(
				'id'        => $placement_id,
				'name'      => $this->placements->name( $placement_id ),
				'size'      => $this->placements->size( $placement_id ),
				'active'    => $this->placements->is_active( $placement_id ),
				'creatives' => $matching,
			);
		}

		return $slots;
	}
}
