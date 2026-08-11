<?php
/**
 * Read model for placement delivery mappings.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Admin;

use LAAO_Advertiser_Portal\Integration\Adsanity\Placement_Mapping;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;
use WP_Error;

/**
 * Assembles provider-neutral rows for the staff mapping template.
 */
final class Placement_Mapping_Data {

	/**
	 * Constructor.
	 *
	 * @param Placement_Repository $placements Placement persistence.
	 * @param Placement_Mapping    $mapping    Provider group reader.
	 */
	public function __construct(
		private readonly Placement_Repository $placements,
		private readonly Placement_Mapping $mapping
	) {
	}

	/**
	 * Complete mapping-screen state.
	 *
	 * @return array{groups: array<int, array{id: int, name: string}>, rows: array<int, array{id: int, name: string, size: string, active: bool, term_id: int, group_name: string, state: string}>, provider_error: WP_Error|null}
	 */
	public function view(): array {
		$available = $this->mapping->available_groups();
		$error     = is_wp_error( $available ) ? $available : null;
		$groups    = is_array( $available ) ? $available : array();
		$names     = array();

		foreach ( $groups as $group ) {
			$names[ $group['id'] ] = $group['name'];
		}

		$rows = array();

		foreach ( $this->placements->all_ids() as $placement_id ) {
			$term_id = $this->placements->adgroup_term_id( $placement_id );

			if ( 0 === $term_id ) {
				$state = 'unmapped';
			} elseif ( $error instanceof WP_Error ) {
				$state = 'unavailable';
			} else {
				$state = isset( $names[ $term_id ] ) ? 'mapped' : 'dangling';
			}

			$rows[] = array(
				'id'         => $placement_id,
				'name'       => $this->placements->name( $placement_id ),
				'size'       => $this->placements->size( $placement_id ),
				'active'     => $this->placements->is_active( $placement_id ),
				'term_id'    => $term_id,
				'group_name' => $names[ $term_id ] ?? '',
				'state'      => $state,
			);
		}

		return array(
			'groups'         => $groups,
			'rows'           => $rows,
			'provider_error' => $error,
		);
	}
}
