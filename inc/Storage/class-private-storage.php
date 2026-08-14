<?php
/**
 * Storage for creative that has not been approved.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Storage;

use WP_Error;

/**
 * Holds uploaded creative outside the Media Library until approval.
 *
 * Unapproved creative is the most commercially sensitive data in the system —
 * an unreleased campaign, a competitor's artwork, an embargoed announcement.
 * Putting it in the Media Library would give it a public URL that needs no
 * authentication and can be guessed from a date directory and a filename.
 *
 * **The layer that actually holds is path unguessability**, because it does
 * not depend on server configuration. The deny files are written too, but
 * nginx reads none of them: if production runs nginx that layer contributes
 * nothing, which is recorded in docs/known-issues.md rather than assumed away.
 * Reads go through an authorized endpoint that streams bytes and never
 * redirects. See docs/domain-model.md.
 */
final class Private_Storage {

	/**
	 * Directory name under the uploads base.
	 */
	public const DIRECTORY = 'aggr-private';

	/**
	 * The absolute path to the private root, without a trailing slash.
	 *
	 * @return string
	 */
	public function root(): string {
		$uploads = wp_upload_dir();

		$base = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] )
			? $uploads['basedir']
			: WP_CONTENT_DIR . '/uploads';

		return rtrim( $base, '/\\' ) . '/' . self::DIRECTORY;
	}

	/**
	 * Creates the private root and its deny files.
	 *
	 * Idempotent: safe to call on every upload, and cheap once the directory
	 * exists.
	 *
	 * @return bool
	 */
	public function ensure(): bool {
		$root = $this->root();

		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			return false;
		}

		$this->write_deny_files( $root );

		return true;
	}

	/**
	 * Creates a harmless file used to verify direct HTTP access is denied.
	 *
	 * The caller must delete the returned path in a finally block. A random
	 * name prevents caches from turning yesterday's server configuration into
	 * today's Site Health result.
	 *
	 * @return array{path: string, url: string}|null Probe location, or null when unavailable.
	 */
	public function create_verification_probe(): ?array {
		$uploads = wp_upload_dir();
		$baseurl = isset( $uploads['baseurl'] ) && is_string( $uploads['baseurl'] )
			? rtrim( $uploads['baseurl'], '/' )
			: '';

		if ( '' === $baseurl || ! $this->ensure() ) {
			return null;
		}

		// Match a real creative's non-hidden UUID filename and common extension.
		// A dotfile probe could be denied by a generic dotfile rule even while
		// the actual creative files remained public, producing a false success.
		$relative = wp_generate_uuid4() . '.png';
		$path     = $this->root() . '/' . $relative;

		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if (
			! $wp_filesystem instanceof \WP_Filesystem_Base
			|| ! $wp_filesystem->put_contents( $path, 'Aggressive Ads private-storage verification.', FS_CHMOD_FILE )
		) {
			return null;
		}

		return array(
			'path' => $relative,
			'url'  => $baseurl . '/' . self::DIRECTORY . '/' . rawurlencode( $relative ),
		);
	}

	/**
	 * Stores an uploaded file under a name we generate.
	 *
	 * @param string $source_path Absolute path to the file to store.
	 * @param string $extension   Extension derived from the detected type, not from the client.
	 * @return array{path: string, token: string, sha256: string, bytes: int}|WP_Error
	 */
	public function store( string $source_path, string $extension ) {
		if ( ! $this->ensure() ) {
			return new WP_Error(
				'aggr_storage_unavailable',
				__( 'The upload could not be saved. Please try again.', 'aggressive-ads' )
			);
		}

		// A token as well as a UUID, so knowing one filename tells an attacker
		// nothing about the next. Both are generated server-side.
		$token    = bin2hex( random_bytes( 16 ) );
		$relative = wp_generate_uuid4() . '.' . $extension;
		$target   = $this->root() . '/' . $relative;

		if ( ! $this->move( $source_path, $target ) ) {
			return new WP_Error(
				'aggr_storage_write_failed',
				__( 'The upload could not be saved. Please try again.', 'aggressive-ads' )
			);
		}

		$checksum = hash_file( 'sha256', $target );
		$bytes    = filesize( $target );

		if ( false === $checksum || false === $bytes ) {
			$this->delete( $relative );

			return new WP_Error(
				'aggr_storage_write_failed',
				__( 'The upload could not be saved. Please try again.', 'aggressive-ads' )
			);
		}

		return array(
			'path'   => $relative,
			'token'  => $token,
			'sha256' => $checksum,
			'bytes'  => (int) $bytes,
		);
	}

	/**
	 * Resolves a stored path, refusing anything outside the private root.
	 *
	 * Containment is checked after realpath() rather than by inspecting the
	 * string, because `a/../../b` and a symlink both look harmless as text.
	 *
	 * @param string $relative Stored relative path.
	 * @return string|null Absolute path, or null when it does not resolve inside the root.
	 */
	public function resolve( string $relative ): ?string {
		if ( '' === $relative || str_contains( $relative, "\0" ) ) {
			return null;
		}

		$root = realpath( $this->root() );

		if ( false === $root ) {
			return null;
		}

		$candidate = realpath( $this->root() . '/' . $relative );

		if ( false === $candidate || ! is_file( $candidate ) ) {
			return null;
		}

		// The separator matters: without it, /var/private-evil passes a
		// str_starts_with check against /var/private.
		if ( ! str_starts_with( $candidate, $root . DIRECTORY_SEPARATOR ) ) {
			return null;
		}

		return $candidate;
	}

	/**
	 * Whether a stored file's contents still hash to what was recorded.
	 *
	 * Proves at approval that the bytes about to be published are the bytes
	 * that were reviewed. Without it, "approved" describes a moment rather
	 * than an artifact.
	 *
	 * @param string $relative Stored relative path.
	 * @param string $expected Recorded sha256.
	 * @return bool
	 */
	public function matches_checksum( string $relative, string $expected ): bool {
		$path = $this->resolve( $relative );

		if ( null === $path || '' === $expected ) {
			return false;
		}

		$actual = hash_file( 'sha256', $path );

		return is_string( $actual ) && hash_equals( $expected, $actual );
	}

	/**
	 * Deletes a stored file.
	 *
	 * @param string $relative Stored relative path.
	 * @return bool
	 */
	public function delete( string $relative ): bool {
		$path = $this->resolve( $relative );

		if ( null === $path ) {
			return false;
		}

		return wp_delete_file_from_directory( $path, $this->root() );
	}

	/**
	 * Atomically moves a file aside while its database record is deleted.
	 *
	 * @param string $relative Stored relative path.
	 * @return string|null Quarantined relative path, or null on failure.
	 */
	public function quarantine( string $relative ): ?string {
		$path = $this->resolve( $relative );

		if ( null === $path ) {
			return null;
		}

		$target = $path . '.aggr-trash-' . wp_generate_uuid4();

		if ( ! rename( $path, $target ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename -- Both paths were resolved under the uploads-backed private root.
			return null;
		}

		return ltrim( substr( $target, strlen( $this->root() ) ), '/\\' );
	}

	/**
	 * Restores a quarantined file after its database deletion was refused.
	 *
	 * @param string $quarantined Quarantined relative path.
	 * @param string $original    Original relative path.
	 * @return bool
	 */
	public function restore( string $quarantined, string $original ): bool {
		$source = $this->resolve( $quarantined );
		$root   = realpath( $this->root() );
		$target = $this->root() . '/' . ltrim( $original, '/\\' );
		$parent = realpath( dirname( $target ) );

		if (
			null === $source
			|| false === $root
			|| false === $parent
			|| ( $parent !== $root && ! str_starts_with( $parent, $root . DIRECTORY_SEPARATOR ) )
		) {
			return false;
		}

		return rename( $source, $target ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename -- Both paths are verified inside the uploads-backed private root.
	}

	/**
	 * Moves a file into place.
	 *
	 * @param string $source Absolute source path.
	 * @param string $target Absolute target path.
	 * @return bool
	 */
	private function move( string $source, string $target ): bool {
		if ( ! is_file( $source ) ) {
			return false;
		}

		// An uploaded file is moved with the function that verifies it really
		// was uploaded; anything else — a sideload, a test fixture — is copied.
		if ( is_uploaded_file( $source ) ) {
			return move_uploaded_file( $source, $target );
		}

		return copy( $source, $target );
	}

	/**
	 * Writes the server-level deny files.
	 *
	 * Apache reads .htaccess and IIS reads web.config. **nginx reads neither**,
	 * so on an nginx host these contribute nothing and the unguessable path is
	 * the only thing standing between an attacker and the file. Written anyway,
	 * because defence in depth costs three small files.
	 *
	 * @param string $root Private root path.
	 * @return void
	 */
	private function write_deny_files( string $root ): void {
		$files = array(
			'.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);

		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			WP_Filesystem();
		}

		foreach ( $files as $name => $contents ) {
			$path = $root . '/' . $name;

			if ( is_file( $path ) ) {
				continue;
			}

			if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
				$wp_filesystem->put_contents( $path, $contents, FS_CHMOD_FILE );
			}
		}
	}
}
