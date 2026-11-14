<?php
/**
 * Revision-owned image source snapshots and publication receipts.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Domain\Image_Set;

/** The source snapshot is authoritative; legacy file fields remain a rollback projection. */
final class Image_Resource_Repository {

	/**
	 * Prevent same-connection callbacks from reentering a publication claim.
	 *
	 * @var array<string,true>
	 */
	private static array $claims = array();

	/**
	 * Read a bounded source snapshot. False distinguishes malformed data/read failure from legacy absence.
	 *
	 * @param int $id Revision id.
	 * @return array<string,mixed>|null|false
	 */
	public function source( int $id ): array|null|false {
		$raw = $this->read( $id, Creative_Repository::META_IMAGE_SOURCE );
		if ( null === $raw || false === $raw ) {
			return $raw;
		}
		return self::decode_source( $raw );
	}

	/**
	 * Validate a bounded snapshot before any of its resource references are used.
	 *
	 * @param string $raw Stored JSON.
	 * @return array<string,mixed>|false
	 */
	private static function decode_source( string $raw ): array|false {
		$value = json_decode( $raw, true, 12 );
		if ( ! is_array( $value ) || 3 !== count( $value ) || 1 !== ( $value['version'] ?? null ) || ! Image_Set::valid( $value['manifest'] ?? null ) || ! is_array( $value['upload'] ?? null ) || 8 !== count( $value['upload'] ) ) {
			return false;
		}
		$upload = $value['upload'];
		foreach ( array( 'path', 'token', 'sha256', 'mime', 'name' ) as $key ) {
			if ( ! is_string( $upload[ $key ] ?? null ) ) {
				return false;
			}
		}
		foreach ( array( 'bytes', 'width', 'height' ) as $key ) {
			if ( ! is_int( $upload[ $key ] ?? null ) || $upload[ $key ] <= 0 ) {
				return false;
			}
		}
		if ( 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $upload['sha256'] ) || $upload['bytes'] > Image_Set::MAX_ENCODED_BYTES ) {
			return false;
		}
		$base = $value['manifest']['resources'][ $value['manifest']['base'] ];
		if ( $upload['width'] !== $base['width'] || $upload['height'] !== $base['height'] || ( count( $value['manifest']['resources'] ) > 1 ? Image_Set::MIME !== $upload['mime'] : $base['mime'] !== $upload['mime'] ) ) {
			return false;
		}
		return $value;
	}

	/**
	 * Commit source fields under the same lock as publication, with a single authoritative snapshot.
	 *
	 * @param int                 $id Revision id.
	 * @param array<string,mixed> $upload Inspected upload; never taken from request metadata.
	 */
	public function record_upload( int $id, array $upload ): bool {
		if ( \Aggressive\Ads\Core\Post_Types::CREATIVE !== get_post_type( $id ) ) {
			return false;
		}
		$lock = $this->claim( $id );
		if ( '' === $lock ) {
			return false;
		}
		try {
			wp_cache_delete( $id, 'post_meta' );
			if ( (int) get_post_meta( $id, Creative_Repository::META_ATTACHMENT_ID, true ) > 0 || metadata_exists( 'post', $id, Creative_Repository::META_IMAGE_PUBLICATION ) ) {
				return false;
			}
			$old_source = $this->source( $id );
			if ( false === $old_source ) {
				return false;
			}
			$manifest = $upload['manifest'] ?? null;
			if ( null !== $manifest && ! Image_Set::valid( $manifest ) ) {
				return false;
			}
			if ( null === $manifest && null !== $old_source ) {
				$size     = (string) get_post_meta( $id, Creative_Repository::META_SIZE, true );
				$manifest = Image_Set::create( $size, array( $size => array_intersect_key( $upload, array_flip( array( 'width', 'height', 'bytes', 'mime', 'sha256', 'name' ) ) ) ) );
				if ( null === $manifest ) {
					return false;
				}
			}
			$columns  = self::file_columns();
			$previous = array();
			foreach ( $columns as $field => $key ) {
				$previous[ $key ] = get_post_meta( $id, $key, true );
			}
			$current_hash = null !== $old_source ? $old_source['upload']['sha256'] : $previous[ Creative_Repository::META_SHA256 ];
			if ( isset( $upload['expected_sha256'] ) && $upload['expected_sha256'] !== $current_hash ) {
				return false;
			}
			foreach ( $columns as $field => $key ) {
				update_post_meta( $id, $key, wp_slash( $upload[ $field ] ) );
			}
			wp_cache_delete( $id, 'post_meta' );
			$good = true;
			foreach ( $columns as $field => $key ) {
				$good = $good && (string) get_post_meta( $id, $key, true ) === (string) $upload[ $field ];
			}
			if ( $good && null !== $manifest ) {
				$source = array(
					'version'  => 1,
					'manifest' => $manifest,
					'upload'   => array_intersect_key( $upload, $columns ),
				);
				$json   = wp_json_encode( $source );
				if ( false !== $json ) {
					update_post_meta( $id, Creative_Repository::META_IMAGE_SOURCE, wp_slash( $json ) );
				}
				$good = false !== $json && $this->source( $id ) === $source;
			}
			if ( ! $good ) {
				if ( null === $old_source ) {
					delete_post_meta( $id, Creative_Repository::META_IMAGE_SOURCE );
				} else {
					$json = wp_json_encode( $old_source );
					if ( false !== $json ) {
						update_post_meta( $id, Creative_Repository::META_IMAGE_SOURCE, wp_slash( $json ) );
					}
				}
				foreach ( $previous as $key => $value ) {
					update_post_meta( $id, $key, wp_slash( $value ) );
				}
				wp_cache_delete( $id, 'post_meta' );
			}
			return $good;
		} finally {
			$this->release( $lock );
		}
	}

	/**
	 * A receipt must match this site's current source and owning tuple.
	 *
	 * @param int $id Revision id.
	 * @return array<string,mixed>|null
	 */
	public function publication( int $id ): ?array {
		$source = $this->source( $id );
		$raw    = $this->read( $id, Creative_Repository::META_IMAGE_PUBLICATION );
		$value  = is_string( $raw ) ? json_decode( $raw, true, 12 ) : null;
		if ( ! is_array( $source ) || ! is_array( $value ) || 1 !== ( $value['version'] ?? null ) || get_current_blog_id() !== ( $value['site_id'] ?? null )
			|| ( $value['source_hash'] ?? null ) !== $source['upload']['sha256'] || Image_Set::fingerprint( $source['manifest'] ) !== ( $value['manifest_hash'] ?? null )
			|| ! is_array( $value['resources'] ?? null ) || array_keys( $value['resources'] ) !== array_keys( $source['manifest']['resources'] ) ) {
			return null;
		}
		foreach ( array(
			'campaign_id'  => Creative_Repository::META_CAMPAIGN_ID,
			'org_id'       => Creative_Repository::META_ORG_ID,
			'placement_id' => Creative_Repository::META_PLACEMENT_ID,
		) as $field => $key ) {
			if ( (int) get_post_meta( $id, $key, true ) !== ( $value[ $field ] ?? null ) ) {
				return null;
			}
		}
		foreach ( $value['resources'] as $size => $row ) {
			if ( ! is_array( $row ) || ! is_int( $row['attachment_id'] ?? null ) || $row['attachment_id'] <= 0 || ! is_string( $row['file'] ?? null )
				|| 1 !== preg_match( '#\A[a-zA-Z0-9_/-]+\.[a-zA-Z0-9]+\z#', $row['file'] ) || str_contains( $row['file'], '..' )
				|| ( $row['sha256'] ?? null ) !== $source['manifest']['resources'][ $size ]['sha256'] ) {
				return null;
			}
		}
		$base = $source['manifest']['base'];
		return (int) get_post_meta( $id, Creative_Repository::META_ATTACHMENT_ID, true ) === $value['resources'][ $base ]['attachment_id'] ? $value : null;
	}

	/**
	 * Store a publication receipt; the workflow holds the source lock and owns all supplied resources.
	 *
	 * @param int                 $id Revision id.
	 * @param array<string,mixed> $receipt Published resource facts.
	 */
	public function record_publication( int $id, array $receipt ): bool {
		$json = wp_json_encode( $receipt );
		if ( false === $json ) {
			return false;
		}
		update_post_meta( $id, Creative_Repository::META_IMAGE_PUBLICATION, wp_slash( $json ) );
		$raw = $this->read( $id, Creative_Repository::META_IMAGE_PUBLICATION );
		return is_string( $raw ) && json_decode( $raw, true, 12 ) === $receipt;
	}

	/**
	 * Review identity binds resources, destination, alternative text and parentage to this revision.
	 *
	 * @param int                 $id Revision id.
	 * @param array<string,mixed> $receipt Resource publication.
	 */
	public function review_digest( int $id, array $receipt ): string {
		return self::digest( $id, $receipt, (string) get_post_meta( $id, Creative_Repository::META_CLICK_URL, true ), (string) get_post_meta( $id, Creative_Repository::META_ALT_TEXT, true ) );
	}

	/**
	 * Artifact approval evidence is durable in the same receipt before assignment activation.
	 *
	 * @param int $id Revision id.
	 */
	public function ready( int $id ): bool {
		$receipt = $this->publication( $id );
		return null !== $receipt && self::review_matches( $id, $receipt, $this->review_digest( $id, $receipt ) );
	}

	/**
	 * A partial/malformed publication also freezes its source until repaired.
	 *
	 * @param int $id Revision id.
	 */
	public function has_publication( int $id ): bool {
		return null !== $this->read( $id, Creative_Repository::META_IMAGE_PUBLICATION );
	}

	/**
	 * Resolve one winning manifest in one indexed, bounded read; never load resources per candidate.
	 *
	 * @param array<string,mixed> $row Selected assignment or authoritative token tuple.
	 * @param string              $size Resolved placement size; empty selects the base for token validation.
	 * @return array{image:string,width:int,height:int,alt:string}|null
	 */
	public function delivery( array $row, string $size ): ?array {
		global $wpdb;
		$id         = (int) ( $row['revision_id'] ?? $row['creative_id'] ?? 0 );
		$keys       = array( Creative_Repository::META_IMAGE_SOURCE, Creative_Repository::META_IMAGE_PUBLICATION, Creative_Repository::META_CAMPAIGN_ID, Creative_Repository::META_ORG_ID, Creative_Repository::META_PLACEMENT_ID, Creative_Repository::META_ATTACHMENT_ID, Creative_Repository::META_CLICK_URL, Creative_Repository::META_ALT_TEXT, Creative_Repository::META_KIND );
		$parameters = array_merge( array( $wpdb->postmeta, $id ), $keys );
		$slots      = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The fixed key list generates matching prepared placeholders/parameters; primary revision scope and sentinel row/byte caps; a publication must be current rather than cached across withdrawal.
		$values = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key,LEFT(meta_value,24577) AS value FROM %i WHERE post_id=%d AND meta_key IN ({$slots}) ORDER BY meta_id LIMIT 10", $parameters ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $values ) || count( $values ) !== count( $keys ) ) {
			return null;
		}
		$map = array();
		foreach ( $values as $value ) {
			if ( isset( $map[ $value['meta_key'] ] ) || strlen( $value['value'] ) > 24576 ) {
				return null;
			}
			$map[ $value['meta_key'] ] = $value['value'];
		}
		$source  = self::decode_source( $map[ Creative_Repository::META_IMAGE_SOURCE ] );
		$receipt = json_decode( $map[ Creative_Repository::META_IMAGE_PUBLICATION ], true, 12 );
		if ( false === $source || ! is_array( $receipt ) || 'image' !== $map[ Creative_Repository::META_KIND ]
			|| 1 !== ( $receipt['version'] ?? null ) || get_current_blog_id() !== ( $receipt['site_id'] ?? null )
			|| ( $receipt['source_hash'] ?? null ) !== $source['upload']['sha256'] || Image_Set::fingerprint( $source['manifest'] ) !== ( $receipt['manifest_hash'] ?? null )
			|| ( isset( $row['image_hash'] ) && $row['image_hash'] !== $receipt['manifest_hash'] ) ) {
			return null;
		}
		foreach ( array(
			'campaign_id'  => Creative_Repository::META_CAMPAIGN_ID,
			'org_id'       => Creative_Repository::META_ORG_ID,
			'placement_id' => Creative_Repository::META_PLACEMENT_ID,
		) as $field => $key ) {
			if ( ( $receipt[ $field ] ?? null ) !== (int) $map[ $key ] ) {
				return null;
			}
		}
		if ( (int) ( $row['campaign_id'] ?? 0 ) !== $receipt['campaign_id'] || (int) ( $row['placement_id'] ?? 0 ) !== $receipt['placement_id']
			|| ( isset( $row['organization_id'] ) && (int) $row['organization_id'] !== $receipt['org_id'] )
			|| ! self::review_matches( $id, $receipt, self::digest( $id, $receipt, $map[ Creative_Repository::META_CLICK_URL ], $map[ Creative_Repository::META_ALT_TEXT ] ) ) ) {
			return null;
		}
		$base      = $source['manifest']['base'];
		$size      = '' === $size ? $base : $size;
		$resource  = $source['manifest']['resources'][ $size ] ?? null;
		$published = $receipt['resources'][ $size ] ?? null;
		if ( ! is_array( $resource ) || ! is_array( $published ) || ( $receipt['resources'][ $base ]['attachment_id'] ?? 0 ) !== (int) $map[ Creative_Repository::META_ATTACHMENT_ID ]
			|| ! is_int( $published['attachment_id'] ?? null ) || $published['attachment_id'] <= 0 || ( $published['sha256'] ?? null ) !== $resource['sha256']
			|| ! is_string( $published['file'] ?? null ) || 1 !== preg_match( '#\A[a-zA-Z0-9_/-]+\.[a-zA-Z0-9]+\z#', $published['file'] ) || str_contains( $published['file'], '..' ) ) {
			return null;
		}
		$uploads = wp_get_upload_dir();
		$url     = trailingslashit( $uploads['baseurl'] ) . $published['file'];
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Preserve the core media URL filter for publisher CDN mappings without per-attachment hot-path queries.
		$url = apply_filters( 'wp_get_attachment_url', $url, $published['attachment_id'] );
		return is_string( $url ) && '' !== $url ? array(
			'image'  => $url,
			'width'  => $resource['width'],
			'height' => $resource['height'],
			'alt'    => $map[ Creative_Repository::META_ALT_TEXT ],
		) : null;
	}

	/**
	 * Materialized, bounded eligibility facts; malformed new records never normalize as legacy images.
	 *
	 * @param int $id Revision id.
	 * @return array{image_version:int,image_sizes:string,image_hash:string}
	 */
	public function projection( int $id ): array {
		$source = $this->source( $id );
		if ( null === $source ) {
			return array(
				'image_version' => 0,
				'image_sizes'   => '',
				'image_hash'    => '',
			);
		}
		if ( false === $source ) {
			return array(
				'image_version' => 255,
				'image_sizes'   => '',
				'image_hash'    => '',
			);
		}
		return array(
			'image_version' => 1,
			'image_sizes'   => implode( ',', array_keys( $source['manifest']['resources'] ) ),
			'image_hash'    => $this->ready( $id ) ? Image_Set::fingerprint( $source['manifest'] ) : '',
		);
	}

	/**
	 * One immutable approval fingerprint covers the whole ad and its artifact publication.
	 *
	 * @param int                 $id Revision id.
	 * @param array<string,mixed> $receipt Context and reviewed resource hashes.
	 * @param string              $url Destination.
	 * @param string              $alt Alternative text.
	 */
	private static function digest( int $id, array $receipt, string $url, string $alt ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Review fingerprints must refuse invalid UTF-8 rather than normalize it into different reviewed text.
		$json = json_encode( array( $id, get_current_blog_id(), $receipt['campaign_id'] ?? 0, $receipt['org_id'] ?? 0, $receipt['placement_id'] ?? 0, $receipt['source_hash'] ?? '', $receipt['manifest_hash'] ?? '', $receipt['resources'] ?? array(), $url, $alt ), JSON_UNESCAPED_SLASHES );
		return false === $json ? '' : hash( 'sha256', $json );
	}

	/**
	 * Missing/foreign/stale proof cannot authorize serving, even when a base attachment exists.
	 *
	 * @param int                 $id Revision id.
	 * @param array<string,mixed> $receipt Current publication.
	 * @param string              $digest Current immutable ad fingerprint.
	 */
	private static function review_matches( int $id, array $receipt, string $digest ): bool {
		$proof = $receipt['review'] ?? null;
		return '' !== $digest && is_array( $proof ) && ( $proof['revision_id'] ?? 0 ) === $id
			&& is_int( $proof['actor_id'] ?? null ) && $proof['actor_id'] > 0 && is_int( $proof['at'] ?? null ) && $proof['at'] > 0
			&& ( $proof['digest'] ?? '' ) === $digest;
	}

	/**
	 * Remove only an incomplete receipt/base pointer created by this failed attempt.
	 *
	 * @param int            $id Revision id.
	 * @param array<int,int> $created This attempt's fresh attachment ids.
	 */
	public function discard_publication( int $id, array $created ): void {
		if ( in_array( (int) get_post_meta( $id, Creative_Repository::META_ATTACHMENT_ID, true ), $created, true ) ) {
			delete_post_meta( $id, Creative_Repository::META_ATTACHMENT_ID );
		}
		delete_post_meta( $id, Creative_Repository::META_IMAGE_PUBLICATION );
	}

	/**
	 * Clear only operational private locators after successful publication/retention.
	 *
	 * @param int $id Revision id.
	 */
	public function clear_private_file( int $id ): void {
		$source = $this->source( $id );
		if ( is_array( $source ) ) {
			$source['upload']['path']  = '';
			$source['upload']['token'] = '';
			$json                      = wp_json_encode( $source );
			if ( false !== $json ) {
				update_post_meta( $id, Creative_Repository::META_IMAGE_SOURCE, wp_slash( $json ) );
			}
		}
	}

	/**
	 * Serialize source edits and publication across processes and site switches.
	 *
	 * @param int $id Revision id.
	 */
	public function claim( int $id ): string {
		global $wpdb;
		$name = 'aggr_image_' . get_current_blog_id() . '_' . $id;
		if ( isset( self::$claims[ $name ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cross-process source/publication serialization with a prepared site-scoped lock name.
		$claimed = $id > 0 && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		if ( $claimed ) {
			self::$claims[ $name ] = true;
		}
		return $claimed ? $name : '';
	}

	/**
	 * Release the exact claim; no supplied source path is used as a lock identity.
	 *
	 * @param string $name Acquired lock name.
	 */
	public function release( string $name ): void {
		global $wpdb;
		if ( ! isset( self::$claims[ $name ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the exact prepared lock acquired above.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		unset( self::$claims[ $name ] );
	}

	/**
	 * Raw indexed read with row/byte limits; distinguish legacy absence from failure or duplicates.
	 *
	 * @param int    $id Revision id.
	 * @param string $key Internal metadata key.
	 * @return string|null|false
	 */
	private function read( int $id, string $key ): string|null|false {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Current revision state, capped before transfer; duplicate metadata fails closed.
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT LEFT(meta_value,24577) FROM %i WHERE post_id=%d AND meta_key=%s ORDER BY meta_id LIMIT 2', $wpdb->postmeta, $id, $key ) );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) || count( $rows ) > 1 || ( isset( $rows[0] ) && strlen( $rows[0] ) > 24576 ) ) {
			return false;
		}
		return $rows[0] ?? null;
	}

	/**
	 * Legacy rollback projection of the source snapshot.
	 *
	 * @return array<string,string>
	 */
	private static function file_columns(): array {
		return array(
			'path'   => Creative_Repository::META_PRIVATE_PATH,
			'token'  => Creative_Repository::META_PRIVATE_TOKEN,
			'sha256' => Creative_Repository::META_SHA256,
			'bytes'  => Creative_Repository::META_FILESIZE,
			'mime'   => Creative_Repository::META_MIME,
			'width'  => Creative_Repository::META_WIDTH,
			'height' => Creative_Repository::META_HEIGHT,
			'name'   => Creative_Repository::META_ORIGINAL_NAME,
		);
	}
}
