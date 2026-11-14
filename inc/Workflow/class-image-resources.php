<?php
/**
 * Read exactly the image resources a revision owns, from private or published storage.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Image_Set;
use Aggressive\Ads\Domain\Html5_Profile;
use Aggressive\Ads\Domain\Html5_Package;
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
	 * @return array{manifest:array<string,mixed>,images:array<string,string>,sha256:string,package?:array{archive:string,document:string,image:string},artifact?:string}|null
	 */
	public function read( int $id ): ?array {
		return $this->resolve( $id, false );
	}

	/**
	 * Verify public copies even if a private original still exists after a failed purge.
	 *
	 * @param int $id Authorized revision.
	 * @return array{manifest:array<string,mixed>,images:array<string,string>,sha256:string,package?:array{archive:string,document:string,image:string},artifact?:string}|null
	 */
	public function published( int $id ): ?array {
		return $this->resolve( $id, true );
	}

	/**
	 * Resolve the requested storage stage without returning an incomplete prefix.
	 *
	 * @param int  $id Revision id.
	 * @param bool $published_only Require the actual public copies.
	 * @return array{manifest:array<string,mixed>,images:array<string,string>,sha256:string,package?:array{archive:string,document:string,image:string},artifact?:string}|null
	 */
	private function resolve( int $id, bool $published_only ): ?array {
		$source = $this->records->source( $id );
		if ( ! is_array( $source ) ) {
			return null;
		}
		if ( 2 === $source['version'] ) {
			return $this->html5( $id, $source, $published_only );
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
	 * Resolve the inert publication envelope and its separately published fallback together.
	 *
	 * @param int                 $id Authorized revision.
	 * @param array<string,mixed> $source Versioned snapshot.
	 * @param bool                $published_only Require actual public resources.
	 * @return array{manifest:array<string,mixed>,images:array<string,string>,sha256:string,package:array{archive:string,document:string,image:string},artifact:string}|null
	 */
	private function html5( int $id, array $source, bool $published_only ): ?array {
		$artifact = ! $published_only && '' !== $source['upload']['path'] ? $this->storage->read( $source['upload']['path'], Html5_Profile::MAX_ARTIFACT ) : null;
		$receipt  = null;
		if ( null === $artifact ) {
			$receipt = $this->records->publication( $id );
			if ( null === $receipt ) {
				return null;
			}
			$artifact = $this->public_file( $receipt['html5']['file'], Html5_Profile::MAX_ARTIFACT );
		}
		if ( null === $artifact || ! hash_equals( $source['upload']['sha256'], hash( 'sha256', $artifact ) ) ) {
			return null;
		}
		$package = Html5_Package::unpack( $artifact, $source['html5'], $source['manifest'] );
		if ( null === $package ) {
			return null;
		}
		$base = $source['manifest']['base'];
		if ( null !== $receipt ) {
			$image = $this->public_file( $receipt['resources'][ $base ]['file'], Image_Set::MAX_BYTES );
			if ( null === $image || $image !== $package['image'] ) {
				return null;
			}
		}
		return array(
			'manifest' => $source['manifest'],
			'images'   => array( $base => $package['image'] ),
			'sha256'   => $source['upload']['sha256'],
			'package'  => $package,
			'artifact' => $artifact,
		);
	}

	/**
	 * Bounded local read, contained within this site's current public upload root.
	 *
	 * @param string       $relative Generated receipt path.
	 * @param positive-int $limit Source or raster ceiling.
	 */
	private function public_file( string $relative, int $limit ): ?string {
		$uploads = wp_get_upload_dir();
		$root    = realpath( $uploads['basedir'] );
		$path    = false !== $root ? realpath( $root . '/' . $relative ) : false;
		if ( false === $root || false === $path || ! str_starts_with( $path, $root . DIRECTORY_SEPARATOR ) || ! is_file( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Contained local publication path with an actual byte cap; never an HTTP request.
		$bytes = file_get_contents( $path, false, null, 0, $limit + 1 );
		return false !== $bytes && strlen( $bytes ) <= $limit ? $bytes : null;
	}

	/**
	 * Reconstruct the exact source after private retention, so copying cannot lose mobile renditions.
	 *
	 * @param int $id Authorized source revision.
	 * @return array{path:string,extension:string,mime:string,sha256:string,name:string,temporary:bool,manifest:array<string,mixed>,html5?:array<string,mixed>}|null
	 */
	public function export( int $id ): ?array {
		$set = $this->read( $id );
		if ( null === $set ) {
			return null;
		}
		if ( isset( $set['artifact'] ) ) {
			$source = $this->records->source( $id );
			$path   = $this->storage->temporary_bytes( $set['artifact'] );
			return null !== $path && is_array( $source ) ? array(
				'path'      => $path,
				'extension' => Html5_Profile::EXTENSION,
				'mime'      => Html5_Profile::MIME,
				'sha256'    => $set['sha256'],
				'name'      => $source['upload']['name'],
				'temporary' => true,
				'manifest'  => $set['manifest'],
				'html5'     => $source['html5'],
			) : null;
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
