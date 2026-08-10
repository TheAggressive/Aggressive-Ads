<?php
/**
 * What an uploaded creative is allowed to be.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Domain;

/**
 * The upload allowlist and its limits, as pure decisions.
 *
 * Every uploaded file is hostile input. These are the value-level rules; the
 * workflow layer additionally checks the file's actual content, because none
 * of this can be decided from a filename.
 */
final class Upload_Rules {

	public const ERROR_NO_FILE          = 'upload_no_file';
	public const ERROR_TOO_LARGE        = 'upload_too_large';
	public const ERROR_TOO_MANY_PIXELS  = 'upload_too_many_pixels';
	public const ERROR_TYPE_NOT_ALLOWED = 'upload_type_not_allowed';
	public const ERROR_TYPE_MISMATCH    = 'upload_type_mismatch';
	public const ERROR_NOT_AN_IMAGE     = 'upload_not_an_image';
	public const ERROR_FAILED           = 'upload_failed';

	/**
	 * Two megabytes.
	 *
	 * Generous for a banner and small enough that a decompression bomb has
	 * little room to work with.
	 */
	public const MAX_BYTES = 2097152;

	/**
	 * Twenty-five million pixels.
	 *
	 * A decompression bomb is a small file that expands to gigabytes once
	 * decoded. This cap is applied to the dimensions read from the header,
	 * **before** anything touches the pixels — a check that runs after
	 * decoding never runs at all, because the request is already dead.
	 */
	public const MAX_PIXELS = 25000000;

	/**
	 * MIME types a creative may be.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_MIME = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/**
	 * Extensions a creative may use.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

	/**
	 * Types refused outright, whatever the site's own settings say.
	 *
	 * An accepted SVG is an XML document with <script> support rendering
	 * inline on a public page — stored XSS executing with each visitor's
	 * session. `svg-support` is active on this site, so WordPress will accept
	 * SVG uploads generally; this list is deliberately independent of
	 * `upload_mimes` so a site-wide setting cannot re-open it.
	 *
	 * @var array<int, string>
	 */
	public const DENIED_EXTENSIONS = array( 'svg', 'svgz', 'php', 'phtml', 'phar', 'js', 'html', 'htm', 'swf', 'xml' );

	/**
	 * MIME types refused outright.
	 *
	 * @var array<int, string>
	 */
	public const DENIED_MIME = array( 'image/svg+xml', 'text/html', 'application/xml', 'text/xml' );

	/**
	 * Whether a MIME type is acceptable.
	 *
	 * @param string $mime Server-detected MIME type.
	 * @return bool
	 */
	public static function is_allowed_mime( string $mime ): bool {
		$mime = strtolower( trim( $mime ) );

		if ( in_array( $mime, self::DENIED_MIME, true ) ) {
			return false;
		}

		return in_array( $mime, self::ALLOWED_MIME, true );
	}

	/**
	 * Whether an extension is acceptable.
	 *
	 * @param string $extension Extension, without the dot.
	 * @return bool
	 */
	public static function is_allowed_extension( string $extension ): bool {
		$extension = strtolower( ltrim( trim( $extension ), '.' ) );

		if ( in_array( $extension, self::DENIED_EXTENSIONS, true ) ) {
			return false;
		}

		return in_array( $extension, self::ALLOWED_EXTENSIONS, true );
	}

	/**
	 * The extension a MIME type should carry.
	 *
	 * Used to name the stored file from what it actually is, rather than from
	 * what it claimed to be.
	 *
	 * @param string $mime Server-detected MIME type.
	 * @return string Empty when the type is not allowed.
	 */
	public static function extension_for_mime( string $mime ): string {
		return match ( strtolower( trim( $mime ) ) ) {
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			default      => '',
		};
	}

	/**
	 * Whether a file is too big.
	 *
	 * @param int $bytes File size.
	 * @return bool
	 */
	public static function exceeds_size( int $bytes ): bool {
		return $bytes > self::MAX_BYTES;
	}

	/**
	 * Whether an image would decode to too many pixels.
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 * @return bool
	 */
	public static function exceeds_pixels( int $width, int $height ): bool {
		if ( $width < 1 || $height < 1 ) {
			return true;
		}

		return ( $width * $height ) > self::MAX_PIXELS;
	}

	/**
	 * A filename that cannot be used to escape a directory or confuse a parser.
	 *
	 * The stored name is always generated, never the client's — this exists so
	 * the original can be kept as a display string without ever being trusted.
	 *
	 * @param string $name Client-supplied filename.
	 * @return string
	 */
	public static function safe_display_name( string $name ): string {
		$name = str_replace( array( "\0", '/', '\\' ), '', $name );
		$name = preg_replace( '/[\x00-\x1F\x7F]/', '', $name );

		if ( ! is_string( $name ) ) {
			return 'creative';
		}

		// Leading dots go too. Nothing here is ever used as a path — the stored
		// filename is always generated — but this string is the one a download
		// header would carry, and a name beginning `..` or `.` is the kind of
		// detail that becomes a problem the day somebody uses it that way.
		$name = ltrim( trim( $name ), '.' );

		if ( '' === $name ) {
			return 'creative';
		}

		return mb_substr( $name, 0, 120 );
	}
}
