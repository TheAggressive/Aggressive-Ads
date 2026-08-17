<?php
/**
 * Accepting a creative file.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Storage\Private_Storage;
use WP_Error;

/**
 * Validates an uploaded file and stores it privately.
 *
 * Everything the browser says about the file is ignored. The claimed MIME type
 * is a request header, the extension is part of a filename the client chose,
 * and both are trivially wrong on purpose. What is checked instead is what the
 * bytes actually are.
 *
 * See docs/threat-model.md for the attacks each step is aimed at.
 */
final class Creative_Uploader {

	/**
	 * Constructor.
	 *
	 * @param Private_Storage $storage Private file storage.
	 */
	public function __construct( private readonly Private_Storage $storage ) {
	}

	/**
	 * Validates and stores one uploaded file.
	 *
	 * @param array<string, mixed> $file One entry from $_FILES.
	 * @return array{path: string, token: string, sha256: string, bytes: int, mime: string, width: int, height: int, name: string}|WP_Error
	 */
	public function accept( array $file ) {
		$tmp = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';

		if ( '' === $tmp || ! is_file( $tmp ) ) {
			return $this->error( Upload_Rules::ERROR_NO_FILE );
		}

		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return $this->error( Upload_Rules::ERROR_FAILED );
		}

		$bytes = filesize( $tmp );

		if ( false === $bytes || Upload_Rules::exceeds_size( (int) $bytes ) ) {
			return $this->error( Upload_Rules::ERROR_TOO_LARGE );
		}

		$claimed_name = isset( $file['name'] ) && is_string( $file['name'] ) ? $file['name'] : 'creative';

		/*
		 * getimagesize() reads the header only, so the dimension cap is applied
		 * before anything decodes the image. A decompression bomb is a small
		 * file that expands to gigabytes during decoding — a validator that
		 * runs afterwards never runs at all, because the request is already
		 * dead.
		 */
		$dimensions = getimagesize( $tmp );

		// False for anything it cannot read as an image, which is the check
		// that a file merely *named* .png does not survive.
		if ( ! is_array( $dimensions ) ) {
			return $this->error( Upload_Rules::ERROR_NOT_AN_IMAGE );
		}

		$width  = (int) $dimensions[0];
		$height = (int) $dimensions[1];

		if ( Upload_Rules::exceeds_pixels( $width, $height ) ) {
			return $this->error( Upload_Rules::ERROR_TOO_MANY_PIXELS );
		}

		$detected = $dimensions['mime'];

		if ( ! Upload_Rules::is_allowed_mime( $detected ) ) {
			return $this->error( Upload_Rules::ERROR_TYPE_NOT_ALLOWED );
		}

		$agreement = $this->types_agree( $tmp, $claimed_name, $detected );

		if ( is_wp_error( $agreement ) ) {
			return $agreement;
		}

		$stored = $this->storage->store( $tmp, Upload_Rules::extension_for_mime( $detected ) );

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return array(
			'path'   => $stored['path'],
			'token'  => $stored['token'],
			'sha256' => $stored['sha256'],
			'bytes'  => $stored['bytes'],
			'mime'   => $detected,
			'width'  => $width,
			'height' => $height,
			'name'   => Upload_Rules::safe_display_name( $claimed_name ),
		);
	}

	/**
	 * Requires WordPress's own type check to agree with the image header.
	 *
	 * **If core "corrected" the extension, this rejects rather than accepting
	 * the correction.** A correction means the claimed type and the real type
	 * differ, and that difference is the attack: a polyglot file that is both
	 * a valid image and a valid archive or script passes a check that only
	 * asks "is this an image?".
	 *
	 * @param string $path         Absolute path to the file.
	 * @param string $claimed_name Client-supplied filename.
	 * @param string $detected     MIME type read from the image header.
	 * @return true|WP_Error
	 */
	private function types_agree( string $path, string $claimed_name, string $detected ) {
		$checked = wp_check_filetype_and_ext( $path, $claimed_name );

		$ext  = isset( $checked['ext'] ) && is_string( $checked['ext'] ) ? $checked['ext'] : '';
		$type = isset( $checked['type'] ) && is_string( $checked['type'] ) ? $checked['type'] : '';

		if ( '' === $ext || '' === $type ) {
			return $this->error( Upload_Rules::ERROR_TYPE_NOT_ALLOWED );
		}

		if ( ! empty( $checked['proper_filename'] ) ) {
			return $this->error( Upload_Rules::ERROR_TYPE_MISMATCH );
		}

		if ( ! Upload_Rules::is_allowed_extension( $ext ) || ! Upload_Rules::is_allowed_mime( $type ) ) {
			return $this->error( Upload_Rules::ERROR_TYPE_NOT_ALLOWED );
		}

		// jpg and jpeg are the same type under different names; anything else
		// disagreeing means the header and the filename tell different stories.
		if ( $type !== $detected ) {
			return $this->error( Upload_Rules::ERROR_TYPE_MISMATCH );
		}

		return true;
	}

	/**
	 * The sentence an advertiser reads for an upload problem.
	 *
	 * Deliberately specific. "Invalid image" tells somebody nothing about
	 * which of six possible things went wrong, and they have to fix it
	 * themselves without calling anyone.
	 *
	 * @param string $code Problem code.
	 * @return WP_Error
	 */
	private function error( string $code ): WP_Error {
		$message = match ( $code ) {
			Upload_Rules::ERROR_NO_FILE => __( 'No file was received. Please choose a file and try again.', 'aggressive-ads' ),
			Upload_Rules::ERROR_TOO_LARGE => sprintf(
				/* translators: %s: maximum file size, e.g. 2 MB. */
				__( 'That file is larger than %s. Please save it at a smaller size and try again.', 'aggressive-ads' ),
				size_format( Upload_Rules::MAX_BYTES )
			),
			Upload_Rules::ERROR_TOO_MANY_PIXELS => __( 'That ad creative has too many pixels to process. Please save it at the size the placement asks for.', 'aggressive-ads' ),
			Upload_Rules::ERROR_NOT_AN_IMAGE    => __( 'That file is not an image we can read. JPEG, PNG, GIF and WebP are supported.', 'aggressive-ads' ),
			Upload_Rules::ERROR_TYPE_MISMATCH   => __( 'That file does not match the type its name suggests, so it has not been accepted.', 'aggressive-ads' ),
			Upload_Rules::ERROR_FAILED          => __( 'The upload did not complete. Please try again.', 'aggressive-ads' ),
			default                             => __( 'That file type is not supported. JPEG, PNG, GIF and WebP are.', 'aggressive-ads' ),
		};

		return new WP_Error( 'aggr_' . $code, $message );
	}
}
