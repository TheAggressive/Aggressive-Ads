<?php
/**
 * Campaign copy (renew / duplicate) against real WordPress.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Nonces;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Workflow\Campaign_Copier;
use Aggressive\Ads\Workflow\Campaign_Editor;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Copy is a new draft, not a transition backwards.
 */
final class CampaignCopyTest extends WP_UnitTestCase {

	/**
	 * Advertiser user id.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * Unrelated advertiser user id.
	 *
	 * @var int
	 */
	private int $other_advertiser;

	/**
	 * Owning organization id.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * Active placement id.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Package id.
	 *
	 * @var int
	 */
	private int $package_id;

	/**
	 * Copy workflow.
	 *
	 * @var Campaign_Copier
	 */
	private Campaign_Copier $copier;

	/**
	 * Draft workflow.
	 *
	 * @var Campaign_Editor
	 */
	private Campaign_Editor $editor;

	/**
	 * Form delivery.
	 *
	 * @var Campaign_Actions
	 */
	private Campaign_Actions $actions;

	/**
	 * Campaign persistence.
	 *
	 * @var Campaign_Repository
	 */
	private Campaign_Repository $campaigns;

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
	 * Two tenants, one usable package, and the copy workflow.
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->advertiser       = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->other_advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->org_id           = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Copy Org',
			)
		);

		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->advertiser );

		$other_org = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $other_org, Org_Repository::META_OWNER_USER, $this->other_advertiser );

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage Leaderboard',
			)
		);
		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );

		$this->package_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PACKAGE,
				'post_status' => 'publish',
				'post_title'  => 'Launch package',
			)
		);
		add_post_meta( $this->package_id, Package_Repository::META_PLACEMENT_ID, $this->placement_id );
		update_post_meta( $this->package_id, Package_Repository::META_DURATION_DAYS, 30 );
		update_post_meta( $this->package_id, Package_Repository::META_PRICE_CENTS, 45000 );
		update_post_meta( $this->package_id, Package_Repository::META_CURRENCY, 'USD' );
		update_post_meta( $this->package_id, Package_Repository::META_IS_ACTIVE, 1 );

		$container       = Plugin::instance()->container();
		$this->editor    = $container->get( Campaign_Editor::class );
		$this->copier    = $container->get( Campaign_Copier::class );
		$this->actions   = $container->get( Campaign_Actions::class );
		$this->campaigns = $container->get( Campaign_Repository::class );
		$this->creatives = $container->get( Creative_Repository::class );
		$this->storage   = $container->get( Private_Storage::class );

		$container->get( Org_Repository::class )->flush_cache();
		$container->get( Ownership::class )->flush_cache();
	}

	/**
	 * Removes fixtures.
	 */
	public function tear_down(): void {
		foreach ( $this->temporary as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}

		$this->temporary = array();
		$_GET            = array();
		$_POST           = array();

		parent::tear_down();
	}

	/**
	 * Renewing a completed campaign is a new draft with a snapshot, not a reopen.
	 *
	 * @return void
	 */
	public function test_renewing_a_completed_campaign_creates_a_draft_without_reopening(): void {
		wp_set_current_user( $this->advertiser );

		$source_id = $this->completed_campaign();
		$source    = $this->creatives->for_campaign( $source_id );
		$this->assertCount( 1, $source );

		$source_path = (string) get_post_meta( $source[0]['id'], Creative_Repository::META_PRIVATE_PATH, true );
		$source_hash = (string) get_post_meta( $source[0]['id'], Creative_Repository::META_SHA256, true );

		$result = $this->copier->copy( $source_id );

		$this->assertIsInt( $result );
		$this->assertNotSame( $source_id, $result );
		$this->assertSame( Post_Statuses::COMPLETE, $this->campaigns->status( $source_id ) );
		$this->assertSame( Post_Statuses::DRAFT, $this->campaigns->status( $result ) );
		$this->assertSame( $this->org_id, $this->campaigns->org_id( $result ) );
		$this->assertSame( $this->package_id, $this->campaigns->package_id( $result ) );
		$this->assertSame( array( $this->placement_id ), $this->campaigns->placement_ids( $result ) );
		$this->assertSame( 45000, $this->campaigns->budget_cents( $result ) );
		$this->assertSame( 'USD', $this->campaigns->currency( $result ) );
		$this->assertSame( 0, $this->campaigns->start_ts( $result ) );
		$this->assertSame( 0, $this->campaigns->end_ts( $result ) );
		$this->assertSame( array(), $this->campaigns->provider_ad_ids( $result ) );
		$this->assertSame( '', $this->campaigns->internal_notes( $result ) );
		$this->assertSame( '', $this->campaigns->review_notes( $result ) );
		$this->assertSame( 'destination', $this->campaigns->wizard_step( $result ) );
		$this->assertStringEndsWith( ' (renewal)', $this->campaigns->title( $result ) );

		$copied = $this->creatives->for_campaign( $result );
		$this->assertCount( 1, $copied );
		$this->assertNotSame( $source[0]['id'], $copied[0]['id'] );
		$this->assertSame( $source[0]['click_url'], $copied[0]['click_url'] );
		$this->assertSame( $this->placement_id, $copied[0]['placement_id'] );

		$copied_path = (string) get_post_meta( $copied[0]['id'], Creative_Repository::META_PRIVATE_PATH, true );
		$this->assertNotSame( $source_path, $copied_path );
		$this->assertNotNull( $this->storage->resolve( $source_path ) );
		$this->assertNotNull( $this->storage->resolve( $copied_path ) );
		$this->assertSame( $source_hash, (string) get_post_meta( $copied[0]['id'], Creative_Repository::META_SHA256, true ) );
		$this->assertSame( 0, (int) get_post_meta( $copied[0]['id'], Creative_Repository::META_PROVIDER_AD, true ) );
		$this->assertSame( 0, (int) get_post_meta( $copied[0]['id'], Creative_Repository::META_ATTACHMENT_ID, true ) );

		$events = ( new Audit_Repository() )->for_object( 'campaign', $result, $this->org_id );
		$names  = array_column( $events, 'event' );
		$this->assertContains( 'campaign.copied', $names );
	}

	/**
	 * Duplicating a draft uses the copy suffix and does not require editability of a terminal row.
	 *
	 * @return void
	 */
	public function test_duplicating_a_draft_uses_the_copy_suffix(): void {
		wp_set_current_user( $this->advertiser );

		$source_id = $this->editor->create( 'Spring flight' );
		$this->assertIsInt( $source_id );

		$result = $this->copier->copy( $source_id );

		$this->assertIsInt( $result );
		$this->assertSame( 'Spring flight (copy)', $this->campaigns->title( $result ) );
		$this->assertSame( Post_Statuses::DRAFT, $this->campaigns->status( $source_id ) );
		$this->assertSame( 'details', $this->campaigns->wizard_step( $result ) );
	}

	/**
	 * Another organization cannot copy this campaign.
	 *
	 * @return void
	 */
	public function test_a_stranger_cannot_copy_another_org_campaign(): void {
		wp_set_current_user( $this->advertiser );
		$source_id = $this->completed_campaign();

		wp_set_current_user( $this->other_advertiser );
		$result = $this->copier->copy( $source_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_forbidden', $result->get_error_code() );
		$this->assertSame( Post_Statuses::COMPLETE, $this->campaigns->status( $source_id ) );
	}

	/**
	 * REST copy returns the new draft and refuses a foreign campaign.
	 *
	 * @return void
	 */
	public function test_rest_copy_is_org_scoped(): void {
		wp_set_current_user( $this->advertiser );
		$source_id = $this->completed_campaign();

		do_action( 'rest_api_init', rest_get_server() );

		$request = new WP_REST_Request( 'POST', '/aggr/v1/campaigns/' . $source_id . '/copy' );
		$request->set_body_params( array( 'org_id' => PHP_INT_MAX ) );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertNotSame( $source_id, (int) $data['id'] );
		$this->assertSame( Post_Statuses::DRAFT, $data['status'] );
		$this->assertSame( $this->org_id, $this->campaigns->org_id( (int) $data['id'] ) );
		$this->assertTrue( $data['can_copy'] );

		wp_set_current_user( $this->other_advertiser );
		$denied = rest_get_server()->dispatch(
			new WP_REST_Request( 'POST', '/aggr/v1/campaigns/' . $source_id . '/copy' )
		);

		$this->assertSame( 403, $denied->get_status() );
	}

	/**
	 * The HTML handler requires the campaign-bound nonce.
	 *
	 * @return void
	 */
	public function test_copy_handler_rejects_a_missing_nonce(): void {
		wp_set_current_user( $this->advertiser );
		$source_id = $this->completed_campaign();
		$_POST     = array( 'campaign_id' => (string) $source_id );

		$this->expectException( 'WPDieException' );
		$this->actions->handle_copy();
	}

	/**
	 * A nonce for another campaign cannot authorize this copy.
	 *
	 * @return void
	 */
	public function test_copy_handler_rejects_a_forged_nonce(): void {
		wp_set_current_user( $this->advertiser );
		$source_id = $this->completed_campaign();
		$_POST     = array(
			'campaign_id' => (string) $source_id,
			'_wpnonce'    => wp_create_nonce( Campaign_Nonces::copy_nonce_action( $source_id + 1 ) ),
		);

		$this->expectException( 'WPDieException' );
		$this->actions->handle_copy();
	}

	/**
	 * A completed campaign with snapshot, artwork, and fields that must not copy.
	 */
	private function completed_campaign(): int {
		$campaign_id = $this->editor->create( 'Finished flight' );
		$this->assertIsInt( $campaign_id );

		$saved = $this->campaigns->update_draft(
			$campaign_id,
			array(
				'package_id'       => $this->package_id,
				'placement_ids'    => array( $this->placement_id ),
				'budget_cents'     => 45000,
				'currency'         => 'USD',
				'advertiser_notes' => 'Please run this again next season.',
				'start_ts'         => time() - ( 40 * DAY_IN_SECONDS ),
				'end_ts'           => time() - DAY_IN_SECONDS,
			)
		);
		$this->assertTrue( $saved );

		$creative_id = $this->creatives->create(
			$campaign_id,
			$this->org_id,
			$this->placement_id,
			array(
				'kind'      => 'image',
				'click_url' => 'https://example.com/tickets',
				'alt_text'  => 'Season poster',
				'size'      => '728x90',
			)
		);
		$this->assertGreaterThan( 0, $creative_id );

		$image = imagecreatetruecolor( 728, 90 );
		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();
		$path  = wp_tempnam( 'aggr-copy' );
		$this->assertNotFalse( file_put_contents( $path, $bytes ) );
		$this->temporary[] = $path;

		$stored = $this->storage->store( $path, 'png' );
		$this->assertIsArray( $stored );

		$this->creatives->record_upload(
			$creative_id,
			array(
				'path'   => $stored['path'],
				'token'  => $stored['token'],
				'sha256' => $stored['sha256'],
				'bytes'  => $stored['bytes'],
				'mime'   => 'image/png',
				'width'  => 728,
				'height' => 90,
				'name'   => 'poster.png',
			)
		);

		$this->campaigns->add_provider_ad_id( $campaign_id, 999 );
		update_post_meta( $campaign_id, Campaign_Repository::META_INTERNAL_NOTES, 'Staff only.' );
		update_post_meta( $campaign_id, Campaign_Repository::META_REVIEW_NOTES, 'Looks good.' );
		update_post_meta( $creative_id, Creative_Repository::META_PROVIDER_AD, 999 );
		update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, 42 );

		wp_update_post(
			array(
				'ID'          => $campaign_id,
				'post_status' => Post_Statuses::COMPLETE,
			)
		);

		return $campaign_id;
	}
}
