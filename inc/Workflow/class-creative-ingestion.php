<?php
/**
 * Closed dispatch for the two supported upload profiles.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

/** Request kind fields never authorize a handler or supply inspected metadata. */
final class Creative_Ingestion {

	/**
	 * Keep both concrete ingestion profiles behind the existing workflow entry points.
	 *
	 * @param Image_Handler $images Existing raster and responsive-set uploads.
	 * @param Html5_Handler $html5 Staff static HTML5 uploads.
	 */
	public function __construct( private readonly Image_Handler $images, private readonly Html5_Handler $html5 ) {}

	/**
	 * A separate fallback file explicitly selects the staff HTML5 workflow.
	 *
	 * @param array<string,mixed> $file PHP upload shape.
	 * @param int                 $max_bytes Placement byte ceiling.
	 * @param string              $base Required base size.
	 * @param array<int,string>   $sizes Placement map sizes.
	 * @return array{path:string,token:string,sha256:string,bytes:int,mime:string,width:int,height:int,name:string,manifest?:array<string,mixed>,html5?:array<string,mixed>}|\WP_Error
	 */
	public function accept( array $file, int $max_bytes, string $base, array $sizes ): array|\WP_Error {
		return array_key_exists( 'fallback', $file ) ? $this->html5->accept( $file, $max_bytes, $base ) : $this->images->accept( $file, $max_bytes, $base, $sizes );
	}

	/**
	 * Describe submitted artwork rather than an internal encoded source envelope.
	 *
	 * @param array<string,mixed> $upload Inspected upload.
	 * @return array{mime:string,bytes:int,name:string}
	 */
	public static function summary( array $upload ): array {
		if ( \Aggressive\Ads\Domain\Html5_Profile::valid( $upload['html5'] ?? null ) ) {
			return array(
				'mime'  => 'application/zip',
				'bytes' => $upload['html5']['archive']['bytes'],
				'name'  => is_string( $upload['name'] ?? null ) ? $upload['name'] : '',
			);
		}
		return Image_Handler::summary( $upload );
	}
}
