<?php
/**
 * Published creative replacement workflow.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Workflow\Creative_Change_Manager;
use Aggressive\Ads\Workflow\Creative_Manager;
use WP_UnitTestCase;

/**
 * Tenant-safe staging, review, provider reconciliation, and rollback metadata.
 */
final class CreativeChangeManagerTest extends WP_UnitTestCase {

	/**
	 * Owning advertiser id.
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * Unrelated advertiser id.
	 *
	 * @var int
	 */
	private int $stranger;

	/**
	 * Staff reviewer id.
	 *
	 * @var int
	 */
	private int $reviewer;

	/**
	 * Owning organization id.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * Published campaign id.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * Mapped placement id.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Current creative id.
	 *
	 * @var int
	 */
	private int $creative_id;

	/**
	 * Replacement workflow.
	 *
	 * @var Creative_Change_Manager
	 */
	private Creative_Change_Manager $changes;

	/**
	 * Creative persistence.
	 *
	 * @var Creative_Repository
	 */
	private Creative_Repository $creatives;

	/**
	 * Campaign persistence.
	 *
	 * @var Campaign_Repository
	 */
	private Campaign_Repository $campaigns;

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
	 * Private stored files.
	 *
	 * @var array<int, string>
	 */
	private array $stored = array();

	/**
	 * Creates one genuinely published campaign.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->owner    = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->stranger = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->reviewer = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );
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
				'post_title'  => 'Running campaign',
			)
		);
		update_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, $this->org_id );
		update_post_meta( $this->campaign_id, Campaign_Repository::META_START_TS, time() - DAY_IN_SECONDS );
		update_post_meta( $this->campaign_id, Campaign_Repository::META_END_TS, time() + WEEK_IN_SECONDS );
		add_post_meta( $this->campaign_id, Campaign_Repository::META_PLACEMENT_ID, $this->placement_id );

		$container       = Plugin::instance()->container();
		$this->changes   = $container->get( Creative_Change_Manager::class );
		$this->creatives = $container->get( Creative_Repository::class );
		$this->campaigns = $container->get( Campaign_Repository::class );
		$this->storage   = $container->get( Private_Storage::class );

		$container->get( Ownership::class )->flush_cache();
		wp_set_current_user( $this->owner );

		$uploaded = $container->get( Creative_Manager::class )->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->image_file(),
			'https://example.com/current',
			'Current exhibition advertisement'
		);
		$this->assertIsArray( $uploaded );
		$this->creative_id = (int) $uploaded['id'];
		$this->remember_storage( $this->creative_id );

		wp_update_post(
			array(
				'ID'          => $this->campaign_id,
				'post_status' => Post_Statuses::LIVE,
			)
		);
	}

	/**
	 * Removes filesystem fixtures.
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

		parent::tear_down();
	}

	/**
	 * Requesting leaves the current creative untouched until staff approval,
	 * then makes the reviewed revision current.
	 *
	 * @return void
	 */
	public function test_request_and_approval_replace_without_downtime_or_duplicate(): void {
		wp_set_current_user( $this->owner );

		$request = $this->changes->request(
			$this->creative_id,
			$this->image_file(),
			'https://example.com/replacement',
			''
		);

		$this->assertIsArray( $request );
		$replacement_id = (int) $request['id'];
		$this->remember_storage( $replacement_id );
		$replacement = $this->creatives->details( $replacement_id );
		$current     = $this->creatives->details( $this->creative_id );
		$this->assertIsArray( $replacement );
		$this->assertIsArray( $current );
		$this->assertSame( 'Advertisement linking to example.com', $replacement['alt_text'] );
		$this->assertSame( 'https://example.com/current', $current['click_url'] );
		$this->assertSame( array( $this->creative_id ), array_column( $this->creatives->for_campaign( $this->campaign_id ), 'id' ) );
		$this->assertSame( 1, $this->campaigns->pending_update_count( $this->campaign_id ) );

		wp_set_current_user( $this->reviewer );
		$this->assertTrue( $this->changes->approve( $replacement_id ) );

		$applied = $this->creatives->details( $replacement_id );
		$this->assertIsArray( $applied );
		$this->assertSame( 'https://example.com/replacement', $applied['click_url'] );
		$this->assertSame( array( $replacement_id ), array_column( $this->creatives->for_campaign( $this->campaign_id ), 'id' ) );
		$this->assertSame( 0, $this->creatives->provider_ad_id( $this->creative_id ) );
		$this->assertSame( 0, $this->campaigns->pending_update_count( $this->campaign_id ) );
	}

