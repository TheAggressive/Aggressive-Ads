<?php
/**
 * Native-delivery production dependency checks.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Security\Delivery_Health;
use Aggressive\Ads\Workflow\Event_Retention;
use Aggressive\Ads\Workflow\Rollup_Reconciler;
use WP_UnitTestCase;

/** Site Health exposes the cache and maintenance requirements. */
final class DeliveryHealthTest extends WP_UnitTestCase {

	/**
	 * Health service under test.
	 *
	 * @var Delivery_Health
	 */
	private Delivery_Health $health;

	/** Resolves the hooked health service. */
	public function set_up(): void {
		parent::set_up();
		$this->health = Plugin::instance()->container()->get( Delivery_Health::class );
	}

	/** The test is wired without replacing core tests. */
	public function test_delivery_health_is_registered(): void {
		$this->assertNotFalse( has_filter( 'site_status_tests', array( $this->health, 'register_test' ) ) );

		$tests  = array( 'direct' => array( 'core_test' => array( 'test' => '__return_true' ) ) );
		$result = $this->health->register_test( $tests );

		$this->assertArrayHasKey( 'core_test', $result['direct'] );
		$this->assertArrayHasKey( 'aggr_delivery_capacity', $result['direct'] );
	}

	/** Missing persistent cache is visible before a high-volume launch. */
	public function test_missing_persistent_cache_is_recommended(): void {
		$external = wp_using_ext_object_cache();
		wp_using_ext_object_cache( false );

		try {
			$result = $this->health->run_test();
		} finally {
			wp_using_ext_object_cache( $external );
		}

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'Redis or Memcached', (string) $result['description'] );
	}

	/** A representative 1,000-id cache item and both cron jobs pass together. */
	public function test_persistent_cache_capacity_and_maintenance_are_good(): void {
		$container = Plugin::instance()->container();
		$container->get( Rollup_Reconciler::class )->ensure_scheduled();
		$container->get( Event_Retention::class )->ensure_scheduled();

		$external = wp_using_ext_object_cache();
		wp_using_ext_object_cache( true );

		try {
			$result = $this->health->run_test();
		} finally {
			wp_using_ext_object_cache( $external );
		}

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( '1,000-creative', (string) $result['description'] );
	}
}
