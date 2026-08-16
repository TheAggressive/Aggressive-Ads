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
	public const ICON        = 'dashicons-megaphone';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Product name for the sidebar label.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Attaches the shell. Priority 9 so submenus at 10 have a parent.
	 */
	public function init(): void {
		add_filter( 'user_has_cap', array( $this, 'grant_staff_shell' ), 10, 3 );
		add_action( 'admin_menu', array( $this, 'register_parent' ), 9 );
		add_action( 'admin_menu', array( $this, 'remove_duplicate_parent' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_rhythm' ) );
	}

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
			$this->settings->product_name(),
			Capabilities::ACCESS_STAFF,
			self::PARENT_SLUG,
			array( $this, 'redirect_to_first_screen' ),
			self::ICON,
			self::POSITION
		);
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
			Settings_Screen::MENU_SLUG     => Capabilities::MANAGE_SETTINGS,
		);
	}
}
