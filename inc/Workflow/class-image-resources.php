<?php
/**
 * Read exactly the image resources a revision owns, from private or published storage.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Image_Set;
use Aggressive\Ads\Repository\Image_Resource_Repository;
use Aggressive\Ads\Storage\Private_Storage;

/** Shared by authorized preview, copying and publication integrity checks. */
final class Image_Resources {

	/**
	 * Resolve exact revision bytes across private and published storage.
	 *
	 * @param Image_Resource_Repository $records Current revision/source binding.
	 * @param Private_Storage           $storage Encrypted source reads.
	 */
	public function __construct( private readonly Image_Resource_Repository $records, private readonly Private_Storage $storage ) {}

	/**
	 * Resolve the complete set or refuse; never return only the readable prefix.
	 *
	 * @param int $id Authorized revision id.
	 * @return array{manifest:array<string,mixed>,images:array<string,string>,sha256:string}|null
	 */
	public function read( int $id ): ?array {
		return $this->resolve( $id, false );
	}

	/**
	 * Verify public copies even if a private original still exists after a failed purge.
	 *
	 * @param int $id Authorized revision.
	 * @return array{manifest:array<string,mixed>,images:array<string,string>,sha256:string}|null
	 */
	public function published( int $id ): ?array {
		return $this->resolve( $id, true );
	}

	/**
	 * Resolve the requested storage stage without returning an incomplete prefix.
	 *
	 * @param int  $id Revision id.
	 * @param bool $published_only Require the actual public copies.
	 * @return array{manifest:array<string,mixed>,images:array<string,string>,sha256:string}|null
	 */
	private function resolve( int $id, bool $published_only ): ?array {
		$source = $this->records->source( $id );
		if ( ! is_array( $source ) ) {
			return null;
		}
		$manifest = $source['manifest'];
		$upload   = $source['upload'];
		$artifact = ! $published_only && '' !== $upload['path'] ? $this->storage->read( $upload['path'], Image_Set::MAX_ENCODED_BYTES ) : null;
		if ( null !== $artifact && hash_equals( $upload['sha256'], hash( 'sha256', $artifact ) ) ) {
			$images = Image_Set::MIME === $upload['mime'] ? Image_Set::unpack( $artifact, $manifest ) : array( $manifest['base'] => $artifact );
			if ( null !== $images ) {
				return array(
					'manifest' => $manifest,
					'images'   => $images,
					'sha256'   => $upload['sha256'],
				);
			}
		}
		$receipt = $this->records->publication( $id );
		if ( null === $receipt ) {
			return null;
		}
		$uploads = wp_get_upload_dir();
		$root    = realpath( $uploads['basedir'] );
		if ( false === $root ) {
			return null;
		}
		$images = array();
		foreach ( $receipt['resources'] as $size => $resource ) {
			$path = realpath( $root . '/' . $resource['file'] );
			if ( false === $path || ! str_starts_with( $path, $root . DIRECTORY_SEPARATOR ) || ! is_file( $path ) ) {
				return null;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Contained local publication file, read with an explicit byte cap rather than an HTTP client.
			$stream = fopen( $path, 'rb' );
			if ( false === $stream ) {
				return null;
			}
			try {
				$bytes = stream_get_contents( $stream, Image_Set::MAX_BYTES + 1 );
			} finally {
				fclose( $stream );
			}
			$expected = $manifest['resources'][ $size ];
			if ( false === $bytes || strlen( $bytes ) !== $expected['bytes'] || ! hash_equals( $expected['sha256'], hash( 'sha256', $bytes ) ) ) {
				return null;
			}
			$images[ $size ] = $bytes;
		}
		$artifact = count( $images ) > 1 ? Image_Set::pack( $manifest, $images ) : $images[ $manifest['base'] ];
		return null !== $artifact && hash_equals( $upload['sha256'], hash( 'sha256', $artifact ) ) ? array(
			'manifest' => $manifest,
			'images'   => $images,
			'sha256'   => $upload['sha256'],
		) : null;
	}

	/**
	 * Reconstruct the exact source after private retention, so copying cannot lose mobile renditions.
	 *
	 * @param int $id Authorized source revision.
	 * @return array{path:string,extension:string,mime:string,sha256:string,name:string,temporary:bool,manifest:array<string,mixed>}|null
	 */
	public function export( int $id ): ?array {
		$set = $this->read( $id );
		if ( null === $set ) {
			return null;
		}
		$manifest = $set['manifest'];
		$base     = $manifest['resources'][ $manifest['base'] ];
		$multiple = count( $set['images'] ) > 1;
		$artifact = $multiple ? Image_Set::pack( $manifest, $set['images'] ) : $set['images'][ $manifest['base'] ];
		if ( null === $artifact ) {
			return null;
		}
		$path = wp_tempnam( 'aggr-image-source' );
		if ( '' === $path ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPressVIPMinimum.Functions.RestrictedFunctions.chmod_chmod -- Core wp_tempnam() file, not a durable filesystem write; plaintext creative bytes must be owner-readable only before writing.
		if ( ! chmod( $path, 0600 ) ) {
			wp_delete_file( $path );
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Core temporary/upload directory and generated filename; Bounded generated plaintext source, handed to a caller with an explicit cleanup contract.
		if ( strlen( $artifact ) !== file_put_contents( $path, $artifact ) ) {
			wp_delete_file( $path );
			return null;
		}
		return array(
			'path'      => $path,
			'extension' => $multiple ? Image_Set::EXTENSION : \Aggressive\Ads\Domain\Upload_Rules::extension_for_mime( $base['mime'] ),
			'mime'      => $multiple ? Image_Set::MIME : $base['mime'],
			'sha256'    => $set['sha256'],
			'name'      => $base['name'],
			'temporary' => true,
			'manifest'  => $manifest,
		);
	}
}
