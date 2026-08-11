<?php
/**
 * The portal document.
 *
 * Renders the whole page: doctype, head, rail, content, footer. It calls
 * neither get_header() nor get_footer(), and that is the point — those are the
 * classic-theme mechanism, meaningless under a block theme like Twenty
 * Twenty-Five, and under a classic theme they would pull in navigation and
 * styling the portal does not want.
 *
 * Owning the document is what makes the portal look and behave the same
 * regardless of the active theme. See
 * docs/adr/0001-standalone-plugin-zero-theme-dependency.md.
 *
 * @package LAAO_Advertiser_Portal
 *
 * @var string $laao_ads_screen Absolute path to the screen partial.
 * @var string $laao_ads_title  Screen title.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\View_Data;

$laao_ads_screen = isset( $laao_ads_screen ) && is_string( $laao_ads_screen ) ? $laao_ads_screen : '';
$laao_ads_title  = isset( $laao_ads_title ) && is_string( $laao_ads_title ) ? $laao_ads_title : '';
$laao_ads_view   = Plugin::instance()->container()->get( View_Data::class );

/*
 * The portal has no queried object, so core's document title falls back to the
 * site name on every screen. Identical titles across pages is a WCAG 2.4.2
 * failure and makes browser history and open tabs useless — which matters most
 * to the people who need them most.
 */
$laao_ads_document_title = '' === $laao_ads_title
	? get_bloginfo( 'name' )
	: sprintf(
		/* translators: 1: screen name. 2: site name. */
		__( '%1$s — %2$s', 'laao-advertiser-portal' ),
		$laao_ads_title,
		get_bloginfo( 'name' )
	);

add_filter( 'pre_get_document_title', static fn (): string => $laao_ads_document_title );

/*
 * Robots: the portal is behind a capability check, so a crawler never sees it,
 * but a signed-in crawler-adjacent tool or a leaked URL should still be told.
 */
add_filter( 'wp_robots', 'wp_robots_no_robots' );

/*
 * The portal is the complete application chrome. Core's front-end admin bar
 * renders at wp_body_open() before our skip link, making unrelated WordPress
 * controls the first keyboard stop and exposing wp-admin navigation to an
 * advertiser who does not need it. Suppress both render locations only for
 * this owned document; the toolbar remains untouched everywhere else.
 */
// phpcs:ignore WordPressVIPMinimum.UserExperience.AdminBarRemoval.RemovalDetected -- This standalone application document replaces WordPress chrome; retaining the toolbar puts unrelated wp-admin controls before the portal skip link. Browser coverage asserts the portal-only keyboard order.
show_admin_bar( false );
remove_action( 'wp_body_open', 'wp_admin_bar_render', 0 );
remove_action( 'wp_footer', 'wp_admin_bar_render', 1000 );

/*
 * Whether wp_head() will emit these itself.
 *
 * Asking current_theme_supports( 'title-tag' ) is the obvious test and it is
 * wrong: under a block theme core swaps _wp_render_title_tag() for
 * _block_template_render_title_tag(), which runs regardless of theme support.
 * The first version of this file checked theme support, got false under
 * Twenty Twenty-Five, and shipped two <title> elements in one document.
 * Asking which callback is attached answers the actual question.
 */
$laao_ads_head_has_title    = has_action( 'wp_head', '_wp_render_title_tag' ) || has_action( 'wp_head', '_block_template_render_title_tag' );
$laao_ads_head_has_viewport = has_action( 'wp_head', '_block_template_viewport_meta_tag' );

?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<?php
	if ( false === $laao_ads_head_has_title ) {
		echo '<title>' . esc_html( $laao_ads_document_title ) . '</title>';
	}

	if ( false === $laao_ads_head_has_viewport ) {
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	}

	wp_head();
	?>
</head>
<body <?php body_class( 'laao-ads-portal' ); ?>>
<?php wp_body_open(); ?>

<a class="laao-ads-skip" href="#laao-ads-main">
	<?php esc_html_e( 'Skip to main content', 'laao-advertiser-portal' ); ?>
</a>

<div class="laao-ads-shell">
	<?php require LAAO_ADS_PLUGIN_DIR . 'templates/portal/partials/rail.php'; ?>

	<div class="laao-ads-body">
		<header class="laao-ads-topbar">
			<div class="laao-ads-org">
				<?php echo esc_html( '' !== $laao_ads_view->org_name() ? $laao_ads_view->org_name() : get_bloginfo( 'name' ) ); ?>
			</div>

			<div class="laao-ads-topbar__actions">
				<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
					<?php esc_html_e( 'Sign out', 'laao-advertiser-portal' ); ?>
				</a>
				<span class="laao-ads-avatar" aria-hidden="true"><?php echo esc_html( $laao_ads_view->org_initials() ); ?></span>
			</div>
		</header>

		<main id="laao-ads-main" class="laao-ads-main" tabindex="-1">
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
</div>

<?php wp_footer(); ?>
</body>
</html>
