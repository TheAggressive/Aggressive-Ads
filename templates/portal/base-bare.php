<?php
/**
 * The portal document, without the signed-in chrome.
 *
 * The signed-in shell renders a rail, an organization name and an avatar, all of which
 * assume a session. The sign-in screen has none by definition, so it gets its
 * own shell rather than a pile of conditionals inside the other one — a layout
 * that is half-rendered for logged-out callers is a layout where somebody
 * eventually reads a null.
 *
 * Everything about the document itself — the title handling, the robots rule,
 * the theme independence — is deliberately identical to base.php.
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

$aggr_screen = isset( $aggr_screen ) && is_string( $aggr_screen ) ? $aggr_screen : '';
$aggr_title  = isset( $aggr_title ) && is_string( $aggr_title ) ? $aggr_title : '';

$aggr_document_title = '' === $aggr_title
	? get_bloginfo( 'name' )
	: sprintf(
		/* translators: 1: screen name. 2: site name. */
		__( '%1$s — %2$s', 'aggressive-ads' ),
		$aggr_title,
		get_bloginfo( 'name' )
	);

add_filter( 'pre_get_document_title', static fn (): string => $aggr_document_title );
add_filter( 'wp_robots', 'wp_robots_no_robots' );

// phpcs:ignore WordPressVIPMinimum.UserExperience.AdminBarRemoval.RemovalDetected -- This owned document replaces WordPress chrome; on a sign-in screen there is no session for a toolbar to describe.
show_admin_bar( false );
remove_action( 'wp_body_open', 'wp_admin_bar_render', 0 );
remove_action( 'wp_footer', 'wp_admin_bar_render', 1000 );

// See base.php: theme support is the wrong question under a block theme.
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
<body <?php body_class( 'aggr-portal aggr-portal--bare' ); ?>>
<?php wp_body_open(); ?>

<main id="aggr-main" class="aggr-bare" tabindex="-1">
	<?php
	if ( '' !== $aggr_screen && is_file( $aggr_screen ) ) {
		require $aggr_screen;
	}
	?>
</main>

<?php wp_footer(); ?>
</body>
</html>
