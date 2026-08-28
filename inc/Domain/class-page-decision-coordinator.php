<?php
/**
 * Page-level decision coordinator for multi-slot batch requests.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Evaluates decisions across multiple page slots with roadblock and competitive separation coordination.
 */
final class Page_Decision_Coordinator {

	/**
	 * Coordinates decisions across multiple slots on a page.
	 *
	 * @param array<string, array{placement_id: int, candidates: list<array<string, mixed>>}> $slots_map Slot data keyed by slot slug.
	 * @param Decision_Pipeline                                                               $pipeline  Standard decision pipeline.
	 * @param int                                                                             $now       Current timestamp.
	 * @param int|null                                                                        $seed      Random seed.
	 * @return array<string, array{result: Decision_Result, trace: Decision_Trace}>
	 */
	public static function coordinate(
		array $slots_map,
		Decision_Pipeline $pipeline,
		int $now,
		?int $seed = null
	): array {
		$results        = array();
		$served_assets  = array();
		$served_org_ids = array();
		$served_cats    = array();

		$current_seed = $seed ?? random_int( 0, PHP_INT_MAX );

		foreach ( $slots_map as $slot_slug => $slot_data ) {
			$placement_id = $slot_data['placement_id'];
			$candidates   = $slot_data['candidates'];

			// 1. Filter candidates against page-level competitive separation & deduplication.
			$eligible_candidates = array();
			$excluded_entries    = array();

			$has_alternative_assets = count(
				array_filter(
					$candidates,
					static function ( array $c ) use ( $served_assets ): bool {
						$aid = (int) ( $c['asset_id'] ?? 0 );
						return $aid > 0 && ! in_array( $aid, $served_assets, true );
					}
				)
			) > 0;

			foreach ( $candidates as $candidate_row ) {
				$candidate_id = (int) ( $candidate_row['id'] ?? 0 );

				// Check competitive separation.
				$comp_reason = Page_Coordination_Rules::evaluate_competitive_separation(
					$candidate_row,
					$served_org_ids,
					$served_cats
				);

				if ( null !== $comp_reason ) {
					$excluded_entries[] = array(
						'candidate_id' => $candidate_id,
						'stage'        => 'page_coordination',
						'reason'       => $comp_reason,
					);
					continue;
				}

				// Check creative deduplication.
				$dedup_reason = Page_Coordination_Rules::evaluate_asset_deduplication(
					$candidate_row,
					$served_assets,
					$has_alternative_assets
				);

				if ( null !== $dedup_reason ) {
					$excluded_entries[] = array(
						'candidate_id' => $candidate_id,
						'stage'        => 'page_coordination',
						'reason'       => $dedup_reason,
					);
					continue;
				}

				$eligible_candidates[] = $candidate_row;
			}

			// 2. Decide using the standard pipeline.
			$request  = new Decision_Request( $placement_id, $now, $current_seed++ );
			$decision = $pipeline->decide( $eligible_candidates, $request );

			$winner = $decision['result']->winner;

			// Append page-coordination trace entries.
			$combined_entries = array_values( array_merge( $excluded_entries, $decision['trace']->entries ) );
			$trace            = new Decision_Trace(
				$combined_entries,
				$decision['result']
			);

			$results[ $slot_slug ] = array(
				'result' => $decision['result'],
				'trace'  => $trace,
			);

			// 3. If a winner emerged, record page-level facts for subsequent slots.
			if ( is_array( $winner ) ) {
				$asset_id = (int) ( $winner['asset_id'] ?? 0 );
				$org_id   = (int) ( $winner['organization_id'] ?? 0 );

				if ( $asset_id > 0 ) {
					$served_assets[] = $asset_id;
				}
				if ( $org_id > 0 ) {
					$served_org_ids[] = $org_id;
				}

				if ( isset( $winner['delivery_settings'] ) ) {
					$settings = is_array( $winner['delivery_settings'] )
						? $winner['delivery_settings']
						: json_decode( (string) $winner['delivery_settings'], true );

					if ( is_array( $settings ) && isset( $settings['category'] ) && is_string( $settings['category'] ) ) {
						$served_cats[] = trim( $settings['category'] );
					}
				}
			}
		}

		return $results;
	}
}
