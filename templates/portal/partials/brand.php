<?php
/**
 * Product mark for the portal rail and sign-in screens.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Plugin;

$aggr_brand    = Plugin::instance()->container()->get( Settings::class );
$aggr_name     = $aggr_brand->product_name();
$aggr_tagline  = $aggr_brand->tagline();
$aggr_logo_url = $aggr_brand->logo_url();
?>
<?php if ( '' !== $aggr_logo_url ) : ?>
	<img class="aggr-brand__logo" src="<?php echo esc_url( $aggr_logo_url ); ?>" alt="<?php echo esc_attr( $aggr_name ); ?>">
<?php else : ?>
	<span class="aggr-brand__mark"><?php echo esc_html( $aggr_name ); ?></span>
<?php endif; ?>
<?php if ( '' !== $aggr_tagline ) : ?>
	<span class="aggr-brand__sub"><?php echo esc_html( $aggr_tagline ); ?></span>
<?php endif; ?>
