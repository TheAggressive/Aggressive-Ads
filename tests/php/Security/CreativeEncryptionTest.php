<?php
/**
 * Encryption at rest for creative awaiting review.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Security;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Repository\Creative_Attachment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Storage\Creative_Cipher;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Workflow\Creative_Promoter;
use Aggressive\Ads\Workflow\Creative_Uploader;
use WP_Error;
use WP_UnitTestCase;

/**
 * The bytes on disk, which is the only thing encryption is about.
 *
 * Every other creative test goes through the storage API and would pass
 * whether or not anything was ever encrypted — they ask storage for the file
 * and storage hands back plaintext either way. These read the file the way an
 * attacker would: off the filesystem, without asking.
 */
final class CreativeEncryptionTest extends WP_UnitTestCase {

	/**
	 * Private storage.
	 *
	 * @var Private_Storage
	 */
	private Private_Storage $storage;

	/**
	 * The cipher, for format-level assertions.
	 *
	 * @var Creative_Cipher
	 */
	private Creative_Cipher $cipher;

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
	 * Stored relative paths to clean up.
	 *
	 * @var array<int, string>
	 */
	private array $stored = array();

	/**
	 * Builds the subject.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->cipher      = new Creative_Cipher();
		$this->storage     = new Private_Storage( $this->cipher );
		$this->attachments = new Creative_Attachment_Repository();
		$this->creatives   = new Creative_Repository( $this->attachments );
	}

	/**
	 * Removes everything this test wrote.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->temporary as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}

		foreach ( $this->stored as $relative ) {
			$this->storage->delete( $relative );
		}

		$this->temporary = array();
		$this->stored    = array();

		parent::tear_down();
	}

	/**
	 * PNG bytes that are recognisably an image and long enough to matter.
	 *
	 * @param int $width  Image width.
	 * @param int $height Image height.
	 * @return string
	 */
	private function png( int $width = 24, int $height = 12 ): string {
		$image = imagecreatetruecolor( $width, $height );

		// Noise, not a blank canvas. A blank truecolor image compresses to a few
		// hundred bytes at any size, so a fixture built from one never crosses
		// the 64 KiB chunk boundary it was made large in order to cross — which
		// is how the multi-chunk test first passed while testing one chunk.
		for ( $x = 0; $x < $width; $x++ ) {
			for ( $y = 0; $y < $height; $y++ ) {
				imagesetpixel( $image, $x, $y, wp_rand( 0, 0xffffff ) );
			}
		}

		ob_start();
		imagepng( $image );

		return (string) ob_get_clean();
	}

	/**
	 * Writes bytes to a temporary file this test will clean up.
	 *
	 * @param string $bytes Contents.
	 * @return string Absolute path.
	 */
	private function temp_file( string $bytes ): string {
		$path = wp_tempnam( 'aggr-encryption' );

		file_put_contents( $path, $bytes );

		$this->temporary[] = $path;

		return $path;
	}

