<?php
/**
 * Native delivery request classification.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Workflow;

use Aggressive\Ads\Workflow\Delivery_Request;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Cooperative browser signals improve reporting without becoming an abuse
 * boundary.
 */
final class DeliveryRequestTest extends TestCase {

	/**
	 * Restore server state after every request-classification example.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		unset( $_SERVER['HTTP_PURPOSE'], $_SERVER['HTTP_SEC_PURPOSE'], $_SERVER['HTTP_USER_AGENT'] );

		parent::tear_down();
	}

	/**
	 * Both current and legacy browser headers identify speculative fetches.
	 *
	 * @return void
	 */
	public function test_prefetch_headers_are_recognized_case_insensitively(): void {
		$this->assertFalse( Delivery_Request::is_prefetch() );

		$_SERVER['HTTP_SEC_PURPOSE'] = 'PrEfEtCh';
		$this->assertTrue( Delivery_Request::is_prefetch() );

		unset( $_SERVER['HTTP_SEC_PURPOSE'] );
		$_SERVER['HTTP_PURPOSE'] = 'prefetch;prerender';
		$this->assertTrue( Delivery_Request::is_prefetch() );
	}

	/**
	 * Known crawlers are excluded, while ordinary and malformed agents remain
	 * eligible because the allowlist is intentionally conservative.
	 *
	 * @return void
	 */
	public function test_only_known_bot_tokens_are_classified_as_nonhuman(): void {
		$this->assertFalse( Delivery_Request::is_obvious_bot() );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; GoogleBot/2.1)';
		$this->assertTrue( Delivery_Request::is_obvious_bot() );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Firefox/128.0';
		$this->assertFalse( Delivery_Request::is_obvious_bot() );

		$_SERVER['HTTP_USER_AGENT'] = array( 'googlebot' );
		$this->assertFalse( Delivery_Request::is_obvious_bot() );
	}
}
