<?php
/**
 * Private creative Site Health verification.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Security\Private_Storage_Health;
use Aggressive\Ads\Storage\Private_Storage;
use WP_UnitTestCase;

/**
 * The health check requests a real probe and removes it afterwards.
 */
final class PrivateStorageHealthTest extends WP_UnitTestCase {

	/**
	 * Subject.
	 *
	 * @var Private_Storage_Health
	 */
	private Private_Storage_Health $health;

	/**
	 * Storage.
	 *
	 * @var Private_Storage
	 */
	private Private_Storage $storage;

	/**
	 * Resolves services.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$container     = Plugin::instance()->container();
		$this->health  = $container->get( Private_Storage_Health::class );
		$this->storage = $container->get( Private_Storage::class );
	}

	/**
	 * The hook is attached and keeps existing tests.
	 *
	 * @return void
	 */
	public function test_site_health_test_is_registered(): void {
		$this->assertNotFalse( has_filter( 'site_status_tests', array( $this->health, 'register_test' ) ) );

		$tests  = array( 'direct' => array( 'core_test' => array( 'test' => '__return_true' ) ) );
		$result = $this->health->register_test( $tests );

		$this->assertArrayHasKey( 'core_test', $result['direct'] );
		$this->assertArrayHasKey( 'aggr_private_storage', $result['direct'] );
	}

	/**
	 * A denied probe is healthy and the probe never remains on disk.
	 *
	 * @return void
	 */
	public function test_denied_probe_is_good_and_cleaned_up(): void {
		$requested = '';
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$requested ) {
				$requested = (string) $url;

				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$result = $this->health->run_test();
		$probe  = basename( (string) wp_parse_url( $requested, PHP_URL_PATH ) );

		$this->assertSame( 'good', $result['status'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}\.png$/', $probe );
		$this->assertFileDoesNotExist( $this->storage->root() . '/' . $probe );
	}

	/**
	 * A successful direct fetch is a critical result.
	 *
	 * @return void
	 */
	public function test_public_probe_is_critical(): void {
		$requested = '';
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$requested ) {
				$requested = (string) $url;

				return array(
					'headers'  => array(),
					'body'     => 'probe',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$result = $this->health->run_test();
		$probe  = basename( (string) wp_parse_url( $requested, PHP_URL_PATH ) );

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'aggr-private', (string) $result['actions'] );
		$this->assertFileDoesNotExist( $this->storage->root() . '/' . $probe );
	}
}
