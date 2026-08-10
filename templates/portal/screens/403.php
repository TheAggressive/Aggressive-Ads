<?php
/**
 * No-access contents.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p class="laao-ads-lede">
	<?php esc_html_e( 'Your account does not have access to the advertiser portal.', 'laao-advertiser-portal' ); ?>
</p>

<p>
	<a class="laao-ads-button" href="<?php echo esc_url( home_url( '/' ) ); ?>">
		<?php esc_html_e( 'Back to the site', 'laao-advertiser-portal' ); ?>
	</a>
</p>
