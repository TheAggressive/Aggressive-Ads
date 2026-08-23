<?php
/**
 * Authorized line-item editing.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Security\Capabilities;
use WP_Error;

/** Owns authorization, validation, concurrency and audit for line items. */
final class Line_Item_Editor {

	/**
	 * Builds the line-item workflow.
	 *
	 * @param Line_Item_Repository $line_items Line-item persistence.
	 * @param Campaign_Repository  $campaigns  Campaign persistence.
	 * @param Line_Item_Validator  $validator  Input validation.
	 * @param Audit_Repository     $audit      Audit persistence.
	 * @param Edit_Window          $window     Campaign edit policy.
	 */
	public function __construct(
		private readonly Line_Item_Repository $line_items,
		private readonly Campaign_Repository $campaigns,
		private readonly Line_Item_Validator $validator,
		private readonly Audit_Repository $audit,
		private readonly Edit_Window $window
	) {
	}

	/**
	 * Ensures the compatibility line item exists.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function ensure_default( int $campaign_id ): array|WP_Error {
		$row = $this->line_items->ensure_default( $campaign_id );

		return null === $row
			? new WP_Error( 'aggr_line_item_not_created', __( 'The campaign delivery strategy could not be created.', 'aggressive-ads' ), array( 'status' => 500 ) )
			: $row;
	}

	/**
	 * Updates one campaign-scoped line item.
	 *
	 * @param int                  $campaign_id      Campaign id.
	 * @param int                  $line_item_id     Line-item id.
	 * @param array<string, mixed> $fields           Candidate values.
	 * @param int                  $expected_revision Last-seen revision.
	 * @return int|WP_Error New line-item revision.
	 */
	public function update( int $campaign_id, int $line_item_id, array $fields, int $expected_revision ): int|WP_Error {
		if (
			! current_user_can( Capabilities::SUBMIT_CAMPAIGN )
			|| ! $this->campaigns->exists( $campaign_id )
			|| ! current_user_can( 'edit_aggr_campaign', $campaign_id )
		) {
			return new WP_Error( 'aggr_not_found', __( 'Not found.', 'aggressive-ads' ), array( 'status' => 404 ) );
		}

		if ( ! $this->window->allows( $campaign_id ) ) {
			return new WP_Error( 'aggr_campaign_not_editable', __( 'This campaign cannot be changed right now.', 'aggressive-ads' ), array( 'status' => 409 ) );
		}

		$row = $this->line_items->ensure_default( $campaign_id );
		if ( null === $row || $line_item_id !== (int) $row['id'] ) {
			return new WP_Error( 'aggr_not_found', __( 'Not found.', 'aggressive-ads' ), array( 'status' => 404 ) );
		}

		if ( $expected_revision < 1 || $expected_revision !== (int) $row['revision'] ) {
			return new WP_Error(
				'aggr_line_item_conflict',
				__( 'This line item changed in another window. Reload it before saving again.', 'aggressive-ads' ),
				array(
					'status'           => 409,
					'current_revision' => (int) $row['revision'],
				)
			);
		}

		$clean = $this->validator->validate( $fields, $row );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$revision = $this->line_items->update( $line_item_id, $campaign_id, $clean, $expected_revision );
		if ( false === $revision ) {
			$current = $this->line_items->default_for_campaign( $campaign_id );
			return new WP_Error(
				'aggr_line_item_conflict',
				__( 'This line item changed in another window. Reload it before saving again.', 'aggressive-ads' ),
				array(
					'status'           => 409,
					'current_revision' => (int) ( $current['revision'] ?? 0 ),
				)
			);
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'line_item.updated',
				object_type: 'line_item',
				object_id: $line_item_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: 'Campaign line item updated.',
				context: array(
					'campaign_id' => $campaign_id,
					'fields'      => array_keys( $clean ),
					'revision'    => $revision,
				),
				actor_user_id: get_current_user_id()
			)
		);

		return $revision;
	}
}
