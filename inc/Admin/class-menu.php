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
