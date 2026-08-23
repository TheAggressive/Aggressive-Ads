<?php
/**
 * Authenticated encryption for creative held in private storage.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Storage;

use WP_Error;

/**
 * Encrypts unapproved creative at rest, and reads it back a chunk at a time.
 *
 * Private storage already leans on an unguessable path and an authorized
 * streaming endpoint. Neither of those helps if the bytes themselves are
 * readable by anything that can open the file: a server misconfiguration that
 * serves the uploads directory, a backup copied somewhere less careful, a
 * shared-hosting neighbour, or a restore handed to somebody who was never
 * meant to see an embargoed campaign. Encryption is the layer that does not
 * depend on the web server, the filesystem permissions, or where the backup
 * ended up.
 *
 * **Streaming, not whole-file.** `secretstream` is used rather than
 * `crypto_secretbox` because the reviewer endpoint streams: a 10 MB creative
 * must not become 10 MB of PHP memory to serve, and a truncated file must be
 * detectable rather than decrypting into a valid-looking prefix. Each chunk is
 * authenticated, chunks cannot be reordered or dropped, and the last one
 * carries the FINAL tag, so a truncation is a read failure and not a
 * half-image.
 *
 * The on-disk format, which is why a header exists at all:
 *
 *     0   8 bytes   "AGGRENC1"  — magic; also how a legacy plaintext file is
 *                                 recognised, since no allowed image format
 *                                 begins with it
 *     8   8 bytes   key fingerprint — so the wrong key is a named failure
 *                                 rather than an authentication error nobody
 *                                 can interpret
 *     16  24 bytes  secretstream header
 *     40  …         [4-byte big-endian length][ciphertext] per chunk
 */
final class Creative_Cipher {

	/**
	 * File magic. Eight bytes, versioned, so a second format can coexist.
	 */
	public const MAGIC = 'AGGRENC1';

	/**
	 * Optional wp-config.php constant holding the key.
	 *
	 * Base64 or hex, 32 bytes decoded. Defining it keeps the key out of the
	 * database entirely, so a read of the database alone — a SQL injection, a
	 * leaked dump, a support copy — does not carry the means to decrypt what
	 * it references.
	 */
	public const KEY_CONSTANT = 'AGGR_CREATIVE_KEY';

	/**
	 * Where the key lives when the constant is not defined.
	 *
	 * Not autoloaded: it is read only on a path that is already touching the
	 * filesystem, and an autoloaded secret is one that is in the object cache
	 * of every request on the site.
	 */
	public const KEY_OPTION = 'aggr_creative_key';

	/**
	 * Plaintext bytes per chunk.
	 */
	private const CHUNK_BYTES = 65536;

	/**
	 * The largest ciphertext chunk length this will act on.
	 *
	 * A declared length is read from the file before the buffer is allocated,
	 * so without a bound a corrupted or hostile header is an out-of-memory.
	 */
	private const MAX_CHUNK_BYTES = 1048576;

	/**
	 * Bytes of the key digest kept as its fingerprint.
	 */
	private const FINGERPRINT_BYTES = 8;

	/**
	 * Bytes holding the declared plaintext length.
	 */
	private const LENGTH_BYTES = 8;

	/**
	 * The resolved key, cached for the request.
	 *
	 * @var string|null
	 */
	private ?string $cached_key = null;

