<?php
/**
 * Advertiser-facing creative replacement history.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

$aggr_creative_updates = $aggr_creative_updates ?? array();

if ( array() === $aggr_creative_updates ) {
	return;
}
?>
<section class="aggr-panel" aria-labelledby="aggr-update-history-heading">
	<h2 id="aggr-update-history-heading" class="aggr-panel__head"><?php esc_html_e( 'Ad update history', 'aggressive-ads' ); ?></h2>
	<ul class="aggr-list">
		<?php foreach ( $aggr_creative_updates as $aggr_update ) : ?>
			<li>
				<strong><?php echo esc_html( (string) $aggr_update['placement'] ); ?>:</strong>
				<?php echo esc_html( (string) $aggr_update['state_text'] ); ?>
				<?php if ( '' !== (string) $aggr_update['notes'] ) : ?>
					&mdash; <?php echo esc_html( (string) $aggr_update['notes'] ); ?>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
