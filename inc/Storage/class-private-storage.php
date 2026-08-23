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
 * **The bytes are encrypted at rest**, so none of the layers below carries
 * the whole weight on its own. The deny files are written, but nginx reads
 * none of them; the path is unguessable, but a backup does not care; reads go
 * through an authorized streaming endpoint, but a filesystem copy goes around
 * it. Encryption is the one control that survives a server misconfiguration,
 * a database dump, a restore handed to the wrong person, and a copy of the
 * uploads directory on somebody's laptop. See `Creative_Cipher`,
 * docs/domain-model.md and docs/threat-model.md.
 */
final class Private_Storage {

	/**
	 * Constructor.
	 *
	 * The default exists so the tests that construct this directly keep
	 * working; the container injects the shared instance, which caches the
	 * resolved key for the request rather than reading it per file.
	 *
	 * @param Creative_Cipher $cipher Encryption for stored bytes.
	 */
	public function __construct( private readonly Creative_Cipher $cipher = new Creative_Cipher() ) {
	}

	/**
	 * Directory name under the uploads base.
	 */
	/**
	 * Previous directory name, migrated away from in db version 6.
	 *
	 * Kept so the migration can find what it is moving, and so a site that still
	 * has a server rule naming the old path is not silently left holding files.
	 */
	public const LEGACY_DIRECTORY = 'aggr-private';

	public const DIRECTORY = 'ads-uploads';

	/**
	 * The files this plugin writes into the private root itself.
	 *
	 * Named once because three walkers skip them, and a walker holding its
	 * own copy of the list is one that encrypts, moves or counts a deny file
	 * as though it were somebody's artwork.
	 *
	 * @var array<int, string>
	 */
	public const DENY_FILES = array( '.htaccess', 'web.config', 'index.php' );

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

		// Fail closed. Storing plaintext because the crypto library is absent
		// would put unapproved artwork on disk in exactly the state this
		// exists to prevent, and it would do it without telling anybody.
		if ( ! $this->cipher->is_available() ) {
			return new WP_Error(
				'aggr_storage_unavailable',
				__( 'The upload could not be saved. Please try again.', 'aggressive-ads' )
			);
		}

		// Taken from the source rather than the target: both describe what the
		// advertiser uploaded, and the target is now ciphertext. Hashing the
		// target would record a digest no later comparison could reproduce.
		$checksum = hash_file( 'sha256', $source_path );
		$bytes    = filesize( $source_path );

		if ( false === $checksum || false === $bytes ) {
			return new WP_Error(
				'aggr_storage_write_failed',
				__( 'The upload could not be saved. Please try again.', 'aggressive-ads' )
			);
		}

		// A token as well as a UUID, so knowing one filename tells an attacker
		// nothing about the next. Both are generated server-side.
		$token    = bin2hex( random_bytes( 16 ) );
		$relative = wp_generate_uuid4() . '.' . $extension;
		$target   = $this->root() . '/' . $relative;

		if ( ! $this->cipher->encrypt( $source_path, $target ) ) {
			// A partial encrypt leaves a file that resolves, carries the magic
			// and cannot be read. Removing it is what keeps "the file exists"
			// and "the creative can be reviewed" from disagreeing.
			if ( is_file( $target ) ) {
				wp_delete_file( $target );
			}

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
		if ( '' === $expected ) {
			return false;
		}

		$actual = $this->checksum( $relative );

		return null !== $actual && hash_equals( $expected, $actual );
	}

	/**
	 * The sha256 of a stored file's plaintext, or null when it cannot be read.
	 *
	 * Distinct from `matches_checksum()` on purpose: "the bytes changed" and
	 * "the bytes cannot be decrypted" are different incidents with different
	 * remedies, and a caller that can only see false reports the wrong one.
	 *
	 * @param string $relative Stored relative path.
	 * @return string|null
	 */
	public function checksum( string $relative ): ?string {
		$path = $this->resolve( $relative );

		return null === $path ? null : $this->cipher->checksum( $path );
	}

	/**
	 * The plaintext length of a stored file.
	 *
	 * @param string $relative Stored relative path.
	 * @return int|null
	 */
	public function plaintext_bytes( string $relative ): ?int {
		$path = $this->resolve( $relative );

		return null === $path ? null : $this->cipher->plaintext_bytes( $path );
	}

	/**
	 * Writes a stored file's plaintext into an open stream.
	 *
	 * @param string   $relative Stored relative path.
	 * @param resource $target   Open, writable stream.
	 * @return bool
	 */
	public function copy_to( string $relative, $target ): bool {
		$path = $this->resolve( $relative );

		return null !== $path && $this->cipher->copy_to( $path, $target );
	}

