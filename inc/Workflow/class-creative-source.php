<?php
/**
 * Where one creative's bytes can be read from.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Repository\Creative_Attachment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Storage\Private_Storage;

/**
 * Finds the file behind a creative, wherever approval has left it.
 *
 * Moved out of `Campaign_Copier` when a second caller needed it: copying one
 * placement's ad onto another placement of the same size reads the same bytes
 * the same way. Two copies of "private file first, then the attachment" are
 * two places to forget that approval can clear the first.
 */
final class Creative_Source {

	/**
	 * Constructor.
	 *
	 * @param Creative_Repository            $creatives   Creative persistence.
	 * @param Creative_Attachment_Repository $attachments Media Library copy of the artwork.
	 * @param Private_Storage                $storage     Private file storage.
	 */
	public function __construct(
		private readonly Creative_Repository $creatives,
		private readonly Creative_Attachment_Repository $attachments,
		private readonly Private_Storage $storage
	) {
	}

	/**
	 * The bytes of one creative: private file first, then the promoted attachment.
	 *
	 * The private file is encrypted, so it is decrypted into a temporary file
	 * the caller must delete; the promoted attachment is ordinary Media Library
	 * bytes and is read where it lies. `temporary` says which of the two this
	 * is, because deleting the wrong one destroys a published creative.
	 *
	 * @param int $creative_id Source creative.
	 * @return array{path: string, extension: string, mime: string, sha256: string, name: string, temporary: bool}|null
	 */
	public function resolve( int $creative_id ): ?array {
		$details = $this->creatives->storage_details( $creative_id );

		if ( null === $details ) {
			return null;
		}

		$temporary = false;
		$path      = '' !== $details['path'] ? $this->storage->export( $details['path'] ) : null;

		if ( null !== $path ) {
			$temporary = true;
		} else {
			$attachment = $this->attachments->attachment_file( $creative_id );
			$path       = '' !== $attachment && is_readable( $attachment ) ? $attachment : null;
		}

		if ( null === $path ) {
			return null;
		}

		$mime = $details['mime'];
		$ext  = Upload_Rules::extension_for_mime( $mime );

		if ( '' === $ext ) {
			// From the stored name when the bytes are a decrypted temporary
			// file, because that file is named .tmp and always would be.
			$ext = strtolower(
				(string) pathinfo( $temporary ? $details['path'] : $path, PATHINFO_EXTENSION )
			);
		}

		if ( ! Upload_Rules::is_allowed_extension( $ext ) || ! Upload_Rules::is_allowed_mime( $mime ) ) {
			if ( $temporary ) {
				wp_delete_file( $path );
			}

			return null;
		}

		return array(
			'path'      => $path,
			'extension' => $ext,
			'mime'      => $mime,
			'sha256'    => $details['sha256'],
			'name'      => $details['name'],
			'temporary' => $temporary,
		);
	}
}
