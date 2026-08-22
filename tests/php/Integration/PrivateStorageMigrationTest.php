<?php
/**
 * The db-version-6 rename of the private creative directory.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Storage\Private_Storage;
use WP_UnitTestCase;

/**
 * Moving creative out of the pre-6 directory.
 *
 * The bytes are the only copy of an advertiser's artwork, so these assert what
 * survives rather than only what the method returns.
 */
final class PrivateStorageMigrationTest extends WP_UnitTestCase {

	/**
	 * Absolute path of the pre-6 directory.
	 */
	private function legacy_root(): string {
		$uploads = wp_upload_dir();

		return rtrim( (string) $uploads['basedir'], '/\\' ) . '/' . Private_Storage::LEGACY_DIRECTORY;
	}

	/**
	 * Absolute path of the current directory.
	 */
	private function current_root(): string {
		$uploads = wp_upload_dir();

		return rtrim( (string) $uploads['basedir'], '/\\' ) . '/' . Private_Storage::DIRECTORY;
	}

	/**
	 * Empties both directories.
	 *
	 * Runs before as well as after. The uploads directory of a WordPress test
	 * install is not reset between runs, so a previous run's creative — 351
	 * files, the first time this was written — is still sitting in the pre-6
	 * directory and gets counted as migrated. Asserting on a count means
	 * asserting the fixture is real first.
	 */
	private function clear_both(): void {
		foreach ( array( $this->legacy_root(), $this->current_root() ) as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}

			foreach ( (array) scandir( $root ) as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}

				wp_delete_file( $root . '/' . $name );
			}

			rmdir( $root ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir -- Test teardown of a directory this test created.
		}
	}

	/**
	 * Starts each test with both directories empty.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->clear_both();
	}

	/**
	 * Leaves nothing behind for the next test or the next run.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->clear_both();

		parent::tear_down();
	}

	/**
	 * Seeds a file in the pre-6 directory.
	 */
	private function seed_legacy( string $name, string $contents ): string {
		$root = $this->legacy_root();

		if ( ! is_dir( $root ) ) {
			wp_mkdir_p( $root );
		}

		$path = $root . '/' . $name;
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		return $path;
	}

	private function storage(): Private_Storage {
		return Plugin::instance()->container()->get( Private_Storage::class );
	}

	/**
	 * Creative moves, with its bytes intact, and the old directory goes.
	 *
	 * @return void
	 */
	public function test_creative_moves_to_the_new_directory(): void {
		$this->seed_legacy( 'a1b2c3d4.png', 'original-bytes' );

		$moved = $this->storage()->migrate_legacy_directory();

		$this->assertSame( 1, $moved );
		$this->assertFileExists( $this->current_root() . '/a1b2c3d4.png' );
		$this->assertSame( 'original-bytes', file_get_contents( $this->current_root() . '/a1b2c3d4.png' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Test assertion.
		$this->assertDirectoryDoesNotExist( $this->legacy_root() );
	}

	/**
	 * Running it twice moves nothing more and destroys nothing.
	 *
	 * @return void
	 */
	public function test_the_migration_is_idempotent(): void {
		$this->seed_legacy( 'e5f6a7b8.png', 'bytes' );

		$this->assertSame( 1, $this->storage()->migrate_legacy_directory() );
		$this->assertSame( 0, $this->storage()->migrate_legacy_directory() );
		$this->assertFileExists( $this->current_root() . '/e5f6a7b8.png' );
	}

	/**
	 * A name already taken at the target is never overwritten.
	 *
	 * Both copies survive instead. Losing the newer bytes to a name collision
	 * would be a silent substitution of one advertiser's artwork for another.
	 *
	 * @return void
	 */
	public function test_a_colliding_name_is_left_alone(): void {
		$this->storage()->ensure();
		file_put_contents( $this->current_root() . '/clash.png', 'target-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$legacy = $this->seed_legacy( 'clash.png', 'legacy-bytes' );

		$this->assertSame( 0, $this->storage()->migrate_legacy_directory() );
		$this->assertSame( 'target-bytes', file_get_contents( $this->current_root() . '/clash.png' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Test assertion.
		$this->assertFileExists( $legacy );
		$this->assertDirectoryExists( $this->legacy_root() );
	}

	/**
	 * Nothing to migrate is not an error.
	 *
	 * @return void
	 */
	public function test_no_legacy_directory_is_a_no_op(): void {
		$this->assertSame( 0, $this->storage()->migrate_legacy_directory() );
	}
}
