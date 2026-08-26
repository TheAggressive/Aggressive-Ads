<?php
/**
 * Shared creative workflow and progressive forms.
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
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Workflow\Creative_Manager;
use WP_Error;
use WP_UnitTestCase;

/**
 * Creative_Manager against real ownership, metadata, storage, and audit data.
 */
final class CreativeManagerTest extends WP_UnitTestCase {

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
	 * Editable campaign id.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * Active campaign placement id.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Shared workflow.
	 *
	 * @var Creative_Manager
	 */
	private Creative_Manager $manager;

	/**
	 * Progressive form delivery.
	 *
	 * @var Creative_Actions
	 */
	private Creative_Actions $actions;

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
	 * Stored relative paths to clean up.
	 *
	 * @var array<int, string>
	 */
	private array $stored = array();

	/**
	 * Builds one editable tenant campaign and placement.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->owner    = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->stranger = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->org_id   = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->owner );

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage Leaderboard',
			)
		);
		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );

		$this->campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
				'post_author' => $this->owner,
			)
		);
		update_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, $this->org_id );
		add_post_meta( $this->campaign_id, Campaign_Repository::META_PLACEMENT_ID, $this->placement_id );

		$this->manager = Plugin::instance()->container()->get( Creative_Manager::class );
		$this->actions = Plugin::instance()->container()->get( Creative_Actions::class );
		$this->storage = Plugin::instance()->container()->get( Private_Storage::class );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();
	}

	/**
	 * Removes filesystem fixtures and request globals.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->stored as $relative ) {
			$this->storage->delete( $relative );
		}

		foreach ( $this->temporary as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}

		$_FILES = array();
		$_POST  = array();

		parent::tear_down();
	}

	/**
	 * A correct image is stored privately, represented safely, and audited.
	 *
	 * @return void
	 */
	public function test_upload_persists_a_valid_private_creative(): void {
		wp_set_current_user( $this->owner );

		$result = $this->manager->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->image_file( 728, 90 ),
			'https://example.com/exhibitions',
			'Visitors viewing a gallery exhibition'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 728, $result['width'] );
		$this->assertSame( 90, $result['height'] );

		$creative = Plugin::instance()->container()->get( Creative_Repository::class )->details( (int) $result['id'] );
		$this->assertIsArray( $creative );
		$this->assertSame( $this->org_id, $creative['org_id'] );
		$this->assertSame( 'Visitors viewing a gallery exhibition', $creative['alt_text'] );

		$stored = Plugin::instance()->container()->get( Creative_Repository::class )->storage_details( (int) $result['id'] );
		$this->assertIsArray( $stored );
		$this->stored[] = $stored['path'];
		$this->assertNotNull( $this->storage->resolve( $stored['path'] ) );

