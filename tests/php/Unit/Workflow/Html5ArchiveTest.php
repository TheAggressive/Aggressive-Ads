<?php
/**
 * Read real ZIPs without extracting attacker-selected paths.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Workflow;

use Aggressive\Ads\Workflow\Html5_Archive;
use Aggressive\Ads\Workflow\Html5_Budget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Html5ArchiveTest extends TestCase {

	/**
	 * Generated ZIP only; no live site or fixture directory is touched.
	 *
	 * @var string
	 */
	private string $path;

	protected function setUp(): void {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_tempnam -- Pure PHP unit fixture in the system temporary directory, without WordPress loaded.
		$this->path = tempnam( sys_get_temp_dir(), 'aggr-archive-test-' );
	}

	protected function tearDown(): void {
		unlink( $this->path );
	}

	public function test_reads_actual_local_members_with_exact_bytes(): void {
		$this->archive(
			array(
				'index.html' => '<div>Reviewed static ad</div>',
				'local.css'  => 'div{color:red}',
			)
		);
		$this->assertSame(
			array(
				'index.html' => '<div>Reviewed static ad</div>',
				'local.css'  => 'div{color:red}',
			),
			( new Html5_Archive() )->read( $this->path, 2097152, new Html5_Budget() )
		);
	}

	#[DataProvider( 'unsafe_members' )]
	public function test_refuses_paths_and_unsupported_content( string $name ): void {
		$this->archive(
			array(
				'index.html' => '<div>Ad</div>',
				$name        => 'hostile member',
			)
		);
		$this->expectException( \RuntimeException::class );
		( new Html5_Archive() )->read( $this->path, 2097152, new Html5_Budget() );
	}

	public function test_refuses_case_collisions(): void {
		$this->archive(
			array(
				'index.html' => '<div>Ad</div>',
				'a.png'      => 'one',
				'A.png'      => 'two',
			)
		);
		$this->expectException( \RuntimeException::class );
		( new Html5_Archive() )->read( $this->path, 2097152, new Html5_Budget() );
	}

	public function test_refuses_directory_prefix_case_collisions(): void {
		$this->archive(
			array(
				'index.html'   => '<div>Ad</div>',
				'Assets/a.png' => 'one',
				'assets/b.png' => 'two',
			)
		);
		$this->expectException( \RuntimeException::class );
		( new Html5_Archive() )->read( $this->path, 2097152, new Html5_Budget() );
	}

	public function test_accepts_five_directory_levels_and_the_exact_compressed_byte_limit(): void {
		$this->archive(
			array(
				'index.html'      => '<div>Ad</div>',
				'a/b/c/d/e/f.png' => 'raster',
			)
		);
		$this->assertSame( 'raster', ( new Html5_Archive() )->read( $this->path, filesize( $this->path ), new Html5_Budget() )['a/b/c/d/e/f.png'] );
		$this->expectException( \RuntimeException::class );
		( new Html5_Archive() )->read( $this->path, filesize( $this->path ) - 1, new Html5_Budget() );
	}

	public function test_refuses_trailing_bytes_and_preamble_polyglots(): void {
		$this->archive( array( 'index.html' => '<div>Ad</div>' ) );
		$original = file_get_contents( $this->path );
		foreach ( array( $original . 'trailing bytes', '<?php echo 1; ?>' . $original ) as $bytes ) {
			file_put_contents( $this->path, $bytes );
			try {
				( new Html5_Archive() )->read( $this->path, 2097152, new Html5_Budget() );
				$this->fail( 'Non-ZIP envelope was accepted.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'aggr_html5_archive', $error->getMessage() );
			}
		}
	}

	public function test_refuses_forged_declared_sizes(): void {
		$this->archive( array( 'index.html' => '<div>Untrusted actual bytes</div>' ) );
		$bytes   = file_get_contents( $this->path );
		$central = strpos( $bytes, "PK\x01\x02" );
		$this->assertIsInt( $central );
		$bytes = substr_replace( $bytes, pack( 'V', 1 ), $central + 24, 4 );
		$bytes = substr_replace( $bytes, pack( 'V', 1 ), 22, 4 );
		file_put_contents( $this->path, $bytes );
		$this->expectException( \RuntimeException::class );
		( new Html5_Archive() )->read( $this->path, 2097152, new Html5_Budget() );
	}

	public function test_enforces_actual_total_expansion_at_the_exact_boundary(): void {
		$index  = '<div>Ad</div>';
		$member = str_repeat( random_bytes( 16384 ), 128 );
		$files  = array(
			'index.html' => $index,
			'a.png'      => $member,
			'b.png'      => $member,
			'c.png'      => $member,
			'd.png'      => substr( $member, 0, strlen( $member ) - strlen( $index ) ),
		);
		$this->archive( $files );
		$read = ( new Html5_Archive() )->read( $this->path, 2097152, new Html5_Budget() );
		$this->assertSame( 8388608, array_sum( array_map( 'strlen', $read ) ) );
		$files['d.png'] .= 'x';
		$this->archive( $files );
		$this->expectException( \RuntimeException::class );
		( new Html5_Archive() )->read( $this->path, 2097152, new Html5_Budget() );
	}

	public function test_refuses_unix_symlink_before_reading_its_target(): void {
		$this->archive(
			array(
				'index.html' => '<div>Ad</div>',
				'a.png'      => '/etc/passwd',
			)
		);
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $this->path ) );
		$this->assertTrue( $zip->setExternalAttributesName( 'a.png', \ZipArchive::OPSYS_UNIX, 0120777 << 16 ) );
		$zip->close();
		$this->expectException( \RuntimeException::class );
		( new Html5_Archive() )->read( $this->path, 2097152, new Html5_Budget() );
	}

	public function test_refuses_actual_expansion_ratio_even_when_compressed_input_is_small(): void {
		$this->archive( array( 'index.html' => '<div>' . str_repeat( 'x', 150000 ) . '</div>' ) );
		$this->assertLessThan( 10000, filesize( $this->path ) );
		$this->expectException( \RuntimeException::class );
		( new Html5_Archive() )->read( $this->path, 2097152, new Html5_Budget() );
	}

	public function test_refuses_entry_count_before_opening_any_member(): void {
		$files = array( 'index.html' => '<div>Ad</div>' );
		for ( $i = 0; $i < 128; ++$i ) {
			$files[ $i . '.png' ] = 'raster';
		}
		$this->archive( $files );
		$this->expectException( \RuntimeException::class );
		( new Html5_Archive() )->read( $this->path, 2097152, new Html5_Budget() );
	}

	/**
	 * Concrete path attacks include both platform path syntaxes and ambiguous encodings.
	 *
	 * @return array<string,array{string}>
	 */
	public static function unsafe_members(): array {
		return array(
			'traversal'       => array( '../a.png' ),
			'absolute'        => array( '/a.png' ),
			'drive'           => array( 'C:/a.png' ),
			'UNC'             => array( '\\host\\a.png' ),
			'backslash'       => array( 'assets\\a.png' ),
			'encoded'         => array( '%2e%2e/a.png' ),
			'empty component' => array( 'assets//a.png' ),
			'dot component'   => array( './a.png' ),
			'deep path'       => array( 'a/b/c/d/e/f/g.png' ),
			'long path'       => array( str_repeat( 'a', 125 ) . '.png' ),
			'dotfile'         => array( '.htaccess' ),
			'PHP'             => array( 'a.php' ),
			'SVG'             => array( 'a.svg' ),
			'JavaScript'      => array( 'a.js' ),
			'nested archive'  => array( 'a.zip' ),
			'nested document' => array( 'a.html' ),
			'font'            => array( 'a.woff2' ),
		);
	}

	/**
	 * Generate actual compressed bytes and central-directory facts with libzip.
	 *
	 * @param array<string,string> $files Canonical or hostile members.
	 */
	private function archive( array $files ): void {
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $this->path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
		foreach ( $files as $name => $bytes ) {
			$this->assertTrue( $zip->addFromString( $name, $bytes ) );
		}
		$this->assertTrue( $zip->close() );
	}
}
