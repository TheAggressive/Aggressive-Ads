<?php
/**
 * Bounded metadata and source bytes for one reviewed responsive image revision.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/** No paths, attachment ids or client-provided approval facts belong in a source manifest. */
final class Image_Set {

	public const VERSION           = 1;
	public const MIME              = 'application/vnd.aggressive-ads.image-set+json';
	public const EXTENSION         = 'aggrset';
	public const MAX_RESOURCES     = 6;
	public const MAX_BYTES         = 2097152;
	public const MAX_MANIFEST      = 16384;
	public const MAX_ENCODED_BYTES = 4194304;

	/**
	 * Canonicalize server-inspected resource facts; never coerce malformed metadata.
	 *
	 * @param string               $base Base placement size.
	 * @param array<string, mixed> $resources Size => inspected image descriptor.
	 * @return array{version:int,base:string,resources:array<string,array{width:int,height:int,bytes:int,mime:string,sha256:string,name:string}>}|null
	 */
	public static function create( string $base, array $resources ): ?array {
		if ( count( $resources ) < 1 || count( $resources ) > self::MAX_RESOURCES || ! isset( $resources[ $base ] ) ) {
			return null;
		}
		$bytes = 0;
		$clean = array();
		foreach ( $resources as $size => $row ) {
			if ( ! is_string( $size ) || ! Ad_Sizes::is_valid( $size ) || ! is_array( $row ) ) {
				return null;
			}
			if (
				count( $row ) !== 6
				|| ! isset( $row['width'], $row['height'], $row['bytes'], $row['mime'], $row['sha256'], $row['name'] )
				|| ! is_int( $row['width'] ) || ! is_int( $row['height'] ) || ! is_int( $row['bytes'] )
				|| ! is_string( $row['mime'] ) || ! is_string( $row['sha256'] ) || ! is_string( $row['name'] )
				|| $size !== $row['width'] . 'x' . $row['height']
				|| $row['bytes'] <= 0 || $row['bytes'] > self::MAX_BYTES
				|| Upload_Rules::exceeds_pixels( $row['width'], $row['height'] )
				|| ! Upload_Rules::is_allowed_mime( $row['mime'] )
				|| 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $row['sha256'] )
				|| strlen( $row['name'] ) > 500
			) {
				return null;
			}
			$bytes         += $row['bytes'];
			$clean[ $size ] = array(
				'width'  => $row['width'],
				'height' => $row['height'],
				'bytes'  => $row['bytes'],
				'mime'   => $row['mime'],
				'sha256' => $row['sha256'],
				'name'   => $row['name'],
			);
		}
		if ( $bytes > self::MAX_BYTES ) {
			return null;
		}
		ksort( $clean, SORT_STRING );
		$result = array(
			'version'   => self::VERSION,
			'base'      => $base,
			'resources' => $clean,
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-domain canonical JSON; this layer cannot depend on WordPress.
		$json = json_encode( $result, JSON_UNESCAPED_SLASHES );
		return false !== $json && strlen( $json ) <= self::MAX_MANIFEST ? $result : null;
	}

	/**
	 * Refuse unknown versions, extra fields, malformed types and non-canonical data.
	 *
	 * @param mixed $value Stored manifest.
	 * @phpstan-assert-if-true array{version:int,base:string,resources:array<string,array{width:int,height:int,bytes:int,mime:string,sha256:string,name:string}>} $value
	 */
	public static function valid( mixed $value ): bool {
		return is_array( $value ) && count( $value ) === 3 && self::VERSION === ( $value['version'] ?? null )
			&& isset( $value['base'], $value['resources'] ) && is_string( $value['base'] ) && is_array( $value['resources'] )
			&& self::create( $value['base'], $value['resources'] ) === $value;
	}

