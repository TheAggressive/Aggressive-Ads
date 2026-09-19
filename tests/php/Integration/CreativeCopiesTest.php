<?php
/**
 * One file on two placements of the same size.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Creative_Actions;
use Aggressive\Ads\Portal\Creative_View_Data;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Workflow\Coverage_Service;
use Aggressive\Ads\Workflow\Creative_Approval;
use Aggressive\Ads\Workflow\Creative_Copies;
use Aggressive\Ads\Workflow\Creative_Manager;
use WP_UnitTestCase;

/**
 * Creative_Copies against real ownership, storage, coverage and audit data.
 *
 * A copy is a creative of its own, so most of what is asserted here is what a
 * copy must *not* do: change the other placement when one is replaced, remove
 * a creative that is not a copy, or reach into another organization.
 */
final class CreativeCopiesTest extends WP_UnitTestCase {

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
	 * Under test.
	 *
	 * @var Creative_Copies
	 */
	private Creative_Copies $copies;

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
	 * A draft with two 728×90 placements.
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

		$container       = Plugin::instance()->container();
		$this->manager   = $container->get( Creative_Manager::class );
		$this->copies    = $container->get( Creative_Copies::class );
		$this->creatives = $container->get( Creative_Repository::class );
		$this->storage   = $container->get( Private_Storage::class );

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
	 * The copy is a second creative on the second placement, with the same
	 * bytes and destination, and both placements are covered for submission.
	 *
	 * @return void
	 */
	public function test_a_copy_covers_the_second_placement_with_the_same_file(): void {
		wp_set_current_user( $this->owner );

		$source = $this->upload( $this->header, 1 );
		$copy   = $this->copies->copy_to_placement( $source, $this->break, $this->campaign_id );

		$this->assertIsArray( $copy, 'The copy was refused.' );
		$this->assertNotSame( $source, (int) $copy['id'], 'A copy must be a creative of its own.' );

		$details = $this->creatives->details( (int) $copy['id'] );
		$this->assertIsArray( $details );
		$this->assertSame( $this->break, $details['placement_id'] );
		$this->assertSame( 'https://example.com/one', $details['click_url'] );
		$this->assertSame( $this->sha( $source ), $this->sha( (int) $copy['id'] ) );
		$this->assertNotSame(
			$this->creatives->storage_details( $source )['path'] ?? '',
			$this->creatives->storage_details( (int) $copy['id'] )['path'] ?? '',
			'Two creatives pointing at one stored file would delete it from under each other.'
		);

		$covered = Plugin::instance()->container()->get( Coverage_Service::class )->covered_placements( $this->campaign_id );
		sort( $covered );
		$expected = array( $this->header, $this->break );
		sort( $expected );
		$this->assertSame( $expected, $covered, 'Coverage did not count both placements.' );

		$events = array_column( ( new Audit_Repository() )->for_object( 'campaign', $this->campaign_id, $this->org_id ), 'event' );
		$this->assertContains( 'creative.copied', $events );

		// The view names the other placement, and only that one.
		$rows = Plugin::instance()->container()->get( Creative_View_Data::class )->creative_rows( $this->campaign_id );
		$this->assertCount( 2, $rows );

		foreach ( $rows as $row ) {
			$this->assertCount( 1, $row['same_file'] );
			$this->assertSame( $row['id'] === $source ? 'Break' : 'Header', $row['same_file'][0]['placement'] );
		}
	}

	/**
	 * Replacing the artwork on one leaves the other's bytes exactly as they were.
	 *
	 * @return void
	 */
	public function test_replacing_one_copy_leaves_the_other_unchanged(): void {
		wp_set_current_user( $this->owner );

		$source = $this->upload( $this->header, 1 );
		$copy   = $this->copies->copy_to_placement( $source, $this->break );
		$this->assertIsArray( $copy );

		$before = $this->sha( $source );
		$result = $this->manager->replace_artwork( (int) $copy['id'], $this->image_file( 728, 90, 2 ) );

		$this->assertIsArray( $result, 'The copy could not be given new artwork.' );
		$this->assertNotSame( $before, $this->sha( (int) $copy['id'] ), 'The replacement did not change the copy.' );
		$this->assertSame( $before, $this->sha( $source ), 'Replacing the copy changed the original.' );
		$this->assertSame( array(), $this->creatives->same_file_elsewhere( $source ) );
	}

