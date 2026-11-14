<?php
/**
 * Shared PHP multipart shape for native creative forms and REST.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Support;

/** File paths are PHP upload values; the workflow validates their bytes and bounds. */
final class Upload_Files {

	/**
	 * Preserve malformed shapes for the handler to refuse; never silently drop files.
	 *
	 * @param array<string,mixed> $files PHP upload entries.
	 * @return array<string,mixed>
	 */
	public static function read( array $files ): array {
		$base = is_array( $files['file'] ?? null ) ? $files['file'] : array();
		if ( ! isset( $files['renditions'] ) ) {
			return $base;
		}
		$group = $files['renditions'];
		$bad   = array_merge( $base, array( 'renditions' => false ) );
		if ( ! is_array( $group ) || ! is_array( $group['name'] ?? null ) || ! array_is_list( $group['name'] ) || count( $group['name'] ) > 5 ) {
			return $bad;
		}
		$rows = array();
		foreach ( $group['name'] as $i => $name ) {
			$row = array();
			foreach ( array( 'name', 'tmp_name', 'error' ) as $key ) {
				if ( ! is_array( $group[ $key ] ?? null ) || count( $group[ $key ] ) !== count( $group['name'] ) || ! array_key_exists( $i, $group[ $key ] ) ) {
					return $bad;
				}
				$row[ $key ] = $group[ $key ][ $i ];
			}
			if ( '' === $row['name'] && '' === $row['tmp_name'] && UPLOAD_ERR_NO_FILE === $row['error'] ) {
				continue;
			}
			$rows[] = $row;
		}
		$base['renditions'] = $rows;
		return $base;
	}
}
