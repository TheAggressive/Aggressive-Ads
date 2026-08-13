<?php
/**
 * Not-found contents.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Routes;

?>
<div class="aggr-pagehead">
	<div>
		<h1 class="aggr-title"><?php esc_html_e( 'Page not found', 'aggressive-ads' ); ?></h1>
		<p class="aggr-lede"><?php esc_html_e( 'That address does not match anything in the portal.', 'aggressive-ads' ); ?></p>
	</div>
</div>

<p>
	<a class="aggr-button" href="<?php echo esc_url( Routes::url() ); ?>">
		<?php esc_html_e( 'Go to your dashboard', 'aggressive-ads' ); ?>
	</a>
</p>