	/**
	 * Another organization's creative, a missing one and one from a different
	 * campaign than the form's all get the same refusal, and nothing is made.
	 *
	 * @return void
	 */
	public function test_a_copy_is_refused_across_tenants_without_saying_which(): void {
		wp_set_current_user( $this->owner );
		$source = $this->upload( $this->header, 1 );

		$their_org      = $this->org( $this->stranger );
		$their_place    = $this->placement( 'Theirs', '728x90' );
		$their_campaign = $this->campaign( $this->stranger, $their_org, array( $their_place ) );
		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		wp_set_current_user( $this->stranger );

		$foreign = $this->copies->copy_to_placement( $source, $their_place );
		$missing = $this->copies->copy_to_placement( 999999, $their_place );

		$this->assertWPError( $foreign );
		$this->assertWPError( $missing );
		$this->assertSame( 'aggr_forbidden', $foreign->get_error_code() );
		$this->assertSame( $missing->get_error_code(), $foreign->get_error_code(), 'The refusal told a stranger the creative exists.' );
		$this->assertSame( $missing->get_error_message(), $foreign->get_error_message() );
		$this->assertSame( array(), $this->creatives->ids_for_campaign( $their_campaign ) );

		// A valid nonce is for the form's campaign; a source from another is refused.
		wp_set_current_user( $this->owner );
		$crossed = Plugin::instance()->container()->get( Creative_Actions::class )->process_copy( $their_campaign, $source, $this->break );

		$this->assertWPError( $crossed );
		$this->assertSame( 'aggr_forbidden', $crossed->get_error_code() );
		$this->assertCount( 1, $this->creatives->for_campaign( $this->campaign_id ) );
	}

	/**
	 * A copy is checked against the placement it lands on, like any upload.
	 *
	 * @return void
	 */
	public function test_a_copy_to_a_different_size_is_refused(): void {
		wp_set_current_user( $this->owner );

		$side = $this->placement( 'Side', '300x250' );
		add_post_meta( $this->campaign_id, Campaign_Repository::META_PLACEMENT_ID, $side );

		$source = $this->upload( $this->header, 1 );
		$result = $this->copies->copy_to_placement( $source, $side );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_creative_size_mismatch', $result->get_error_code() );
		$this->assertCount( 1, $this->creatives->for_campaign( $this->campaign_id ) );
	}

	/**
	 * A running campaign's empty size can take a copy, which waits for review.
	 *
	 * @return void
	 */
	public function test_a_copy_onto_a_running_campaigns_empty_size_is_held_for_review(): void {
		wp_set_current_user( $this->owner );
		$source = $this->upload( $this->header, 1 );

		wp_update_post(
			array(
				'ID'          => $this->campaign_id,
				'post_status' => Post_Statuses::LIVE,
			)
		);

		$copy = $this->copies->copy_to_placement( $source, $this->break );

		$this->assertIsArray( $copy );
		$this->assertContains(
			(int) $copy['id'],
			Plugin::instance()->container()->get( Creative_Approval::class )->awaiting( $this->campaign_id ),
			'The copy skipped review.'
		);
	}

	/**
	 * Removal takes the ticked copies with it, and nothing else.
	 *
	 * @return void
	 */
	public function test_removal_takes_only_the_copies_asked_for(): void {
		wp_set_current_user( $this->owner );

		$third = $this->placement( 'Footer', '728x90' );
		add_post_meta( $this->campaign_id, Campaign_Repository::META_PLACEMENT_ID, $third );

		$source    = $this->upload( $this->header, 1 );
		$copy      = $this->copies->copy_to_placement( $source, $this->break );
		$unrelated = $this->upload( $third, 3 );
		$this->assertIsArray( $copy );

		// Not asked: the copy stays.
		$this->assertTrue( $this->copies->remove_with_copies( $source, array() ) );
		$this->assertNotNull( $this->creatives->details( (int) $copy['id'] ) );
		$this->assertCount( 2, $this->creatives->for_campaign( $this->campaign_id ) );

		// Asked, alongside an id that is not a copy of this file.
		$again = $this->copies->copy_to_placement( (int) $copy['id'], $this->header );
		$this->assertIsArray( $again );

		$removed = Plugin::instance()->container()->get( Creative_Actions::class )->process_remove( (int) $again['id'], array( (int) $copy['id'], $unrelated ) );

		$this->assertTrue( $removed );
		$this->assertNull( $this->creatives->details( (int) $again['id'] ) );
		$this->assertNull( $this->creatives->details( (int) $copy['id'] ), 'The ticked copy was left behind.' );
		$this->assertNotNull( $this->creatives->details( $unrelated ), 'A creative that was not a copy was removed because the form named it.' );
		$this->assertSame( array( $unrelated ), array_column( $this->creatives->for_campaign( $this->campaign_id ), 'id' ) );
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
