<?php
/**
 * The Review step's sketch of the campaign's ads on an article.
 *
 * An illustration, hidden from assistive technology: the creative list beside
 * it says the same thing in words. `Page_Preview` decides which area each size
 * belongs in; this only draws. A size with no file yet is drawn dashed and says
 * so, so the gap is visible where the ad would be.
 *
 * Scope is inherited from the screen, as it is for every partial here.
 *
 * @var array<int, array<string, mixed>> $aggr_slots      Placements and the creatives on each.
 * @var string                           $aggr_check_host The site's host, for the sketch's address bar.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Page_Preview;

$aggr_sketch = Page_Preview::arrange( $aggr_slots );
?>
<div class="aggr-pagepreview" aria-hidden="true">
	<div class="aggr-pagepreview__bar"><span class="aggr-pagepreview__url"><?php echo esc_html( $aggr_check_host . ' / article' ); ?></span></div>
	<div class="aggr-pagepreview__body">
		<div class="aggr-pagepreview__column">
			<span class="aggr-pagepreview__line aggr-pagepreview__line--title"></span>
			<?php foreach ( $aggr_sketch['column'] as $aggr_tile ) : ?>
				<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-page-preview-tile.php'; ?>
			<?php endforeach; ?>
			<span class="aggr-pagepreview__line"></span>
			<span class="aggr-pagepreview__line"></span>
			<span class="aggr-pagepreview__line aggr-pagepreview__line--short"></span>
		</div>
		<div class="aggr-pagepreview__rail">
			<?php foreach ( $aggr_sketch['rail'] as $aggr_tile ) : ?>
				<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-page-preview-tile.php'; ?>
			<?php endforeach; ?>
		</div>
	</div>
</div>
