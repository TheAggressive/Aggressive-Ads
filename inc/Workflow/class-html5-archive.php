<?php
/**
 * Bounded, read-only ZIP inspection. No member is extracted to a filesystem path.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Html5_Profile;

/** Libzip handles compression; actual streamed bytes enforce the input contract. */
final class Html5_Archive {

	/**
	 * Read only the declared static profile, with one budget across all members.
	 *
	 * @param string       $path PHP upload path.
	 * @param int          $limit Placement byte ceiling.
	 * @param Html5_Budget $budget Entire ingest budget.
	 * @return array<string,string> Canonical member name => bytes.
	 * @throws \RuntimeException Unsupported input or exceeded work budget.
	 */
	public function read( string $path, int $limit, Html5_Budget $budget ): array {
		if ( ! class_exists( \ZipArchive::class ) ) {
			throw new \RuntimeException( 'aggr_html5_unavailable' );
		}
		$size = is_file( $path ) ? filesize( $path ) : false;
		if ( false === $size || $size < 22 || $size > min( $limit, Html5_Profile::MAX_BYTES ) ) {
			throw new \RuntimeException( 'aggr_html5_archive' );
		}
		// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Bounded header of a local upload snapshot, never an HTTP URL.
		$header = file_get_contents( $path, false, null, 0, 4 );
		// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Fixed-size terminal record of a local upload; ZIP preambles, comments, trailing bytes and multidisk archives are outside this profile.
		$end = file_get_contents( $path, false, null, -22, 22 );
		if ( "PK\x03\x04" !== $header || false === $end || "PK\x05\x06" !== substr( $end, 0, 4 ) || "\0\0\0\0" !== substr( $end, 4, 4 ) || "\0\0" !== substr( $end, 20, 2 ) ) {
			throw new \RuntimeException( 'aggr_html5_archive' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS ) ) {
			throw new \RuntimeException( 'aggr_html5_archive' );
		}
		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native libzip property name.
			$entries = $zip->numFiles;
			if ( $entries < 1 || $entries > Html5_Profile::MAX_ENTRIES || '' !== $zip->getArchiveComment() ) {
				throw new \RuntimeException( 'aggr_html5_archive' );
			}
			$files    = array();
			$names    = array();
			$prefixes = array();
			$total    = 0;
			for ( $index = 0; $index < $entries; ++$index ) {
				$budget->check();
				$stat = $zip->statIndex( $index, \ZipArchive::FL_ENC_RAW );
				if ( ! is_array( $stat ) || ! is_string( $stat['name'] ?? null ) ) {
					throw new \RuntimeException( 'aggr_html5_archive' );
				}
				$name       = $stat['name'];
				$directory  = str_ends_with( $name, '/' );
				$canonical  = $directory ? substr( $name, 0, -1 ) : $name;
				$system     = 0;
				$attributes = 0;
				if ( ! Html5_Profile::path( $canonical ) || isset( $names[ strtolower( $canonical ) ] ) || ! $zip->getExternalAttributesIndex( $index, $system, $attributes )
					|| ( $stat['encryption_method'] ?? -1 ) !== \ZipArchive::EM_NONE || ! in_array( $stat['comp_method'] ?? -1, array( \ZipArchive::CM_STORE, \ZipArchive::CM_DEFLATE ), true ) ) {
					throw new \RuntimeException( 'aggr_html5_archive' );
				}
				$type = ( $attributes >> 16 ) & 0170000;
				if ( ( \ZipArchive::OPSYS_UNIX === $system && ! in_array( $type, array( 0, $directory ? 0040000 : 0100000 ), true ) ) || ( ! $directory && ! Html5_Profile::member( $name ) ) ) {
					throw new \RuntimeException( 'aggr_html5_archive' );
				}
				$names[ strtolower( $canonical ) ] = true;
				$prefix                            = '';
				$parts                             = explode( '/', $canonical );
				foreach ( $parts as $position => $part ) {
					$prefix       = '' === $prefix ? $part : $prefix . '/' . $part;
					$key          = strtolower( $prefix );
					$is_directory = $directory || $position < count( $parts ) - 1;
					if ( isset( $prefixes[ $key ] ) && ( $prefixes[ $key ]['name'] !== $prefix || $prefixes[ $key ]['directory'] !== $is_directory ) ) {
						throw new \RuntimeException( 'aggr_html5_archive' );
					}
					$prefixes[ $key ] = array(
						'name'      => $prefix,
						'directory' => $is_directory,
					);
				}
				if ( $directory ) {
					if ( 0 !== ( $stat['size'] ?? -1 ) ) {
						throw new \RuntimeException( 'aggr_html5_archive' );
					}
					continue;
				}
				$files[ $name ] = $this->member( $zip, $index, $stat, $total, $budget );
			}
			if ( ! isset( $files['index.html'] ) ) {
				throw new \RuntimeException( 'aggr_html5_archive' );
			}
			ksort( $files, SORT_STRING );
			return $files;
		} finally {
			$zip->close();
		}
	}

	/**
	 * Central-directory sizes never authorize unbounded decompression.
	 *
	 * @param \ZipArchive         $zip Open read-only archive.
	 * @param int                 $index Exact member index.
	 * @param array<string,mixed> $stat libzip member facts.
	 * @param int                 $total Expanded bytes already read.
	 * @param Html5_Budget        $budget Entire ingest budget.
	 * @throws \RuntimeException Unsupported input or exceeded work budget.
	 */
	private function member( \ZipArchive $zip, int $index, array $stat, int &$total, Html5_Budget $budget ): string {
		$stream = $zip->getStreamIndex( $index );
		if ( false === $stream ) {
			throw new \RuntimeException( 'aggr_html5_archive' );
		}
		$bytes = '';
		try {
			while ( ! feof( $stream ) ) {
				$budget->check();
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read-only libzip member stream with actual byte limits; WP_Filesystem does not expose ZIP streams.
				$chunk = fread( $stream, 8192 );
				if ( false === $chunk || ( '' === $chunk && ! feof( $stream ) ) ) {
					throw new \RuntimeException( 'aggr_html5_archive' );
				}
				$total += strlen( $chunk );
				$bytes .= $chunk;
				if ( strlen( $bytes ) > Html5_Profile::MAX_BYTES || $total > Html5_Profile::MAX_EXPANDED || strlen( $bytes ) > 100 * max( 1, (int) ( $stat['comp_size'] ?? 0 ) ) ) {
					throw new \RuntimeException( 'aggr_html5_archive' );
				}
			}
		} finally {
			fclose( $stream );
		}
		if ( '' === $bytes || strlen( $bytes ) !== ( $stat['size'] ?? -1 ) || sprintf( '%08x', $stat['crc'] ?? -1 ) !== hash( 'crc32b', $bytes ) ) {
			throw new \RuntimeException( 'aggr_html5_archive' );
		}
		return $bytes;
	}
}
