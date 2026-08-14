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
 * @package Aggressive\Ads
 *
 * @var string $aggr_screen Absolute path to the screen partial.
 * @var string $aggr_title  Screen title.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\View_Data;

$aggr_screen = isset( $aggr_screen ) && is_string( $aggr_screen ) ? $aggr_screen : '';
$aggr_title  = isset( $aggr_title ) && is_string( $aggr_title ) ? $aggr_title : '';
$aggr_view   = Plugin::instance()->container()->get( View_Data::class );

/*
 * The portal has no queried object, so core's document title falls back to the
 * site name on every screen. Identical titles across pages is a WCAG 2.4.2
 * failure and makes browser history and open tabs useless — which matters most
 * to the people who need them most.
 */
$aggr_document_title = '' === $aggr_title
	? get_bloginfo( 'name' )
	: sprintf(
		/* translators: 1: screen name. 2: site name. */
		__( '%1$s — %2$s', 'aggressive-ads' ),
		$aggr_title,
		get_bloginfo( 'name' )
	);

add_filter( 'pre_get_document_title', static fn (): string => $aggr_document_title );

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
$aggr_head_has_title    = has_action( 'wp_head', '_wp_render_title_tag' ) || has_action( 'wp_head', '_block_template_render_title_tag' );
$aggr_head_has_viewport = has_action( 'wp_head', '_block_template_viewport_meta_tag' );

?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<?php
	if ( false === $aggr_head_has_title ) {
		echo '<title>' . esc_html( $aggr_document_title ) . '</title>';
	}

	if ( false === $aggr_head_has_viewport ) {
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	}

	wp_head();
	?>
</head>
<body <?php body_class( 'aggr-portal' ); ?>>
<?php wp_body_open(); ?>

<a class="aggr-skip" href="#aggr-main">
	<?php esc_html_e( 'Skip to main content', 'aggressive-ads' ); ?>
</a>

<div class="aggr-shell">
	<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/rail.php'; ?>

	<div class="aggr-body">
		<header class="aggr-topbar">
			<div class="aggr-org">
				<?php echo esc_html( '' !== $aggr_view->org_name() ? $aggr_view->org_name() : get_bloginfo( 'name' ) ); ?>
			</div>

			<div class="aggr-topbar__actions">
				<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
					<?php esc_html_e( 'Sign out', 'aggressive-ads' ); ?>
				</a>
				<span class="aggr-avatar" aria-hidden="true"><?php echo esc_html( $aggr_view->org_initials() ); ?></span>
			</div>
		</header>

		<main id="aggr-main" class="aggr-main" tabindex="-1">
			<?php
			if ( '' !== $aggr_screen && is_file( $aggr_screen ) ) {
				require $aggr_screen;
			}
			?>
		</main>

		<footer class="aggr-colophon">
			<p>
				<?php
				printf(
					/* translators: %s: site name. */
					esc_html__( 'Advertising with %s', 'aggressive-ads' ),
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
