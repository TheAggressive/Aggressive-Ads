<?php
/**
 * The campaign page's "Your ads": the edit flow's ad cards, in a panel.
 *
 * It had a layout of its own — a grid of small cards with Update under each —
 * which was a third way of drawing one ad. It draws the same cards as the edit
 * flow's Ads step now: preview, Update, where it goes, an update waiting for
 * review with a way to withdraw it, and an upload for a size with no ad.
 *
 * @package Aggressive\Ads
 *
 * @var array<string, mixed>       $aggr_campaign         Campaign row.
 * @var list<array<string, mixed>> $aggr_creative_updates Replacement history rows.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_campaign = isset( $aggr_campaign ) && is_array( $aggr_campaign ) ? $aggr_campaign : array();

if ( true !== ( $aggr_campaign['can_request_updates'] ?? false ) ) {
	return;
}

$aggr_can_update  = true;
$aggr_ads_eyebrow = '';
$aggr_ads_in_edit = false;
?>
<section class="aggr-panel aggr-wizard" aria-labelledby="aggr-update-creatives-heading">
	<h2 id="aggr-update-creatives-heading" class="aggr-panel__head"><?php esc_html_e( 'Your ads', 'aggressive-ads' ); ?></h2>
	<p><?php esc_html_e( 'Select Update to change an ad\'s artwork or its link. The current ad keeps running until the review team accepts the new one.', 'aggressive-ads' ); ?></p>

	<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-edit-ads.php'; ?>
</section>
