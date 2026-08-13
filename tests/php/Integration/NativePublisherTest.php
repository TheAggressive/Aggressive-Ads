<?php
/**
 * Native publisher against real WordPress.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Integration\Native\Publisher;
use Aggressive\Ads\Plugin;
use WP_UnitTestCase;

/**
 * Publish is a cache bust. There is no downstream ads CPT.
 */
final class NativePublisherTest extends WP_UnitTestCase {

	/**
	 * Approval's publish effect succeeds without creating a provider post.
	 *
	 * @return void
	 */
	public function test_publish_returns_a_complete_empty_result(): void {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => 'draft',
			)
		);

		$result = Plugin::instance()->container()->get( Publisher::class )->publish_campaign( $campaign_id );

		$this->assertTrue( $result->is_complete() );
		$this->assertSame( array(), $result->ad_ids() );
		$this->assertFalse( post_type_exists( 'ads' ) );
	}
}
