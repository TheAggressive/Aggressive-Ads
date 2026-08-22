<?php
/**
 * Deleting private creative at uninstall.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Install\Uninstaller;
use Aggressive\Ads\Storage\Private_Storage;
use WP_UnitTestCase;

/**
 * The last untested destructive path in the plugin.
 *
 * What it removes is an advertiser's only remaining copy of artwork nobody
 * approved, so the cases worth asserting are mostly the ones where it must not
 * remove something.
 */
final class UninstallPrivateFilesTest extends WP_UnitTestCase {

	/**
	 * Absolute path of one uploads subdirectory.
	 *
	 * @param string $directory Directory name under uploads.
	 * @return string
	 */
	private function root( string $directory ): string {
		$uploads = wp_upload_dir();

		return rtrim( (string) $uploads['basedir'], '/\\' ) . '/' . $directory;
	}

	/**
	 * Writes a file into one of the private directories.
	 *
	 * @param string $directory Directory name under uploads.
	 * @param string $name      File name.
	 * @return string Absolute path.
	 */
	private function seed( string $directory, string $name ): string {
		$root = $this->root( $directory );

		if ( ! is_dir( $root ) ) {
			wp_mkdir_p( $root );
		}

		$path = $root . '/' . $name;
		file_put_contents( $path, 'creative bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		return $path;
	}

	/**
	 * Empties both private directories.
	 *
	 * Runs before as well as after. The suite's uploads directory keeps
	 * whatever earlier tests wrote — twenty-five creative files, the first time
	 * this was run — so a count assertion against it measured the leftovers
	 * rather than the fixture.
	 *
	 * @return void
	 */
	private function clear_both(): void {
		foreach ( array( Private_Storage::DIRECTORY, Private_Storage::LEGACY_DIRECTORY ) as $directory ) {
			$root = $this->root( $directory );

			if ( ! is_dir( $root ) ) {
				continue;
			}

			foreach ( (array) scandir( $root ) as $name ) {
				if ( '.' !== $name && '..' !== $name && is_file( $root . '/' . $name ) ) {
					wp_delete_file( $root . '/' . $name );
				}
			}

			rmdir( $root ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir -- Test teardown of a directory this test created.
		}
	}

	/**
	 * Starts each test with both directories gone.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->clear_both();
	}

	/**
	 * Leaves nothing for the next test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->clear_both();

		parent::tear_down();
	}

	/**
	 * Current-name creative is deleted and the directory goes with it.
	 *
	 * @return void
	 */
	public function test_it_deletes_creative_and_removes_the_directory(): void {
		$path = $this->seed( Private_Storage::DIRECTORY, 'a1b2c3d4.png' );

		$deleted = Uninstaller::delete_private_files();

		$this->assertSame( 1, $deleted );
		$this->assertFileDoesNotExist( $path );
		$this->assertDirectoryDoesNotExist( $this->root( Private_Storage::DIRECTORY ) );
	}

	/**
	 * The pre-6 directory is cleared too.
	 *
	 * A site that upgraded partway, or never upgraded, still has bytes under
	 * the old name, and uninstall is the last chance to clear them.
	 *
	 * @return void
	 */
	public function test_it_clears_the_legacy_directory(): void {
		$legacy  = $this->seed( Private_Storage::LEGACY_DIRECTORY, 'e5f6a7b8.png' );
		$current = $this->seed( Private_Storage::DIRECTORY, 'c9d0e1f2.png' );

		$deleted = Uninstaller::delete_private_files();

		$this->assertSame( 2, $deleted );
		$this->assertFileDoesNotExist( $legacy );
		$this->assertFileDoesNotExist( $current );
	}

	/**
	 * Nothing to delete is not an error.
	 *
	 * @return void
	 */
	public function test_absent_directories_are_a_no_op(): void {
		$this->assertSame( 0, Uninstaller::delete_private_files() );
	}

	/**
	 * A directory somebody else put there is left alone.
	 *
	 * The sweep deletes files it finds and then tries to remove the directory.
	 * A nested directory is not ours to reason about, so the removal has to
	 * fail rather than recurse.
	 *
	 * @return void
	 */
	public function test_an_unexpected_subdirectory_survives(): void {
		$root   = $this->root( Private_Storage::DIRECTORY );
		$nested = $root . '/somebody-elses';

		wp_mkdir_p( $nested );
		file_put_contents( $nested . '/keep.txt', 'not ours' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		Uninstaller::delete_private_files();

		$this->assertDirectoryExists( $nested, 'A directory this plugin did not create must survive.' );
		$this->assertFileExists( $nested . '/keep.txt' );

		// Teardown cannot remove a nested tree, so clear it here.
		wp_delete_file( $nested . '/keep.txt' );
		rmdir( $nested ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir -- Test cleanup of a directory this test created.
	}

	/**
	 * Running it twice deletes nothing the second time.
	 *
	 * @return void
	 */
	public function test_it_is_idempotent(): void {
		$this->seed( Private_Storage::DIRECTORY, 'aabbccdd.png' );

		$this->assertSame( 1, Uninstaller::delete_private_files() );
		$this->assertSame( 0, Uninstaller::delete_private_files() );
	}
}
