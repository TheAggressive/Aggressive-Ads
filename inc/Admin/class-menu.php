<?php
/**
 * Unified Advertising admin menu shell.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Security\Capabilities;

/**
 * Registers the parent. Screens register their own submenus against PARENT_SLUG.
 */
final class Menu implements Service {

	public const PARENT_SLUG = 'aggr';
	public const POSITION    = 26;
	/**
	 * No glyph from WordPress; the mark is painted in CSS instead.
	 *
	 * A data-URI icon becomes a background-image, and a background-image cannot
	 * take a colour from CSS — it would stay one fixed shade through hover, the
	 * current-page state and all eight admin colour schemes. Masking the same
	 * file with `currentColor` lets it inherit whatever the menu is already
	 * using, which is what every other icon in that sidebar does.
	 */
	public const ICON = 'none';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Product name for the sidebar label.
	 */
	/**
	 * Constructor.
	 *
	 * @param Settings     $settings Product name for the sidebar label.
	 * @param Pending_Work $pending  Waiting-work count for the parent badge.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Pending_Work $pending
	) {
	}

	/**
	 * Attaches the shell. Priority 9 so submenus at 10 have a parent.
	 */
	public function init(): void {
		add_filter( 'user_has_cap', array( $this, 'grant_staff_shell' ), 10, 3 );
		add_action( 'admin_menu', array( $this, 'register_parent' ), 9 );
		add_action( 'admin_menu', array( $this, 'remove_duplicate_parent' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_rhythm' ) );

		// Inline and on every admin page, because the menu is on every admin
		// page. A stylesheet request for nine declarations would cost more than
		// it saves, and the compiled admin styles only load on our screens.
		add_action( 'admin_head', array( $this, 'print_icon_style' ) );
	}

	/**
	 * Paints the menu mark so it takes its colour from the menu.
	 *
	 * Centred by making the container a flex box rather than by nudging the
	 * mark with a margin. The first version used `margin: 7px auto 0`, which
	 * was a number picked to look right against one row height while
	 * WordPress's own `padding: 8px 0` on this pseudo-element was still
	 * applying underneath it — so the mark sat off-centre, and would have
	 * drifted again on the folded menu and the mobile breakpoint, where the row
	 * is a different height. Zeroing that padding and letting flex do the
	 * centring means there is no measurement to keep in sync.
	 *
	 * **The mask travels in the rule, not behind a URL.** Pointing `mask` at
	 * the SVG's own URL made the mark a second HTTP request, and an element
	 * whose mask has not arrived paints nothing — so the icon was missing on
	 * every admin page until that request came back, which is a visible flash
	 * on the one element that is on every screen. Inlining a 234-byte file
	 * costs less than the request that fetched it, and the mark is now painted
	 * in the same style recalculation as the rule that positions it.
	 *
	 * Inlining does **not** reintroduce the colour problem the constant above
	 * describes: that one is about a data-URI *background-image*, whose pixels
	 * are the image's own. A mask contributes shape only — the colour is still
	 * `background-color: currentColor` — so how the mask is delivered has no
	 * bearing on it.
	 *
	 * Escaped with `esc_attr` rather than `esc_url`, because `data:` is not in
	 * WordPress's allowed protocol list and `esc_url` would strip the value to
	 * an empty string — the mark would vanish, which is the failure this whole
	 * change exists to fix. The payload is base64, which carries no character
	 * `esc_attr` alters.
	 *
	 * @return void
	 */
	public function print_icon_style(): void {
		if ( ! current_user_can( Capabilities::ACCESS_STAFF ) ) {
			return;
		}

		printf(
			'<style id="aggr-menu-icon">#toplevel_page_%1$s .wp-menu-image{display:flex;align-items:center;justify-content:center;}#toplevel_page_%1$s .wp-menu-image::before{content:"";display:block;width:20px;height:20px;padding:0;margin:0;background-color:currentColor;-webkit-mask:url(%2$s) no-repeat center/20px 20px;mask:url(%2$s) no-repeat center/20px 20px;}</style>' . "\n",
			esc_attr( self::PARENT_SLUG ),
			esc_attr( self::ICON_DATA_URI )
		);
	}

	/**
	 * The mark as a `data:` URI.
	 *
	 * A constant rather than a read of `ICON_FILE`, because this prints on
	 * every admin page and a per-request `file_get_contents` buys nothing: the
	 * mark is a fixed, version-controlled asset that cannot change between
	 * requests. `MenuIconTest` asserts these bytes are the shipped file's, so
	 * the two cannot drift — the copy is checked, not trusted.
	 *
	 * Base64 rather than percent-encoding: the payload lands inside a CSS
	 * `url()` inside an HTML `<style>`, and base64's alphabet cannot carry a
	 * quote, an angle bracket or a parenthesis out of that nesting. A
	 * percent-encoded SVG has to be escaped correctly for all three contexts at
	 * once, which is a thing to get right again on every edit of the file.
	 *
	 * Regenerate after editing `assets/svg/aggressive-ads-icon.svg`:
	 *
	 *     printf 'data:image/svg+xml;base64,%s' "$(base64 -w0 assets/svg/aggressive-ads-icon.svg)"
	 *
	 * `MenuIconTest` fails with that command in its message if the two drift.
	 */
	private const ICON_DATA_URI = 'data:image/svg+xml;base64,PHN2ZyBmaWxsPSJjdXJyZW50Q29sb3IiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgd2lkdGg9IjI0IiBoZWlnaHQ9IjI0IiB2aWV3Qm94PSIwIDAgMjQgMjQiPjxwYXRoIGQ9Ik00LjMzIDIwLjYzSDBMMTAuNTggMy4zN2g0LjQxem0xOS42NyAwaC0zLjg4TDEyLjc4IDguMTZoMy42N3ptLTcuODItMy4xMiAxLjkyIDMuMTJINi4zMWw0LjctNy44MWgzLjIzbC0yLjY2IDQuNjl6Ii8+PC9zdmc+';

	/**
	 * Loads the rhythm-only stylesheet on the Advertising screens.
	 *
	 * Enqueued here rather than per screen because it is one sheet for all of
	 * them, and because a screen that forgets to ask for it is a screen that
	 * quietly looks different from its siblings.
	 *
	 * It carries spacing and measure only — the screens themselves are native
	 * WordPress markup that core already styles. See src/styles/admin-native.css
	 * for why that restraint is the point rather than an omission.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public function enqueue_rhythm( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, self::PARENT_SLUG ) ) {
			return;
		}

		$relative = 'dist/styles/admin-native.css';
		$path     = AGGR_PLUGIN_DIR . $relative;

		if ( ! is_file( $path ) ) {
			return;
		}

		$mtime = filemtime( $path );

		wp_enqueue_style(
			'aggr-admin-native',
			AGGR_PLUGIN_URL . $relative,
			array(),
			false === $mtime ? AGGR_VERSION : (string) $mtime
		);
	}

	/**
	 * Derives aggr_access_staff from any submenu capability.
	 *
	 * @param array<string, bool> $allcaps Caps the user already holds.
	 * @param array<int, string>  $caps    Caps being asked.
	 * @param array<int, mixed>   $args    current_user_can() arguments.
	 * @return array<string, bool>
	 */
	public function grant_staff_shell( array $allcaps, array $caps, array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- user_has_cap signature.
		if ( ! in_array( Capabilities::ACCESS_STAFF, $caps, true ) ) {
			return $allcaps;
		}

		foreach ( Capabilities::staff_menu_caps() as $cap ) {
			if ( ! empty( $allcaps[ $cap ] ) ) {
				$allcaps[ Capabilities::ACCESS_STAFF ] = true;
				break;
			}
		}

		return $allcaps;
	}

	/**
	 * Registers the Advertising parent.
	 */
	public function register_parent(): void {
		add_menu_page(
			$this->settings->product_name(),
			$this->menu_title(),
			Capabilities::ACCESS_STAFF,
			self::PARENT_SLUG,
			array( $this, 'redirect_to_first_screen' ),
			self::ICON,
			self::POSITION
		);
	}

	/**
	 * The sidebar label, with a count when work is waiting.
	 *
	 * **On the parent, not only on Review.** WordPress slides a submenu in from
	 * off-canvas on hover, so the badge on `Review` is off-screen on every admin
	 * page — including while you are inside Advertising. The parent's is the
	 * only copy anybody sees without going looking for it.
	 *
	 * Gated on the capability that can act on it. A person who administers
	 * organizations but cannot review campaigns is shown no number, because a
	 * badge they cannot clear is one they learn to ignore, and it would leak
	 * the size of a queue they have no access to.
	 *
	 * The page title stays the plain product name: the badge is markup, and
	 * `add_menu_page` puts the page title in `<title>` where tags would show
	 * through as text.
	 */
	private function menu_title(): string {
		$label = $this->settings->product_name();

		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			return $label;
		}

		return $this->pending->label_with_badge( $label, $this->pending->parent_badge() );
	}

	/**
	 * WordPress duplicates the parent as a submenu. Drop it.
	 */
	public function remove_duplicate_parent(): void {
		remove_submenu_page( self::PARENT_SLUG, self::PARENT_SLUG );
	}

	/**
	 * Sends the parent click to the first screen this user can actually open.
	 */
	public function redirect_to_first_screen(): void {
		foreach ( $this->landing_pages() as $slug => $cap ) {
			if ( current_user_can( $cap ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=' . $slug ) );
				exit;
			}
		}

		wp_die(
			esc_html__( 'You do not have permission to view this page.', 'aggressive-ads' ),
			'',
			array( 'response' => 403 )
		);
	}

	/**
	 * Submenu slug → capability, in sidebar order.
	 *
	 * @return array<string, string>
	 */
	private function landing_pages(): array {
		return array(
			Review_Screen::MENU_SLUG       => Capabilities::REVIEW_CAMPAIGNS,
			Organization_Screen::MENU_SLUG => Capabilities::MANAGE_ORGS,
			Placement_Screen::MENU_SLUG    => Capabilities::MANAGE_PLACEMENTS,
			Package_Screen::MENU_SLUG      => Capabilities::MANAGE_PACKAGES,
			Reports_Screen::MENU_SLUG      => Capabilities::VIEW_REPORTS,
			Settings_Screen::MENU_SLUG     => Capabilities::MANAGE_SETTINGS,
		);
	}
}
