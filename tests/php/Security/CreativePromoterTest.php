<?php
/**
 * Promotion from private storage into the Media Library.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Security;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Repository\Creative_Attachment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Workflow\Creative_Promoter;
use Aggressive\Ads\Workflow\Creative_Uploader;
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
	 * Media Library copy of the artwork.
	 *
	 * @var Creative_Attachment_Repository
	 */
	private Creative_Attachment_Repository $attachments;

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

		$this->storage     = new Private_Storage();
		$this->attachments = new Creative_Attachment_Repository();
		$this->creatives   = new Creative_Repository( $this->attachments );
		$this->promoter    = new Creative_Promoter( $this->creatives, $this->attachments, $this->storage );
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

		$temp = wp_tempnam( 'aggr-promote' );
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
		$this->assertSame( $attachment_id, $this->attachments->attachment_id( $creative_id ) );
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
		$this->assertSame( 'aggr_creative_changed', $result->get_error_code() );
		$this->assertSame( 0, $this->attachments->attachment_id( $creative_id ) );
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
	 * The private original is gone once the public copy exists.
	 *
	 * Private storage sits under wp-content/uploads and is web-reachable on any
	 * server that ignores .htaccess, so an approved creative left there is a
	 * second copy exposed for the campaign's whole life. Delivery uses the
	 * attachment; the original has no reader left.
	 *
	 * @return void
	 */
	public function test_the_private_original_is_removed_after_promotion(): void {
		$creative_id = $this->creative_with_file();
		$details     = $this->creatives->storage_details( $creative_id );

		$this->assertIsArray( $details );
		$this->assertNotSame( '', $details['path'] );
		$this->assertNotNull( $this->storage->resolve( $details['path'] ), 'Fixture must have a real file before promotion.' );

		$attachment_id = $this->promoter->promote( $creative_id );

		$this->assertIsInt( $attachment_id );
		$this->assertNull( $this->storage->resolve( $details['path'] ), 'The private original must not survive promotion.' );
	}

	/**
	 * The pointer goes with the bytes.
	 *
	 * A path left behind would have the retention sweep rediscovering a file
	 * that is not there, and Campaign_Copier preferring a private original that
	 * no longer exists over the attachment that does.
	 *
	 * @return void
	 */
	public function test_the_private_pointer_is_cleared_after_promotion(): void {
		$creative_id = $this->creative_with_file();

		$this->promoter->promote( $creative_id );

		$details = $this->creatives->storage_details( $creative_id );

		$this->assertTrue( null === $details || '' === $details['path'] );
	}

	/**
	 * Promotion still succeeds when the private file is already gone.
	 *
	 * @return void
	 */
	public function test_promoting_twice_does_not_fail_on_the_missing_original(): void {
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
		$this->assertSame( 'aggr_creative_file_missing', $result->get_error_code() );
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
		$this->assertSame( 'aggr_creative_file_missing', $result->get_error_code() );
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

	/**
	 * A failed creative-to-attachment link removes the unreferenced attachment.
	 *
	 * @return void
	 */
	public function test_a_failed_attachment_link_is_compensated(): void {
		$creative_id = $this->creative_with_file();
		$before      = wp_count_posts( 'attachment' )->inherit;
		$fail_link   = static fn ( $check, int $object_id, string $meta_key ) => Creative_Repository::META_ATTACHMENT_ID === $meta_key ? false : $check;

		add_filter( 'update_post_metadata', $fail_link, 10, 3 );

		try {
			$result = $this->promoter->promote( $creative_id );
		} finally {
			remove_filter( 'update_post_metadata', $fail_link, 10 );
		}

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_creative_link_failed', $result->get_error_code() );
		$this->assertSame( $before, wp_count_posts( 'attachment' )->inherit );
	}

	/**
	 * Attachment insertion failure removes the already-copied public file.
	 *
	 * @return void
	 */
	public function test_failed_attachment_insertion_removes_the_public_copy(): void {
		$creative_id = $this->creative_with_file();
		$uploads     = wp_upload_dir();
		$before      = glob( trailingslashit( $uploads['path'] ) . '*' );
		$before      = is_array( $before ) ? $before : array();
		$reject      = static fn ( bool $is_empty, array $post ): bool => 'attachment' === ( $post['post_type'] ?? '' ) ? true : $is_empty;

		add_filter( 'wp_insert_post_empty_content', $reject, 10, 2 );

		try {
			$result = $this->promoter->promote( $creative_id );
		} finally {
			remove_filter( 'wp_insert_post_empty_content', $reject, 10 );
		}

		$after = glob( trailingslashit( $uploads['path'] ) . '*' );
		$after = is_array( $after ) ? $after : array();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $before, $after );
	}
}
