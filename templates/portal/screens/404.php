<?php
/**
 * Not-found contents.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Portal\Routes;

?>
<div class="laao-ads-pagehead">
	<div>
		<h1 class="laao-ads-title"><?php esc_html_e( 'Page not found', 'laao-advertiser-portal' ); ?></h1>
		<p class="laao-ads-lede"><?php esc_html_e( 'That address does not match anything in the portal.', 'laao-advertiser-portal' ); ?></p>
	</div>
</div>

<p>
	<a class="laao-ads-button" href="<?php echo esc_url( Routes::url() ); ?>">
		<?php esc_html_e( 'Go to your dashboard', 'laao-advertiser-portal' ); ?>
	</a>
</p>
