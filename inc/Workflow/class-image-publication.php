<?php
/**
 * Publish every reviewed raster in a revision before exposing its receipt.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Image_Set;
use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Creative_Attachment_Repository;
use Aggressive\Ads\Repository\Image_Resource_Repository;
use Aggressive\Ads\Storage\Private_Storage;

/** Legacy images still use their existing promoter; source-snapshot revisions use this atomic receipt. */
final class Image_Publication {

	/**
	 * Bind public resources to one encrypted, revision-owned source.
	 *
	 * @param Creative_Repository            $creatives Revision ownership and private locator.
	 * @param Creative_Attachment_Repository $attachments Existing marked attachment records.
	 * @param Image_Resource_Repository      $resources Authoritative source and publication records.
	 * @param Private_Storage                $storage Authenticated private source bytes.
	 */
	public function __construct(
		private readonly Creative_Repository $creatives,
		private readonly Creative_Attachment_Repository $attachments,
		private readonly Image_Resource_Repository $resources,
		private readonly Private_Storage $storage
	) {}

	/**
	 * Verify the entire source, create resources, then publish the bound receipt.
	 *
	 * @param int $id Revision id.
	 * @return int|\WP_Error Real base image attachment id.
	 */
	public function promote( int $id ): int|\WP_Error {
		$lock = $this->resources->claim( $id );
		if ( '' === $lock ) {
			return $this->error();
		}
		$created   = array();
		$committed = false;
		try {
			wp_cache_delete( $id, 'post_meta' );
			$source  = $this->resources->source( $id );
			$details = $this->creatives->details( $id );
			if ( ! is_array( $source ) || null === $details ) {
				return $this->error();
			}
			$existing = $this->resources->publication( $id );
			if ( null !== $existing ) {
				if ( null === ( new Image_Resources( $this->resources, $this->storage ) )->published( $id ) ) {
					return $this->error();
				}
				if ( ! $this->resources->ready( $id ) ) {
					$reviewed = $this->review( $id, $existing );
					if ( null === $reviewed || ! $this->resources->record_publication( $id, $reviewed ) ) {
						return $this->error();
					}
				}
				if ( '' !== $source['upload']['path'] && $this->storage->delete( $source['upload']['path'] ) ) {
					$this->creatives->clear_private_file( $id );
				}
				return $existing['resources'][ $source['manifest']['base'] ]['attachment_id'];
			}
			if ( ! $this->can_publish() ) {
				return $this->error();
			}
			$placements   = new \Aggressive\Ads\Repository\Placement_Repository();
			$placement_id = (int) $details['placement_id'];
			if ( ! Image_Set::fits( $source['manifest'], $placements->size( $placement_id ), array_values( $placements->size_map( $placement_id )->breakpoints() ), $placements->max_bytes( $placement_id ) ) ) {
				return $this->error();
			}
			$upload = $source['upload'];
			$bytes  = $this->storage->read( $upload['path'], Image_Set::MAX_ENCODED_BYTES );
			if ( null === $bytes || ! hash_equals( $upload['sha256'], hash( 'sha256', $bytes ) ) ) {
				return $this->error();
			}
			$manifest = $source['manifest'];
			$images   = Image_Set::MIME === $upload['mime'] ? Image_Set::unpack( $bytes, $manifest ) : array( $manifest['base'] => $bytes );
			if ( null === $images ) {
				return $this->error();
			}
			// Verify every resource before the first file becomes public.
			foreach ( $manifest['resources'] as $size => $resource ) {
				if ( ! isset( $images[ $size ] ) || strlen( $images[ $size ] ) !== $resource['bytes'] || ! hash_equals( $resource['sha256'], hash( 'sha256', $images[ $size ] ) ) ) {
					return $this->error();
				}
			}
			$published = array();
			foreach ( $manifest['resources'] as $size => $resource ) {
				$result = $this->publish_resource( $id, $resource, $images[ $size ], (string) $details['alt_text'] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$created[]          = $result['attachment_id'];
				$published[ $size ] = $result;
			}
			$receipt = array(
				'version'       => 1,
				'site_id'       => get_current_blog_id(),
				'campaign_id'   => (int) $details['campaign_id'],
				'org_id'        => (int) $details['org_id'],
				'placement_id'  => (int) $details['placement_id'],
				'manifest_hash' => Image_Set::fingerprint( $manifest ),
				'source_hash'   => $upload['sha256'],
				'resources'     => $published,
			);
			$receipt = $this->review( $id, $receipt );
			if ( null === $receipt ) {
				return $this->error();
			}
			$base_id = $published[ $manifest['base'] ]['attachment_id'];
			if ( ! $this->resources->record_publication( $id, $receipt ) || ! $this->attachments->set_attachment_id( $id, $base_id ) || ! $this->resources->ready( $id ) ) {
				return $this->error();
			}
			$committed = true;
			if ( $this->storage->delete( $upload['path'] ) ) {
				$this->creatives->clear_private_file( $id );
			}
			return $base_id;
		} finally {
			if ( ! $committed && array() !== $created ) {
				$this->resources->discard_publication( $id, $created );
				$this->attachments->discard_resources( $created );
			}
			$this->resources->release( $lock );
		}
	}

	/**
	 * The artifact's durable approval/audit proof is committed atomically with its resource receipt.
	 *
	 * @param int                 $id Exact revision.
	 * @param array<string,mixed> $receipt Published resource identities.
	 * @return array<string,mixed>|null
	 */
	private function review( int $id, array $receipt ): ?array {
		if ( ! $this->can_publish() ) {
			return null;
		}
		$receipt['review'] = array(
			'revision_id' => $id,
			'actor_id'    => get_current_user_id(),
			'at'          => time(),
			'digest'      => $this->resources->review_digest( $id, $receipt ),
		);
		return $receipt;
	}

	/** Publication remains a separate authority from review or source ownership. */
	private function can_publish(): bool {
		return get_current_user_id() > 0 && current_user_can( \Aggressive\Ads\Security\Capabilities::REVIEW_CAMPAIGNS ) && current_user_can( \Aggressive\Ads\Security\Capabilities::PUBLISH_TO_ADSANITY );
	}

	/**
	 * Preserve the exact bytes and dimensions, without automatic image resizing.
	 *
	 * @param int                 $id Owning revision.
	 * @param array<string,mixed> $raster Reviewed resource facts.
	 * @param string              $bytes Plaintext raster bytes.
	 * @param string              $alt Approved alternative text.
	 * @return array{attachment_id:int,file:string,sha256:string}|\WP_Error
	 */
	private function publish_resource( int $id, array $raster, string $bytes, string $alt ): array|\WP_Error {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return $this->error();
		}
		$extension = Upload_Rules::extension_for_mime( $raster['mime'] );
		$name      = wp_generate_uuid4() . '.' . $extension;
		$relative  = ltrim( $uploads['subdir'] . '/' . $name, '/' );
		$target    = trailingslashit( $uploads['path'] ) . $name;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Core temporary/upload directory and generated filename; Reviewed bounded raster bytes, generated filename; failures are compensated before a publication receipt is exposed.
		if ( strlen( $bytes ) !== file_put_contents( $target, $bytes ) ) {
			wp_delete_file( $target );
			return $this->error();
		}
		$attachment_id = $this->attachments->create_raster_resource( $id, $target, $relative, $raster, $alt );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $target );
			return $this->error();
		}
		return array(
			'attachment_id' => $attachment_id,
			'file'          => $relative,
			'sha256'        => $raster['sha256'],
		);
	}

	/** Refuse partial or unreadable publication without revealing source details. */
	private function error(): \WP_Error {
		return new \WP_Error( 'aggr_image_publication_failed', __( 'The image set could not be verified and published. Please try again.', 'aggressive-ads' ) );
	}
}
