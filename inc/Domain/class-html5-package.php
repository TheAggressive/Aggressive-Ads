<?php
/**
 * One authenticated source envelope for archive, compiled document and fallback.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/** All bytes are encrypted together; the source snapshot binds their manifest. */
final class Html5_Package {

	/**
	 * Canonical source bytes, including the original ZIP for reviewed copies.
	 *
	 * @param array<string,mixed> $manifest Static profile facts.
	 * @param array<string,mixed> $fallback Raster manifest.
	 * @param string              $archive Original bounded ZIP.
	 * @param string              $document Compiled self-contained document.
	 * @param string              $image Separately inspected fallback raster.
	 */
	public static function pack( array $manifest, array $fallback, string $archive, string $document, string $image ): ?string {
		if ( ! self::matches( $manifest, $fallback, $archive, $document, $image ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-domain canonical envelope, with binary content encoded rather than executed.
		$json = json_encode(
			array(
				'version'  => 1,
				'html5'    => $manifest,
				'fallback' => $fallback,
				'archive'  => base64_encode( $archive ),
				'document' => base64_encode( $document ),
				'image'    => base64_encode( $image ),
			),
			JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		return false !== $json && strlen( $json ) <= Html5_Profile::MAX_ARTIFACT ? $json : null;
	}

	/**
	 * Recheck every byte before publication, preview or copying.
	 *
	 * @param string              $bytes Authenticated decrypted envelope.
	 * @param array<string,mixed> $manifest Static profile facts.
	 * @param array<string,mixed> $fallback Raster manifest.
	 * @return array{archive:string,document:string,image:string}|null
	 */
	public static function unpack( string $bytes, array $manifest, array $fallback ): ?array {
		if ( strlen( $bytes ) > Html5_Profile::MAX_ARTIFACT ) {
			return null;
		}
		$value = json_decode( $bytes, true, 12 );
		if ( ! is_array( $value ) || count( $value ) !== 6 || ( $value['version'] ?? null ) !== 1 || ( $value['html5'] ?? null ) !== $manifest || ( $value['fallback'] ?? null ) !== $fallback ) {
			return null;
		}
		$out = array();
		foreach ( array( 'archive', 'document', 'image' ) as $key ) {
			if ( ! is_string( $value[ $key ] ?? null ) ) {
				return null;
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Strict bounded source decoding; all lengths and hashes must match immutable manifests.
			$decoded = base64_decode( $value[ $key ], true );
			if ( false === $decoded ) {
				return null;
			}
			$out[ $key ] = $decoded;
		}
		return self::matches( $manifest, $fallback, $out['archive'], $out['document'], $out['image'] ) ? $out : null;
	}

	/**
	 * The compiled document and fallback are inseparable from the exact archive.
	 *
	 * @param array<string,mixed> $manifest Static profile facts.
	 * @param array<string,mixed> $fallback Raster manifest.
	 * @param string              $archive Original ZIP bytes.
	 * @param string              $document Compiled document.
	 * @param string              $image Fallback bytes.
	 */
	private static function matches( array $manifest, array $fallback, string $archive, string $document, string $image ): bool {
		if ( ! Html5_Profile::valid( $manifest ) || ! Image_Set::valid( $fallback ) || count( $fallback['resources'] ) !== 1 || Image_Set::fingerprint( $fallback ) !== $manifest['fallback_hash'] ) {
			return false;
		}
		foreach ( array(
			'archive'  => $archive,
			'document' => $document,
		) as $key => $bytes ) {
			if ( strlen( $bytes ) !== $manifest[ $key ]['bytes'] || ! hash_equals( $manifest[ $key ]['sha256'], hash( 'sha256', $bytes ) ) ) {
				return false;
			}
		}
		$base = $fallback['resources'][ $fallback['base'] ];
		return strlen( $image ) === $base['bytes'] && hash_equals( $base['sha256'], hash( 'sha256', $image ) );
	}
}
