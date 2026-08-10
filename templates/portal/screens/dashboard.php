<?php
/**
 * Dashboard contents.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$laao_ads_user = wp_get_current_user();
?>
<p class="laao-ads-lede">
	<?php
	printf(
		/* translators: %s: the advertiser's display name. */
		esc_html__( 'Welcome back, %s.', 'laao-advertiser-portal' ),
		esc_html( $laao_ads_user->display_name )
	);
	?>
</p>

<section class="laao-ads-panel" aria-labelledby="laao-ads-next-steps">
	<h2 id="laao-ads-next-steps" class="laao-ads-panel__title">
		<?php esc_html_e( 'Needs your attention', 'laao-advertiser-portal' ); ?>
	</h2>

	<p class="laao-ads-empty">
		<?php esc_html_e( 'Nothing needs your attention right now.', 'laao-advertiser-portal' ); ?>
	</p>
</section>
