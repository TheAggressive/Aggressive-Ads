<?php
/**
 * What an uploaded creative is allowed to be.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

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
	 * The most any placement may be configured to allow: two megabytes.
	 *
	 * A ceiling, not the limit. Each placement carries its own maximum and
	 * this is the highest one an administrator can set — small enough that a
	 * decompression bomb has little room to work with, whatever a placement
	 * asks for.
	 */
	public const CEILING_MAX_BYTES = 2097152;

	/**
	 * What a placement allows when nobody has said: 150 kilobytes.
	 *
	 * The figure display advertising already runs on — comfortable for a
	 * well-compressed banner at the usual sizes, and small enough to refuse an
	 * unoptimised export, which is what most oversized creative actually is.
	 * A placement created before this setting existed reads as unset and gets
	 * this, so the safe number is the one that applies without anyone acting.
	 */
	public const DEFAULT_MAX_BYTES = 153600;

	/**
	 * The smallest maximum a placement may be given: ten kilobytes.
	 *
	 * A floor exists so a typo cannot close a placement to every creative
	 * there is. Ten kilobytes still admits a flat-colour banner, so a number
	 * below it is a mistake rather than a strict policy.
	 */
	public const FLOOR_MAX_BYTES = 10240;

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
	 * The maximum a placement actually enforces.
	 *
	 * Takes the stored number and answers with one that is safe to enforce:
	 * unset or nonsense becomes the default, and anything outside the floor
	 * and the ceiling is clamped rather than refused. Refusing would leave the
	 * caller to decide what to do with a bad stored value, and every caller
	 * deciding separately is how one of them ends up enforcing nothing.
	 *
	 * @param int $configured What the placement has stored, 0 when unset.
	 * @return int
	 */
	public static function resolve_max_bytes( int $configured ): int {
		if ( $configured < 1 ) {
			return self::DEFAULT_MAX_BYTES;
		}

		return max( self::FLOOR_MAX_BYTES, min( self::CEILING_MAX_BYTES, $configured ) );
	}

	/**
	 * Whether a file is too big for the placement it is going to.
	 *
	 * The limit is a parameter because it belongs to the placement, not to
	 * this class. Passing it in is also what stops a caller reaching for the
	 * ceiling by habit and enforcing two megabytes on a placement that asked
	 * for a hundred and fifty kilobytes.
	 *
	 * @param int $bytes     File size.
	 * @param int $max_bytes The placement's resolved maximum.
	 * @return bool
	 */
	public static function exceeds_size( int $bytes, int $max_bytes ): bool {
		return $bytes > self::resolve_max_bytes( $max_bytes );
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
