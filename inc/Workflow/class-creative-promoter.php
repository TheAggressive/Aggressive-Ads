<?php
/**
 * Turning a reviewed creative into an attachment.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Storage\Private_Storage;
use WP_Error;

/**
 * Promotes an approved creative from private storage into the Media Library.
 *
 * This is the second stage, and it runs **only at approval**. Doing it at
 * upload would put rejected and abandoned artwork permanently in the library,
 * and would remove the one checkpoint where the reviewed bytes are proven to
 * be the published bytes.
 *
 * That checkpoint is the sha256 re-verification. Without it, "approved"
 * describes a moment rather than an artifact: somebody could replace the file
 * between review and publication and nothing would notice.
 *
 * See docs/domain-model.md.
 */
final class Creative_Promoter {

	/**
	 * Constructor.
	 *
	 * @param Creative_Repository $creatives Creative persistence.
	 * @param Private_Storage     $storage   Private file storage.
	 */
	public function __construct(
		private readonly Creative_Repository $creatives,
		private readonly Private_Storage $storage
	) {
	}

	/**
	 * Promotes one creative, or returns why it cannot be.
	 *
	 * Idempotent: a creative already promoted returns its existing attachment
	 * rather than making a second copy, so a retried approval does not fill
	 * the library with duplicates.
	 *
	 * @param int $creative_id Creative post id.
	 * @return int|WP_Error Attachment id.
	 */
	public function promote( int $creative_id ) {
		if ( $this->creatives->has_attachment( $creative_id ) ) {
			return $this->creatives->attachment_id( $creative_id );
		}

		$details = $this->creatives->storage_details( $creative_id );

		if ( null === $details || '' === $details['path'] ) {
			return new WP_Error(
				'aggr_creative_file_missing',
				__( 'This creative has no file to publish.', 'aggressive-ads' )
			);
		}

		$path = $this->storage->resolve( $details['path'] );

		if ( null === $path ) {
			return new WP_Error(
				'aggr_creative_file_missing',
				__( 'This creative’s file could not be found.', 'aggressive-ads' )
			);
		}

		// The bytes about to go on a public site must be the bytes somebody
		// approved.
		if ( ! $this->storage->matches_checksum( $details['path'], $details['sha256'] ) ) {
			return new WP_Error(
				'aggr_creative_changed',
				__( 'This creative’s file has changed since it was reviewed, so it has not been published.', 'aggressive-ads' )
			);
		}

		$attachment_id = $this->sideload( $path, $creative_id, $details );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$this->creatives->set_attachment_id( $creative_id, $attachment_id );

		// Collected from the advertiser at upload and carried through to the
		// image itself, which is what stops the theme's render-time alt-text
		// shim from having anything to fix. See docs/accessibility.md.
		if ( '' !== $details['alt_text'] ) {
			$this->creatives->set_attachment_alt_text( $attachment_id, $details['alt_text'] );
		}

		return $attachment_id;
	}

	/**
	 * Copies the private file into the Media Library.
	 *
	 * @param string                                                                            $path        Absolute path to the private file.
	 * @param int                                                                               $creative_id Creative post id.
	 * @param array{path: string, sha256: string, mime: string, alt_text: string, name: string} $details     Stored details.
	 * @return int|WP_Error
	 */
	private function sideload( string $path, int $creative_id, array $details ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) || ! isset( $uploads['path'] ) || ! is_string( $uploads['path'] ) ) {
			return new WP_Error(
				'aggr_upload_dir_unavailable',
				__( 'The Media Library is not writable, so this creative could not be published.', 'aggressive-ads' )
			);
		}

		// A name generated from what the file is, never from what it was
		// called: the original name is a display string and stays one.
		$filename = wp_unique_filename( $uploads['path'], basename( $path ) );
		$target   = trailingslashit( $uploads['path'] ) . $filename;

		if ( ! copy( $path, $target ) ) {
			return new WP_Error(
				'aggr_promote_failed',
				__( 'This creative could not be copied into the Media Library.', 'aggressive-ads' )
			);
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $details['mime'],
				'post_title'     => '' !== $details['name'] ? $details['name'] : sprintf( 'Creative %d', $creative_id ),
				'post_status'    => 'inherit',
			),
			$target,
			0,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$attachment_id = (int) $attachment_id;

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $target )
		);

		return $attachment_id;
	}
}
