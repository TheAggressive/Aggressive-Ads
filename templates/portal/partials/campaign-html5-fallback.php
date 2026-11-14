<?php
/**
 * Staff static HTML5 controls shared by native upload and replacement forms.
 *
 * @var string $aggr_rendition_key Unique form identity.
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Domain\Upload_Rules;

if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
	return;
}
?>
<div class="aggr-field">
	<label for="aggr-html5-fallback-<?php echo esc_attr( $aggr_rendition_key ); ?>"><?php esc_html_e( 'HTML5 raster fallback', 'aggressive-ads' ); ?></label>
	<p class="aggr-hint" id="aggr-html5-hint-<?php echo esc_attr( $aggr_rendition_key ); ?>"><?php esc_html_e( 'For static HTML5, choose a ZIP as the creative file and a matching raster fallback here. Review covers both; all files in an approved package become public. Use one index.html, supported local styles and raster assets. Scripts and external resources are unavailable. Leave this field empty for an image creative.', 'aggressive-ads' ); ?></p>
	<input id="aggr-html5-fallback-<?php echo esc_attr( $aggr_rendition_key ); ?>" name="fallback" type="file" accept="<?php echo esc_attr( Upload_Rules::accept_attribute() ); ?>" aria-describedby="aggr-html5-hint-<?php echo esc_attr( $aggr_rendition_key ); ?>">
</div>
