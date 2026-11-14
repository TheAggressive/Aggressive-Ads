<?php
/**
 * Structural bounds and whole-set review identity, independent of WordPress.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Image_Set;
use PHPUnit\Framework\TestCase;

final class ImageSetTest extends TestCase {

	/** Hash/length fixtures; actual image detection is exercised through the uploader in integration. */
	private function manifest( array $bytes ): array {
		$rows = array();
		foreach ( $bytes as $size => $body ) {
			$dimensions    = array_map( 'intval', explode( 'x', $size ) );
			$rows[ $size ] = array(
				'width'  => $dimensions[0],
				'height' => $dimensions[1],
				'bytes'  => strlen( $body ),
				'mime'   => 'image/png',
				'sha256' => hash( 'sha256', $body ),
				'name'   => $size . '.png',
			);
		}
		return Image_Set::create( '728x90', $rows );
	}

	public function test_every_rendition_is_bound_to_the_artifact_and_review_identity(): void {
		$bytes    = array(
			'728x90' => 'base bytes',
			'320x50' => 'mobile bytes',
		);
		$manifest = $this->manifest( $bytes );
		$packed   = Image_Set::pack( $manifest, $bytes );
		$this->assertIsString( $packed );
		$this->assertSame( array_reverse( $bytes, true ), Image_Set::unpack( $packed, $manifest ) );
		$changed           = $bytes;
		$changed['320x50'] = 'different mobile';
		$this->assertNull( Image_Set::pack( $manifest, $changed ) );
		$this->assertNotSame( Image_Set::fingerprint( $manifest ), Image_Set::fingerprint( $this->manifest( $changed ) ) );
		$this->assertNull( Image_Set::unpack( str_replace( base64_encode( 'mobile bytes' ), base64_encode( 'broken bytes' ), $packed ), $manifest ) );
		$this->assertNull( Image_Set::pack( $manifest, array( '728x90' => 'base bytes' ) ) );
	}

	public function test_unknown_versions_fields_and_lossy_scalar_types_are_refused(): void {
		$manifest = $this->manifest(
			array(
				'728x90' => 'base',
				'320x50' => 'mobile',
			)
		);
		foreach ( array( array( 'version' => 2 ), array( 'approved' => true ), array( 'base' => array() ) ) as $change ) {
			$this->assertFalse( Image_Set::valid( array_replace( $manifest, $change ) ) );
		}
		$manifest['resources']['320x50']['bytes'] = '6';
		$this->assertFalse( Image_Set::valid( $manifest ) );
		$manifest['resources']['320x50']['bytes'] = 6;
		$manifest['resources']['320x50']['mime']  = 'image/svg+xml';
		$this->assertFalse( Image_Set::valid( $manifest ) );
	}

	public function test_aggregate_and_decode_work_are_bounded_at_the_boundary(): void {
		$bytes    = array(
			'728x90' => str_repeat( 'a', Image_Set::MAX_BYTES - 1 ),
			'320x50' => 'b',
		);
		$manifest = $this->manifest( $bytes );
		$this->assertTrue( Image_Set::valid( $manifest ) );
		$this->assertIsString( Image_Set::pack( $manifest, $bytes ) );
		$rows = $manifest['resources'];
		++$rows['320x50']['bytes'];
		$this->assertNull( Image_Set::create( '728x90', $rows ) );
		$this->assertNull( Image_Set::unpack( str_repeat( 'x', Image_Set::MAX_ENCODED_BYTES + 1 ), $manifest ) );
		$this->assertNull( Image_Set::unpack( '{"images":[],"manifest":null}', $manifest ) );
		$this->assertNull( Image_Set::create( '300x250', $manifest['resources'] ) );
	}
}
