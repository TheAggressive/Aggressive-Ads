<?php
/**
 * Autoloader mapping tests.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Unit;

use LAAO_Advertiser_Portal\Autoloader;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The class-name-to-filename mapping is the part that is easy to get subtly
 * wrong, so it is tested directly through resolve(), which has no side effects.
 */
final class AutoloaderTest extends TestCase {

	/**
	 * Absolute path to inc/.
	 *
	 * @var string
	 */
	private string $inc_dir;

	/**
	 * The subject.
	 *
	 * @var Autoloader
	 */
	private Autoloader $autoloader;

	/**
	 * Sets up the subject.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		$this->inc_dir    = dirname( __DIR__, 3 ) . '/inc';
		$this->autoloader = new Autoloader( $this->inc_dir );
	}

	/**
	 * A root-namespace class maps to inc/class-{slug}.php.
	 *
	 * @return void
	 */
	public function test_resolves_a_root_namespace_class(): void {
		$this->assertSame(
			$this->inc_dir . '/class-service-container.php',
			$this->autoloader->resolve( 'LAAO_Advertiser_Portal\\Service_Container' )
		);
	}

	/**
	 * A namespaced class maps to inc/{Segment}/class-{slug}.php, with the
	 * directory segment's case preserved.
	 *
	 * @return void
	 */
	public function test_resolves_a_subnamespaced_interface(): void {
		$this->assertSame(
			$this->inc_dir . '/Core/interface-service.php',
			$this->autoloader->resolve( 'LAAO_Advertiser_Portal\\Core\\Service' )
		);
	}

	/**
	 * Underscores in the class name become hyphens; the name lowercases.
	 *
	 * @return void
	 */
	public function test_underscores_become_hyphens_and_the_name_lowercases(): void {
		$this->assertSame(
			$this->inc_dir . '/class-container-exception.php',
			$this->autoloader->resolve( 'LAAO_Advertiser_Portal\\Container_Exception' )
		);
	}

	/**
	 * Another vendor's class is not ours to load.
	 *
	 * @return void
	 */
	public function test_ignores_classes_outside_the_namespace(): void {
		$this->assertNull( $this->autoloader->resolve( 'WP_Query' ) );
		$this->assertNull( $this->autoloader->resolve( 'Adsanity\\Meta_Data' ) );
		$this->assertNull( $this->autoloader->resolve( 'LAAO\\Assets\\Styles' ) );
	}

	/**
	 * A namespace that merely starts with our prefix is not ours either.
	 *
	 * @return void
	 */
	public function test_ignores_a_similarly_named_namespace(): void {
		$this->assertNull( $this->autoloader->resolve( 'LAAO_Advertiser_Portal_Addon\\Thing' ) );
	}

	/**
	 * A class with no backing file resolves to null rather than a path that
	 * would fatal on require.
	 *
	 * @return void
	 */
	public function test_returns_null_for_a_class_with_no_file(): void {
		$this->assertNull( $this->autoloader->resolve( 'LAAO_Advertiser_Portal\\Nope\\Not_A_Class' ) );
	}

	/**
	 * Path separators and traversal sequences cannot survive the identifier
	 * check, so no class name can walk out of the base directory.
	 *
	 * @param string $fqcn A hostile class name.
	 * @return void
	 *
	 * @dataProvider data_hostile_class_names
	 */
	public function test_refuses_segments_that_are_not_identifiers( string $fqcn ): void {
		$this->assertNull( $this->autoloader->resolve( $fqcn ) );
	}

	/**
	 * Hostile class names.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_hostile_class_names(): array {
		return array(
			'parent traversal'   => array( 'LAAO_Advertiser_Portal\\..\\..\\Thing' ),
			'forward slash'      => array( 'LAAO_Advertiser_Portal\\Core/../../Thing' ),
			'null byte'          => array( "LAAO_Advertiser_Portal\\Core\\Thing\0.php" ),
			'empty segment'      => array( 'LAAO_Advertiser_Portal\\\\Thing' ),
			'trailing separator' => array( 'LAAO_Advertiser_Portal\\' ),
		);
	}

	/**
	 * A traversal segment must not reach a real file outside the base directory.
	 *
	 * The data-provider case above asserts null for traversal names too, but it
	 * would pass with the guard removed — nothing exists at those paths, so
	 * is_file() rejects them for an unrelated reason. This one points a `..`
	 * segment at a file that genuinely exists one level up, so only the
	 * identifier check can produce null.
	 *
	 * @return void
	 */
	public function test_traversal_cannot_reach_a_real_file_outside_the_base_directory(): void {
		$scoped = new Autoloader( $this->inc_dir . '/Core' );

		$traversed = $this->inc_dir . '/Core/../class-service-container.php';

		$this->assertFileExists(
			$traversed,
			'Test precondition: the traversal target must exist, or this proves nothing.'
		);

		$this->assertNull( $scoped->resolve( 'LAAO_Advertiser_Portal\\..\\Service_Container' ) );
	}

	/**
	 * Resolving is not enough — autoload() must load the file it found.
	 *
	 * @return void
	 */
	public function test_autoload_loads_a_resolvable_class(): void {
		$this->autoloader->autoload( 'LAAO_Advertiser_Portal\\Container_Exception' );

		$this->assertTrue( class_exists( 'LAAO_Advertiser_Portal\\Container_Exception', false ) );
	}

	/**
	 * A foreign class is a silent no-op, not a warning or a failed require —
	 * other autoloaders after ours must still get their turn.
	 *
	 * @return void
	 */
	public function test_autoload_is_a_no_op_for_foreign_classes(): void {
		$this->autoloader->autoload( 'Some\\Other\\Vendor_Class' );

		$this->assertFalse( class_exists( 'Some\\Other\\Vendor_Class', false ) );
	}
}
