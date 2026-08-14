<?php
/**
 * Upload security.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Security;

use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Workflow\Creative_Uploader;
use WP_Error;
use WP_UnitTestCase;

/**
 * Every uploaded file is hostile input.
 *
 * These assertions are about what the bytes actually are, because everything
 * the browser says — the MIME header, the extension, the filename — is chosen
 * by the caller and trivially wrong on purpose.
 */
final class CreativeUploadTest extends WP_UnitTestCase {

	/**
	 * The subject.
	 *
	 * @var Creative_Uploader
	 */
	private Creative_Uploader $uploader;

	/**
	 * Private storage.
	 *
	 * @var Private_Storage
	 */
	private Private_Storage $storage;

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

		$this->storage  = new Private_Storage();
		$this->uploader = new Creative_Uploader( $this->storage );
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
	 * Writes a temporary file and returns a $_FILES-shaped entry.
	 *
	 * @param string $contents File contents.
	 * @param string $name     Client-supplied filename.
	 * @return array<string, mixed>
	 */
	private function upload( string $contents, string $name ): array {
		$path = wp_tempnam( 'aggr-test' );

		file_put_contents( $path, $contents );

		$this->temporary[] = $path;

		return array(
			'name'     => $name,
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $contents ),
		);
	}

	/**
	 * A real PNG of the given dimensions.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @return string
	 */
	private function png( int $width = 8, int $height = 8 ): string {
		$image = imagecreatetruecolor( $width, $height );

		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();

		return $bytes;
	}

	/**
	 * A genuine image is accepted and stored under a name we generate.
	 *
	 * @return void
	 */
	public function test_a_real_image_is_accepted(): void {
		$result = $this->uploader->accept( $this->upload( $this->png( 40, 20 ), 'banner.png' ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'image/png', $result['mime'] );
		$this->assertSame( 40, $result['width'] );
		$this->assertSame( 20, $result['height'] );
		$this->assertSame( 64, strlen( $result['sha256'] ) );

		// The stored name is ours, never the client's.
		$this->assertStringNotContainsString( 'banner', $result['path'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}\.png$/', $result['path'] );

		$this->assertNotNull( $this->storage->resolve( $result['path'] ) );
	}

	/**
	 * **An SVG is refused, whatever it is called.**
	 *
	 * An accepted SVG is an XML document with <script> support rendering
	 * inline on a public page — stored XSS executing with each visitor's
	 * session. `svg-support` is active on this site, so WordPress accepts SVG
	 * uploads generally; our allowlist is independent of `upload_mimes`
	 * precisely so a site-wide setting cannot re-open this.
	 *
	 * @param string $name Filename the caller supplies.
	 * @return void
	 *
	 * @dataProvider data_svg_names
	 */
	public function test_svg_is_refused( string $name ): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

		$this->assertInstanceOf(
			WP_Error::class,
			$this->uploader->accept( $this->upload( $svg, $name ) ),
			"An SVG named {$name} was accepted."
		);
	}

	/**
	 * Names an SVG might arrive under.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_svg_names(): array {
		return array(
			'plain'           => array( 'logo.svg' ),
			'uppercase'       => array( 'LOGO.SVG' ),
			'double image'    => array( 'logo.png.svg' ),
			'claiming png'    => array( 'logo.svg.png' ),
			'no extension'    => array( 'logo' ),
			'trailing spaces' => array( 'logo.svg   ' ),
		);
	}

	/**
	 * The allowlist refuses SVG at the value level too, regardless of caller.
	 *
	 * @return void
	 */
	public function test_the_rules_deny_svg_directly(): void {
		$this->assertFalse( Upload_Rules::is_allowed_mime( 'image/svg+xml' ) );
		$this->assertFalse( Upload_Rules::is_allowed_extension( 'svg' ) );
		$this->assertFalse( Upload_Rules::is_allowed_extension( 'svgz' ) );
		$this->assertFalse( Upload_Rules::is_allowed_extension( 'SVG' ) );
	}

	/**
	 * A script that merely claims to be an image is refused.
	 *
	 * @return void
	 */
	public function test_a_script_named_as_an_image_is_refused(): void {
		$php = "<?php echo shell_exec( \$_GET['c'] ); ?>";

		$this->assertInstanceOf( WP_Error::class, $this->uploader->accept( $this->upload( $php, 'shell.php' ) ) );
		$this->assertInstanceOf( WP_Error::class, $this->uploader->accept( $this->upload( $php, 'shell.png' ) ) );
		$this->assertInstanceOf( WP_Error::class, $this->uploader->accept( $this->upload( $php, 'shell.php.png' ) ) );
	}

	/**
	 * **A file whose real type differs from its name is refused, not corrected.**
	 *
	 * WordPress will happily tell you the "proper" filename for a mislabelled
	 * file. Accepting that correction is the mistake: a correction means the
	 * claimed type and the real type differ, and that difference is the attack.
	 *
	 * @return void
	 */
	public function test_a_mislabelled_image_is_refused_rather_than_corrected(): void {
		$result = $this->uploader->accept( $this->upload( $this->png(), 'banner.gif' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_' . Upload_Rules::ERROR_TYPE_MISMATCH, $result->get_error_code() );
	}

	/**
	 * A file over the size cap is refused.
	 *
	 * @return void
	 */
	public function test_an_oversized_file_is_refused(): void {
		$oversized = str_repeat( 'a', Upload_Rules::MAX_BYTES + 1 );

		$result = $this->uploader->accept( $this->upload( $oversized, 'big.png' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_' . Upload_Rules::ERROR_TOO_LARGE, $result->get_error_code() );
	}

	/**
	 * The pixel cap is applied to header dimensions, before any decoding.
	 *
	 * A decompression bomb is a small file that expands to gigabytes once
	 * decoded, so a check that runs after decoding never runs at all.
	 *
	 * @return void
	 */
	public function test_the_pixel_cap_is_enforced(): void {
		$this->assertTrue( Upload_Rules::exceeds_pixels( 30000, 30000 ) );
		$this->assertTrue( Upload_Rules::exceeds_pixels( 0, 100 ) );
		$this->assertFalse( Upload_Rules::exceeds_pixels( 728, 90 ) );
	}

	/**
	 * An empty or missing file is refused rather than stored.
	 *
	 * @return void
	 */
	public function test_a_missing_file_is_refused(): void {
		$this->assertInstanceOf( WP_Error::class, $this->uploader->accept( array() ) );

		$this->assertInstanceOf(
			WP_Error::class,
			$this->uploader->accept(
				array(
					'name'     => 'x.png',
					'tmp_name' => '/tmp/does-not-exist-' . wp_generate_uuid4(),
					'error'    => UPLOAD_ERR_OK,
				)
			)
		);
	}

	/**
	 * A failed transfer is refused even when a temp file happens to exist.
	 *
	 * @return void
	 */
	public function test_a_failed_transfer_is_refused(): void {
		$file          = $this->upload( $this->png(), 'banner.png' );
		$file['error'] = UPLOAD_ERR_PARTIAL;

		$this->assertInstanceOf( WP_Error::class, $this->uploader->accept( $file ) );
	}

	/**
	 * **A stored path cannot be used to read outside the private root.**
	 *
	 * Containment is checked after realpath(), because `a/../../b` and a
	 * symlink both look harmless as text.
	 *
	 * @param string $path A path that must not resolve.
	 * @return void
	 *
	 * @dataProvider data_traversal_paths
	 */
	public function test_traversal_paths_do_not_resolve( string $path ): void {
		$this->assertNull( $this->storage->resolve( $path ), "Resolved a path it should not: {$path}" );
	}

	/**
	 * Paths that must never resolve.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_traversal_paths(): array {
		return array(
			'parent'          => array( '../wp-config.php' ),
			'deep parent'     => array( '../../../../wp-config.php' ),
			'absolute'        => array( '/etc/passwd' ),
			'null byte'       => array( "abc\0.png" ),
			'empty'           => array( '' ),
			'directory'       => array( '.' ),
			'nested traverse' => array( 'a/../../../wp-config.php' ),
		);
	}

	/**
	 * **Traversal cannot reach a file that genuinely exists outside the root.**
	 *
	 * The data provider above asserts null for traversal strings too, but it
	 * would pass with the containment check removed — nothing exists at those
	 * paths, so realpath() rejects them for an unrelated reason. This one puts
	 * a real file one level up and aims at it, so only the containment check
	 * can produce null.
	 *
	 * @return void
	 */
	public function test_traversal_cannot_reach_a_real_file_outside_the_root(): void {
		$this->storage->ensure();

		$outside = dirname( $this->storage->root() ) . '/aggr-traversal-target.txt';

		file_put_contents( $outside, 'secret' );
		$this->temporary[] = $outside;

		$this->assertFileExists( $outside, 'Test precondition: the target must exist, or this proves nothing.' );

		$this->assertNull(
			$this->storage->resolve( '../' . basename( $outside ) ),
			'A path escaped the private root.'
		);
	}

	/**
	 * **An image declaring enormous dimensions is refused before decoding.**
	 *
	 * Built as a header only, which is exactly the shape of a decompression
	 * bomb: a tiny file that expands to gigabytes once decoded. getimagesize()
	 * reads the header, so the cap is applied while the file is still small.
	 *
	 * @return void
	 */
	public function test_an_image_claiming_enormous_dimensions_is_refused(): void {
		$header = $this->png_header( 30000, 30000 );

		// The dimensions have to be readable, or this would be testing that a
		// corrupt file is rejected rather than that the cap works.
		$temp = wp_tempnam( 'aggr-bomb' );
		file_put_contents( $temp, $header );
		$this->temporary[] = $temp;

		$probe = getimagesize( $temp );
		$this->assertIsArray( $probe, 'Test precondition: the crafted header must be readable.' );
		$this->assertSame( 30000, $probe[0] );

		$result = $this->uploader->accept( $this->upload( $header, 'bomb.png' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_' . Upload_Rules::ERROR_TOO_MANY_PIXELS, $result->get_error_code() );
	}

	/**
	 * A PNG header declaring the given dimensions, with no pixel data.
	 *
	 * @param int $width  Declared width.
	 * @param int $height Declared height.
	 * @return string
	 */
	private function png_header( int $width, int $height ): string {
		$ihdr = pack( 'N', $width ) . pack( 'N', $height ) . chr( 8 ) . chr( 2 ) . chr( 0 ) . chr( 0 ) . chr( 0 );

		return "\x89PNG\r\n\x1a\n"
			. pack( 'N', 13 ) . 'IHDR' . $ihdr . pack( 'N', crc32( 'IHDR' . $ihdr ) );
	}

	/**
	 * The checksum recorded at upload detects a file swapped afterwards.
	 *
	 * This is what makes "approved" describe an artifact rather than a moment.
	 *
	 * @return void
	 */
	public function test_a_swapped_file_fails_its_checksum(): void {
		$result = $this->uploader->accept( $this->upload( $this->png( 12, 12 ), 'banner.png' ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $this->storage->matches_checksum( $result['path'], $result['sha256'] ) );

		$stored = $this->storage->resolve( $result['path'] );
		$this->assertNotNull( $stored );

		file_put_contents( $stored, $this->png( 16, 16 ) );

		$this->assertFalse(
			$this->storage->matches_checksum( $result['path'], $result['sha256'] ),
			'A file replaced after review still matched its recorded checksum.'
		);
	}

	/**
	 * The private root carries its deny files.
	 *
	 * Apache and IIS read these; nginx reads neither, which is why the
	 * unguessable path is the layer that actually holds. Written anyway.
	 *
	 * @return void
	 */
	public function test_the_private_root_is_marked_deny(): void {
		$this->assertTrue( $this->storage->ensure() );

		foreach ( array( '.htaccess', 'web.config', 'index.php' ) as $name ) {
			$this->assertFileExists( $this->storage->root() . '/' . $name );
		}
	}

	/**
	 * A hostile filename is never used as a stored name, and is neutralised
	 * before it is kept for display.
	 *
	 * @return void
	 */
	public function test_display_names_are_neutralised(): void {
		$this->assertSame( 'etcpasswd', Upload_Rules::safe_display_name( '../etc/passwd' ) );
		$this->assertSame( 'creative', Upload_Rules::safe_display_name( '' ) );
		$this->assertSame( 'ab.png', Upload_Rules::safe_display_name( "a\0b.png" ) );
		$this->assertLessThanOrEqual( 120, mb_strlen( Upload_Rules::safe_display_name( str_repeat( 'x', 500 ) ) ) );
	}
}