	/**
	 * Stores bytes through the real upload path.
	 *
	 * @param string $bytes PNG contents.
	 * @return array{path: string, token: string, sha256: string, bytes: int, mime: string, width: int, height: int, name: string}
	 */
	private function upload( string $bytes ): array {
		$uploader = new Creative_Uploader( $this->storage );

		$accepted = $uploader->accept(
			array(
				'name'     => 'poster.png',
				'tmp_name' => $this->temp_file( $bytes ),
				'error'    => UPLOAD_ERR_OK,
				'size'     => strlen( $bytes ),
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $accepted );
		$this->assertIsArray( $accepted );

		$this->stored[] = $accepted['path'];

		return $accepted;
	}

	/**
	 * The absolute path of a stored file, asserted to exist first.
	 *
	 * @param string $relative Stored relative path.
	 * @return string
	 */
	private function on_disk( string $relative ): string {
		$path = $this->storage->resolve( $relative );

		$this->assertIsString( $path, 'The fixture must actually be on disk before anything is asserted about it.' );

		return (string) $path;
	}

	/**
	 * **The uploaded bytes are not what is on disk.**
	 *
	 * The whole point, and the assertion every other creative test is missing.
	 * A regression that stopped encrypting would leave every one of them green.
	 *
	 * @return void
	 */
	public function test_an_uploaded_creative_is_not_readable_from_the_filesystem(): void {
		$bytes    = $this->png();
		$accepted = $this->upload( $bytes );
		$raw      = (string) file_get_contents( $this->on_disk( $accepted['path'] ) );

		$this->assertNotSame( $bytes, $raw );
		$this->assertStringStartsWith( Creative_Cipher::MAGIC, $raw );

		// Not merely different — none of the plaintext survives. A format that
		// encrypted only part of the file would pass the comparison above.
		$this->assertStringNotContainsString( substr( $bytes, 8, 32 ), $raw );
		$this->assertStringNotContainsString( "\x89PNG", $raw );
	}

	/**
	 * The recorded digest describes the upload, not the ciphertext.
	 *
	 * Hashing the stored file instead would record a digest that matches
	 * nothing the advertiser sent, and the promoter's re-verification — the
	 * check that makes approval describe an artifact — would be comparing the
	 * ciphertext against itself.
	 *
	 * @return void
	 */
	public function test_the_recorded_checksum_and_size_describe_the_plaintext(): void {
		$bytes    = $this->png();
		$accepted = $this->upload( $bytes );

		$this->assertSame( hash( 'sha256', $bytes ), $accepted['sha256'] );
		$this->assertSame( strlen( $bytes ), $accepted['bytes'] );
		$this->assertNotSame( strlen( $bytes ), filesize( $this->on_disk( $accepted['path'] ) ) );
		$this->assertTrue( $this->storage->matches_checksum( $accepted['path'], $accepted['sha256'] ) );
	}

	/**
	 * Reads come back byte-identical, across the chunk boundary.
	 *
	 * 64 KiB is the chunk size, so a creative larger than one chunk is the case
	 * where tag handling and chunk ordering can go wrong without a small
	 * fixture ever noticing.
	 *
	 * @return void
	 */
	public function test_a_multi_chunk_creative_round_trips_exactly(): void {
		$bytes = $this->png( 400, 400 );

		$this->assertGreaterThan( 65536, strlen( $bytes ), 'The fixture must exceed one chunk to be testing anything.' );

		$accepted = $this->upload( $bytes );
		$exported = $this->storage->export( $accepted['path'] );

		$this->assertIsString( $exported );
		$this->temporary[] = (string) $exported;

		$this->assertSame( $bytes, (string) file_get_contents( (string) $exported ) );
		$this->assertSame( strlen( $bytes ), $this->storage->plaintext_bytes( $accepted['path'] ) );
	}

	/**
	 * **A tampered file is refused, not served.**
	 *
	 * Authentication is the half of encryption that matters here: private
	 * storage lives under uploads, and anything that can write there could
	 * otherwise substitute artwork that a reviewer approves and a publisher
	 * ships.
	 *
	 * @return void
	 */
	public function test_a_tampered_file_cannot_be_read_at_all(): void {
		$accepted = $this->upload( $this->png() );
		$path     = $this->on_disk( $accepted['path'] );
		$raw      = (string) file_get_contents( $path );

		// A byte inside the ciphertext body, past the 48-byte header.
		$offset         = 80;
		$raw[ $offset ] = chr( ord( $raw[ $offset ] ) ^ 0xff );

		file_put_contents( $path, $raw );

		$this->assertNull( $this->storage->checksum( $accepted['path'] ) );
		$this->assertNull( $this->storage->export( $accepted['path'] ) );
		$this->assertFalse( $this->storage->matches_checksum( $accepted['path'], $accepted['sha256'] ) );
	}

	/**
	 * A file cut in the middle of a chunk is refused.
	 *
	 * This is the easy half of truncation — the final chunk is incomplete, so
	 * the read runs out of ciphertext. It is here as the companion to the
	 * test below, which covers the case that is genuinely dangerous.
	 *
	 * @return void
	 */
	public function test_a_file_cut_mid_chunk_is_refused(): void {
		$bytes    = $this->png( 400, 400 );
		$accepted = $this->upload( $bytes );
		$path     = $this->on_disk( $accepted['path'] );
		$raw      = (string) file_get_contents( $path );

		file_put_contents( $path, substr( $raw, 0, intdiv( strlen( $raw ), 2 ) ) );

		$this->assertNull( $this->storage->checksum( $accepted['path'] ) );
	}

	/**
	 * **A file cut exactly on a chunk boundary is refused.**
	 *
	 * The dangerous one, and the reason the last chunk carries a FINAL tag.
	 * Every surviving chunk here is whole and authenticates perfectly: without
	 * the tag, the read reaches a clean end of file and reports success,
	 * handing a reviewer the top third of an image with nothing anywhere to
	 * say the rest was ever there.
	 *
	 * Cutting at the halfway mark does **not** test this — it lands inside a
	 * chunk, where the read fails for the unrelated reason that the
	 * ciphertext ran out. That version of this test passed with the FINAL-tag
	 * check deleted.
	 *
	 * @return void
	 */
	public function test_a_file_cut_on_a_chunk_boundary_is_refused(): void {
		$bytes    = $this->png( 400, 400 );
		$accepted = $this->upload( $bytes );
		$path     = $this->on_disk( $accepted['path'] );
		$raw      = (string) file_get_contents( $path );

		// 48-byte header, then one whole record: a 4-byte length plus a 64 KiB
		// chunk and its authentication tag.
		$boundary = 48 + 4 + 65536 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

		$this->assertGreaterThan(
			$boundary,
			strlen( $raw ),
			'The fixture must be longer than one chunk, or nothing is being cut off.'
		);

		file_put_contents( $path, substr( $raw, 0, $boundary ) );

		$this->assertNull( $this->storage->checksum( $accepted['path'] ) );
		$this->assertNull( $this->storage->export( $accepted['path'] ) );
	}

	/**
	 * A creative encrypted under another key is a named failure.
	 *
	 * @return void
	 */
	public function test_a_file_written_under_a_different_key_is_refused(): void {
		$accepted = $this->upload( $this->png() );
		$path     = $this->on_disk( $accepted['path'] );
		$raw      = (string) file_get_contents( $path );

		// Replace only the fingerprint, leaving a structurally valid file.
		file_put_contents( $path, substr( $raw, 0, 8 ) . str_repeat( "\x00", 8 ) . substr( $raw, 16 ) );

		$this->assertNull( $this->storage->checksum( $accepted['path'] ) );
	}

	/**
	 * Promotion publishes the plaintext, under the right extension.
	 *
	 * The sideload reads a decrypted temporary file whose name ends in .tmp.
	 * Naming the Media Library copy after it would publish every creative with
	 * an extension that does not match its bytes.
	 *
	 * @return void
	 */
	public function test_promotion_publishes_decrypted_bytes_with_the_stored_extension(): void {
		$bytes    = $this->png();
		$accepted = $this->upload( $bytes );

		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		$this->creatives->record_upload( $creative_id, $accepted );

		$promoter      = new Creative_Promoter( $this->creatives, $this->attachments, $this->storage );
		$attachment_id = $promoter->promote( $creative_id );

		$this->assertIsInt( $attachment_id );

		$file = get_attached_file( (int) $attachment_id );

		$this->assertIsString( $file );
		$this->assertSame( 'png', strtolower( (string) pathinfo( (string) $file, PATHINFO_EXTENSION ) ) );
		$this->assertSame( $bytes, (string) file_get_contents( (string) $file ) );

		wp_delete_attachment( (int) $attachment_id, true );
	}

	/**
	 * A creative whose bytes cannot be read is not reported as changed.
	 *
	 * "Somebody replaced the artwork" and "the file cannot be decrypted" send a
	 * reviewer looking in completely different places, and only one of them is
	 * something the advertiser can fix.
	 *
	 * @return void
	 */
	public function test_an_unreadable_creative_is_reported_as_unreadable(): void {
		$accepted = $this->upload( $this->png() );
		$path     = $this->on_disk( $accepted['path'] );
		$raw      = (string) file_get_contents( $path );

		file_put_contents( $path, substr( $raw, 0, 60 ) );

		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		$this->creatives->record_upload( $creative_id, $accepted );

		$promoter = new Creative_Promoter( $this->creatives, $this->attachments, $this->storage );
		$result   = $promoter->promote( $creative_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_creative_unreadable', $result->get_error_code() );
	}

	/**
	 * The export is a temporary file the caller owns, and it is real plaintext.
	 *
	 * @return void
	 */
	public function test_export_produces_plaintext_outside_the_private_root(): void {
		$bytes    = $this->png();
		$accepted = $this->upload( $bytes );
		$exported = $this->storage->export( $accepted['path'] );

		$this->assertIsString( $exported );
		$this->temporary[] = (string) $exported;

		$this->assertSame( $bytes, (string) file_get_contents( (string) $exported ) );
		$this->assertStringNotContainsString( Private_Storage::DIRECTORY, (string) $exported );
	}

	/**
	 * Legacy plaintext keeps working until the migration reaches it.
	 *
	 * An install that predates encryption has a directory full of plaintext,
	 * and the review queue has to keep working while the migration runs — and
	 * has to keep working if the migration never finishes.
	 *
	 * @return void
	 */
	public function test_a_legacy_plaintext_file_is_still_readable(): void {
		$bytes    = $this->png();
		$relative = 'legacy-' . wp_generate_uuid4() . '.png';

		$this->storage->ensure();
		file_put_contents( $this->storage->root() . '/' . $relative, $bytes );
		$this->stored[] = $relative;

		$this->assertSame( hash( 'sha256', $bytes ), $this->storage->checksum( $relative ) );
		$this->assertSame( strlen( $bytes ), $this->storage->plaintext_bytes( $relative ) );
	}

	/**
	 * **The migration encrypts what is there, and destroys nothing.**
	 *
	 * Asserted by count as well as by content: "the file is encrypted" would
	 * pass whether it converted one file or emptied the directory, and this is
	 * a migration that runs unattended over an advertiser's only copy of
	 * artwork nobody has approved yet.
	 *
	 * @return void
	 */
	public function test_the_migration_encrypts_legacy_files_and_leaves_the_rest_alone(): void {
		$this->storage->ensure();

		$root   = $this->storage->root();
		$legacy = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$bytes    = $this->png( 24 + $i, 12 );
			$relative = 'legacy-' . wp_generate_uuid4() . '.png';

			file_put_contents( $root . '/' . $relative, $bytes );

			$legacy[ $relative ] = $bytes;
			$this->stored[]      = $relative;
		}

		// One already-encrypted file, which the migration must skip rather
		// than encrypt a second time.
		$already = $this->upload( $this->png( 30, 15 ) );

		$before = count( (array) scandir( $root ) );

		// Counted rather than assumed to be three. Other tests in the same
		// process share this directory — the database rolls back between tests
		// and the filesystem does not — so asserting a bare 3 makes the test
		// pass or fail on what ran before it.
		$plaintext = 0;

		foreach ( (array) scandir( $root ) as $name ) {
			$name = (string) $name;

			if ( '.' === $name || '..' === $name || in_array( $name, Private_Storage::DENY_FILES, true ) ) {
				continue;
			}

			if ( is_file( $root . '/' . $name ) && ! $this->cipher->is_encrypted( $root . '/' . $name ) ) {
				++$plaintext;
			}
		}

		$this->assertGreaterThanOrEqual( 3, $plaintext, 'The three fixtures must be on disk as plaintext before the migration runs.' );
		$this->assertSame( $plaintext, $this->storage->encrypt_existing_files() );

		$this->assertSame(
			$before,
			count( (array) scandir( $root ) ),
			'The migration must not add or remove files: it replaces each in place.'
		);

		foreach ( $legacy as $relative => $bytes ) {
			$path = $this->on_disk( $relative );

			$this->assertTrue( $this->cipher->is_encrypted( $path ) );
			$this->assertNotSame( $bytes, (string) file_get_contents( $path ) );
			$this->assertSame( hash( 'sha256', $bytes ), $this->storage->checksum( $relative ) );
		}

		// Idempotent, and the second run must find nothing left to do.
		$this->assertSame( 0, $this->storage->encrypt_existing_files() );

		// The file that was already encrypted still reads.
		$this->assertTrue( $this->storage->matches_checksum( $already['path'], $already['sha256'] ) );
	}

	/**
	 * The migration leaves the deny files exactly as it found them.
	 *
	 * They are not creative, and encrypting `index.php` would turn the
	 * directory's own listing guard into an unreadable blob.
	 *
	 * @return void
	 */
	public function test_the_migration_does_not_touch_the_deny_files(): void {
		$this->storage->ensure();

		$root   = $this->storage->root();
		$before = array();

		foreach ( Private_Storage::DENY_FILES as $name ) {
			$this->assertFileExists( $root . '/' . $name );

			$before[ $name ] = (string) file_get_contents( $root . '/' . $name );
		}

		$this->assertCount( 3, $before, 'The fixture must find every deny file before asserting they survive.' );

		$this->storage->encrypt_existing_files();

		foreach ( $before as $name => $contents ) {
			$this->assertSame( $contents, (string) file_get_contents( $root . '/' . $name ) );
		}
	}

	/**
	 * **A file the migration cannot encrypt is left as plaintext, not replaced.**
	 *
	 * The failure mode has to be "still readable" rather than "now unreadable",
	 * because nobody is watching an unattended migration and the bytes cannot
	 * be rebuilt from anything else the site holds.
	 *
	 * Failure is induced through the key rather than through file permissions.
	 * The first version of this chmod'd the source to 0000, which works as a
	 * developer and does nothing at all in CI, where PHPUnit runs as root and
	 * root ignores the permission bits — the migration cheerfully encrypted the
	 * file and the test failed on the count. An unreadable *key* fails
	 * identically for every user, which is the point: the assertion is about
	 * what the migration does when encryption fails, not about how it failed.
	 *
	 * @return void
	 */
	public function test_a_file_that_cannot_be_encrypted_is_left_untouched(): void {
		$this->assertFalse(
			defined( Creative_Cipher::KEY_CONSTANT ),
			'This test induces failure through the stored key, which the constant would override.'
		);

		$this->storage->ensure();

		$bytes    = $this->png();
		$relative = 'legacy-' . wp_generate_uuid4() . '.png';
		$path     = $this->storage->root() . '/' . $relative;

		file_put_contents( $path, $bytes );
		$this->stored[] = $relative;

		// Not decodable as 32 bytes, so key() fails closed rather than
		// generating a replacement — add_option() cannot overwrite a row that
		// already exists.
		update_option( Creative_Cipher::KEY_OPTION, 'not-a-usable-key' );

		// Fresh instances: the cipher caches the resolved key per request.
		$cipher  = new Creative_Cipher();
		$storage = new Private_Storage( $cipher );

		$this->assertInstanceOf(
			WP_Error::class,
			$cipher->key(),
			'The fixture must actually break the key before anything is asserted about the migration.'
		);

		$encrypted = $storage->encrypt_existing_files();

		$this->assertSame( 0, $encrypted );
		$this->assertSame(
			$bytes,
			(string) file_get_contents( $path ),
			'The original must survive a failed migration byte for byte.'
		);
		$this->assertFalse(
			$cipher->is_encrypted( $path ),
			'A half-written encrypt must not be left where a reader would find it.'
		);

		// Nothing partially written alongside it either.
		$leftovers = glob( $this->storage->root() . '/*.aggr-encrypting-*' );

		$this->assertSame( array(), false === $leftovers ? array() : $leftovers );
	}
}