	/**
	 * Materialises a stored file's plaintext in a temporary file.
	 *
	 * For the two callers that hand a path to code which cannot be given a
	 * stream: the promoter, which sideloads into the Media Library, and the
	 * copier, which re-stores the bytes under a new creative. **The caller
	 * must delete the returned path**, and should do so in a finally block —
	 * it is unapproved creative sitting outside private storage.
	 *
	 * @param string $relative Stored relative path.
	 * @return string|null Absolute temporary path, or null.
	 */
	public function export( string $relative ): ?string {
		$path = $this->resolve( $relative );

		if ( null === $path ) {
			return null;
		}

		$temp = wp_tempnam( basename( $relative ) );

		if ( '' === $temp ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming a decrypt into a temporary file. WP_Filesystem would hold the whole creative in memory to do it.
		$handle = fopen( $temp, 'wb' );

		if ( false === $handle ) {
			wp_delete_file( $temp );

			return null;
		}

		$ok = $this->cipher->copy_to( $path, $handle );

		fclose( $handle );

		if ( ! $ok ) {
			wp_delete_file( $temp );

			return null;
		}

		return $temp;
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
	 * Encrypts stored creative that predates encryption at rest.
	 *
	 * Idempotent, resumable and deliberately non-destructive. Each file is
	 * encrypted into a sibling, the sibling is decrypted back and compared
	 * against the original's digest, and only then does it replace the
	 * original in a single rename. A file that fails at any step is left
	 * exactly as it was and the walk carries on, because the failure mode
	 * has to be "still plaintext" and never "an advertiser's only copy of
	 * their artwork is now unreadable".
	 *
	 * Reads keep working throughout: `Creative_Cipher::read()` passes a file
	 * without the magic through unchanged, so a half-finished migration is a
	 * mix of both and the review queue never notices.
	 *
	 * @return int Number of files encrypted.
	 */
	public function encrypt_existing_files(): int {
		if ( ! $this->cipher->is_available() || ! is_dir( $this->root() ) ) {
			return 0;
		}

		$root  = $this->root();
		$names = scandir( $root );

		if ( false === $names ) {
			return 0;
		}

		$encrypted = 0;

		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name || in_array( $name, self::DENY_FILES, true ) ) {
				continue;
			}

			$path = $root . '/' . $name;

			if ( ! is_file( $path ) || $this->cipher->is_encrypted( $path ) ) {
				continue;
			}

			if ( $this->encrypt_in_place( $path ) ) {
				++$encrypted;
			}
		}

		return $encrypted;
	}

	/**
	 * Encrypts one file, replacing it only once the result reads back.
	 *
	 * @param string $path Absolute path to a plaintext file inside the root.
	 * @return bool
	 */
	private function encrypt_in_place( string $path ): bool {
		// Checked before hashing rather than after. This walks a whole
		// directory unattended, and hash_file() on a file it cannot open is a
		// PHP warning per file — a log full of them is how a migration that
		// skipped something looks exactly like one that worked.
		if ( ! is_readable( $path ) ) {
			return false;
		}

		$digest = hash_file( 'sha256', $path );

		if ( false === $digest ) {
			return false;
		}

		$temp = $path . '.aggr-encrypting-' . wp_generate_uuid4();

		if ( ! $this->cipher->encrypt( $path, $temp ) ) {
			if ( is_file( $temp ) ) {
				wp_delete_file( $temp );
			}

			return false;
		}

		// Read the new file back before trusting it. Asserting that encrypt()
		// returned true only asserts that it was called; what matters is that
		// the bytes come out the same, and the one moment that is cheap to
		// check is while the plaintext is still there to compare against.
		$readback = $this->cipher->checksum( $temp );

		if ( null === $readback || ! hash_equals( $digest, $readback ) ) {
			wp_delete_file( $temp );

			return false;
		}

		// One rename, so there is no instant where the path holds neither the
		// old file nor the new one.
		return rename( $temp, $path ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename -- Both paths are inside the uploads-backed private root, and an atomic replace is the only way to migrate a file without a window in which it is absent.
	}

	/**
	 * Moves stored creative out of the pre-6 directory name.
	 *
	 * Idempotent, and deliberately non-destructive: files are moved one at a
	 * time, anything already present at the target is left alone, and the old
	 * directory is removed only once it holds nothing but the deny files this
	 * plugin wrote. A creative whose bytes went missing during a rename is a
	 * campaign that cannot be reviewed, so the failure mode is "both copies
	 * exist" rather than "neither does".
	 *
	 * @return int Number of files moved.
	 */
	public function migrate_legacy_directory(): int {
		$uploads = wp_upload_dir();
		$base    = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ? $uploads['basedir'] : '';

		if ( '' === $base ) {
			return 0;
		}

		$legacy = rtrim( $base, '/\\' ) . '/' . self::LEGACY_DIRECTORY;

		if ( ! is_dir( $legacy ) || ! $this->ensure() ) {
			return 0;
		}

		$root  = $this->root();
		$moved = 0;
		$names = scandir( $legacy );

		if ( false === $names ) {
			return 0;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			WP_Filesystem();
		}

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return 0;
		}

		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name || in_array( $name, self::DENY_FILES, true ) ) {
				continue;
			}

			$from = $legacy . '/' . $name;
			$to   = $root . '/' . $name;

			if ( ! is_file( $from ) || file_exists( $to ) ) {
				continue;
			}

			// Overwrite is false on purpose: the guard above already skipped an
			// existing target, and a race that created one since is not a
			// reason to destroy it.
			if ( $wp_filesystem->move( $from, $to, false ) ) {
				++$moved;
			}
		}

		$this->remove_directory_if_only_deny_files( $legacy );

		return $moved;
	}

	/**
	 * Deletes a directory when nothing but our own deny files remain in it.
	 *
	 * @param string $directory Absolute path.
	 * @return bool Whether the directory was removed.
	 */
	private function remove_directory_if_only_deny_files( string $directory ): bool {
		$names = scandir( $directory );

		if ( false === $names ) {
			return false;
		}

		$remaining = array_diff( $names, array( '.', '..' ), self::DENY_FILES );

		if ( array() !== $remaining ) {
			return false;
		}

		foreach ( self::DENY_FILES as $name ) {
			$path = $directory . '/' . $name;

			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			WP_Filesystem();
		}

		return $wp_filesystem instanceof \WP_Filesystem_Base && $wp_filesystem->rmdir( $directory );
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
