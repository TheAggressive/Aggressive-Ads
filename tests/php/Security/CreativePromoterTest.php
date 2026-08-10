<?php
/**
 * Promotion from private storage into the Media Library.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Security;

use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Repository\Creative_Repository;
use LAAO_Advertiser_Portal\Storage\Private_Storage;
use LAAO_Advertiser_Portal\Workflow\Creative_Promoter;
use LAAO_Advertiser_Portal\Workflow\Creative_Uploader;
use WP_Error;
use WP_UnitTestCase;

/**
 * The checkpoint where reviewed bytes become published bytes.
 *
 * Promotion happens only at approval, and the sha256 re-verification is what
 * makes "approved" describe an artifact rather than a moment.
 */
final class CreativePromoterTest extends WP_UnitTestCase {

	/**
	 * The subject.
	 *
	 * @var Creative_Promoter
	 */
	private Creative_Promoter $promoter;

	/**
	 * Private storage.
	 *
	 * @var Private_Storage
	 */
	private Private_Storage $storage;

	/**
	 * Creative persistence.
	 *
	 * @var Creative_Repository
	 */
	private Creative_Repository $creatives;

	/**
	 * Temporary files to clean up.
	 *
	 * @var array<int, string>
	 */
	private array $temporary = array();

	/**
	 * Builds the subject.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->storage   = new Private_Storage();
		$this->creatives = new Creative_Repository();
		$this->promoter  = new Creative_Promoter( $this->creatives, $this->storage );
	}

	/**
	 * Removes temporary files.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->temporary as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}

		$this->temporary = array();

		parent::tear_down();
	}

	/**
	 * Creates a creative with a real uploaded file behind it.
	 *
	 * @param string $alt_text Alternative text to record.
	 * @return int
	 */
	private function creative_with_file( string $alt_text = 'A poster for the spring season' ): int {
		$image = imagecreatetruecolor( 24, 12 );

		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();

		$temp = wp_tempnam( 'laao-ads-promote' );
		file_put_contents( $temp, $bytes );
		$this->temporary[] = $temp;

		$uploader = new Creative_Uploader( $this->storage );
		$accepted = $uploader->accept(
			array(
				'name'     => 'poster.png',
				'tmp_name' => $temp,
				'error'    => UPLOAD_ERR_OK,
				'size'     => strlen( $bytes ),
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $accepted );

		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		$this->creatives->record_upload( $creative_id, $accepted );
		update_post_meta( $creative_id, Creative_Repository::META_ALT_TEXT, $alt_text );

		return $creative_id;
	}

	/**
	 * A reviewed creative becomes an attachment.
	 *
	 * @return void
	 */
	public function test_a_reviewed_creative_is_promoted(): void {
		$creative_id = $this->creative_with_file();

		$attachment_id = $this->promoter->promote( $creative_id );

		$this->assertIsInt( $attachment_id );
		$this->assertSame( 'attachment', get_post_type( $attachment_id ) );
		$this->assertSame( $attachment_id, $this->creatives->attachment_id( $creative_id ) );
	}

	/**
	 * The advertiser's alt text travels with the image.
	 *
	 * This closes the gap the LAAO theme currently patches at render time,
	 * where three front-page ads had no alt text at all. See
	 * docs/accessibility.md.
	 *
	 * @return void
	 */
	public function test_alt_text_travels_to_the_attachment(): void {
		$creative_id   = $this->creative_with_file( 'Spring season: three plays in repertory' );
		$attachment_id = $this->promoter->promote( $creative_id );

		$this->assertIsInt( $attachment_id );
		$this->assertSame(
			'Spring season: three plays in repertory',
			get_post_meta( $attachment_id, '_wp_attachment_image_alt', true )
		);
	}

	/**
	 * **A file changed after review is not published.**
	 *
	 * Without the checksum, "approved" describes the moment somebody clicked
	 * rather than the bytes they looked at, and swapping the file between
	 * review and publication would go unnoticed.
	 *
	 * @return void
	 */
	public function test_a_file_changed_after_review_is_refused(): void {
		$creative_id = $this->creative_with_file();

		$stored = $this->storage->resolve(
			(string) get_post_meta( $creative_id, Creative_Repository::META_PRIVATE_PATH, true )
		);

		$this->assertNotNull( $stored );

		$swap = imagecreatetruecolor( 40, 40 );
		ob_start();
		imagepng( $swap );
		file_put_contents( $stored, (string) ob_get_clean() );

		$result = $this->promoter->promote( $creative_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'laao_ads_creative_changed', $result->get_error_code() );
		$this->assertSame( 0, $this->creatives->attachment_id( $creative_id ) );
	}

	/**
	 * Promotion is idempotent, so a retried approval does not fill the Media
	 * Library with duplicates.
	 *
	 * @return void
	 */
	public function test_promoting_twice_reuses_the_attachment(): void {
		$creative_id = $this->creative_with_file();

		$first  = $this->promoter->promote( $creative_id );
		$second = $this->promoter->promote( $creative_id );

		$this->assertIsInt( $first );
		$this->assertSame( $first, $second );
	}

	/**
	 * A creative with no stored file cannot be promoted.
	 *
	 * @return void
	 */
	public function test_a_creative_without_a_file_is_refused(): void {
		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		$result = $this->promoter->promote( $creative_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'laao_ads_creative_file_missing', $result->get_error_code() );
	}

	/**
	 * A creative whose file has been deleted is refused rather than published
	 * with nothing behind it.
	 *
	 * @return void
	 */
	public function test_a_creative_whose_file_vanished_is_refused(): void {
		$creative_id = $this->creative_with_file();
		$relative    = (string) get_post_meta( $creative_id, Creative_Repository::META_PRIVATE_PATH, true );

		$this->assertTrue( $this->storage->delete( $relative ) );

		$result = $this->promoter->promote( $creative_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'laao_ads_creative_file_missing', $result->get_error_code() );
	}

	/**
	 * The promoted attachment is a real image WordPress can work with, not
	 * just a row in the database.
	 *
	 * @return void
	 */
	public function test_the_attachment_has_usable_metadata(): void {
		$creative_id   = $this->creative_with_file();
		$attachment_id = $this->promoter->promote( $creative_id );

		$this->assertIsInt( $attachment_id );

		$metadata = wp_get_attachment_metadata( $attachment_id );

		$this->assertIsArray( $metadata );
		$this->assertSame( 24, $metadata['width'] );
		$this->assertSame( 12, $metadata['height'] );
	}
}
