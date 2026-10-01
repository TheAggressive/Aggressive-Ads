<?php
/**
 * A line item's stored values, labelled for reading.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

/**
 * The vocabularies live here rather than in Review_Data so a new pricing model
 * or goal is one edit in a file about line items, not a hunt through the
 * presenter for the whole review screen.
 */
final class Line_Item_Labels {

	/**
	 * A line item with its stored values labelled for reading.
	 *
	 * The panel printed `live`, `FLAT`, `none` and `even` — slugs, one of them
	 * upper-cased to look deliberate. The raw values stay, because the delivery
	 * policy form edits them; the labels sit beside them for the summary.
	 * Unknown values fall back to the slug made readable rather than to
	 * nothing, so a vocabulary that grows still shows something true.
	 *
	 * @param array<string, mixed> $item Line item row.
	 * @return array<string, mixed>
	 */
	public static function labelled( array $item ): array {
		$status  = (string) ( $item['status'] ?? '' );
		$pricing = (string) ( $item['pricing_model'] ?? '' );
		$goal    = (string) ( $item['goal_type'] ?? '' );

		$statuses = array(
			'draft'     => _x( 'Draft', 'line item status', 'aggressive-ads' ),
			'ready'     => _x( 'Ready', 'line item status', 'aggressive-ads' ),
			'scheduled' => _x( 'Scheduled', 'line item status', 'aggressive-ads' ),
			'live'      => _x( 'Live', 'line item status', 'aggressive-ads' ),
			'paused'    => _x( 'Paused', 'line item status', 'aggressive-ads' ),
			'completed' => _x( 'Completed', 'line item status', 'aggressive-ads' ),
			'cancelled' => _x( 'Cancelled', 'line item status', 'aggressive-ads' ),
		);

		$pricings = array(
			'flat'           => __( 'Flat fee', 'aggressive-ads' ),
			'cpm'            => __( 'Per thousand impressions (CPM)', 'aggressive-ads' ),
			'cpc'            => __( 'Per click (CPC)', 'aggressive-ads' ),
			'cpa'            => __( 'Per conversion (CPA)', 'aggressive-ads' ),
			'share_of_voice' => __( 'Share of voice', 'aggressive-ads' ),
		);

		$goals = array(
			'none'           => _x( 'No delivery goal', 'line item goal', 'aggressive-ads' ),
			'impressions'    => _x( 'Impressions', 'line item goal', 'aggressive-ads' ),
			'clicks'         => _x( 'Clicks', 'line item goal', 'aggressive-ads' ),
			'conversions'    => _x( 'Conversions', 'line item goal', 'aggressive-ads' ),
			'spend'          => _x( 'Spend', 'line item goal', 'aggressive-ads' ),
			'share_of_voice' => _x( 'Share of voice', 'line item goal', 'aggressive-ads' ),
		);

		$readable = static fn ( string $slug ): string => ucfirst( str_replace( '_', ' ', $slug ) );

		$item['status_label']  = $statuses[ $status ] ?? $readable( $status );
		$item['pricing_label'] = $pricings[ $pricing ] ?? $readable( $pricing );
		$item['goal_label']    = $goals[ $goal ] ?? $readable( $goal );

		return $item;
	}
}