	/**
	 * Stable review identity over all rendition facts, not just the base image.
	 *
	 * @param array<string, mixed> $manifest Canonical manifest.
	 */
	public static function fingerprint( array $manifest ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-domain canonical JSON; this layer cannot depend on WordPress.
		$json = self::valid( $manifest ) ? json_encode( $manifest, JSON_UNESCAPED_SLASHES ) : false;
		return false === $json ? '' : hash( 'sha256', $json );
	}

	/**
	 * Revalidate a set when placement policy changes before review.
	 *
	 * @param array<string,mixed> $manifest Canonical rendition facts.
	 * @param string              $base Required stored placement size.
	 * @param array<int,string>   $sizes Current map sizes.
	 * @param int                 $max_bytes Current per-file placement limit.
	 */
	public static function fits( array $manifest, string $base, array $sizes, int $max_bytes ): bool {
		if ( ! self::valid( $manifest ) || $base !== $manifest['base'] ) {
			return false;
		}
		$sizes[] = $base;
		foreach ( $manifest['resources'] as $size => $raster ) {
			if ( ! in_array( $size, $sizes, true ) || $raster['bytes'] > Upload_Rules::resolve_max_bytes( $max_bytes ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * One encrypted artifact keeps existing quarantine and retention operations atomic.
	 *
	 * @param array<string, mixed> $manifest Canonical manifest.
	 * @param array<string,string> $images Size => plaintext image bytes.
	 */
	public static function pack( array $manifest, array $images ): ?string {
		if ( ! self::matches( $manifest, $images ) ) {
			return null;
		}
		ksort( $images, SORT_STRING );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary raster bytes inside a bounded encrypted source artifact, never executable markup.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-domain canonical JSON; this layer cannot depend on WordPress.
		$encoded = json_encode(
			array(
				'manifest' => $manifest,
				'images'   => array_map( 'base64_encode', $images ),
			),
			JSON_UNESCAPED_SLASHES
		);
		return false !== $encoded && strlen( $encoded ) <= self::MAX_ENCODED_BYTES ? $encoded : null;
	}

	/**
	 * Authenticate each member against the exact revision manifest after decryption.
	 *
	 * @param string              $artifact Decrypted bounded source.
	 * @param array<string,mixed> $manifest Revision-owned manifest.
	 * @return array<string,string>|null
	 */
	public static function unpack( string $artifact, array $manifest ): ?array {
		if ( strlen( $artifact ) > self::MAX_ENCODED_BYTES ) {
			return null;
		}
		$value = json_decode( $artifact, true, 8 );
		if ( ! is_array( $value ) || count( $value ) !== 2 || ( $value['manifest'] ?? null ) !== $manifest || ! is_array( $value['images'] ?? null ) ) {
			return null;
		}
		$images = array();
		foreach ( $value['images'] as $size => $encoded ) {
			if ( ! is_string( $size ) || ! is_string( $encoded ) ) {
				return null;
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Strict binary decoding of the bounded source artifact; checksums and lengths are verified below.
			$bytes = base64_decode( $encoded, true );
			if ( false === $bytes ) {
				return null;
			}
			$images[ $size ] = $bytes;
		}
		return self::matches( $manifest, $images ) ? $images : null;
	}

	/**
	 * Match every resource, count and byte; no ignored or duplicate member is permitted.
	 *
	 * @param array<string,mixed>  $manifest Canonical manifest.
	 * @param array<string,string> $images Size => bytes.
	 */
	private static function matches( array $manifest, array $images ): bool {
		if ( ! self::valid( $manifest ) || count( $images ) !== count( $manifest['resources'] ) ) {
			return false;
		}
		foreach ( $manifest['resources'] as $size => $row ) {
			if ( ! isset( $images[ $size ] ) || ! is_string( $images[ $size ] ) || strlen( $images[ $size ] ) !== $row['bytes'] || ! hash_equals( $row['sha256'], hash( 'sha256', $images[ $size ] ) ) ) {
				return false;
			}
		}
		return true;
	}
}
