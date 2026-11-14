<?php
/**
 * The native raster handler: a single image or one reviewed responsive set.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Image_Set;
use Aggressive\Ads\Storage\Private_Storage;

/** Reuses the hostile-image validator; a set never bypasses its per-file checks. */
final class Image_Handler {

	/**
	 * Validate members before staging one encrypted source.
	 *
	 * @param Creative_Uploader $uploader Existing raster validation and encryption.
	 * @param Private_Storage   $storage Bounded encrypted source artifacts.
	 */
	public function __construct( private readonly Creative_Uploader $uploader, private readonly Private_Storage $storage ) {}

	/**
	 * Accept one base image and optional PHP-upload rendition entries.
	 *
	 * @param array<string,mixed> $file Base upload, with optional rendition uploads.
	 * @param int                 $max_bytes Placement limit for each raster.
	 * @param string              $base Required base size.
	 * @param array<int,string>   $sizes Existing placement-map sizes.
	 * @return array{path:string,token:string,sha256:string,bytes:int,mime:string,width:int,height:int,name:string,manifest?:array{version:int,base:string,resources:array<string,array{width:int,height:int,bytes:int,mime:string,sha256:string,name:string}>}}|\WP_Error
	 */
	public function accept( array $file, int $max_bytes, string $base, array $sizes ): array|\WP_Error {
		$sizes[] = $base;
		$extra   = array_key_exists( 'renditions', $file ) ? $file['renditions'] : array();
		if ( ! is_array( $extra ) || ! array_is_list( $extra ) || count( $extra ) >= Image_Set::MAX_RESOURCES ) {
			return $this->error();
		}
		if ( array() === $extra ) {
			return $this->uploader->accept( $file, $max_bytes );
		}
		if ( ! ( new \Aggressive\Ads\Repository\Image_Projection_Repository() )->available() ) {
			return new \WP_Error(
				'aggr_image_set_unavailable',
				__( 'Responsive image delivery is not ready. Ask the publisher to complete the database upgrade, then try again.', 'aggressive-ads' ),
				array(
					'status' => 503,
					'field'  => 'file',
				)
			);
		}
		$accepted  = array();
		$resources = array();
		$images    = array();
		$total     = 0;
		$started   = hrtime( true );
		try {
			foreach ( array_merge( array( $file ), $extra ) as $index => $input ) {
				if ( ! is_array( $input ) || ( isset( $input['renditions'] ) && $index > 0 ) ) {
					return $this->error();
				}
				$row = $this->uploader->accept( $input, $max_bytes );
				if ( is_wp_error( $row ) ) {
					return $row;
				}
				$accepted[] = $row;
				$size       = $row['width'] . 'x' . $row['height'];
				$total     += $row['bytes'];
				if ( ( 0 === $index && $size !== $base ) || ! in_array( $size, $sizes, true ) || isset( $resources[ $size ] ) || $total > Image_Set::MAX_BYTES || hrtime( true ) - $started > 5_000_000_000 ) {
					return $this->error();
				}
				$bytes = $this->storage->read( $row['path'], Image_Set::MAX_BYTES );
				if ( null === $bytes ) {
					return $this->storage_error();
				}
				$images[ $size ]    = $bytes;
				$resources[ $size ] = array_intersect_key( $row, array_flip( array( 'width', 'height', 'bytes', 'mime', 'sha256', 'name' ) ) );
			}
			$manifest = Image_Set::create( $base, $resources );
			if ( null === $manifest ) {
				return $this->error();
			}
			$artifact = Image_Set::pack( $manifest, $images );
			if ( null === $artifact ) {
				return $this->error();
			}
			$temp = wp_tempnam( 'aggr-image-set' );
			if ( '' === $temp ) {
				return $this->storage_error();
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPressVIPMinimum.Functions.RestrictedFunctions.chmod_chmod -- Core wp_tempnam() file, not a durable filesystem write; plaintext creative bytes must be owner-readable only before writing.
			if ( ! chmod( $temp, 0600 ) ) {
				wp_delete_file( $temp );
				return $this->storage_error();
			}
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Core temporary/upload directory and generated filename; Bounded generated plaintext source, removed in finally; durable storage encrypts it.
				if ( strlen( $artifact ) !== file_put_contents( $temp, $artifact ) ) {
					return $this->storage_error();
				}
				$stored = $this->storage->store( $temp, Image_Set::EXTENSION );
			} finally {
				wp_delete_file( $temp );
			}
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
			if ( hrtime( true ) - $started > 5_000_000_000 ) {
				$this->storage->delete( $stored['path'] );
				return $this->error();
			}
			return array_merge(
				$accepted[0],
				$stored,
				array(
					'mime'     => Image_Set::MIME,
					'manifest' => $manifest,
				)
			);
		} finally {
			foreach ( $accepted as $row ) {
				$this->storage->delete( $row['path'] );
			}
		}
	}

	/**
	 * Human-facing upload facts describe the rasters, not the encrypted container.
	 *
	 * @param array<string,mixed> $upload Accepted source.
	 * @return array{mime:string,bytes:int,name:string}
	 */
	public static function summary( array $upload ): array {
		$manifest = $upload['manifest'] ?? null;
		if ( Image_Set::valid( $manifest ) ) {
			$base = $manifest['resources'][ $manifest['base'] ];
			return array(
				'mime'  => $base['mime'],
				'bytes' => array_sum( array_column( $manifest['resources'], 'bytes' ) ),
				'name'  => $base['name'],
			);
		}
		return array(
			'mime'  => (string) $upload['mime'],
			'bytes' => (int) $upload['bytes'],
			'name'  => (string) $upload['name'],
		);
	}

	/** Storage failures must not be reported as invalid advertiser artwork. */
	private function storage_error(): \WP_Error {
		return new \WP_Error(
			'aggr_creative_not_created',
			__( 'The creative could not be saved. Please try again.', 'aggressive-ads' ),
			array(
				'status' => 500,
				'field'  => 'file',
			)
		);
	}

	/** No raw filenames, paths or internal failures in public errors. */
	private function error(): \WP_Error {
		return new \WP_Error(
			'aggr_image_set_invalid',
			__( 'Choose a base image and up to five different renditions in the placement’s supported sizes, totaling no more than 2 MB.', 'aggressive-ads' ),
			array(
				'status' => 422,
				'field'  => 'file',
			)
		);
	}
}
