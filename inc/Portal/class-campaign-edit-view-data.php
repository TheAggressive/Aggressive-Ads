<?php
/**
 * What the running-campaign edit flow renders.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Campaign_Request_Repository;
use Aggressive\Ads\Workflow\Campaign_Change_Manager;
use Aggressive\Ads\Workflow\Link_Checker;
use Aggressive\Ads\Workflow\Live_Link_Change;
use Aggressive\Ads\Workflow\Live_Package_Change;

/**
 * The edit flow's rows, built beside `View_Data` rather than inside it.
 *
 * The edit flow draws creation's own cards — the package grid, the schedule,
 * the Destination card, the size cards — so it needs what creation's steps
 * read, taken from the proposal where one is staged and from the campaign
 * where not. `View_Data` had reached twenty dependencies; this is the part of
 * it that only the edit flow reads.
 */
final class Campaign_Edit_View_Data {

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository         $campaigns      Campaign persistence.
	 * @param Campaign_Request_Repository $requests       Staged changes.
	 * @param Campaign_Change_Manager     $changes        What a change may choose.
	 * @param Live_Package_Change         $package_change What a package sells.
	 * @param Live_Link_Change            $links          The link every ad goes to.
	 * @param Link_Checker                $checker        Link check results.
	 * @param Creative_View_Data          $creative_view  Size cards.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Campaign_Request_Repository $requests,
		private readonly Campaign_Change_Manager $changes,
		private readonly Live_Package_Change $package_change,
		private readonly Live_Link_Change $links,
		private readonly Link_Checker $checker,
		private readonly Creative_View_Data $creative_view
	) {
	}

	/**
	 * Adds the edit flow's keys to a campaign row.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $row         The row `View_Data` built.
	 * @return array<string, mixed>
	 */
	public function add( int $campaign_id, array $row ): array {
		$staged = $this->requests->pending_edits( $campaign_id );

		/*
		 * Values the edit screen shows: the campaign, overlaid with whatever
		 * the advertiser has staged so far. Rendering the stored campaign
		 * instead would silently discard a half-finished proposal every time
		 * they moved between steps.
		 *
		 * The dates are also given as the date inputs read them. The staged
		 * proposal holds timestamps, and the schedule read `start_date` — which
		 * it never held — so stepping back showed the stored dates, and saving
		 * wrote them over the date change already staged.
		 */
		$values               = array_merge( $this->changes->current( $campaign_id ), $staged );
		$values['start_date'] = Date_Input::format( (int) ( $values['start_ts'] ?? 0 ) );
		$values['end_date']   = Date_Input::format( (int) ( $values['end_ts'] ?? 0 ) );
		$row['edit_values']   = $values;

		$package = (int) ( $values['package_id'] ?? 0 );

		/*
		 * What editing may choose from: the proposed package's placements and
		 * the campaign's own, the same list Live_Edit_Rules holds a change to.
		 * No package means the whole catalogue, as before.
		 */
		$choices                       = $this->changes->placement_choices( $campaign_id, $package > 0 ? $package : null );
		$row['edit_placement_options'] = array() === $choices
			? (array) ( $row['placement_options'] ?? array() )
			: array_values(
				array_filter(
					(array) ( $row['placement_options'] ?? array() ),
					static fn ( array $option ): bool => in_array( (int) $option['id'], $choices, true )
				)
			);

		list( $used, $total ) = $this->links->usage( $campaign_id );

		$row['edit_link']       = (string) ( $values['default_click_url'] ?? '' );
		$row['edit_link_used']  = $used;
		$row['edit_link_total'] = $total;
		$row['edit_link_check'] = $this->checker->last( $campaign_id, true );
		$row['edit_slots']      = $this->slots( $campaign_id, $staged, (array) ( $row['creatives'] ?? array() ) );

		return $row;
	}

	/**
	 * The size cards: every size the campaign runs, then any the proposal adds.
	 *
	 * A size the proposal adds is marked `proposed`. It cannot take an ad yet —
	 * the placement is not the campaign's until the change is approved — and
	 * the card says so rather than offering an upload that would be refused.
	 *
	 * @param int                              $campaign_id Campaign post id.
	 * @param array<string, mixed>             $staged      The proposal so far.
	 * @param array<int, array<string, mixed>> $creatives   Render-ready creative rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function slots( int $campaign_id, array $staged, array $creatives ): array {
		$running  = $this->campaigns->placement_ids( $campaign_id );
		$proposed = $running;

		if ( isset( $staged['placement_ids'] ) && is_array( $staged['placement_ids'] ) ) {
			$proposed = array_map( 'intval', $staged['placement_ids'] );
		} elseif ( isset( $staged['package_id'] ) ) {
			$proposed = $this->package_change->placements( (int) $staged['package_id'] );
		}

		$added = array_values( array_diff( $proposed, $running ) );
		$slots = array();

		foreach ( $this->creative_view->creative_slots( $campaign_id, $creatives, array_merge( $running, $added ) ) as $slot ) {
			$slot['proposed'] = in_array( (int) $slot['id'], $added, true );
			$slot['leaving']  = ! in_array( (int) $slot['id'], $proposed, true );
			$slots[]          = $slot;
		}

		return $slots;
	}
}
