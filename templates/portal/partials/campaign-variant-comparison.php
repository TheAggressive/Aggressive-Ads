<?php
/**
 * A campaign's variants side by side, one table per placement.
 *
 * P17 slice 3. Built by `Delivery_View_Data::variant_comparison()`, which
 * returns nothing until a placement holds two creatives *and* something has been
 * seen in the window — so this renders only where there is a comparison with
 * data behind it, never a table of dashes over a campaign that has not started.
 *
 * Rows the advertiser may not expect are deliberate. "Before per-ad counting"
 * and "An ad no longer on this placement" are delivery that really happened on
 * this placement, and leaving them out would make the rows sum to less than the
 * placement delivered.
 *
 * @var array<string, mixed> $aggr_campaign The campaign being viewed.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_comparison = is_array( $aggr_campaign['variant_comparison'] ?? null ) ? $aggr_campaign['variant_comparison'] : array();
$aggr_groups     = is_array( $aggr_comparison['groups'] ?? null ) ? $aggr_comparison['groups'] : array();

if ( array() === $aggr_groups ) {
	return;
}
?>
<section class="aggr-panel" aria-labelledby="aggr-variant-comparison-heading">
	<h2 id="aggr-variant-comparison-heading" class="aggr-panel__head">
		<?php esc_html_e( 'Compare your ads', 'aggressive-ads' ); ?>
	</h2>

	<?php foreach ( $aggr_groups as $aggr_group ) : ?>
		<div class="aggr-tablewrap" role="region" aria-label="<?php echo esc_attr( (string) $aggr_group['placement'] ); ?>" tabindex="0">
			<table class="aggr-table">
				<caption><?php echo esc_html( (string) $aggr_group['placement'] ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Ad', 'aggressive-ads' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Share of rotation', 'aggressive-ads' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Impressions', 'aggressive-ads' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Clicks', 'aggressive-ads' ); ?></th>
						<th scope="col"><?php esc_html_e( 'CTR', 'aggressive-ads' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Viewable', 'aggressive-ads' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Conversions', 'aggressive-ads' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( (array) $aggr_group['rows'] as $aggr_row ) : ?>
						<tr>
							<th scope="row" class="aggr-table__primary"><?php echo esc_html( (string) $aggr_row['label'] ); ?></th>
							<td><?php echo esc_html( (string) $aggr_row['share'] ); ?></td>
							<td><?php echo esc_html( (string) $aggr_row['impressions'] ); ?></td>
							<td><?php echo esc_html( (string) $aggr_row['clicks'] ); ?></td>
							<td><?php echo esc_html( (string) $aggr_row['ctr'] ); ?></td>
							<td><?php echo esc_html( (string) $aggr_row['viewable'] ); ?></td>
							<td><?php echo esc_html( (string) $aggr_row['conversions'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endforeach; ?>

	<p class="aggr-hint">
		<?php echo esc_html( (string) ( $aggr_comparison['range'] ?? '' ) ); ?>
		<?php if ( '' !== (string) ( $aggr_comparison['note'] ?? '' ) ) : ?>
			<span class="aggr-hint__freshness"><?php echo esc_html( (string) $aggr_comparison['note'] ); ?></span>
		<?php endif; ?>
	</p>
</section>
