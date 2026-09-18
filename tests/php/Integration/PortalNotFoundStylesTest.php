<?php
/**
 * The portal's own "not found" page is still a portal page.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Router;
use Aggressive\Ads\Portal\Routes;
use WP_UnitTestCase;

/**
 * An address under the portal that names no screen gets the portal's 404
 * template. The stylesheet was only enqueued for recognised screens, so that
 * page shipped the portal's markup with none of its CSS — the rail, the skip
 * link and a red "New campaign" button as a column of unstyled text.
 */
final class PortalNotFoundStylesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// Rewrite rules only exist under pretty permalinks, and go_to() cannot
		// resolve a portal address until they are in this process.
		$this->set_permalink_structure( '/%postname%/' );
		Plugin::instance()->container()->get( Router::class )->register_rules();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Test setup: the rules have to exist before go_to() can match one.
		flush_rewrite_rules( false );

		wp_dequeue_style( Assets::HANDLE );
		wp_deregister_style( Assets::HANDLE );
	}

	public function tear_down(): void {
		wp_dequeue_style( Assets::HANDLE );
		$this->set_permalink_structure( '' );

		parent::tear_down();
	}

	public function test_an_unknown_portal_address_still_gets_the_portal_styles(): void {
		$this->go_to( trailingslashit( Routes::url( 'dashboard' ) ) . 'no-such-page/' );

		$router = Plugin::instance()->container()->get( Router::class );

		$this->assertNull( $router->request(), 'The fixture address matched a real screen, so this proves nothing.' );
		$this->assertTrue( $router->draws_page() );

		Plugin::instance()->container()->get( Assets::class )->enqueue();

		$this->assertTrue( wp_style_is( Assets::HANDLE, 'enqueued' ), 'The portal 404 would render unstyled.' );
	}

	public function test_a_page_outside_the_portal_gets_none_of_it(): void {
		$this->go_to( home_url( '/' ) );

		$this->assertFalse( Plugin::instance()->container()->get( Router::class )->draws_page() );

		Plugin::instance()->container()->get( Assets::class )->enqueue();

		$this->assertFalse( wp_style_is( Assets::HANDLE, 'enqueued' ), 'The portal stylesheet leaked onto the public site.' );
	}
}
