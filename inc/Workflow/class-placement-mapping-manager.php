<?php
/**
 * Authorized placement-to-provider mapping writes.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Workflow;

use LAAO_Advertiser_Portal\Audit\Audit_Event;
use LAAO_Advertiser_Portal\Integration\Adsanity\Placement_Mapping;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;
use LAAO_Advertiser_Portal\Security\Capabilities;
use WP_Error;

/**
 * Validates, persists, verifies, and audits one mapping change.
 */
final class Placement_Mapping_Manager {

	/**
	 * Constructor.
	 *
	 * @param Placement_Repository $placements Placement persistence.
	 * @param Placement_Mapping    $mapping    Provider group resolution.
	 * @param Audit_Repository     $audit      Audit persistence.
	 */
	public function __construct(
		private readonly Placement_Repository $placements,
		private readonly Placement_Mapping $mapping,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Changes one placement's provider group.
	 *
	 * @param int $placement_id Placement post id.
	 * @param int $term_id      Provider term id, or zero to clear.
	 * @return true|WP_Error
	 */
	public function update( int $placement_id, int $term_id ) {
		if ( ! current_user_can( Capabilities::MANAGE_PLACEMENTS ) || ! current_user_can( 'edit_laao_ads_placement', $placement_id ) ) {
			$this->record( $placement_id, Audit_Event::OUTCOME_DENIED, 'Placement mapping change denied.' );

			return new WP_Error(
				'laao_ads_forbidden',
				__( 'You do not have permission to manage placement mappings.', 'laao-advertiser-portal' )
			);
		}

		if ( ! $this->placements->exists( $placement_id ) ) {
			return new WP_Error(
				'laao_ads_placement_not_found',
				__( 'That placement could not be found.', 'laao-advertiser-portal' )
			);
		}

		$groups = $this->mapping->available_groups();

		if ( is_wp_error( $groups ) ) {
			return $groups;
		}

		$valid_ids = array_column( $groups, 'id' );

		if ( $term_id < 0 || ( $term_id > 0 && ! in_array( $term_id, $valid_ids, true ) ) ) {
			return new WP_Error(
				'laao_ads_invalid_adgroup',
				__( 'Choose an existing AdSanity ad group.', 'laao-advertiser-portal' )
			);
		}

		$previous = $this->placements->adgroup_term_id( $placement_id );

		if ( $previous === $term_id ) {
			return true;
		}

		if ( ! $this->placements->set_adgroup_term_id( $placement_id, $term_id ) ) {
			$this->record( $placement_id, Audit_Event::OUTCOME_FAILED, 'Placement mapping write failed.' );

			return new WP_Error(
				'laao_ads_mapping_not_saved',
				__( 'The placement mapping could not be saved. No approval behavior changed.', 'laao-advertiser-portal' )
			);
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'placement.mapping_updated',
				object_type: 'placement',
				object_id: $placement_id,
				message: 0 === $term_id ? 'Placement mapping cleared.' : 'Placement mapping updated.',
				context: array(
					'previous_term_id' => $previous,
					'term_id'          => $term_id,
				),
				actor_user_id: get_current_user_id()
			)
		);

		return true;
	}

	/**
	 * Records a denied or failed change without request payloads.
	 *
	 * @param int    $placement_id Placement post id.
	 * @param string $outcome      Audit outcome.
	 * @param string $message      Fixed summary.
	 * @return void
	 */
	private function record( int $placement_id, string $outcome, string $message ): void {
		$this->audit->insert(
			new Audit_Event(
				event: 'placement.mapping_update_failed',
				outcome: $outcome,
				object_type: 'placement',
				object_id: max( 0, $placement_id ),
				message: $message,
				actor_user_id: get_current_user_id()
			)
		);
	}
}
