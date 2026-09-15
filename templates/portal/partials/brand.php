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

/*
 * The last word of a multi-word name takes the accent, as "Ads" does in the
 * brand's own lockup. A one-word name stays a single colour.
 */
$aggr_words = preg_split( '/\s+/', trim( $aggr_name ) );
$aggr_words = is_array( $aggr_words ) ? $aggr_words : array( $aggr_name );
$aggr_last  = count( $aggr_words ) > 1 ? (string) array_pop( $aggr_words ) : '';
?>
<?php if ( '' !== $aggr_logo_url ) : ?>
	<img class="aggr-brand__logo" src="<?php echo esc_url( $aggr_logo_url ); ?>" alt="<?php echo esc_attr( $aggr_name ); ?>">
<?php else : ?>
	<?php
	/*
	 * Text only. The plugin's own mark is not drawn here: advertisers are the
	 * publisher's customers, so a site that wants a mark sets its logo.
	 */
	?>
	<span class="aggr-brand__lockup">
		<span class="aggr-brand__mark">
			<span class="aggr-brand__word"><?php echo esc_html( implode( ' ', $aggr_words ) ); ?></span>
			<?php if ( '' !== $aggr_last ) : ?>
				<span class="aggr-brand__word aggr-brand__word--accent"><?php echo esc_html( $aggr_last ); ?></span>
			<?php endif; ?>
		</span>
	</span>
<?php endif; ?>
<?php if ( '' !== $aggr_tagline ) : ?>
	<span class="aggr-brand__sub"><?php echo esc_html( $aggr_tagline ); ?></span>
<?php endif; ?>
