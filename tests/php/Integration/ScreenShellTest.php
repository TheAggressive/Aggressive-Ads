<?php
/**
 * Every Advertising screen opens with the same header, in the order core expects.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Conversions_Screen;
use Aggressive\Ads\Admin\Forecast_Screen;
use Aggressive\Ads\Admin\Organization_Screen;
use Aggressive\Ads\Admin\Package_Screen;
use Aggressive\Ads\Admin\Placement_Screen;
use Aggressive\Ads\Admin\Reports_Screen;
use Aggressive\Ads\Admin\Screen_Shell;
use Aggressive\Ads\Admin\Settings_Screen;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * The header is presentation, but its order is behaviour: core's `common.js`
 * moves every admin notice to sit directly after `.wp-header-end`, or after the
 * first heading when there is none. Seven screens printed a bare `<h1>` and no
 * marker; a purpose line under the title would have had notices land between
 * the two.
 */
final class ScreenShellTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();
	}

	/**
	 * Output of one shell call.
	 *
	 * @param callable $emit Prints markup.
	 */
	private static function capture( callable $emit ): string {
		ob_start();
		$emit();

		return (string) ob_get_clean();
	}

	/**
	 * Title, purpose, marker, then content — and nothing else in between.
	 */
	public function test_the_marker_comes_after_the_whole_header_and_before_the_content(): void {
		$html = self::capture(
			static fn () => Screen_Shell::mount( 'Placements', 'What each slot is for.', 'aggr-x-root', 'data-aggr-x', array( 'a' => 1 ), 'Needs JavaScript.' )
		);

		$title   = strpos( $html, '<h1' );
		$purpose = strpos( $html, 'What each slot is for.' );
		$marker  = strpos( $html, 'class="wp-header-end"' );
		$root    = strpos( $html, 'id="aggr-x-root"' );

		$this->assertIsInt( $title );
		$this->assertIsInt( $purpose );
		$this->assertIsInt( $marker );
		$this->assertIsInt( $root );
		$this->assertLessThan( $purpose, $title );
		$this->assertLessThan( $marker, $purpose, 'A notice moved after the marker must not split the title from its purpose line.' );
		$this->assertLessThan( $root, $marker );
	}

	/**
	 * The heading is the title alone; the group and the decorative mark sit
	 * above it, outside the heading's accessible name.
	 */
	public function test_the_heading_is_named_by_its_title_alone(): void {
		$html = self::capture( static fn () => Screen_Shell::open( 'Organizations', '', 'Advertisers' ) );

		$this->assertSame( 1, preg_match( '#<h1[^>]*>(.*?)</h1>#s', $html, $heading ) );
		$this->assertSame( 'Organizations', $heading[1], 'Nothing but the title inside the heading.' );

		$this->assertSame( 1, preg_match( '#<p class="aggr-admin-head__eyebrow">(.*?)</p>#s', $html, $eyebrow ) );
		$this->assertSame( 'Advertisers', wp_strip_all_tags( $eyebrow[1] ) );
		$this->assertStringContainsString( 'aria-hidden="true"', $eyebrow[1], 'The mark is decorative.' );
		$this->assertLessThan( strpos( $html, '<h1' ), strpos( $html, 'aggr-admin-head__eyebrow' ) );
	}

	/**
	 * Every group name resolves, and an unknown key prints nothing rather than
	 * a raw key.
	 */
	public function test_every_section_has_a_name(): void {
		foreach ( array( 'campaigns', 'inventory', 'advertisers', 'measurement', 'setup' ) as $key ) {
			$this->assertNotSame( '', Screen_Shell::section( $key ), $key );
		}

		$this->assertSame( '', Screen_Shell::section( 'nonsense' ) );
		$this->assertStringNotContainsString(
			'aggr-admin-head__eyebrow',
			self::capture( static fn () => Screen_Shell::open( 'Title' ) ),
			'No group, no empty eyebrow.'
		);
	}

	/**
	 * No purpose, no empty paragraph.
	 */
	public function test_an_empty_purpose_prints_no_paragraph(): void {
		$html = self::capture( static fn () => Screen_Shell::open( 'Advertising Settings' ) );

		$this->assertStringNotContainsString( 'aggr-admin-head__purpose', $html );
		$this->assertSame( 1, substr_count( $html, 'class="wp-header-end"' ) );
	}

	/**
	 * Every argument that reaches markup is escaped.
	 */
	public function test_title_and_purpose_are_escaped(): void {
		$html = self::capture( static fn () => Screen_Shell::open( '<script>t</script>', '<img src=x onerror=p>' ) );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * The payload reaches the bundle intact, including characters that would
	 * break out of the attribute if the encoding were wrong.
	 */
	public function test_the_payload_round_trips_through_the_attribute(): void {
		$payload = array(
			'name'  => 'Quote " and \' and <tag> & amp',
			'count' => 3,
		);

		$html = self::capture(
			static fn () => Screen_Shell::mount( 'T', '', 'aggr-x-root', 'data-aggr-x', $payload, 'N' )
		);

		$this->assertSame( 1, preg_match( '#data-aggr-x="([^"]*)"#', $html, $attribute ) );
		$this->assertSame( $payload, json_decode( html_entity_decode( $attribute[1], ENT_QUOTES ), true ) );
	}

	/**
	 * The build-missing notice sits under the header like any other notice.
	 */
	public function test_an_unbuilt_screen_still_has_the_header_and_its_notice_after_it(): void {
		$html = self::capture( static fn () => Screen_Shell::unbuilt( 'Packages', 'Run pnpm build.' ) );

		$marker = strpos( $html, 'class="wp-header-end"' );
		$notice = strpos( $html, 'notice-error' );

		$this->assertIsInt( $marker );
		$this->assertIsInt( $notice );
		$this->assertLessThan( $notice, $marker );
		$this->assertSame( 1, substr_count( $html, '<div class="wrap aggr-admin">' ) );
		$this->assertStringEndsWith( '</div></div>', $html, 'The notice, then the wrap, both closed.' );
	}

	/**
	 * The screens that should be opened by the shell.
	 *
	 * Review is absent on purpose: its header is rendered by React and changes
	 * with the view. See docs/admin-ui.md.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function screens(): array {
		return array(
			'organizations' => array( Organization_Screen::class ),
			'placements'    => array( Placement_Screen::class ),
			'outlook'       => array( Forecast_Screen::class ),
			'packages'      => array( Package_Screen::class ),
			'conversions'   => array( Conversions_Screen::class ),
			'reports'       => array( Reports_Screen::class ),
			'settings'      => array( Settings_Screen::class ),
		);
	}

	/**
	 * Exactly one header per screen — a count, not a presence check, so a
	 * screen that prints its own `<h1>` beside the shell's fails too.
	 *
	 * @dataProvider screens
	 *
	 * @param class-string $screen Screen class.
	 */
	public function test_each_screen_opens_with_exactly_one_shell_header( string $screen ): void {
		$admin = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );

		foreach ( Capabilities::staff_menu_caps() as $cap ) {
			$this->assertTrue(
				user_can( $admin, $cap ),
				"The fixture must hold {$cap}, or the render dies and the assertions below read nothing."
			);
		}

		wp_set_current_user( $admin );

		$instance = Plugin::instance()->container()->get( $screen );
		$html     = self::capture( array( $instance, 'render' ) );

		$this->assertSame( 1, substr_count( $html, '<h1' ), 'One page title.' );
		$this->assertSame( 1, substr_count( $html, 'class="aggr-admin-head"' ), 'Opened by Screen_Shell.' );
		$this->assertSame( 1, substr_count( $html, 'class="wp-header-end"' ), 'One marker for core to put notices under.' );
		$this->assertSame( 1, substr_count( $html, 'class="aggr-admin-head__eyebrow"' ), 'Every screen names its group.' );
	}
}
