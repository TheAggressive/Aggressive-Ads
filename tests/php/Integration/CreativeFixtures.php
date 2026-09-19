<?php
/**
 * Records and files the creative workflow tests build on.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Storage\Private_Storage;

/**
 * An organization, placements, a campaign and images to upload to it.
 *
 * Three test classes built these by hand, each slightly differently. A
 * fixture that differs between suites is how two suites come to test two
 * different things under one name.
 */
trait CreativeFixtures {

	/**
	 * Temporary files handed to uploads, removed in tear_down.
	 *
	 * @var array<int, string>
	 */
	private array $temporary = array();

	/**
	 * An organization owned by one advertiser.
	 *
	 * @param int $owner Owner user id.
	 * @return int
	 */
	private function org( int $owner ): int {
		$org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $org_id, Org_Repository::META_OWNER_USER, $owner );

		return $org_id;
	}

	/**
	 * An active placement.
	 *
	 * @param string $name Title.
	 * @param string $size Size, e.g. 728x90.
	 * @return int
	 */
	private function placement( string $name, string $size ): int {
		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);
		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, $size );

		return $placement_id;
	}

	/**
	 * A draft campaign on the given placements.
	 *
	 * @param int             $owner      Author.
	 * @param int             $org_id     Organization.
	 * @param array<int, int> $placements Placement ids.
	 * @return int
	 */
	private function campaign( int $owner, int $org_id, array $placements ): int {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
				'post_author' => $owner,
			)
		);
		update_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, $org_id );

		foreach ( $placements as $placement_id ) {
			add_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID, $placement_id );
		}

		return $campaign_id;
	}

	/**
	 * A PNG as an upload presents it, whose bytes depend on `$variant`.
	 *
	 * Two variants never share a checksum, and the same variant always does —
	 * which is what the same-file tests turn on.
	 *
	 * @param int $width   Width.
	 * @param int $height  Height.
	 * @param int $variant Distinguishing pixel.
	 * @return array<string, mixed>
	 */
	private function image_file( int $width, int $height, int $variant ): array {
		$image = imagecreatetruecolor( $width, $height );
		imagesetpixel( $image, $variant % $width, 0, (int) imagecolorallocate( $image, 255, 255, 255 ) );
		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();
		$path  = wp_tempnam( 'aggr-creative-fixture' );
		file_put_contents( $path, $bytes );
		$this->temporary[] = $path;

		return array(
			'name'     => 'creative.png',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $bytes ),
		);
	}

	/**
	 * Removes a campaign's stored creative files and every temporary file.
	 *
	 * Private storage lives outside the database the test rolls back, so a
	 * file left there outlives the test that made it.
	 *
	 * @param int $campaign_id Campaign whose creatives' files to remove.
	 * @return void
	 */
	private function remove_creative_files( int $campaign_id ): void {
		$creatives = Plugin::instance()->container()->get( Creative_Repository::class );
		$storage   = Plugin::instance()->container()->get( Private_Storage::class );

		foreach ( $creatives->ids_for_campaign( $campaign_id ) as $creative_id ) {
			$stored = $creatives->storage_details( $creative_id );

			if ( null !== $stored && '' !== $stored['path'] ) {
				$storage->delete( $stored['path'] );
			}
		}

		foreach ( $this->temporary as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}
}
