<?php
/**
 * Every ad on a size has an assignment row of its own.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Creative_View_Data;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Attachment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Workflow\Coverage_Service;
use Aggressive\Ads\Workflow\Creative_Manager;
use Aggressive\Ads\Workflow\Revision_Policy;
use WP_UnitTestCase;

/**
 * Assignment rows against real uploads, removals and revisions.
 *
 * The Ads step, coverage and delivery all read this table, so an ad without
 * a row is saved and invisible. Counts are asserted, and so is what must not
 * move: the first ad's row when the second is replaced.
 */
final class CreativeAssignmentRowsTest extends WP_UnitTestCase {

	/**
	 * Owning advertiser user id.
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * Unrelated advertiser user id.
	 *
	 * @var int
	 */
	private int $stranger;

	/**
	 * Owning organization id.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * Draft campaign id.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * First 728×90 placement.
	 *
	 * @var int
	 */
	private int $header;

	/**
	 * Second 728×90 placement.
	 *
	 * @var int
	 */
	private int $break;

	/**
	 * Upload and removal.
	 *
	 * @var Creative_Manager
	 */
	private Creative_Manager $manager;

	/**
	 * Media Library links, which mark an ad approved.
	 *
	 * @var Creative_Attachment_Repository
	 */
	private Creative_Attachment_Repository $attachments;

	/**
	 * Creative persistence.
	 *
	 * @var Creative_Repository
	 */
	private Creative_Repository $creatives;

	/**
	 * Private file storage.
	 *
	 * @var Private_Storage
	 */
	private Private_Storage $storage;

	/**
	 * Temporary source files.
	 *
	 * @var array<int, string>
	 */
	private array $temporary = array();

	/**
	 * A draft with two 728×90 placements; these tests use the first.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->owner    = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->stranger = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->org_id   = $this->org( $this->owner );

		$this->header = $this->placement( 'Header', '728x90' );
		$this->break  = $this->placement( 'Break', '728x90' );

		$this->campaign_id = $this->campaign( $this->owner, $this->org_id, array( $this->header, $this->break ) );

		$container         = Plugin::instance()->container();
		$this->manager     = $container->get( Creative_Manager::class );
		$this->attachments = $container->get( Creative_Attachment_Repository::class );
		$this->creatives   = $container->get( Creative_Repository::class );
		$this->storage     = $container->get( Private_Storage::class );

		$container->get( Ownership::class )->flush_cache();
	}

	/**
	 * Removes stored files and fixtures.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->creatives->ids_for_campaign( $this->campaign_id ) as $creative_id ) {
			$stored = $this->creatives->storage_details( $creative_id );

			if ( null !== $stored && '' !== $stored['path'] ) {
				$this->storage->delete( $stored['path'] );
			}
		}

		foreach ( $this->temporary as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}

		$_POST = array();

		parent::tear_down();
	}

	/**
	 * Removing an ad and uploading another to the same size shows the new one.
	 *
	 * The defect this is for: removal left the removed ad's row holding the
	 * size's slot, so the next upload was saved, never assigned, and never
	 * listed — the size said "Needs a file" with a file in the database.
	 *
	 * @return void
	 */
	public function test_an_upload_after_a_removal_is_listed_and_covers(): void {
		wp_set_current_user( $this->owner );

		$first = $this->upload( $this->header, 1 );

		// Rows are made when the campaign is read; the Ads step reads it.
		$this->assertSame( array( $first ), $this->listed() );
		$this->assertTrue( $this->manager->remove( $first ) );

		// Retired by the removal itself, not left for the next read to heal.
		$this->assertSame( Assignment_Rules::CANCELLED, $this->rows_on( $this->header )[ $first ]['status'] );

		$second = $this->upload( $this->header, 2 );

		$this->assertSame( array( $second ), $this->listed(), 'The new ad is not listed.' );
		$this->assertContains( $this->header, $this->covered(), 'The size is not covered by the new ad.' );

		$rows = $this->rows_on( $this->header );
		$this->assertCount( 2, $rows, 'Expected the retired row and the new one, and nothing else.' );
		$this->assertSame( Assignment_Rules::CANCELLED, $rows[ $first ]['status'], 'The removed ad still holds a live row.' );
		$this->assertNull( $rows[ $first ]['compat_key'], 'The removed ad still holds the slot.' );
		$this->assertSame( '1', (string) $rows[ $second ]['compat_key'], 'The new ad did not take the slot.' );
	}

	/**
	 * A second ad on one size is listed beside the first, with a row of its own.
	 *
	 * @return void
	 */
	public function test_a_second_ad_on_one_size_is_listed(): void {
		wp_set_current_user( $this->owner );

		$first  = $this->upload( $this->header, 1 );
		$second = $this->upload( $this->header, 2 );

		$this->assertSame( array( $first, $second ), $this->listed() );

		$rows = $this->rows_on( $this->header );
		$this->assertCount( 2, $rows );
		$this->assertSame( '1', (string) $rows[ $first ]['compat_key'], 'The first ad lost the slot.' );
		$this->assertNull( $rows[ $second ]['compat_key'] );

		// Asking again makes nothing new: one row per ad, however often healed.
		$migrator = Plugin::instance()->container()->get( Creative_Assignment_Migrator::class );
		$migrator->migrate_one( $second );
		$migrator->migrate_one( $first );
		$this->assertCount( 2, $this->rows_on( $this->header ) );
	}

