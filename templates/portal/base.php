<?php
/**
 * The portal document.
 *
 * Renders the whole page: doctype, head, shell, footer. It calls neither
 * get_header() nor get_footer(), and that is the point — those are the
 * classic-theme mechanism, meaningless under a block theme like Twenty
 * Twenty-Five, and under a classic theme they would pull in navigation and
 * styling the portal does not want.
 *
 * Owning the document is what makes the portal look and behave the same
 * regardless of the active theme. See
 * docs/adr/0001-standalone-plugin-zero-theme-dependency.md.
 *
 * wp_head() and wp_footer() still run, because the admin bar, wp_robots and
 * every enqueue depend on them, and because plugins legitimately need them.
 *
 * @package LAAO_Advertiser_Portal
 *
 * @var string $laao_ads_screen  Absolute path to the screen partial.
 * @var string $laao_ads_title   Screen title.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$laao_ads_screen = isset( $laao_ads_screen ) && is_string( $laao_ads_screen ) ? $laao_ads_screen : '';
$laao_ads_title  = isset( $laao_ads_title ) && is_string( $laao_ads_title ) ? $laao_ads_title : '';

?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'laao-ads-portal' ); ?>>
<?php wp_body_open(); ?>

<a class="laao-ads-skip" href="#laao-ads-main">
	<?php esc_html_e( 'Skip to main content', 'laao-advertiser-portal' ); ?>
</a>

<div class="laao-ads-shell">
	<header class="laao-ads-masthead">
		<a class="laao-ads-brand" href="<?php echo esc_url( LAAO_Advertiser_Portal\Portal\Routes::url() ); ?>">
			<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
			<span class="laao-ads-brand__sub"><?php esc_html_e( 'Advertiser Portal', 'laao-advertiser-portal' ); ?></span>
		</a>

		<?php require LAAO_ADS_PLUGIN_DIR . 'templates/portal/partials/navigation.php'; ?>
	</header>

	<main id="laao-ads-main" class="laao-ads-main" tabindex="-1">
		<?php if ( '' !== $laao_ads_title ) : ?>
			<h1 class="laao-ads-title"><?php echo esc_html( $laao_ads_title ); ?></h1>
		<?php endif; ?>

		<?php
		if ( '' !== $laao_ads_screen && is_file( $laao_ads_screen ) ) {
			require $laao_ads_screen;
		}
		?>
	</main>

	<footer class="laao-ads-colophon">
		<p>
			<?php
			printf(
				/* translators: %s: site name. */
				esc_html__( 'Advertising with %s', 'laao-advertiser-portal' ),
				esc_html( get_bloginfo( 'name' ) )
			);
			?>
		</p>
	</footer>
</div>

<?php wp_footer(); ?>
</body>
</html>
