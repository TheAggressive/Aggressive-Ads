<?php
/**
 * Staff-only static HTML5 ingestion, sharing raster inspection and encrypted storage.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Html5_Profile;
use Aggressive\Ads\Domain\Html5_Package;
use Aggressive\Ads\Domain\Image_Set;
use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Storage\Private_Storage;

/** No new role, execution switch, external fetch or general archive extraction. */
final class Html5_Handler {

	/**
	 * Share existing raster inspection and source encryption.
	 *
	 * @param Creative_Uploader $rasters Existing raster inspector.
	 * @param Private_Storage   $storage Encrypted revision storage.
	 */
	public function __construct( private readonly Creative_Uploader $rasters, private readonly Private_Storage $storage ) {}

	/**
	 * Input is an archive plus a separate fallback upload, never client manifest facts.
	 *
	 * @param array<string,mixed> $file PHP upload with fallback entry.
	 * @param int                 $max_bytes Placement byte ceiling.
	 * @param string              $base Required placement size.
	 * @return array{path:string,token:string,sha256:string,bytes:int,mime:string,width:int,height:int,name:string,manifest?:array<string,mixed>,html5?:array<string,mixed>}|\WP_Error
	 * @throws \RuntimeException Unsupported input or exceeded work budget.
	 */
	public function accept( array $file, int $max_bytes, string $base ): array|\WP_Error {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			return new \WP_Error( 'aggr_html5_forbidden', __( 'Only campaign reviewers may upload HTML5 creative.', 'aggressive-ads' ), array( 'status' => 403 ) );
		}
		if ( ! \Aggressive\Ads\Support\Html5_Runtime::available() || ! ( new \Aggressive\Ads\Repository\Image_Projection_Repository() )->available() ) {
			return new \WP_Error( 'aggr_html5_unavailable', __( 'Static HTML5 delivery is unavailable. Ask the publisher to check the runtime and database upgrade.', 'aggressive-ads' ), array( 'status' => 503 ) );
		}
		$budget = new Html5_Budget();
		$limit  = Upload_Rules::resolve_max_bytes( $max_bytes );
		if ( ! is_string( $file['tmp_name'] ?? null ) || ! is_string( $file['name'] ?? null ) || strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== 'zip'
			|| ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || ! is_array( $file['fallback'] ?? null ) || ! empty( $file['renditions'] ) ) {
			return $this->error();
		}
		try {
			if ( ! is_file( $file['tmp_name'] ) ) {
				return $this->error();
			}
			// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local PHP upload, copied under an actual byte cap before any inspection.
			$archive = file_get_contents( $file['tmp_name'], false, null, 0, $limit + 1 );
			if ( false === $archive || strlen( $archive ) > $limit ) {
				return $this->error();
			}
			$snapshot = $this->storage->temporary_bytes( $archive );
			if ( null === $snapshot ) {
				return $this->error();
			}
			try {
				$files = ( new Html5_Archive() )->read( $snapshot, $limit, $budget );
			} finally {
				wp_delete_file( $snapshot );
			}
			$images = array();
			foreach ( $files as $name => $bytes ) {
				if ( 'index.html' === $name || str_ends_with( $name, '.css' ) ) {
					continue;
				}
				$inspected = $this->inspect_bytes( $bytes, $name, $limit );
				if ( is_wp_error( $inspected ) ) {
					return $inspected;
				}
				$images[ $name ] = 'data:' . $inspected['mime'] . ';base64,' . base64_encode( $bytes );
				$budget->check();
			}
			$document = ( new Html5_Compiler() )->compile( $files, $images, $budget );
			if ( strlen( $document ) > $limit ) {
				return $this->error();
			}
			if ( ! is_string( $file['fallback']['tmp_name'] ?? null ) || ! is_string( $file['fallback']['name'] ?? null ) || ( $file['fallback']['error'] ?? null ) !== UPLOAD_ERR_OK || ! is_file( $file['fallback']['tmp_name'] ) ) {
				return $this->error();
			}
			// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local inspected PHP upload, read under the placement byte cap; never an HTTP request.
			$image = file_get_contents( $file['fallback']['tmp_name'], false, null, 0, $limit + 1 );
			if ( false === $image || strlen( $image ) > $limit ) {
				return $this->error();
			}
			$fallback = $this->inspect_bytes( $image, $file['fallback']['name'], $limit );
			if ( is_wp_error( $fallback ) ) {
				return $fallback;
			}
			$fallback['sha256'] = hash( 'sha256', $image );
			$manifest           = Image_Set::create( $base, array( $base => $fallback ) );
			if ( null === $manifest ) {
				return $this->error();
			}
			$resources = array();
			foreach ( $files as $name => $bytes ) {
				$resources[ $name ] = self::fact( $bytes );
			}
			$html5    = array(
				'version'       => 1,
				'profile'       => 'static',
				'archive'       => self::fact( $archive ),
				'document'      => self::fact( $document ),
				'fallback_hash' => Image_Set::fingerprint( $manifest ),
				'resources'     => $resources,
			);
			$artifact = Html5_Package::pack( $html5, $manifest, $archive, $document, $image );
			if ( null === $artifact ) {
				return $this->error();
			}
			$budget->check();
			$stored = $this->storage->store_bytes( $artifact, Html5_Profile::EXTENSION );
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
			try {
				$budget->check();
			} catch ( \RuntimeException $error ) {
				$this->storage->delete( $stored['path'] );
				throw $error;
			}
			return array_merge(
				$stored,
				array(
					'mime'     => Html5_Profile::MIME,
					'width'    => $fallback['width'],
					'height'   => $fallback['height'],
					'name'     => Upload_Rules::safe_display_name( $file['name'] ),
					'manifest' => $manifest,
					'html5'    => $html5,
				)
			);
		} catch ( \RuntimeException $error ) {
			return $this->error();
		}
	}

	/**
	 * Inspect local raster assets with the same MIME, pixel and filename checks as image uploads.
	 *
	 * @param string $bytes Bounded member bytes.
	 * @param string $name Canonical member name.
	 * @param int    $limit Placement ceiling.
	 * @return array{bytes:int,mime:string,width:int,height:int,name:string}|\WP_Error
	 */
	private function inspect_bytes( string $bytes, string $name, int $limit ): array|\WP_Error {
		$temp = $this->storage->temporary_bytes( $bytes );
		if ( null === $temp ) {
			return $this->error();
		}
		try {
			return $this->rasters->inspect(
				array(
					'tmp_name' => $temp,
					'name'     => $name,
					'error'    => UPLOAD_ERR_OK,
				),
				$limit
			);
		} finally {
			wp_delete_file( $temp );
		}
	}

	/**
	 * Record exact inspected byte counts and hashes.
	 *
	 * @param string $bytes Inspected resource.
	 * @return array{bytes:int,sha256:string}
	 */
	private static function fact( string $bytes ): array {
		return array(
			'bytes'  => strlen( $bytes ),
			'sha256' => hash( 'sha256', $bytes ),
		);
	}

	/** No raw source, path or parser errors enter public responses. */
	private function error(): \WP_Error {
		return new \WP_Error(
			'aggr_html5_invalid',
			__( 'Use a static HTML5 ZIP with one index.html, supported local styles and raster images, plus a matching raster fallback. Scripts, external resources and unsafe or oversized archives are not accepted.', 'aggressive-ads' ),
			array(
				'status' => 422,
				'field'  => 'file',
			)
		);
	}
}