	/**
	 * A site that met the old removal heals on the next page view.
	 *
	 * Deleted directly, as removal used to: the row is left `draft` and
	 * holding the slot.
	 *
	 * @return void
	 */
	public function test_a_slot_held_by_a_deleted_ad_is_taken_by_the_next(): void {
		wp_set_current_user( $this->owner );

		$gone = $this->upload( $this->header, 1 );
		$this->assertSame( array( $gone ), $this->listed() );
		wp_delete_post( $gone, true );

		$next = $this->upload( $this->header, 2 );

		$this->assertSame( array( $next ), $this->listed() );

		$rows = $this->rows_on( $this->header );
		$this->assertSame( Assignment_Rules::CANCELLED, $rows[ $gone ]['status'] );
		$this->assertSame( '1', (string) $rows[ $next ]['compat_key'] );
		$this->assertSame( 728, (int) $rows[ $next ]['width'], 'The new row was not built from the new ad.' );
	}

	/**
	 * Replacing the second ad on a size moves its own row, not the first's.
	 *
	 * @return void
	 */
	public function test_replacing_one_ad_of_a_rotation_moves_only_its_row(): void {
		wp_set_current_user( $this->owner );

		$first  = $this->upload( $this->header, 1 );
		$second = $this->upload( $this->header, 2 );
		$this->listed();

		$before = $this->rows_on( $this->header );

		// Approved, so a text change makes a new revision rather than an edit.
		$this->attachments->set_attachment_id( $second, self::factory()->attachment->create() );

		$revision = Plugin::instance()->container()->get( Revision_Policy::class )->apply_text_change( $second, 'https://example.com/changed' );
		$this->assertGreaterThan( 0, $revision );

		$after = $this->rows_on( $this->header );

		$this->assertSame( (int) $before[ $first ]['id'], (int) $after[ $first ]['id'], 'The first ad lost its row.' );
		$this->assertSame( (string) $before[ $first ]['click_url'], (string) $after[ $first ]['click_url'], "The first ad's row was rewritten." );
		$this->assertSame( (int) $before[ $second ]['id'], (int) $after[ $revision ]['id'], "The second ad's row did not move onto its revision." );
		$this->assertArrayNotHasKey( $second, $after );
	}

	/**
	 * Creative ids the Ads step lists for the campaign.
	 *
	 * @return array<int, int>
	 */
	private function listed(): array {
		return array_map(
			'intval',
			array_column( Plugin::instance()->container()->get( Creative_View_Data::class )->creative_rows( $this->campaign_id ), 'id' )
		);
	}

	/**
	 * Placements the campaign covers for submission.
	 *
	 * @return array<int, int>
	 */
	private function covered(): array {
		return Plugin::instance()->container()->get( Coverage_Service::class )->covered_placements( $this->campaign_id );
	}

	/**
	 * Every assignment row on one placement, keyed by revision.
	 *
	 * @param int $placement_id Placement.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows_on( int $placement_id ): array {
		$rows = array();

		foreach ( Plugin::instance()->container()->get( Creative_Assignment_Repository::class )->for_campaign( $this->campaign_id ) as $row ) {
			if ( (int) $row['placement_id'] === $placement_id ) {
				$rows[ (int) $row['revision_id'] ] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Uploads a distinct image to one placement.
	 *
	 * @param int $placement_id Placement.
	 * @param int $variant      Which image; the same variant is the same bytes.
	 * @return int Creative id.
	 */
	private function upload( int $placement_id, int $variant ): int {
		$result = $this->manager->upload(
			$this->campaign_id,
			$placement_id,
			$this->image_file( 728, 90, $variant ),
			'https://example.com/' . ( 1 === $variant ? 'one' : 'other' ),
			'Ad'
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );

		return (int) $result['id'];
	}

	/**
	 * A creative's recorded checksum.
	 *
	 * @param int $creative_id Creative post id.
	 * @return string
	 */
	private function sha( int $creative_id ): string {
		return (string) ( $this->creatives->storage_details( $creative_id )['sha256'] ?? '' );
	}

	/**
	 * A PNG whose bytes depend on `$variant`, so two variants never share a checksum.
	 *
	 * @param int $width   Width.
	 * @param int $height  Height.
	 * @param int $variant Distinguishing pixel.
	 * @return array<string, mixed>
	 */
	private function image_file( int $width, int $height, int $variant ): array {
		$image = imagecreatetruecolor( $width, $height );
		imagesetpixel( $image, $variant % $width, 0, (int) imagecolorallocate( $image, 255, 255, 255 ) );
		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();
		$path  = wp_tempnam( 'aggr-creative-copies' );
		file_put_contents( $path, $bytes );
		$this->temporary[] = $path;

		return array(
			'name'     => 'creative.png',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $bytes ),
		);
	}

	/**
	 * An organization owned by one advertiser.
	 *
	 * @param int $owner Owner user id.
	 * @return int
	 */
	private function org( int $owner ): int {
		$org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $org_id, Org_Repository::META_OWNER_USER, $owner );

		return $org_id;
	}

	/**
	 * An active placement.
	 *
	 * @param string $name Title.
	 * @param string $size Size, e.g. 728x90.
	 * @return int
	 */
	private function placement( string $name, string $size ): int {
		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);
		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, $size );

		return $placement_id;
	}

	/**
	 * A draft campaign on the given placements.
	 *
	 * @param int             $owner      Author.
	 * @param int             $org_id     Organization.
	 * @param array<int, int> $placements Placement ids.
	 * @return int
	 */
	private function campaign( int $owner, int $org_id, array $placements ): int {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
				'post_author' => $owner,
			)
		);
		update_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, $org_id );

		foreach ( $placements as $placement_id ) {
			add_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID, $placement_id );
		}

		return $campaign_id;
	}
}
