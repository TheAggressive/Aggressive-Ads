<?php
/**
 * The waiting-work count on the Advertising menu.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Menu;
use Aggressive\Ads\Admin\Pending_Work;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * The badge lives on the parent because the submenu's copy is positioned
 * off-canvas until hover, so it is not visible on any admin page. These assert
 * the parts of that which can break silently: the number, who is shown it, and
 * that the page title stays free of markup.
 */
final class MenuBadgeTest extends WP_UnitTestCase {

	/**
	 * Waiting-work count under test.
	 *
	 * @var Pending_Work
	 */
	private Pending_Work $pending;

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->pending = Plugin::instance()->container()->get( Pending_Work::class );
	}

	/**
	 * A campaign waiting for a decision.
	 */
	private function submitted_campaign(): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::SUBMITTED,
				'post_title'  => 'Waiting on staff',
			)
		);
	}

	/**
	 * Registers the plugin's admin menu as one user would see it.
	 *
	 * @param int $user_id Acting user.
	 * @return string The Advertising parent's menu title.
	 */
	private function parent_title_for( int $user_id ): string {
		global $menu, $submenu, $_registered_pages, $_parent_pages;

		$menu              = array();
		$submenu           = array();
		$_registered_pages = array();
		$_parent_pages     = array();

		wp_set_current_user( $user_id );

		Plugin::instance()->container()->get( Menu::class )->register_parent();

		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && Menu::PARENT_SLUG === $item[2] ) {
				return (string) $item[0];
			}
		}

		return '';
	}

	/**
	 * **The assertion the whole feature is.**
	 *
	 * Core's own bubble markup, so the count inherits the admin colour scheme
	 * and the folded-menu positioning every other plugin's badge gets.
	 */
	public function test_a_reviewer_sees_the_waiting_count_on_the_parent(): void {
		$this->submitted_campaign();

		$reviewer = (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );

		$this->assertGreaterThan( 0, $this->pending->pending_decision_count(), 'Without waiting work this test proves nothing.' );

		$title = $this->parent_title_for( $reviewer );

		$this->assertNotSame( '', $title, 'The Advertising parent was never registered.' );

		/*
		 * **`update-plugins`, not `awaiting-mod`.** Core inverts the bubble on
		 * the active row through two different selectors, and the one that
		 * applies to a parent of a current submenu names `.update-plugins`:
		 *
		 *     #adminmenu li.current a .awaiting-mod
		 *     #adminmenu li a.wp-has-current-submenu .update-plugins
		 *
		 * The Advertising parent is always the second kind. Marked
		 * `awaiting-mod` it matched neither, so it inverted on hover and not on
		 * the open screen — visible, wrong, and easy to reintroduce.
		 */
		$this->assertStringContainsString( 'update-plugins', $title );
		$this->assertStringContainsString( '<span class="update-count">1</span>', $title );
		$this->assertStringNotContainsString(
			'awaiting-mod',
			$title,
			'A parent badge marked awaiting-mod keeps its resting colour on the active row.'
		);
	}

	/**
	 * And the submenu keeps the other spelling, because a submenu row becomes
	 * `li.current` and that is the selector core inverts for it.
	 */
	public function test_the_submenu_badge_keeps_the_current_item_spelling(): void {
		$this->submitted_campaign();

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$badge = $this->pending->submenu_badge();

		$this->assertStringContainsString( 'awaiting-mod', $badge );
		$this->assertStringContainsString( '<span class="pending-count">1</span>', $badge );
		$this->assertStringNotContainsString( 'update-plugins', $badge );
	}

	/**
	 * Both spellings agree on the number, since they render one count twice.
	 */
	public function test_both_badges_report_the_same_count(): void {
		$this->submitted_campaign();

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$this->assertStringContainsString( '>1<', $this->pending->parent_badge() );
		$this->assertStringContainsString( '>1<', $this->pending->submenu_badge() );
	}

	/**
	 * Nothing waiting shows no bubble at all.
	 *
	 * A badge reading zero is noise, and noise is what teaches people to stop
	 * reading badges.
	 */
	public function test_an_empty_queue_shows_no_bubble(): void {
		$reviewer = (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );

		$this->assertSame( 0, $this->pending->pending_decision_count() );

		$title = $this->parent_title_for( $reviewer );

		$this->assertNotSame( '', $title );
		$this->assertStringNotContainsString( 'awaiting-mod', $title );
	}

	/**
	 * **Only somebody who can act on it is shown it.**
	 *
	 * A badge you cannot clear is one you learn to ignore, and it would leak
	 * the size of a queue this person has no access to. Asserted with a user
	 * who holds a different staff capability, so the menu is registered for
	 * them and only the count is withheld.
	 */
	public function test_staff_who_cannot_review_are_shown_no_count(): void {
		$this->submitted_campaign();

		$user = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$role = get_role( 'administrator' );

		$this->assertInstanceOf( \WP_Role::class, $role );
		$role->remove_cap( Capabilities::REVIEW_CAMPAIGNS );

		try {
			$title = $this->parent_title_for( $user );

			$this->assertNotSame( '', $title, 'The menu must still register; only the count is withheld.' );
			$this->assertStringNotContainsString( 'awaiting-mod', $title );
			$this->assertStringNotContainsString( '1', $title );
		} finally {
			$role->add_cap( Capabilities::REVIEW_CAMPAIGNS );
		}
	}

	/**
	 * The page title stays plain text.
	 *
	 * `add_menu_page` puts the page title into `<title>`, where the bubble's
	 * markup would show through as literal tags on every Advertising screen.
	 */
	public function test_the_page_title_carries_no_markup(): void {
		global $menu;

		$this->submitted_campaign();

		$reviewer = (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );

		$this->parent_title_for( $reviewer );

		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && Menu::PARENT_SLUG === $item[2] ) {
				$this->assertStringNotContainsString( '<span', (string) $item[3], 'The page title must not carry the badge markup.' );

				return;
			}
		}

		$this->fail( 'The Advertising parent was never registered.' );
	}

	/**
	 * The menu and the Review screen must agree, because they are the same
	 * number rendered twice on one page.
	 */
	public function test_the_screen_and_the_menu_count_the_same_things(): void {
		$this->submitted_campaign();

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$data = Plugin::instance()->container()->get( \Aggressive\Ads\Admin\Review_Data::class );

		$this->assertSame(
			$this->pending->pending_decision_count(),
			$data->pending_decision_count(),
			'Two definitions of "waiting" would show two different numbers on one screen.'
		);
	}
}
