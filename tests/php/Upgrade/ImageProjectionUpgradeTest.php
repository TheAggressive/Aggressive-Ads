<?php
/**
 * Real additive DDL, bounded projection repair and recovery.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Upgrade;

use Aggressive\Ads\Install\Image_Projection_Migrator;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Install\Migration_Map;
use Aggressive\Ads\Install\Upgrader;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Image_Projection_Repository;
use WP_UnitTestCase;

/** DDL targets only the isolated WordPress test database, never the working site. */
final class ImageProjectionUpgradeTest extends WP_UnitTestCase {

	public function tear_down(): void {
		( new Image_Projection_Repository() )->install();
		Image_Projection_Migrator::unschedule();
		delete_option( Installer::OPTION_UPGRADE_LOCK );
		parent::tear_down();
	}

	/** The literal previous schema is upgraded through the real container migration map. */
	public function test_version_34_upgrade_preserves_identity_and_history_and_retries_failed_ddl(): void {
		global $wpdb;
		$table            = ( new Creative_Assignment_Repository() )->table_name();
		$original_version = Installer::stored_db_version();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$errors = $wpdb->suppress_errors( true );
		$fail   = static fn ( string $sql ): string => str_starts_with( $sql, 'ALTER TABLE ' . $table ) ? 'ALTER TABLE aggr_image_upgrade_refused ADD invalid' : $sql;
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Restore the literal previous assignment schema in the isolated database.
			$this->assertNotFalse( $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN image_version, DROP COLUMN image_sizes, DROP COLUMN image_hash', $table ) ) );
			wp_cache_delete( 'columns_' . get_current_blog_id(), 'aggr_image_schema' );
			$this->assertFalse( ( new Image_Projection_Repository() )->available() );
			update_option( Installer::OPTION_DB_VERSION, 34 );
			delete_option( Installer::OPTION_UPGRADE_LOCK );
			add_filter( 'query', $fail );
			try {
				Plugin::instance()->container()->get( Upgrader::class )->maybe_upgrade();
			} finally {
				remove_filter( 'query', $fail );
			}
			$this->assertSame( 34, Installer::stored_db_version() );
			$this->assertFalse( ( new Image_Projection_Repository() )->available() );
			Plugin::instance()->container()->get( Upgrader::class )->maybe_upgrade();
			$this->assertSame( 35, Installer::stored_db_version() );
			$this->assertTrue( ( new Image_Projection_Repository() )->available() );
			$steps = Migration_Map::steps( Plugin::instance()->container() );
			$steps[35]();
			$this->assertNotFalse( wp_next_scheduled( Image_Projection_Migrator::HOOK ) );
		} finally {
			remove_filter( 'query', $fail );
			( new Image_Projection_Repository() )->install();
			update_option( Installer::OPTION_DB_VERSION, $original_version );
			delete_option( Installer::OPTION_UPGRADE_LOCK );
			// Real ALTER commits the acquired lock; persist its release before the harness rolls back later DML.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Clean up real DDL under WP_UnitTestCase's autocommit=0 test connection.
			$wpdb->query( 'COMMIT' );
			$wpdb->suppress_errors( $errors );
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/** Failed reads do not finish; failed writes do not skip input; a CAS race is refused. */
	public function test_bounded_batches_resume_and_preserve_operator_state(): void {
		global $wpdb;
		$table = ( new Creative_Assignment_Repository() )->table_name();
		for ( $i = 0; $i < 101; ++$i ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Historical legacy rows deliberately carry no new format facts.
			$this->assertSame(
				1,
				$wpdb->insert(
					$table,
					array(
						'revision_id'     => 900000 + $i,
						'campaign_id'     => 700000,
						'status'          => 'completed',
						'weight'          => 37,
						'revision'        => 8,
						'operator_paused' => 1,
					)
				)
			);
		}
		$rows = new Image_Projection_Repository();
		$rows->begin();
		$rows->progress( 0, false );
		$worker = Plugin::instance()->container()->get( Image_Projection_Migrator::class );
		$this->assertSame( 100, $worker->run_batch() );
		$this->assertFalse( $rows->state()['done'] );
		$before = $rows->state()['cursor'];
		$input  = $rows->after( $before )[0];
		$this->assertTrue(
			$rows->write(
				$input,
				array(
					'image_version' => 0,
					'image_sizes'   => '',
					'image_hash'    => '',
				)
			)
		);
		$this->assertFalse(
			$rows->write(
				$input,
				array(
					'image_version' => 1,
					'image_sizes'   => '320x50',
					'image_hash'    => str_repeat( 'a', 64 ),
				)
			)
		);
		$fail   = static fn ( string $sql ): string => str_starts_with( $sql, 'SELECT id,revision,revision_id FROM ' ) ? 'SELECT aggr_image_read_refused' : $sql;
		$errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$this->assertSame( 0, $worker->run_batch() );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $errors );
		}
		$this->assertSame( $before, $rows->state()['cursor'] );
		$this->assertSame( 'read_failed', $rows->state()['error'] );
		$this->assertSame( 1, $worker->run_batch() );
		$this->assertTrue( $rows->state()['done'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify migration did not alter terminal/operator/history fields.
		$states = $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT status,weight,operator_paused,image_version FROM %i WHERE campaign_id=%d', $table, 700000 ), ARRAY_A );
		$this->assertSame(
			array(
				array(
					'status'          => 'completed',
					'weight'          => '37',
					'operator_paused' => '1',
					'image_version'   => '0',
				),
			),
			$states
		);
	}
}