	/**
	 * Rejection requires useful feedback and never alters the current ad.
	 *
	 * @return void
	 */
	public function test_rejection_preserves_current_ad_and_exposes_feedback(): void {
		wp_set_current_user( $this->owner );
		$request = $this->changes->request( $this->creative_id, $this->image_file(), 'https://example.com/proposed', 'Proposed advertisement' );
		$this->assertIsArray( $request );
		$replacement_id = (int) $request['id'];
		$this->remember_storage( $replacement_id );

		wp_set_current_user( $this->reviewer );
		$missing = $this->changes->reject( $replacement_id, '   ' );
		$this->assertWPError( $missing );
		$this->assertSame( 'aggr_replacement_notes_required', $missing->get_error_code() );
		$this->assertTrue( $this->changes->reject( $replacement_id, 'Use the approved exhibition branding.' ) );

		$this->assertSame( Creative_Repository::CHANGE_REJECTED, $this->creatives->change_state( $replacement_id ) );
		$this->assertSame( 'Use the approved exhibition branding.', $this->creatives->change_notes( $replacement_id ) );
		$current = $this->creatives->details( $this->creative_id );
		$this->assertIsArray( $current );
		$this->assertSame( 'https://example.com/current', $current['click_url'] );
		$this->assertSame( 0, $this->campaigns->pending_update_count( $this->campaign_id ) );
	}

	/**
	 * Object ids cannot cross tenant boundaries, and one current ad cannot have
	 * two ambiguous pending revisions.
	 *
	 * @return void
	 */
	public function test_tenant_boundary_and_single_pending_revision_are_enforced(): void {
		wp_set_current_user( $this->stranger );
		$foreign = $this->changes->request( $this->creative_id, $this->image_file(), 'https://example.com/attack', 'Foreign update' );
		$this->assertWPError( $foreign );
		$this->assertSame( 'aggr_forbidden', $foreign->get_error_code() );

		wp_set_current_user( $this->owner );
		$first = $this->changes->request( $this->creative_id, $this->image_file(), 'https://example.com/first', 'First update' );
		$this->assertIsArray( $first );
		$this->remember_storage( (int) $first['id'] );

		$second = $this->changes->request( $this->creative_id, $this->image_file(), 'https://example.com/second', 'Second update' );
		$this->assertWPError( $second );
		$this->assertSame( 'aggr_replacement_pending', $second->get_error_code() );
	}

	/**
	 * The advertiser may withdraw a pending revision without touching delivery.
	 *
	 * @return void
	 */
	public function test_owner_can_withdraw_a_pending_revision(): void {
		wp_set_current_user( $this->owner );
		$request = $this->changes->request( $this->creative_id, $this->image_file(), 'https://example.com/withdraw', 'Withdrawn update' );
		$this->assertIsArray( $request );
		$replacement_id = (int) $request['id'];
		$stored         = $this->creatives->storage_details( $replacement_id );
		$this->assertIsArray( $stored );

		$this->assertTrue( $this->changes->withdraw( $replacement_id ) );
		$this->assertNull( get_post( $replacement_id ) );
		$this->assertNull( $this->storage->resolve( $stored['path'] ) );
		$current = $this->creatives->details( $this->creative_id );
		$this->assertIsArray( $current );
		$this->assertSame( 'https://example.com/current', $current['click_url'] );
		$this->assertSame( 0, $this->campaigns->pending_update_count( $this->campaign_id ) );
	}

	/**
	 * Makes one temporary PNG upload entry.
	 *
	 * @return array<string, mixed>
	 */
	private function image_file(): array {
		$image = imagecreatetruecolor( 728, 90 );
		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();
		$path  = wp_tempnam( 'aggr-creative-change' );
		file_put_contents( $path, $bytes );
		$this->temporary[] = $path;

		return array(
			'name'     => 'replacement.png',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $bytes ),
		);
	}

	/**
	 * Remembers private storage for cleanup.
	 *
	 * @param int $creative_id Creative id.
	 * @return void
	 */
	private function remember_storage( int $creative_id ): void {
		$stored = $this->creatives->storage_details( $creative_id );

		if ( null !== $stored && '' !== $stored['path'] ) {
			$this->stored[] = $stored['path'];
		}
	}
}