	/**
	 * Whether libsodium is usable in this process.
	 *
	 * WordPress has shipped sodium_compat since 5.2 and loads it when the
	 * extension is absent, so this is expected to be true everywhere. It is
	 * still checked rather than assumed, because the alternative to knowing is
	 * a fatal error on the upload path.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_push' )
			&& function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_pull' );
	}

	/**
	 * The encryption key, 32 raw bytes.
	 *
	 * Deliberately **not** derived from `wp_salt()`, which is what an earlier
	 * sketch of this proposed. Salts rotate — that is what they are for — and
	 * this plugin has already paid for keying durable data with one: the
	 * organization name registry was orphaned by a rotation and had to be
	 * rekeyed in schema v10. A rotation that silently makes every creative
	 * awaiting review undecryptable is the same defect with a worse blast
	 * radius, because the bytes cannot be rebuilt from anything the site still
	 * holds. `token_hash` still uses `wp_salt( 'auth' )` on purpose: it
	 * verifies a bearer token, where a rotation *should* invalidate.
	 *
	 * @return string|WP_Error
	 */
	public function key(): string|WP_Error {
		if ( null !== $this->cached_key ) {
			return $this->cached_key;
		}

		if ( defined( self::KEY_CONSTANT ) ) {
			$decoded = self::decode( (string) constant( self::KEY_CONSTANT ) );

			if ( null === $decoded ) {
				// Never fall through to the stored key. An operator who set the
				// constant believes that is the key in use, and quietly using a
				// different one would encrypt today's uploads with a key their
				// backup procedure does not know about.
				return new WP_Error(
					'aggr_creative_key_invalid',
					__( 'The creative encryption key is not valid. It must be 32 bytes, base64 or hex encoded.', 'aggressive-ads' )
				);
			}

			$this->cached_key = $decoded;

			return $decoded;
		}

		$stored = self::decode( (string) get_option( self::KEY_OPTION, '' ) );

		if ( null === $stored ) {
			$generated = sodium_crypto_secretstream_xchacha20poly1305_keygen();

			// add_option is the closest thing to an atomic test-and-set here.
			// Two uploads racing on a fresh install must not each generate a
			// key and encrypt with the one the other is about to overwrite.
			add_option( self::KEY_OPTION, sodium_bin2base64( $generated, SODIUM_BASE64_VARIANT_ORIGINAL ), '', false );

			$stored = self::decode( (string) get_option( self::KEY_OPTION, '' ) );
		}

		if ( null === $stored ) {
			return new WP_Error(
				'aggr_creative_key_unavailable',
				__( 'The creative encryption key could not be read or created.', 'aggressive-ads' )
			);
		}

		$this->cached_key = $stored;

		return $stored;
	}

	/**
	 * A short, non-secret identifier for a key.
	 *
	 * Written into every file so that "this was encrypted with a key this site
	 * no longer has" is a distinguishable answer. Without it the only symptom
	 * of a lost or replaced key is an authentication failure, which reads
	 * identically to a corrupted file.
	 *
	 * @param string $key Raw key bytes.
	 * @return string Raw fingerprint bytes.
	 */
	public function fingerprint( string $key ): string {
		return substr( hash( 'sha256', $key, true ), 0, self::FINGERPRINT_BYTES );
	}

