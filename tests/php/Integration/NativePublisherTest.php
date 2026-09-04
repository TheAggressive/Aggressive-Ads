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
use Aggressive\Ads\Repository\Creative_Attachment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Workflow\Creative_Uploader;
use WP_Error;
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

	/**
	 * Publication promotes every private creative before reporting success.
	 *
	 * @return void
	 */
	public function test_publish_promotes_campaign_creatives(): void {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => 'draft',
			)
		);
		$container   = Plugin::instance()->container();
		$creatives   = $container->get( Creative_Repository::class );
		$attachments = $container->get( Creative_Attachment_Repository::class );
		$storage     = $container->get( Private_Storage::class );
		$creative_id = $creatives->create(
			$campaign_id,
			1,
			1,
			array(
				'kind'      => 'image',
				'click_url' => 'https://example.com/',
				'alt_text'  => 'Advertisement',
				'size'      => '24x12',
			)
		);

		$image = imagecreatetruecolor( 24, 12 );
		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();
		$temp  = wp_tempnam( 'aggr-native-publisher' );
		file_put_contents( $temp, $bytes );

		$accepted = ( new Creative_Uploader( $storage ) )->accept(
			array(
				'name'     => 'creative.png',
				'tmp_name' => $temp,
				'error'    => UPLOAD_ERR_OK,
				'size'     => strlen( $bytes ),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $accepted );
		$creatives->record_upload( $creative_id, $accepted );

		$result = $container->get( Publisher::class )->publish_campaign( $campaign_id );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $attachments->has_attachment( $creative_id ) );

		unlink( $temp );
		$storage->delete( $accepted['path'] );
	}
}
