<?php
/**
 * Schema qualification and bounded repair of image eligibility snapshots.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

/** Only the format projection is repaired; status, weights and reviewed revisions are not rewritten. */
final class Image_Projection_Repository {

	/**
	 * Claims owned by this request, including callbacks sharing its connection.
	 *
	 * @var array<string,true>
	 */
	private static array $claims = array();

	public const CURSOR = 'aggr_image_projection_cursor';
	public const DONE   = 'aggr_image_projection_done';
	public const ERROR  = 'aggr_image_projection_error';

	/** Qualify actual columns so a failed upgrade can continue serving legacy image rows. */
	public function available(): bool {
		$columns = $this->columns();
		if ( null === $columns ) {
			return false;
		}
		$types   = array(
			'image_version' => '/^tinyint(?:\(\d+\))? unsigned$/',
			'image_sizes'   => '/^varchar\(191\)$/',
			'image_hash'    => '/^char\(64\)$/',
		);
		$matched = 0;
		foreach ( $columns as $column ) {
			$name = $column['Field'];
			if ( isset( $types[ $name ] ) && 1 === preg_match( $types[ $name ], strtolower( $column['Type'] ) ) && 'NO' === $column['Null'] && ( 'image_version' === $name ? '0' : '' ) === (string) $column['Default'] ) {
				++$matched;
			}
		}
		return 3 === $matched;
	}

	/**
	 * Share one schema read with table existence checks, keeping candidate reads within their budget.
	 *
	 * @return list<array{Field:string,Type:string,Null:string,Default:string|null}>|null
	 */
	public function columns(): ?array {
		global $wpdb;
		$key     = 'columns_' . get_current_blog_id();
		$columns = wp_cache_get( $key, 'aggr_image_schema', false, $found );
		if ( ! $found ) {
			$errors = $wpdb->suppress_errors( true );
			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed assignment schema metadata, shared and cached per site; actual DDL invalidates it.
				$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', ( new Creative_Assignment_Repository() )->table_name() ), ARRAY_A );
				if ( '' !== $wpdb->last_error ) {
					$columns = false;
				}
			} finally {
				$wpdb->suppress_errors( $errors );
			}
		}
		if ( ! is_array( $columns ) || array() === $columns || count( $columns ) > 64 ) {
			wp_cache_set( $key, false, 'aggr_image_schema', 60 );
			return null;
		}
		$clean = array();
		foreach ( $columns as $column ) {
			if ( ! is_array( $column ) || ! is_string( $column['Field'] ?? null ) || ! is_string( $column['Type'] ?? null ) || ! is_string( $column['Null'] ?? null ) ) {
				return null;
			}
			$default = $column['Default'] ?? null;
			if ( null !== $default && ! is_string( $default ) && ! is_int( $default ) ) {
				return null;
			}
			$clean[] = array(
				'Field'   => $column['Field'],
				'Type'    => $column['Type'],
				'Null'    => $column['Null'],
				'Default' => null === $default ? null : (string) $default,
			);
		}
		if ( ! $found ) {
			wp_cache_set( $key, $clean, 'aggr_image_schema', 60 );
		}
		return $clean;
	}

	/**
	 * Verify failed DDL cannot advance the schema marker.
	 *
	 * @throws \RuntimeException When the image projection schema is incomplete.
	 */
	public function install(): void {
		( new Creative_Assignment_Repository() )->install_table();
		wp_cache_delete( 'columns_' . get_current_blog_id(), 'aggr_image_schema' );
		if ( ! $this->available() ) {
			throw new \RuntimeException( 'Image eligibility columns could not be installed.' );
		}
	}

	/**
	 * Primary-id cursor; SQL failures must not masquerade as the end of a migration.
	 *
	 * @param int $cursor Last committed assignment.
	 * @return list<array<string,mixed>>|\WP_Error
	 */
	public function after( int $cursor ): array|\WP_Error {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed cursor and hard input cap, not an aggregate over catalogue history.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,revision,revision_id FROM %i WHERE id>%d ORDER BY id LIMIT 100', ( new Creative_Assignment_Repository() )->table_name(), max( 0, $cursor ) ), ARRAY_A );
		return '' !== $wpdb->last_error ? new \WP_Error( 'image_projection_read' ) : $rows;
	}

	/**
	 * CAS protects a concurrently repointed or changed assignment, including terminal history.
	 *
	 * @param array<string,mixed>                                           $row Observed identity/revision.
	 * @param array{image_version:int,image_sizes:string,image_hash:string} $fields Derived image facts.
	 */
	public function write( array $row, array $fields ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed derived columns and expected revision; no lifecycle status is changed.
		$result = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET image_version=%d,image_sizes=%s,image_hash=%s,revision=revision+1 WHERE id=%d AND revision=%d AND revision_id=%d', ( new Creative_Assignment_Repository() )->table_name(), $fields['image_version'], $fields['image_sizes'], $fields['image_hash'], $row['id'], $row['revision'], $row['revision_id'] ) );
		return 1 === $result;
	}

	/**
	 * Operator-visible, site-scoped migration state.
	 *
	 * @return array{cursor:int,done:bool,error:string}
	 */
	public function state(): array {
		return array(
			'cursor' => max( 0, (int) get_option( self::CURSOR, 0 ) ),
			'done'   => 1 === (int) get_option( self::DONE, 0 ),
			'error'  => (string) get_option( self::ERROR, '' ),
		);
	}

	/** Begin or retry without discarding committed progress. */
	public function begin(): void {
		delete_option( self::DONE );
		if ( false === get_option( self::CURSOR, false ) ) {
			add_option( self::CURSOR, 0, '', false );
		}
	}

	/** Serialize cursor commits across cron and manual recovery workers on this site. */
	public function claim(): string {
		global $wpdb;
		$name = 'aggr_image_projection_' . get_current_blog_id();
		if ( isset( self::$claims[ $name ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Nonblocking site-scoped migration worker lock; never used by delivery.
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $name ) ) ) {
			return '';
		}
		self::$claims[ $name ] = true;
		return $name;
	}

	/**
	 * Release this worker's exact site-scoped claim.
	 *
	 * @param string $name Acquired lock name.
	 */
	public function release( string $name ): void {
		global $wpdb;
		if ( ! isset( self::$claims[ $name ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The prepared name is supplied only by claim() and released in finally.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		unset( self::$claims[ $name ] );
	}

	/**
	 * Persist state only after the corresponding bounded write completed.
	 *
	 * @param int    $cursor Last committed id.
	 * @param bool   $done End of id space observed without a failed read.
	 * @param string $error Bounded internal reason code, never SQL.
	 */
	public function progress( int $cursor, bool $done, string $error = '' ): bool {
		update_option( self::CURSOR, $cursor, false );
		update_option( self::ERROR, $error, false );
		update_option( self::DONE, $done ? 1 : 0, false );
		return $this->state() === array(
			'cursor' => $cursor,
			'done'   => $done,
			'error'  => $error,
		);
	}
}
