<?php
/**
 * No-access contents.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p class="aggr-lede">
	<?php esc_html_e( 'Your account does not have access to the advertiser portal.', 'aggressive-ads' ); ?>
</p>

<p>
	<a class="aggr-button" href="<?php echo esc_url( home_url( '/' ) ); ?>">
		<?php esc_html_e( 'Back to the site', 'aggressive-ads' ); ?>
	</a>
</p>
