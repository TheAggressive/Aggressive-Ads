<?php
/**
 * Site create/delete hooks are wired on every boot, including single-site.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Install\Site_Lifecycle;
use Aggressive\Ads\Plugin;
use WP_UnitTestCase;

/**
 * Behaviour that needs two blogs lives in the multisite suite. A refactor
 * that drops the actions would leave those tests green if this file did not
 * exist — they never run on the default PHPUnit config.
 */
final class SiteLifecycleTest extends WP_UnitTestCase {

	/**
	 * The wp_initialize_site hook is how a network-active install reaches a new blog.
	 *
	 * @return void
	 */
	public function test_initialize_site_is_hooked(): void {
		$lifecycle = Plugin::instance()->container()->get( Site_Lifecycle::class );

		$this->assertNotFalse( has_action( 'wp_initialize_site', array( $lifecycle, 'initialize_site' ) ) );
	}

	/**
	 * Core does not drop plugin tables; we have to.
	 *
	 * @return void
	 */
	public function test_uninitialize_site_is_hooked(): void {
		$lifecycle = Plugin::instance()->container()->get( Site_Lifecycle::class );

		$this->assertNotFalse( has_action( 'wp_uninitialize_site', array( $lifecycle, 'uninitialize_site' ) ) );
	}
}
