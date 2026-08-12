<?php
/**
 * Private creative retention against real WordPress storage.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Integration;

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Install\Installer;
use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Repository\Creative_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Security\Ownership;
use LAAO_Advertiser_Portal\Security\Roles;
use LAAO_Advertiser_Portal\Storage\Private_Storage;
use LAAO_Advertiser_Portal\Workflow\Creative_Retention;
use WP_UnitTestCase;

/**
 * Proves the ninety-day purge deletes private bytes and keeps the record.
 */
final class CreativeRetentionTest extends WP_UnitTestCase {

	/**
	 * @var Creative_Retention
	 */
	private Creative_Retention $retention;

	/**
	 * @var Private_Storage
	 */
	private Private_Storage $storage;

	/**
	 * @var Creative_Repository
	 */
	private Creative_Repository $creatives;

	/**
	 * @var Audit_Repository
	 */
	private Audit_Repository $audit;

	/**
	 * @var int
	 */
	private int $org_id;

	/**
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$container       = Plugin::instance()->container();
		$this->retention = $container->get( Creative_Retention::class );
		$this->storage   = $container->get( Private_Storage::class );
		$this->creatives = $container->get( Creative_Repository::class );
		$this->audit     = $container->get( Audit_Repository::class );

		$advertiser = (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$this->org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $advertiser );
		$container->get( Ownership::class )->flush_cache();

		$this->storage->ensure();
	}

	/**
	 * A terminal campaign past retention loses its private file, not its post.
	 *
	 * @return void
	 */
	public function test_private_files_for_old_terminal_campaigns_are_purged(): void {
		$end         = time() - ( 100 * DAY_IN_SECONDS );
		$campaign_id = $this->campaign( Post_Statuses::COMPLETE, $end );
		$creative_id = $this->creative_with_private_file( $campaign_id );
		$path        = (string) get_post_meta( $creative_id, Creative_Repository::META_PRIVATE_PATH, true );

		$this->assertNotNull( $this->storage->resolve( $path ) );

		$this->assertSame( 1, $this->retention->purge() );

		$this->assertNull( $this->storage->resolve( $path ) );
		$this->assertSame( '', (string) get_post_meta( $creative_id, Creative_Repository::META_PRIVATE_PATH, true ) );
		$this->assertSame( Post_Types::CREATIVE, get_post_type( $creative_id ) );
		$this->assertNotSame( '', (string) get_post_meta( $creative_id, Creative_Repository::META_SHA256, true ) );

		$events = $this->audit->for_object( 'campaign', $campaign_id, $this->org_id );
		$this->assertSame( 'campaign.private_files_purged', $events[0]['event'] );
		$this->assertSame( '', (string) get_post_meta( $creative_id, Creative_Repository::META_PRIVATE_TOKEN, true ) );
	}

	/**
	 * Recent terminals and live campaigns keep their private files.
	 *
	 * @return void
	 */
	public function test_recent_and_live_campaigns_are_not_purged(): void {
		$recent = $this->creative_with_private_file(
			$this->campaign( Post_Statuses::COMPLETE, time() - ( 10 * DAY_IN_SECONDS ) )
		);
		$live   = $this->creative_with_private_file(
			$this->campaign( Post_Statuses::LIVE, time() + ( 10 * DAY_IN_SECONDS ) )
		);

		$recent_path = (string) get_post_meta( $recent, Creative_Repository::META_PRIVATE_PATH, true );
		$live_path   = (string) get_post_meta( $live, Creative_Repository::META_PRIVATE_PATH, true );

		$this->assertSame( 0, $this->retention->purge() );
		$this->assertNotNull( $this->storage->resolve( $recent_path ) );
		$this->assertNotNull( $this->storage->resolve( $live_path ) );
	}

	/**
	 * A missing file still clears the pointer so the sweep can finish.
	 *
	 * @return void
	 */
	public function test_a_missing_file_still_clears_the_pointer(): void {
		$campaign_id = $this->campaign( Post_Statuses::CANCELLED, time() - ( 120 * DAY_IN_SECONDS ) );
		$creative_id = $this->creative_with_private_file( $campaign_id );
		$path        = (string) get_post_meta( $creative_id, Creative_Repository::META_PRIVATE_PATH, true );

		$this->assertTrue( $this->storage->delete( $path ) );
		$this->assertSame( 1, $this->retention->purge_campaign( $campaign_id ) );
		$this->assertSame( '', (string) get_post_meta( $creative_id, Creative_Repository::META_PRIVATE_PATH, true ) );
	}

	/**
	 * The daily event is scheduled and a drifted recurrence is repaired.
	 *
	 * @return void
	 */
	public function test_the_sweep_is_scheduled_and_repaired(): void {
		wp_clear_scheduled_hook( Creative_Retention::HOOK );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', Creative_Retention::HOOK );

		$this->retention->ensure_scheduled();

		$this->assertSame( Creative_Retention::RECURRENCE, wp_get_schedule( Creative_Retention::HOOK ) );
	}

	/**
	 * @param string $status Campaign status.
	 * @param int    $end_ts End timestamp.
	 * @return int
	 */
	private function campaign( string $status, int $end_ts ): int {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => $status,
				'post_date'   => gmdate( 'Y-m-d H:i:s', $end_ts ),
			)
		);

		update_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, $this->org_id );
		update_post_meta( $campaign_id, Campaign_Repository::META_START_TS, $end_ts - WEEK_IN_SECONDS );
		update_post_meta( $campaign_id, Campaign_Repository::META_END_TS, $end_ts );

		wp_update_post(
			array(
				'ID'                => $campaign_id,
				'post_modified'     => gmdate( 'Y-m-d H:i:s', $end_ts ),
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', $end_ts ),
			)
		);

		return $campaign_id;
	}

	/**
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	private function creative_with_private_file( int $campaign_id ): int {
		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		$source = wp_tempnam( 'laao-ads-retention' );
		file_put_contents( $source, 'retention-fixture' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write.

		$stored = $this->storage->store( $source, 'bin' );
		$this->assertIsArray( $stored );

		update_post_meta( $creative_id, Creative_Repository::META_CAMPAIGN_ID, $campaign_id );
		update_post_meta( $creative_id, Creative_Repository::META_ORG_ID, $this->org_id );
		$this->creatives->record_upload(
			$creative_id,
			array(
				'path'   => $stored['path'],
				'token'  => $stored['token'],
				'sha256' => $stored['sha256'],
				'bytes'  => $stored['bytes'],
				'mime'   => 'application/octet-stream',
				'width'  => 0,
				'height' => 0,
				'name'   => 'fixture.bin',
			)
		);

		return $creative_id;
	}
}