		$events = ( new Audit_Repository() )->for_object( 'campaign', $this->campaign_id, $this->org_id );
		$this->assertSame( 'creative.uploaded', $events[0]['event'] );
	}

	/**
	 * Wrong dimensions are explained and the staged private file is removed.
	 *
	 * @return void
	 */
	public function test_dimension_mismatch_fails_without_an_orphan(): void {
		wp_set_current_user( $this->owner );
		$before = $this->private_images();

		$result = $this->manager->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->image_file( 720, 90 ),
			'https://example.com/',
			'Gallery exhibition'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_creative_size_mismatch', $result->get_error_code() );
		$this->assertStringContainsString( '720 × 90', $result->get_error_message() );
		$this->assertStringContainsString( '728x90', $result->get_error_message() );
		$this->assertSame( $before, $this->private_images(), 'Rejected dimensions left an unreferenced private file.' );
		$this->assertSame( array(), Plugin::instance()->container()->get( Creative_Repository::class )->for_campaign( $this->campaign_id ) );
	}

	/**
	 * Destination is mandatory and missing alternative text is generated.
	 *
	 * @return void
	 */
	public function test_destination_is_required_and_accessible_text_is_automatic(): void {
		wp_set_current_user( $this->owner );
		$file = $this->image_file( 728, 90 );

		$missing_url = $this->manager->upload( $this->campaign_id, $this->placement_id, $file, '', 'Alt text' );
		$this->assertWPError( $missing_url );
		$this->assertSame( 'aggr_click_url_required', $missing_url->get_error_code() );

		$bad_url = $this->manager->upload( $this->campaign_id, $this->placement_id, $file, 'javascript:alert(1)', 'Alt text' );
		$this->assertWPError( $bad_url );
		$this->assertSame( 'aggr_click_url_invalid', $bad_url->get_error_code() );

		$uploaded = $this->manager->upload( $this->campaign_id, $this->placement_id, $file, 'https://www.example.com/gallery', '' );
		$this->assertIsArray( $uploaded );

		$repository = Plugin::instance()->container()->get( Creative_Repository::class );
		$creative   = $repository->details( (int) $uploaded['id'] );
		$stored     = $repository->storage_details( (int) $uploaded['id'] );

		$this->assertIsArray( $creative );
		$this->assertSame( 'Advertisement linking to example.com', $creative['alt_text'] );
		$this->assertIsArray( $stored );
		$this->stored[] = $stored['path'];
	}

	/**
	 * A second creative on one placement is accepted.
	 *
	 * The P1 limitation P2 exists to remove, and the assertion is inverted from
	 * what it used to be: this test previously proved the refusal. Keeping it
	 * pointed at the same shape rather than deleting it is deliberate — the
	 * behaviour changed, so the test that described the old behaviour is the
	 * right place to describe the new one.
	 *
	 * @return void
	 */
	public function test_a_second_creative_for_the_same_placement_is_accepted(): void {
		wp_set_current_user( $this->owner );

		$first = $this->manager->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->image_file( 728, 90 ),
			'https://example.com/',
			'Gallery exhibition'
		);
		$this->assertIsArray( $first );

		$stored = Plugin::instance()->container()->get( Creative_Repository::class )->storage_details( (int) $first['id'] );
		$this->assertIsArray( $stored );
		$this->stored[] = $stored['path'];

		$second = $this->manager->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->image_file( 728, 90 ),
			'https://example.com/replacement',
			'Replacement exhibition creative'
		);

		$this->assertIsArray( $second, 'A second creative on one placement was refused.' );

		$stored_second = Plugin::instance()->container()->get( Creative_Repository::class )->storage_details( (int) $second['id'] );
		$this->assertIsArray( $stored_second );
		$this->stored[] = $stored_second['path'];

		// A count, not a presence check: "a second was accepted" would pass
		// whether the first survived or was silently replaced.
		$this->assertCount(
			2,
			Plugin::instance()->container()->get( Creative_Repository::class )->for_campaign( $this->campaign_id ),
			'The second upload replaced the first rather than joining it.'
		);
	}

	/**
	 * The cap is per placement, not per campaign.
	 *
	 * This exists because sabotage found the gap: counting every creative on
	 * the campaign rather than the ones on this placement left every other test
	 * green, since they all use a single placement. On a real campaign with
	 * several placements it would have refused an upload to an empty slot
	 * because a *different* slot was full — an error naming the wrong thing,
	 * about a limit the advertiser had not reached.
	 *
	 * @return void
	 */
	public function test_the_cap_counts_only_the_placement_being_uploaded_to(): void {
		wp_set_current_user( $this->owner );

		$second_placement = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Sidebar',
			)
		);

		update_post_meta( $second_placement, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $second_placement, Placement_Repository::META_SIZE, '728x90' );
		add_post_meta( $this->campaign_id, Campaign_Repository::META_PLACEMENT_ID, $second_placement );

		// Fill the first placement to its limit.
		for ( $i = 0; $i < Creative_Manager::MAX_CREATIVES_PER_PLACEMENT; $i++ ) {
			$made = $this->manager->upload(
				$this->campaign_id,
				$this->placement_id,
				$this->image_file( 728, 90 ),
				'https://example.com/' . $i,
				'Leaderboard ' . $i
			);

			$stored = Plugin::instance()->container()->get( Creative_Repository::class )->storage_details( (int) $made['id'] );

			if ( is_array( $stored ) ) {
				$this->stored[] = $stored['path'];
			}
		}

		// The other placement is empty and must still accept one.
		$other = $this->manager->upload(
			$this->campaign_id,
			$second_placement,
			$this->image_file( 728, 90 ),
			'https://example.com/sidebar',
			'Sidebar creative'
		);

		$this->assertIsArray(
			$other,
			'A full placement blocked an upload to a different, empty one.'
		);

		$stored_other = Plugin::instance()->container()->get( Creative_Repository::class )->storage_details( (int) $other['id'] );

		if ( is_array( $stored_other ) ) {
			$this->stored[] = $stored_other['path'];
		}
	}

	/**
	 * The backstop refuses the eleventh, and says why.
	 *
	 * Not a product constraint — ten is high enough that no honest rotation
	 * meets it. It exists because the cost of a runaway lands on the publisher
	 * reviewing them, and rate limiting bounds how fast creatives arrive
	 * without bounding how many there are.
	 *
	 * @return void
	 */
	public function test_the_eleventh_creative_on_one_placement_is_refused(): void {
		wp_set_current_user( $this->owner );

		for ( $i = 0; $i < Creative_Manager::MAX_CREATIVES_PER_PLACEMENT; $i++ ) {
			$made = $this->manager->upload(
				$this->campaign_id,
				$this->placement_id,
				$this->image_file( 728, 90 ),
				'https://example.com/' . $i,
				'Rotation creative ' . $i
			);

			$this->assertIsArray( $made, 'The cap refused an upload below its own limit.' );

			$stored = Plugin::instance()->container()->get( Creative_Repository::class )->storage_details( (int) $made['id'] );

			if ( is_array( $stored ) ) {
				$this->stored[] = $stored['path'];
			}
		}

		$over = $this->manager->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->image_file( 728, 90 ),
			'https://example.com/over',
			'One too many'
		);

		$this->assertWPError( $over );
		$this->assertSame( 'aggr_creative_limit_reached', $over->get_error_code() );

		// And nothing was stored for the refused one.
		$this->assertCount(
			Creative_Manager::MAX_CREATIVES_PER_PLACEMENT,
			Plugin::instance()->container()->get( Creative_Repository::class )->for_campaign( $this->campaign_id )
		);
	}

	/**
	 * A posted object id cannot upload into another organization's campaign.
	 *
	 * @return void
	 */
	public function test_another_tenant_cannot_upload(): void {
		wp_set_current_user( $this->stranger );

		$result = $this->manager->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->image_file( 728, 90 ),
			'https://example.com/',
			'Gallery exhibition'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_forbidden', $result->get_error_code() );
	}

	/**
	 * Removal deletes both the private bytes and the creative record.
	 *
	 * @return void
	 */
	public function test_remove_deletes_private_file_and_record(): void {
		wp_set_current_user( $this->owner );
		$uploaded = $this->manager->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->image_file( 728, 90 ),
			'https://example.com/',
			'Gallery exhibition'
		);
		$this->assertIsArray( $uploaded );

		$creative_id = (int) $uploaded['id'];
		$stored      = Plugin::instance()->container()->get( Creative_Repository::class )->storage_details( $creative_id );
		$this->assertIsArray( $stored );
		$relative = $stored['path'];

		$this->assertTrue( $this->manager->remove( $creative_id ) );
		$this->assertNull( $this->storage->resolve( $relative ) );
		$this->assertNull( get_post( $creative_id ) );

		$events = ( new Audit_Repository() )->for_object( 'campaign', $this->campaign_id, $this->org_id );
		$this->assertSame( 'creative.removed', $events[0]['event'] );
	}

	/**
	 * A vetoed record deletion restores the private bytes to their original path.
	 *
	 * @return void
	 */
	public function test_remove_restores_private_file_when_record_deletion_fails(): void {
		wp_set_current_user( $this->owner );
		$uploaded = $this->manager->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->image_file( 728, 90 ),
			'https://example.com/',
			'Gallery exhibition'
		);
		$this->assertIsArray( $uploaded );

		$creative_id = (int) $uploaded['id'];
		$stored      = Plugin::instance()->container()->get( Creative_Repository::class )->storage_details( $creative_id );
		$this->assertIsArray( $stored );
		$this->stored[] = $stored['path'];

		$veto = static fn ( $delete, \WP_Post $post ) => $creative_id === (int) $post->ID ? false : $delete;
		add_filter( 'pre_delete_post', $veto, 10, 2 );

		try {
			$result = $this->manager->remove( $creative_id );
		} finally {
			remove_filter( 'pre_delete_post', $veto, 10 );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_creative_not_deleted', $result->get_error_code() );
		$this->assertNotNull( get_post( $creative_id ) );
		$this->assertNotNull( $this->storage->resolve( $stored['path'] ) );
	}

	/**
	 * Submitted creative cannot be removed through the draft workflow.
	 *
	 * @return void
	 */
	public function test_remove_refuses_a_locked_campaign(): void {
		wp_set_current_user( $this->owner );
		$uploaded = $this->manager->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->image_file( 728, 90 ),
			'https://example.com/',
			'Gallery exhibition'
		);
		$this->assertIsArray( $uploaded );

		$creative_id = (int) $uploaded['id'];
		$stored      = Plugin::instance()->container()->get( Creative_Repository::class )->storage_details( $creative_id );
		$this->assertIsArray( $stored );
		$this->stored[] = $stored['path'];

		wp_update_post(
			array(
				'ID'          => $this->campaign_id,
				'post_status' => Post_Statuses::SUBMITTED,
			)
		);

		$result = $this->manager->remove( $creative_id );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_campaign_not_editable', $result->get_error_code() );
		$this->assertNotNull( get_post( $creative_id ) );
		$this->assertNotNull( $this->storage->resolve( $stored['path'] ) );
	}

	/**
	 * A nonce for another action cannot authorize upload form delivery.
	 *
	 * @return void
	 */
	public function test_upload_handler_rejects_a_forged_nonce(): void {
		wp_set_current_user( $this->owner );
		$_POST = array(
			'campaign_id'  => (string) $this->campaign_id,
			'placement_id' => (string) $this->placement_id,
			'_wpnonce'     => wp_create_nonce( Creative_Actions::remove_nonce_action( $this->placement_id ) ),
		);

		$this->expectException( 'WPDieException' );
		$this->actions->handle_upload();
	}

	/**
	 * Makes a temporary PNG upload entry.
	 *
	 * @param int $width  Image width.
	 * @param int $height Image height.
	 * @return array<string, mixed>
	 */
	private function image_file( int $width, int $height ): array {
		$image = imagecreatetruecolor( $width, $height );
		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();
		$path  = wp_tempnam( 'aggr-creative-manager' );
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
	 * Current stored image paths, excluding server deny files.
	 *
	 * @return array<int, string>
	 */
	private function private_images(): array {
		$this->storage->ensure();
		$paths = glob( $this->storage->root() . '/*' );
		$paths = is_array( $paths ) ? $paths : array();
		$paths = array_values(
			array_filter(
				$paths,
				static fn ( string $path ): bool => 1 === preg_match( '/\.(?:jpe?g|png|gif|webp)$/i', $path )
			)
		);
		sort( $paths );

		return $paths;
	}
}
