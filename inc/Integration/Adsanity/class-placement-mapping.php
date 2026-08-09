<?php
/**
 * Placement to ad-group resolution.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Integration\Adsanity;

use LAAO_Advertiser_Portal\Repository\Placement_Repository;
use WP_Error;

/**
 * Turns the placements on a campaign into the ad groups its ads publish into.
 *
 * **Fails closed, and before anything is written.** Approval resolves every
 * mapping first; if any placement is unmapped or points at a term that no
 * longer exists, nothing is created, no status changes, and the reviewer is
 * told which placement to fix. The alternative — discovering the problem
 * halfway through publishing — leaves some ads live and the campaign in a
 * state nobody designed.
 *
 * Mapping is keyed on **term id, never on term name**. Names are editorial
 * text a staff member can change in the taxonomy admin without knowing
 * anything depends on them, and the live taxonomy already contains a term
 * whose name uses U+00D7 rather than the letter `x` — so name matching works
 * for four placements out of five and fails on the fifth in a way that reads
 * as a typo. See docs/adr/0007-placement-mapping-is-explicit-data.md.
 */
final class Placement_Mapping {

	/**
	 * Constructor.
	 *
	 * @param Placement_Repository $placements Placement persistence.
	 */
	public function __construct( private readonly Placement_Repository $placements ) {
	}

	/**
	 * Resolves every placement to its ad-group term, or explains what is wrong.
	 *
	 * All-or-nothing on purpose: a partial map would let a caller publish the
	 * placements that happened to resolve.
	 *
	 * @param array<int, int> $placement_ids Placements to resolve.
	 * @return array<int, int>|WP_Error Placement id to ad-group term id.
	 */
	public function resolve_all( array $placement_ids ) {
		if ( ! Adsanity::is_available() ) {
			return new WP_Error(
				'laao_ads_provider_unavailable',
				__( 'AdSanity is not active, so campaigns cannot be published.', 'laao-advertiser-portal' )
			);
		}

		if ( array() === $placement_ids ) {
			return new WP_Error(
				'laao_ads_no_placements_to_resolve',
				__( 'This campaign has no placements to publish.', 'laao-advertiser-portal' )
			);
		}

		$resolved = array();
		$unmapped = array();
		$dangling = array();

		foreach ( $placement_ids as $placement_id ) {
			$term_id = $this->placements->adgroup_term_id( $placement_id );

			if ( $term_id <= 0 ) {
				$unmapped[] = $this->label( $placement_id );

				continue;
			}

			if ( ! Adsanity::group_exists( $term_id ) ) {
				// The term was deleted from the taxonomy after somebody mapped
				// it. Publishing into an id that resolves to nothing produces
				// an ad that exists and renders nowhere.
				$dangling[] = $this->label( $placement_id );

				continue;
			}

			$resolved[ $placement_id ] = $term_id;
		}

		if ( array() !== $unmapped || array() !== $dangling ) {
			return $this->failure( $unmapped, $dangling );
		}

		return $resolved;
	}

	/**
	 * The guard callable the state machine consumes.
	 *
	 * @param callable $placements_for Resolves a campaign's placements.
	 * @return callable
	 *
	 * @phpstan-param  callable(int): array<int, int> $placements_for
	 * @phpstan-return callable(int, array<string, mixed>): (true|WP_Error)
	 */
	public function as_guard( callable $placements_for ): callable {
		return function ( int $campaign_id ) use ( $placements_for ): bool|WP_Error {
			$resolved = $this->resolve_all( $placements_for( $campaign_id ) );

			return is_wp_error( $resolved ) ? $resolved : true;
		};
	}

	/**
	 * Builds the error, naming every placement that needs attention.
	 *
	 * Naming all of them rather than the first means a reviewer fixes the
	 * configuration once instead of discovering the next one on retry.
	 *
	 * @param array<int, string> $unmapped Placements with no ad group set.
	 * @param array<int, string> $dangling Placements pointing at a deleted term.
	 * @return WP_Error
	 */
	private function failure( array $unmapped, array $dangling ): WP_Error {
		$messages = array();

		if ( array() !== $unmapped ) {
			$messages[] = sprintf(
				/* translators: %s: comma-separated list of placement names. */
				__( 'These placements have no ad group assigned: %s.', 'laao-advertiser-portal' ),
				implode( ', ', $unmapped )
			);
		}

		if ( array() !== $dangling ) {
			$messages[] = sprintf(
				/* translators: %s: comma-separated list of placement names. */
				__( 'These placements point at an ad group that no longer exists: %s.', 'laao-advertiser-portal' ),
				implode( ', ', $dangling )
			);
		}

		return new WP_Error(
			'laao_ads_placement_unmapped',
			implode( ' ', $messages ),
			array(
				'unmapped' => $unmapped,
				'dangling' => $dangling,
			)
		);
	}

	/**
	 * A placement's name, falling back to its id.
	 *
	 * @param int $placement_id Placement post id.
	 * @return string
	 */
	private function label( int $placement_id ): string {
		$name = $this->placements->name( $placement_id );

		return '' === $name ? sprintf( '#%d', $placement_id ) : $name;
	}
}
