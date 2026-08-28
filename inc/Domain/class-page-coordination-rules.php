<?php
/**
 * Page-level coordination rules for multi-slot batch decisions.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Pure rules for roadblocks, competitive separation, and creative deduplication.
 */
final class Page_Coordination_Rules {

	/**
	 * Checks if a candidate is excluded due to creative asset duplication on the page.
	 *
	 * @param array<string, mixed> $candidate_row Candidate being evaluated.
	 * @param array<int, int>      $served_assets Asset IDs already awarded in earlier slots on this page.
	 * @param bool                 $has_alternatives Whether other eligible candidates exist for this slot.
	 * @return string|null Exclusion reason or null.
	 */
	public static function evaluate_asset_deduplication( array $candidate_row, array $served_assets, bool $has_alternatives ): ?string {
		$asset_id = (int) ( $candidate_row['asset_id'] ?? 0 );

		if ( $asset_id > 0 && in_array( $asset_id, $served_assets, true ) && $has_alternatives ) {
			return Exclusion_Reason::PAGE_DUPLICATE_ASSET;
		}

		return null;
	}

	/**
	 * Checks if a candidate is excluded by competitive separation rules.
	 *
	 * @param array<string, mixed> $candidate_row   Candidate being evaluated.
	 * @param array<int, int>      $served_org_ids  Organization IDs already awarded on this page.
	 * @param array<int, string>   $served_cats     Category strings already awarded on this page.
	 * @return string|null Exclusion reason or null.
	 */
	public static function evaluate_competitive_separation( array $candidate_row, array $served_org_ids, array $served_cats ): ?string {
		$org_id   = (int) ( $candidate_row['organization_id'] ?? 0 );
		$settings = self::extract_settings( $candidate_row );

		// Check category exclusivity if configured.
		$category           = isset( $settings['category'] ) && is_string( $settings['category'] ) ? trim( $settings['category'] ) : '';
		$exclusive_category = ! empty( $settings['exclusive_category'] );

		if ( $exclusive_category && '' !== $category && in_array( $category, $served_cats, true ) ) {
			return Exclusion_Reason::PAGE_COMPETITIVE_SEPARATION;
		}

		// Check competing organization restrictions if declared.
		$competing_orgs = isset( $settings['competing_orgs'] ) && is_array( $settings['competing_orgs'] )
			? array_map( 'intval', $settings['competing_orgs'] )
			: array();

		foreach ( $served_org_ids as $served_org ) {
			if ( $served_org !== $org_id && in_array( $served_org, $competing_orgs, true ) ) {
				return Exclusion_Reason::PAGE_COMPETITIVE_SEPARATION;
			}
		}

		return null;
	}

	/**
	 * Checks whether a candidate requires a roadblock.
	 *
	 * @param array<string, mixed> $candidate_row Candidate row.
	 */
	public static function is_roadblock( array $candidate_row ): bool {
		if ( ! empty( $candidate_row['roadblock'] ) ) {
			return true;
		}

		$settings = self::extract_settings( $candidate_row );
		return ! empty( $settings['roadblock'] );
	}

	/**
	 * Extracts delivery settings array.
	 *
	 * @param array<string, mixed> $row Candidate row.
	 * @return array<string, mixed>
	 */
	private static function extract_settings( array $row ): array {
		if ( isset( $row['delivery_settings'] ) ) {
			if ( is_array( $row['delivery_settings'] ) ) {
				return $row['delivery_settings'];
			}
			if ( is_string( $row['delivery_settings'] ) ) {
				$decoded = json_decode( $row['delivery_settings'], true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		return array();
	}
}
