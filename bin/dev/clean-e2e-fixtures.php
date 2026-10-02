<?php
/**
 * Removes what the browser suite and the dev seed leave on a site.
 *
 * For a site the suite ran against before it had a disposable one of its own
 * (`pnpm e2e:site`). Run through `bin/dev/clean-e2e-fixtures.sh`, which backs
 * the site up first; on its own this only reports, and it refuses to delete
 * without the backup directory the wrapper creates.
 *
 * **Only what a fixture provably made.** Each rule names the seed or spec that
 * creates what it matches, so nothing goes because it merely looks like test
 * data:
 *
 *  - organizations the seeds create: Bright Angle Media (bin/dev/seed.php),
 *    Apex Analytics Group and Zephyr Outdoor Co (seed-organizations.php), and
 *    every `e2e-` slug (seed-page-coordination.php, the signup spec);
 *  - every campaign and creative those organizations own;
 *  - "Created for a client" campaigns in any organization — the title
 *    review.spec.ts types when it creates a campaign for an advertiser, which
 *    until the spec named its fixture was the first advertiser in the list,
 *    on a working site a real one;
 *  - `e2e-` campaigns, whatever organization they name (seed-live-ad.php's
 *    point at one it later deleted);
 *  - `e2e-` creatives whose campaign is gone or going: seed-live-ad.php
 *    deleted its campaign each run and left the creative behind;
 *  - `e2e-` placements and packages, unless something that stays refers to
 *    them.
 *
 * Users are reported and left: an account can own things this does not know
 * about. The audit log is left as it is; it is the record of what happened,
 * fixtures included.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Storage\Private_Storage;

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_CLI' ) ) {
	exit( 1 );
}

$aggr_clean = static function (): void {
	global $wpdb;

	$confirm = '1' === getenv( 'AGGR_CLEAN_CONFIRM' );
	$e2e     = $wpdb->esc_like( 'e2e-' ) . '%';

	/*
	 * A list ready for `IN ( %d, … )`: never empty, because an empty `IN ()`
	 * is a syntax error. A lone 0 matches no post and no row.
	 */
	$bind = static fn ( array $post_ids ): array => array() === $post_ids ? array( 0 ) : array_values( $post_ids );

	$ids = static function ( string $prepared ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A one-off maintenance read; every caller passes the result of $wpdb->prepare().
		return array_values( array_unique( array_map( 'intval', (array) $wpdb->get_col( $prepared ) ) ) );
	};

	$titles = static function ( array $post_ids ): array {
		global $wpdb;

		if ( array() === $post_ids ) {
			return array();
		}

		$counts = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A one-off maintenance report.
		$found = (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_title FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', array_fill( 0, count( $post_ids ), '%d' ) ) . ')', ...$post_ids ) );

		foreach ( $found as $title ) {
			$counts[ (string) $title ] = ( $counts[ (string) $title ] ?? 0 ) + 1;
		}

		arsort( $counts );

		return $counts;
	};

	$orgs = $ids(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ( post_name IN ( 'bright-angle-media', 'apex-analytics-group', 'zephyr-outdoor-co' ) OR post_name LIKE %s )",
			Post_Types::ORGANIZATION,
			$e2e
		)
	);

	$org_list  = $bind( $orgs );
	$campaigns = $ids(
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One %d per organization, built from the same list that is spread.
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			WHERE p.post_type = %s AND ( p.post_title = 'Created for a client' OR p.post_name LIKE %s OR m.meta_value IN (" . implode( ',', array_fill( 0, count( $org_list ), '%d' ) ) . ') )',
			Campaign_Repository::META_ORG_ID,
			Post_Types::CAMPAIGN,
			$e2e,
			...$org_list
		)
	);

	// Theirs, and e2e- creatives whose campaign is gone or going.
	$campaign_list = $bind( $campaigns );
	$creatives     = array_values(
		array_unique(
			array_merge(
				$ids(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One %d per campaign, built from the same list that is spread.
					$wpdb->prepare(
						"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN (" . implode( ',', array_fill( 0, count( $campaign_list ), '%d' ) ) . ')',
						Creative_Repository::META_CAMPAIGN_ID,
						...$campaign_list
					)
				),
				$ids(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One %d per organization, built from the same list that is spread.
					$wpdb->prepare(
						"SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type = %s AND m.meta_key = %s AND m.meta_value IN (" . implode( ',', array_fill( 0, count( $org_list ), '%d' ) ) . ')',
						Post_Types::CREATIVE,
						Creative_Repository::META_ORG_ID,
						...$org_list
					)
				),
				// Tied to a going campaign as its child post (seed-page-coordination.php).
				$ids(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One %d per campaign, built from the same list that is spread.
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent IN (" . implode( ',', array_fill( 0, count( $campaign_list ), '%d' ) ) . ')',
						Post_Types::CREATIVE,
						...$campaign_list
					)
				),
				// e2e- creatives with no campaign left by either link.
				$ids(
					$wpdb->prepare(
						"SELECT p.ID FROM {$wpdb->posts} p
						LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s
						LEFT JOIN {$wpdb->posts} k ON k.ID = c.meta_value
						LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent AND p.post_parent > 0
						WHERE p.post_type = %s AND p.post_name LIKE %s AND k.ID IS NULL AND parent.ID IS NULL",
						Creative_Repository::META_CAMPAIGN_ID,
						Post_Types::CREATIVE,
						$e2e
					)
				)
			)
		)
	);

	$candidates = static fn ( string $type ): array => $ids(
		$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name LIKE %s", $type, $e2e )
	);

	$placement_candidates = $candidates( Post_Types::PLACEMENT );
	$package_candidates   = $candidates( Post_Types::PACKAGE );
	$doomed               = array_merge( $campaigns, $creatives, $placement_candidates, $package_candidates );

	// Kept while anything that stays — anything not on the lists above —
	// refers to it. Filtered here rather than in SQL, so each query binds one
	// list.
	$doomed_set = array_flip( $doomed );
	$in_use     = static function ( array $post_ids, string $meta_key ) use ( $wpdb, $bind, $doomed_set ): array {
		$list = $bind( $post_ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A one-off maintenance read.
		$refs = (array) $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One %d per post, built from the same list that is spread.
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN (" . implode( ',', array_fill( 0, count( $list ), '%d' ) ) . ')',
				$meta_key,
				...$list
			)
		);

		$kept = array();

		foreach ( $refs as $ref ) {
			if ( ! isset( $doomed_set[ (int) $ref->post_id ] ) ) {
				$kept[] = (int) $ref->meta_value;
			}
		}

		return array_values( array_unique( $kept ) );
	};

	$placements_kept = $in_use( $placement_candidates, Campaign_Repository::META_PLACEMENT_ID );
	$packages_kept   = $in_use( $package_candidates, Campaign_Repository::META_PACKAGE_ID );
	$placements      = array_values( array_diff( $placement_candidates, $placements_kept ) );
	$packages        = array_values( array_diff( $package_candidates, $packages_kept ) );

	$line_items = array();
	$tables     = array();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Listing the plugin's own tables.
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'aggr_' ) . '%' ) ) as $table ) {
		$table = (string) $table;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the plugin's own schema.
		$columns = array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) ) );

		// The record of what happened stays, fixtures included.
		if ( in_array( 'object_type', $columns, true ) ) {
			continue;
		}

		$tables[ $table ] = $columns;

		if ( str_ends_with( $table, 'aggr_line_items' ) ) {
			$line_items = $ids(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The table, then one %d per campaign from the same list.
				$wpdb->prepare(
					'SELECT id FROM %i WHERE campaign_id IN (' . implode( ',', array_fill( 0, count( $campaign_list ), '%d' ) ) . ')',
					$table,
					...$campaign_list
				)
			);
		}
	}

	$by_column = array(
		'campaign_id'      => $campaigns,
		'line_item_id'     => $line_items,
		'creative_id'      => $creatives,
		'revision_id'      => $creatives,
		'root_creative_id' => $creatives,
		'organization_id'  => $orgs,
		'org_id'           => $orgs,
		'placement_id'     => $placements,
	);

	// Each table's WHERE, prepared once, used to count and then to delete.
	$rows = array();

	foreach ( $tables as $table => $columns ) {
		$where = array();
		$args  = array( $table );

		foreach ( $by_column as $column => $post_ids ) {
			if ( in_array( $column, $columns, true ) && array() !== $post_ids ) {
				$where[] = '%i IN (' . implode( ',', array_fill( 0, count( $post_ids ), '%d' ) ) . ')';
				$args    = array_merge( $args, array( $column ), $post_ids );
			}
		}

		if ( array() === $where ) {
			continue;
		}

		/*
		 * Assembled from placeholders only — %i for the table and each column,
		 * %d per id — so every value is still bound by prepare(). The sniff
		 * cannot follow a WHERE built in a loop.
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Built only from %i and %d placeholders; see above.
		$condition = $wpdb->prepare( 'FROM %i WHERE ' . implode( ' OR ', $where ), ...$args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $condition is the output of $wpdb->prepare() above.
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) ' . $condition );

		if ( $count > 0 ) {
			$rows[ $table ] = array( $count, $condition );
		}
	}

	$users = get_users(
		array(
			'search'         => '*@example.test',
			'search_columns' => array( 'user_email' ),
			'fields'         => array( 'user_email' ),
		)
	);

	$report = static function ( string $label, array $counts ): void {
		WP_CLI::log( sprintf( '%s: %d', $label, array_sum( $counts ) ) );

		foreach ( $counts as $title => $count ) {
			WP_CLI::log( sprintf( '    %4d  %s', $count, $title ) );
		}
	};

	WP_CLI::log( $confirm ? 'Deleting:' : 'Dry run. Nothing is deleted without AGGR_CLEAN_CONFIRM=1.' );
	$report( 'Organizations', $titles( $orgs ) );
	$report( 'Campaigns', $titles( $campaigns ) );
	$report( 'Creatives (with their private files and Media Library copies)', $titles( $creatives ) );
	$report( 'Placements', $titles( $placements ) );
	$report( 'Packages', $titles( $packages ) );

	if ( array() !== $placements_kept || array() !== $packages_kept ) {
		$report( 'Kept, still in use by something that stays', $titles( array_merge( $placements_kept, $packages_kept ) ) );
	}

	foreach ( $rows as $table => $row ) {
		WP_CLI::log( sprintf( 'Rows in %s: %d', $table, $row[0] ) );
	}

	WP_CLI::log( sprintf( 'Fixture users (@example.test), left in place: %d', count( $users ) ) );

	foreach ( $users as $user ) {
		WP_CLI::log( '    ' . $user->user_email );
	}

	if ( ! $confirm ) {
		return;
	}

	/*
	 * Nothing is deleted without somewhere to put it first. The wrapper copies
	 * the database there; each file this removes is copied there just before
	 * it goes, under the path it had, so a restore is two copies back.
	 */
	$backup = (string) getenv( 'AGGR_CLEAN_BACKUP' );

	if ( '' === $backup || ! is_dir( $backup ) ) {
		WP_CLI::error( 'Refusing to delete: AGGR_CLEAN_BACKUP is not a directory. Run through bin/dev/clean-e2e-fixtures.sh.' );
	}

	$keep = static function ( string $file, string $under, string $root ) use ( $backup ): void {
		if ( ! is_file( $file ) || ! str_starts_with( $file, $root ) ) {
			return;
		}

		$target = $backup . '/' . $under . substr( $file, strlen( $root ) );

		wp_mkdir_p( dirname( $target ) );

		if ( ! copy( $file, $target ) ) {
			WP_CLI::error( "Could not back up {$file}; nothing further is deleted." );
		}
	};

	$storage = new Private_Storage();
	$uploads = rtrim( (string) wp_upload_dir()['basedir'], '/' );
	$files   = 0;
	$copies  = 0;

	foreach ( $creatives as $creative_id ) {
		$path = (string) get_post_meta( $creative_id, Creative_Repository::META_PRIVATE_PATH, true );

		if ( '' !== $path ) {
			$resolved = $storage->resolve( $path );

			if ( null !== $resolved ) {
				$keep( $resolved, Private_Storage::DIRECTORY, $storage->root() );
			}

			if ( $storage->delete( $path ) ) {
				++$files;
			}
		}

		$attachment = (int) get_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, true );

		// A copy can share its Media Library file with a creative that stays,
		// and the coordination fixture reuses the live ad's.
		$shared = $attachment > 0 && array() !== array_diff(
			$ids( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d", Creative_Repository::META_ATTACHMENT_ID, $attachment ) ),
			$creatives
		);

		if ( $attachment > 0 && ! $shared ) {
			$original = (string) get_attached_file( $attachment );
			$meta     = wp_get_attachment_metadata( $attachment );
			$sizes    = is_array( $meta ) && isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array();

			$keep( $original, 'uploads', $uploads );

			foreach ( $sizes as $size ) {
				if ( is_array( $size ) && isset( $size['file'] ) ) {
					$keep( dirname( $original ) . '/' . $size['file'], 'uploads', $uploads );
				}
			}

			if ( false !== wp_delete_attachment( $attachment, true ) ) {
				++$copies;
			}
		}

		wp_delete_post( $creative_id, true );
	}

	// Line items go with their campaign (Line_Item_Lifecycle).
	foreach ( $campaigns as $campaign_id ) {
		wp_delete_post( $campaign_id, true );
	}

	foreach ( $rows as $row ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $row[1] is the output of $wpdb->prepare() above.
		$wpdb->query( 'DELETE ' . $row[1] );
	}

	foreach ( array_merge( $placements, $packages, $orgs ) as $post_id ) {
		wp_delete_post( $post_id, true );
	}

	wp_cache_flush();

	WP_CLI::success( sprintf( 'Removed %d campaigns, %d creatives (%d private files, %d Media Library copies), %d placements, %d packages and %d organizations.', count( $campaigns ), count( $creatives ), $files, $copies, count( $placements ), count( $packages ), count( $orgs ) ) );
};

$aggr_clean();
