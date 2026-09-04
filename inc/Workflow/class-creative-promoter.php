<?php
/**
 * Turning a reviewed creative into an attachment.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Repository\Creative_Attachment_Repository;
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
	 * @param Creative_Repository            $creatives Creative persistence.
	 * @param Creative_Attachment_Repository $attachments Media Library copy of the artwork.
	 * @param Private_Storage                $storage   Private file storage.
	 */
	public function __construct(
		private readonly Creative_Repository $creatives,
		private readonly Creative_Attachment_Repository $attachments,
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
		if ( $this->attachments->has_attachment( $creative_id ) ) {
			return $this->attachments->attachment_id( $creative_id );
		}

		$details = $this->creatives->storage_details( $creative_id );

		if ( null === $details || '' === $details['path'] ) {
			return new WP_Error(
				'aggr_creative_file_missing',
				__( 'This creative has no file to publish.', 'aggressive-ads' )
			);
		}

		if ( null === $this->storage->resolve( $details['path'] ) ) {
			return new WP_Error(
				'aggr_creative_file_missing',
				__( 'This creative’s file could not be found.', 'aggressive-ads' )
			);
		}

		$actual = $this->storage->checksum( $details['path'] );

		// Two failures, deliberately told apart. "The bytes changed" sends a
		// reviewer looking for who replaced the artwork; "the bytes cannot be
		// read" is a lost encryption key or a damaged file, and no amount of
		// looking at the campaign will explain it.
		if ( null === $actual ) {
			return new WP_Error(
				'aggr_creative_unreadable',
				__( 'This creative’s file could not be read, so it has not been published. Its stored copy may be damaged, or the site’s creative encryption key may have changed.', 'aggressive-ads' )
			);
		}

		// The bytes about to go on a public site must be the bytes somebody
		// approved.
		if ( '' === $details['sha256'] || ! hash_equals( $details['sha256'], $actual ) ) {
			return new WP_Error(
				'aggr_creative_changed',
				__( 'This creative’s file has changed since it was reviewed, so it has not been published.', 'aggressive-ads' )
			);
		}

		// Stored creative is encrypted, and the Media Library sideload wants a
		// path it can read. The plaintext exists outside private storage for
		// the length of this call and no longer — which is why the delete is in
		// a finally rather than after the happy path.
		$plaintext = $this->storage->export( $details['path'] );

		if ( null === $plaintext ) {
			return new WP_Error(
				'aggr_creative_unreadable',
				__( 'This creative’s file could not be read, so it has not been published. Its stored copy may be damaged, or the site’s creative encryption key may have changed.', 'aggressive-ads' )
			);
		}

		try {
			$attachment_id = $this->sideload( $plaintext, $creative_id, $details );
		} finally {
			wp_delete_file( $plaintext );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Marked before the id is recorded: an attachment that exists but is not
		// yet linked should still be out of the library, not briefly in it.
		$this->attachments->mark_attachment_as_creative( $attachment_id, $creative_id );

		if ( ! $this->attachments->set_attachment_id( $creative_id, $attachment_id ) ) {
			wp_delete_attachment( $attachment_id, true );

			return new WP_Error(
				'aggr_creative_link_failed',
				__( 'This creative could not be linked to its Media Library file.', 'aggressive-ads' )
			);
		}

		// Collected from the advertiser at upload and carried through to the
		// image itself, which is what stops the theme's render-time alt-text
		// shim from having anything to fix. See docs/accessibility.md.
		if ( '' !== $details['alt_text'] ) {
			$this->attachments->set_attachment_alt_text( $attachment_id, $details['alt_text'] );
		}

		$this->drop_private_original( $creative_id, $details['path'] );

		return $attachment_id;
	}

	/**
	 * Removes the private original once the public copy is in place.
	 *
	 * Private storage lives under wp-content/uploads, so its contents are
	 * web-reachable on any server that does not read .htaccess — nginx among
	 * them. Filenames are UUIDs and nothing ever emits one, but the smallest
	 * exposed set is the one holding only creative still awaiting review. An
	 * approved creative is delivered from its attachment; keeping the original
	 * left a second copy in the private directory for the campaign's whole life
	 * plus the ninety-day retention window.
	 *
	 * Safe because Campaign_Copier resolves bytes from the private file *or*
	 * the promoted attachment, so renew and duplicate keep working.
	 *
	 * Deliberately not fatal. The bytes are published and the approval has
	 * happened; a file that will not delete is a retention problem for the daily
	 * sweep, not a reason to fail an approval and leave the campaign in a state
	 * the reviewer cannot explain.
	 *
	 * @param int    $creative_id Creative post id.
	 * @param string $path        Stored private path, relative to the root.
	 * @return void
	 */
	private function drop_private_original( int $creative_id, string $path ): void {
		if ( '' === $path ) {
			return;
		}

		if ( $this->storage->delete( $path ) ) {
			$this->creatives->clear_private_file( $creative_id );
		}
	}

	/**
	 * Copies the private file into the Media Library.
	 *
	 * @param string                                                                            $path        Absolute path to readable plaintext bytes.
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
		//
		// Taken from the *stored* name rather than from $path, which is a
		// decrypted temporary file and therefore ends in .tmp. Deriving the
		// Media Library filename from it would publish every creative with the
		// wrong extension.
		$filename = wp_unique_filename( $uploads['path'], basename( $details['path'] ) );
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
			wp_delete_file( $target );

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