	/**
	 * Whether a file carries this format's magic.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function is_encrypted( string $path ): bool {
		if ( ! is_readable( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reads eight bytes to identify a format. WP_Filesystem has no partial read and would load the whole creative to answer.
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return false;
		}

		$magic = fread( $handle, strlen( self::MAGIC ) );

		fclose( $handle );

		return self::MAGIC === $magic;
	}

	/**
	 * Encrypts one file into another.
	 *
	 * The target is written from nothing; the caller owns moving it into place
	 * and owns deleting it if this returns false. Nothing here overwrites the
	 * source, because a half-written in-place encryption is an advertiser's
	 * only copy of their artwork destroyed.
	 *
	 * @param string $source Absolute path to plaintext.
	 * @param string $target Absolute path to write.
	 * @return bool
	 */
	public function encrypt( string $source, string $target ): bool {
		$key = $this->key();

		if ( $key instanceof WP_Error || ! $this->is_available() ) {
			return false;
		}

		// Checked rather than left to fopen(), whose failure is a PHP warning
		// before it is a return value — and a warning is not a control flow.
		if ( ! is_readable( $source ) ) {
			return false;
		}

		$total = filesize( $source );

		if ( false === $total ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Chunked encryption. WP_Filesystem reads whole files, which is what streaming exists to avoid.
		$in = fopen( $source, 'rb' );

		if ( false === $in ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Chunked encryption; see above.
		$out = fopen( $target, 'wb' );

		if ( false === $out ) {
			fclose( $in );

			return false;
		}

		$ok = $this->write_stream( $in, $out, $key, $total );

		fclose( $in );
		fclose( $out );

		return $ok;
	}

	/**
	 * Reads a stored file as plaintext, one chunk at a time.
	 *
	 * A file without the magic is passed through unchanged. That is what lets
	 * an install that predates encryption keep serving the creative it already
	 * has while the migration works through it, and it is why the migration can
	 * be interrupted without taking the review queue down.
	 *
	 * @param string                 $path Absolute path.
	 * @param callable(string): bool $sink Receives each plaintext chunk; returning false aborts.
	 * @return bool Whether the whole file was read and authenticated.
	 */
	public function read( string $path, callable $sink ): bool {
		if ( ! is_readable( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Chunked read; see encrypt().
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return false;
		}

		$magic = fread( $handle, strlen( self::MAGIC ) );

		if ( self::MAGIC !== $magic ) {
			rewind( $handle );

			$ok = $this->passthrough( $handle, $sink );

			fclose( $handle );

			return $ok;
		}

		$ok = $this->read_stream( $handle, $sink );

		fclose( $handle );

		return $ok;
	}

	/**
	 * The sha256 of a stored file's plaintext.
	 *
	 * Separate from a bare hash_file() because the recorded checksum describes
	 * what the advertiser uploaded, not what is on disk. Hashing the ciphertext
	 * would mean every file failed the comparison that proves the bytes about
	 * to be published are the bytes somebody reviewed.
	 *
	 * @param string $path Absolute path.
	 * @return string|null Lowercase hex digest, or null when the file cannot be read.
	 */
	public function checksum( string $path ): ?string {
		$context = hash_init( 'sha256' );

		$ok = $this->read(
			$path,
			static function ( string $chunk ) use ( $context ): bool {
				hash_update( $context, $chunk );

				return true;
			}
		);

		return $ok ? hash_final( $context ) : null;
	}

	/**
	 * The plaintext length of a stored file, without decrypting it.
	 *
	 * @param string $path Absolute path.
	 * @return int|null Null when the file cannot be measured.
	 */
	public function plaintext_bytes( string $path ): ?int {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reads the header only. WP_Filesystem would load the whole creative to answer.
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return null;
		}

		$magic = fread( $handle, strlen( self::MAGIC ) );

		if ( self::MAGIC !== $magic ) {
			fclose( $handle );

			$size = filesize( $path );

			return false === $size ? null : (int) $size;
		}

		$declared = $this->exactly( $handle, self::FINGERPRINT_BYTES + self::LENGTH_BYTES );

		fclose( $handle );

		if ( null === $declared ) {
			return null;
		}

		$unpacked = unpack( 'J', substr( $declared, self::FINGERPRINT_BYTES ) );
		$length   = is_array( $unpacked ) ? (int) $unpacked[1] : -1;

		return $length < 0 ? null : $length;
	}

	/**
	 * Copies a stored file's plaintext into an open stream.
	 *
	 * @param string   $path   Absolute path.
	 * @param resource $target Open, writable stream.
	 * @return bool
	 */
	public function copy_to( string $path, $target ): bool {
		return $this->read(
			$path,
			static function ( string $chunk ) use ( $target ): bool {
				return false !== fwrite( $target, $chunk ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- Writes to a caller-owned stream: the HTTP response body, or a temporary file inside the uploads directory.
			}
		);
	}

	/**
	 * Decodes a configured or stored key.
	 *
	 * @param string $value Base64 or hex.
	 * @return string|null Raw 32 bytes, or null when it is neither.
	 */
	private static function decode( string $value ): ?string {
		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		if ( 1 === preg_match( '/^[0-9a-fA-F]{64}$/', $value ) ) {
			$raw = hex2bin( $value );

			return is_string( $raw ) ? $raw : null;
		}

		$decoded = base64_decode( $value, true );

		return is_string( $decoded ) && SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES === strlen( $decoded )
			? $decoded
			: null;
	}

	/**
	 * Writes the header and every encrypted chunk.
	 *
	 * @param resource $in    Open plaintext stream.
	 * @param resource $out   Open target stream.
	 * @param string   $key   Raw key.
	 * @param int      $total Plaintext length.
	 * @return bool
	 */
	private function write_stream( $in, $out, string $key, int $total ): bool {
		list( $state, $header ) = sodium_crypto_secretstream_xchacha20poly1305_init_push( $key );

		if ( ! $this->put( $out, self::MAGIC . $this->fingerprint( $key ) . pack( 'J', $total ) . $header ) ) {
			return false;
		}

		$written = 0;

		do {
			$chunk = fread( $in, self::CHUNK_BYTES );

			if ( false === $chunk ) {
				return false;
			}

			$written += strlen( $chunk );

			// The last chunk is known from the length, not from feof(): a read
			// that lands exactly on the end of the file does not set feof, so
			// a file that is an exact multiple of the chunk size would be
			// written without its FINAL tag and refuse to decrypt.
			$last = $written >= $total;

			$cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
				$state,
				$chunk,
				'',
				$last
					? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
					: SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
			);

			if ( ! $this->put( $out, pack( 'N', strlen( $cipher ) ) . $cipher ) ) {
				return false;
			}
		} while ( ! $last );

		return true;
	}

	/**
	 * Reads and authenticates every chunk of an encrypted file.
	 *
	 * @param resource               $handle Open stream, positioned after the magic.
	 * @param callable(string): bool $sink   Receives each plaintext chunk.
	 * @return bool
	 */
	private function read_stream( $handle, callable $sink ): bool {
		$key = $this->key();

		if ( $key instanceof WP_Error || ! $this->is_available() ) {
			return false;
		}

		$fingerprint = fread( $handle, self::FINGERPRINT_BYTES );

		if ( ! is_string( $fingerprint ) || ! hash_equals( $this->fingerprint( $key ), $fingerprint ) ) {
			return false;
		}

		$declared = $this->exactly( $handle, self::LENGTH_BYTES );
		$unpacked = null === $declared ? false : unpack( 'J', $declared );
		$expected = is_array( $unpacked ) ? (int) $unpacked[1] : -1;

		if ( $expected < 0 ) {
			return false;
		}

		$header = fread( $handle, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES );

		if ( ! is_string( $header ) || SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES !== strlen( $header ) ) {
			return false;
		}

		$state    = sodium_crypto_secretstream_xchacha20poly1305_init_pull( $header, $key );
		$produced = 0;

		while ( true ) {
			$length = $this->exactly( $handle, 4 );

			if ( null === $length ) {
				// Ran out of file without ever seeing the FINAL tag.
				return false;
			}

			$unpacked = unpack( 'N', $length );
			$size     = is_array( $unpacked ) ? (int) $unpacked[1] : 0;

			if ( $size < 1 || $size > self::MAX_CHUNK_BYTES ) {
				return false;
			}

			$cipher = $this->exactly( $handle, $size );

			if ( null === $cipher ) {
				return false;
			}

			$result = sodium_crypto_secretstream_xchacha20poly1305_pull( $state, $cipher );

			if ( ! is_array( $result ) ) {
				return false;
			}

			list( $plain, $tag ) = $result;

			$produced += strlen( $plain );

			if ( '' !== $plain && ! $sink( $plain ) ) {
				return false;
			}

			if ( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $tag ) {
				// Anything after the final tag means the file is not the file
				// that was written, which is worth refusing rather than
				// ignoring — as is a header claiming a length the stream did
				// not produce.
				return $produced === $expected && '' === (string) fread( $handle, 1 );
			}
		}
	}

	/**
	 * Copies a plaintext file through unchanged.
	 *
	 * @param resource               $handle Open stream at position zero.
	 * @param callable(string): bool $sink   Receives each chunk.
	 * @return bool
	 */
	private function passthrough( $handle, callable $sink ): bool {
		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, self::CHUNK_BYTES );

			if ( false === $chunk ) {
				return false;
			}

			if ( '' !== $chunk && ! $sink( $chunk ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Reads exactly this many bytes, or reports that it could not.
	 *
	 * A short read is legal — fread() may return fewer bytes than asked for —
	 * and treating one as the end of the file is how an unremarkable
	 * filesystem turns into a decryption failure.
	 *
	 * @param resource $handle Open stream.
	 * @param int      $bytes  Bytes wanted.
	 * @return string|null
	 */
	private function exactly( $handle, int $bytes ): ?string {
		$buffer = '';
		$have   = 0;

		while ( $have < $bytes ) {
			$want = $bytes - $have;

			// The loop condition already guarantees this, but stating it is
			// what keeps a zero-length fread() — which reads nothing forever —
			// unreachable rather than merely unlikely.
			if ( $want < 1 ) {
				break;
			}

			$chunk = fread( $handle, $want );

			if ( false === $chunk || '' === $chunk ) {
				return null;
			}

			$buffer .= $chunk;
			$have   += strlen( $chunk );
		}

		return $buffer;
	}

	/**
	 * Writes a whole string, or reports that it could not.
	 *
	 * @param resource $handle Open stream.
	 * @param string   $data   Bytes to write.
	 * @return bool
	 */
	private function put( $handle, string $data ): bool {
		$written = fwrite( $handle, $data ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- Writing the encrypted creative into the uploads directory, which is the one place this plugin writes.

		return false !== $written && strlen( $data ) === $written;
	}
}
