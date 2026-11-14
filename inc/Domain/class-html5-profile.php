<?php
/**
 * Limits and immutable facts for the supported static HTML5 profile.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/** Executable or externally resolved content is outside this profile. */
final class Html5_Profile {

	public const MIME         = 'application/vnd.aggressive-ads.static-html5+json';
	public const EXTENSION    = 'aggrhtml';
	public const MAX_BYTES    = 2097152;
	public const MAX_EXPANDED = 8388608;
	public const MAX_ARTIFACT = 9437184;
	public const MAX_ENTRIES  = 128;
	public const MAX_NODES    = 4096;
	public const MAX_DEPTH    = 32;
	public const POLICY       = "default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'";

	/**
	 * A canonical archive path, never a filesystem destination or URL.
	 *
	 * @param string $name Archive member name.
	 */
	public static function path( string $name ): bool {
		if ( strlen( $name ) > 128 || substr_count( $name, '/' ) > 5 || 1 !== preg_match( '#\A[a-zA-Z0-9_-][a-zA-Z0-9_./-]*\z#', $name ) ) {
			return false;
		}
		foreach ( explode( '/', $name ) as $part ) {
			if ( '' === $part || '.' === $part[0] || str_contains( $part, '..' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Only one entry document, local styles and the established raster types.
	 *
	 * @param string $name Canonical member name.
	 */
	public static function member( string $name ): bool {
		return 'index.html' === $name || ( self::path( $name ) && in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), array( 'css', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif' ), true ) );
	}

	/**
	 * Validate persisted inspected facts without coercing client/stored values.
	 *
	 * @param mixed $value Snapshot manifest.
	 * @phpstan-assert-if-true array{version:1,profile:'static',archive:array{bytes:int,sha256:string},document:array{bytes:int,sha256:string},fallback_hash:string,resources:array<string,array{bytes:int,sha256:string}>} $value
	 */
	public static function valid( mixed $value ): bool {
		if ( ! is_array( $value ) || count( $value ) !== 6 || ( $value['version'] ?? null ) !== 1 || ( $value['profile'] ?? null ) !== 'static'
			|| ! is_array( $value['resources'] ?? null ) || ! isset( $value['resources']['index.html'] ) || count( $value['resources'] ) > self::MAX_ENTRIES ) {
			return false;
		}
		foreach ( array( 'archive', 'document' ) as $key ) {
			if ( ! self::fact( $value[ $key ] ?? null, self::MAX_BYTES ) ) {
				return false;
			}
		}
		if ( ! is_string( $value['fallback_hash'] ?? null ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $value['fallback_hash'] ) ) {
			return false;
		}
		$total = 0;
		foreach ( $value['resources'] as $name => $row ) {
			if ( ! is_string( $name ) || ! self::path( $name ) || ! self::member( $name ) || ! self::fact( $row, self::MAX_BYTES ) ) {
				return false;
			}
			$total += $row['bytes'];
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-domain bounded canonical manifest, independent of WordPress.
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES );
		return $total <= self::MAX_EXPANDED && false !== $json && strlen( $json ) <= Image_Set::MAX_MANIFEST;
	}

	/**
	 * Bind the inspected archive manifest and compiled-output facts to the approval receipt.
	 *
	 * @param array<string,mixed> $manifest Validated static-profile facts.
	 */
	public static function fingerprint( array $manifest ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-domain canonical manifest fingerprint; malformed input has no approval identity.
		$json = self::valid( $manifest ) ? json_encode( $manifest, JSON_UNESCAPED_SLASHES ) : false;
		return false === $json ? '' : hash( 'sha256', $json );
	}

	/**
	 * Current placement limits apply again at review and publication.
	 *
	 * @param array<string,mixed> $manifest Validated static-profile facts.
	 * @param int                 $max_bytes Current placement limit.
	 */
	public static function fits( array $manifest, int $max_bytes ): bool {
		$limit = Upload_Rules::resolve_max_bytes( $max_bytes );
		return self::valid( $manifest ) && $manifest['archive']['bytes'] <= $limit && $manifest['document']['bytes'] <= $limit;
	}

	/**
	 * Strict size and checksum pair for one inspected resource.
	 *
	 * @param mixed $row Persisted fact.
	 * @param int   $limit Byte ceiling.
	 */
	private static function fact( mixed $row, int $limit ): bool {
		return is_array( $row ) && count( $row ) === 2 && is_int( $row['bytes'] ?? null ) && $row['bytes'] > 0 && $row['bytes'] <= $limit
			&& is_string( $row['sha256'] ?? null ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $row['sha256'] );
	}
}
