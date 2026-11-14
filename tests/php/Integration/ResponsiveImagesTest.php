<?php
/**
 * Actual upload, publication, revision and delivery of one responsive image set.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Image_Set;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Image_Resource_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Workflow\Creative_Manager;
use Aggressive\Ads\Workflow\Creative_Promoter;
use WP_UnitTestCase;

final class ResponsiveImagesTest extends WP_UnitTestCase {

	use CreativeFixtures;

	/**
	 * Tenant owner.
	 *
	 * @var int
	 */
	private int $owner;
	/**
	 * Editable campaign.
	 *
	 * @var int
	 */
	private int $campaign_id;
	/**
	 * Responsive placement.
	 *
	 * @var int
	 */
	private int $placement_id;
	/**
	 * Authorized upload workflow.
	 *
	 * @var Creative_Manager
	 */
	private Creative_Manager $manager;
	/**
	 * Published attachment cleanup.
	 *
	 * @var array<int,int>
	 */
	private array $published = array();

	public function set_up(): void {
		parent::set_up();
		$this->assertTrue( ( new \Aggressive\Ads\Repository\Image_Projection_Repository() )->available() );
		$this->owner        = (int) self::factory()->user->create( array( 'role' => 'aggr_advertiser' ) );
		$this->placement_id = $this->placement( 'Responsive test banner', '728x90' );
		$this->assertTrue(
			( new Placement_Repository() )->set_size_map(
				$this->placement_id,
				array(
					0   => '320x50',
					768 => '728x90',
				)
			)
		);
		$this->campaign_id = $this->campaign( $this->owner, $this->org( $this->owner ), array( $this->placement_id ) );
		wp_set_current_user( $this->owner );
		$this->manager = Plugin::instance()->container()->get( Creative_Manager::class );
	}

	public function tear_down(): void {
		foreach ( $this->published as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->remove_creative_files( $this->campaign_id );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Every published member keeps its reviewed bytes; a receipt is only exposed after all succeed. */
	public function test_publication_binds_all_real_attachments_and_is_idempotent(): void {
		$file               = $this->image_file( 728, 90, 1 );
		$file['renditions'] = array( $this->image_file( 320, 50, 2 ) );
		$result             = $this->manager->upload( $this->campaign_id, $this->placement_id, $file, 'https://example.com/destination', 'Responsive ad' );
		$this->assertIsArray( $result );
		$records  = new Image_Resource_Repository();
		$before   = $records->source( $result['id'] );
		$promoter = Plugin::instance()->container()->get( Creative_Promoter::class );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$base = $promoter->promote( $result['id'] );
		$this->assertIsInt( $base );
		$receipt = $records->publication( $result['id'] );
		$this->assertIsArray( $receipt );
		$this->published = array_column( $receipt['resources'], 'attachment_id' );
		$this->assertCount( 2, $this->published );
		$this->assertSame( $base, $receipt['resources']['728x90']['attachment_id'] );
		$this->assertTrue( $records->ready( $result['id'] ) );
		foreach ( $receipt['resources'] as $size => $resource ) {
			$this->assertSame( $before['manifest']['resources'][ $size ]['sha256'], hash_file( 'sha256', get_attached_file( $resource['attachment_id'] ) ) );
		}
		$this->assertSame( '', $records->source( $result['id'] )['upload']['path'] );
		$this->assertNull( Plugin::instance()->container()->get( Private_Storage::class )->resolve( $before['upload']['path'] ) );
		$this->assertSame( $base, $promoter->promote( $result['id'] ) );
		$this->assertSame( $receipt, $records->publication( $result['id'] ) );
	}

	/** Source tampering cannot publish even the undamaged base rendition. */
	public function test_a_modified_mobile_member_refuses_the_whole_publication(): void {
		$file               = $this->image_file( 728, 90, 1 );
		$file['renditions'] = array( $this->image_file( 320, 50, 2 ) );
		$result             = $this->manager->upload( $this->campaign_id, $this->placement_id, $file, 'https://example.com/destination', 'Responsive ad' );
		$this->assertIsArray( $result );
		$records = new Image_Resource_Repository();
		$source  = $records->source( $result['id'] );
		$storage = Plugin::instance()->container()->get( Private_Storage::class );
		$bytes   = $storage->read( $source['upload']['path'], Image_Set::MAX_ENCODED_BYTES );
		$this->assertIsString( $bytes );
		$value                     = json_decode( $bytes, true );
		$value['images']['320x50'] = base64_encode( 'tampered mobile resource' );
		$changed                   = json_encode( $value, JSON_UNESCAPED_SLASHES );
		file_put_contents( $storage->resolve( $source['upload']['path'] ), $changed );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertWPError( Plugin::instance()->container()->get( Creative_Promoter::class )->promote( $result['id'] ) );
		$this->assertNull( $records->publication( $result['id'] ) );
		$this->assertSame( 0, (int) get_post_meta( $result['id'], Creative_Repository::META_ATTACHMENT_ID, true ) );
	}

	/** One workflow upload produces one private artifact and a server-derived manifest. */
	public function test_upload_preserves_all_renditions_in_one_encrypted_source(): void {
		$file               = $this->image_file( 728, 90, 1 );
		$file['renditions'] = array( $this->image_file( 320, 50, 2 ) );
		$result             = $this->manager->upload( $this->campaign_id, $this->placement_id, $file, 'https://example.com/destination', 'Responsive ad' );
		$this->assertIsArray( $result );
		$source = ( new Image_Resource_Repository() )->source( $result['id'] );
		$this->assertIsArray( $source );
		$this->assertSame( array( '320x50', '728x90' ), array_keys( $source['manifest']['resources'] ) );
		$this->assertSame( Image_Set::MIME, $source['upload']['mime'] );
		$storage  = Plugin::instance()->container()->get( Private_Storage::class );
		$artifact = $storage->read( $source['upload']['path'], Image_Set::MAX_ENCODED_BYTES );
		$this->assertIsString( $artifact );
		$images = Image_Set::unpack( $artifact, $source['manifest'] );
		$this->assertIsArray( $images );
		$this->assertSame( hash_file( 'sha256', $file['tmp_name'] ), hash( 'sha256', $images['728x90'] ) );
		$this->assertSame( hash_file( 'sha256', $file['renditions'][0]['tmp_name'] ), hash( 'sha256', $images['320x50'] ) );
		$this->assertNull( ( new Image_Resource_Repository() )->publication( $result['id'] ) );
		$this->assertSame( $source['upload']['path'], ( new Creative_Repository() )->storage_details( $result['id'] )['path'] );
	}

	/** No partial accepted source remains after a duplicate size is refused. */
	public function test_duplicate_and_out_of_map_sizes_are_refused_without_a_creative(): void {
		foreach ( array( array( 728, 90 ), array( 300, 250 ) ) as $size ) {
			$file               = $this->image_file( 728, 90, 1 );
			$file['renditions'] = array( $this->image_file( $size[0], $size[1], 2 ) );
			$this->assertWPError( $this->manager->upload( $this->campaign_id, $this->placement_id, $file, 'https://example.com/destination', 'Responsive ad' ) );
		}
		$this->assertSame( array(), ( new Creative_Repository() )->ids_for_campaign( $this->campaign_id ) );
	}

	/** Real upload, staff approval, assignment projection, single/batch fill and signed identity meet. */
	public function test_reviewed_sizes_deliver_as_one_revision_and_refuse_changed_proof(): void {
		$settings = Plugin::instance()->container()->get( \Aggressive\Ads\Core\Settings::class );
		$document = $settings->get();
		$document['modules'][ \Aggressive\Ads\Domain\Settings_Schema::MODULE_NATIVE_DELIVERY ] = true;
		$this->assertTrue( $settings->save( $document ) );
		$id = $this->upload_set();
		$this->approve_on_running_campaign( $id );
		$records = new Image_Resource_Repository();
		$receipt = $records->publication( $id );
		$this->assertTrue( ( new Creative_Repository() )->is_approved( $id ) );
		$fill   = Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Fill_Service::class );
		$slug   = get_post_field( 'post_name', $this->placement_id );
		$tokens = new \Aggressive\Ads\Workflow\Fill_Token();
		$rows   = ( new \Aggressive\Ads\Repository\Creative_Assignment_Repository() )->for_campaign( $this->campaign_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'live', $rows[0]['status'] );
		$this->assertSame( 1, (int) $rows[0]['image_version'] );
		$this->assertSame( Image_Set::fingerprint( $records->source( $id )['manifest'] ), $rows[0]['image_hash'] );
		$this->assertIsArray( $records->delivery( $rows[0], '320x50' ) );
		wp_set_current_user( 0 );
		foreach ( array(
			320  => '320x50',
			1024 => '728x90',
		) as $width => $size ) {
			$single = $fill->for_slug( $slug, 0, $width );
			$this->assertIsArray( $single['creative'] );
			$this->assertSame( 'image', $single['creative']['format'] );
			$this->assertSame( 1, $single['creative']['formatVersion'] );
			$this->assertSame( (int) explode( 'x', $size )[0], $single['creative']['width'] );
			$this->assertStringEndsWith( $receipt['resources'][ $size ]['file'], $single['creative']['image'] );
			$identity = $tokens->parse( $single['creative']['token'] );
			$this->assertSame( $id, $identity['creative_id'] );
			$this->assertTrue( $fill->accepts( $identity ) );
			$batch = $fill->for_slots( array( $slug ), $width );
			$this->assertSame( $single['creative']['image'], $batch[ $slug ]['creative']['image'] );
		}
		update_post_meta( $id, Creative_Repository::META_ALT_TEXT, 'Unreviewed change' );
		$this->assertFalse( ( new Creative_Repository() )->is_approved( $id ) );
		$this->assertFalse( $fill->accepts( $identity ) );
		$this->assertNull( $fill->for_slug( $slug, 0, 320 )['creative'] );
		$this->assertNull( $fill->for_slots( array( $slug ), 320 )[ $slug ]['creative'] );
	}

	/** Text revisions preserve all resource identities and require a new review proof. */
	public function test_text_revision_carries_the_set_and_cannot_serve_before_reapproval(): void {
		$id = $this->upload_set();
		$this->approve_on_running_campaign( $id );
		$records   = new Image_Resource_Repository();
		$revisions = Plugin::instance()->container()->get( \Aggressive\Ads\Repository\Creative_Revision_Repository::class );
		$next      = $revisions->create_pending_text_revision( $id, 'https://example.com/next', 'Next description' );
		$this->assertGreaterThan( 0, $next );
		$this->assertSame( $records->source( $id )['manifest'], $records->source( $next )['manifest'] );
		$this->assertSame( $records->publication( $id )['resources'], $records->publication( $next )['resources'] );
		$this->assertFalse( $records->ready( $next ) );
		$this->assertTrue( $revisions->is_text_only_revision( $next ) );
		wp_set_current_user( $this->owner );
		$this->assertWPError( Plugin::instance()->container()->get( Creative_Promoter::class )->promote( $next ) );
		$this->assertFalse( $records->ready( $next ) );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertIsInt( Plugin::instance()->container()->get( Creative_Promoter::class )->promote( $next ) );
		$this->assertTrue( $records->ready( $next ) );
		$this->assertTrue( $records->ready( $id ) );
	}

	/** Copies reconstruct every reviewed byte after promotion has purged the private original. */
	public function test_copy_revalidates_every_member_against_the_target_placement(): void {
		$id = $this->upload_set();
		$this->approve_on_running_campaign( $id );
		$other = $this->placement( 'Another responsive banner', '728x90' );
		add_post_meta( $this->campaign_id, \Aggressive\Ads\Repository\Campaign_Repository::META_PLACEMENT_ID, $other );
		wp_set_current_user( $this->owner );
		$copies = Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Creative_Copies::class );
		$this->assertWPError( $copies->copy_to_placement( $id, $other ) );
		$this->assertTrue(
			( new Placement_Repository() )->set_size_map(
				$other,
				array(
					0   => '320x50',
					768 => '728x90',
				)
			)
		);
		$copy = $copies->copy_to_placement( $id, $other );
		$this->assertIsArray( $copy );
		$records = new Image_Resource_Repository();
		$this->assertSame( $records->source( $id )['manifest'], $records->source( $copy['id'] )['manifest'] );
		$this->assertNull( $records->publication( $copy['id'] ) );
	}

	/** Publication and edits share a nonreentrant lock, and publication makes the source immutable. */
	public function test_source_lock_and_frozen_receipt_refuse_overlapping_writes(): void {
		$id      = $this->upload_set();
		$records = new Image_Resource_Repository();
		$before  = $records->source( $id );
		$lock    = $records->claim( $id );
		try {
			$this->assertNotSame( '', $lock );
			$this->assertFalse( ( new Creative_Repository() )->set_text( $id, 'https://example.com/race', 'Changed' ) );
			$this->assertFalse( $records->record_upload( $id, $before['upload'] ) );
			$this->assertWPError( Plugin::instance()->container()->get( Creative_Promoter::class )->promote( $id ) );
		} finally {
			$records->release( $lock );
		}
		$this->assertSame( $before, $records->source( $id ) );
		$this->approve_on_running_campaign( $id );
		$this->assertFalse( ( new Creative_Repository() )->set_text( $id, 'https://example.com/race', 'Changed' ) );
		$this->assertFalse( $records->record_upload( $id, $before['upload'] ) );
	}

	/** Only the owner or staff may extract any rendition; previews never expose the source container. */
	public function test_preview_extracts_rasters_and_hides_foreign_or_missing_sizes(): void {
		$id         = $this->upload_set();
		$controller = Plugin::instance()->container()->get( \Aggressive\Ads\REST\Creative_File_Controller::class );
		$mobile     = $controller->prepare( $id, '320x50' );
		$this->assertIsArray( $mobile );
		$this->assertSame( 'image/png', $mobile['mime'] );
		$this->assertSame( array( 320, 50 ), array_slice( getimagesizefromstring( $mobile['content'] ), 0, 2 ) );
		$this->assertWPError( $controller->prepare( $id, '300x250' ) );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'aggr_advertiser' ) ) );
		$this->assertWPError( $controller->prepare( $id, '320x50' ) );
	}

	/** Upload through the shared authorization, inspection and persistence path. */
	private function upload_set(): int {
		$file               = $this->image_file( 728, 90, 1 );
		$file['renditions'] = array( $this->image_file( 320, 50, 2 ) );
		$result             = $this->manager->upload( $this->campaign_id, $this->placement_id, $file, 'https://example.com/destination', 'Responsive ad' );
		$this->assertIsArray( $result );
		return $result['id'];
	}

	/** Another upload finishing during inspection must win over an earlier stale source snapshot. */
	public function test_overlapping_replacement_refuses_the_stale_writer(): void {
		$id                  = $this->upload_set();
		$outer               = $this->image_file( 728, 90, 7 );
		$outer['name']       = 'overlapping-base.png';
		$outer['renditions'] = array( $this->image_file( 320, 50, 8 ) );
		$other               = $this->image_file( 728, 90, 3 );
		$other['renditions'] = array( $this->image_file( 320, 50, 4 ) );
		$changed             = false;
		$winner              = null;
		$records             = new Image_Resource_Repository();
		$storage             = Plugin::instance()->container()->get( Private_Storage::class );
		$interleave          = function ( array $checked, string $path, string $filename ) use ( $id, $other, &$changed, &$winner, $records ): array {
			if ( 'overlapping-base.png' === $filename && ! $changed ) {
				$changed = true;
				$this->assertIsArray( $this->manager->replace_artwork( $id, $other ) );
				$winner = $records->source( $id );
			}
			return $checked;
		};
		add_filter( 'wp_check_filetype_and_ext', $interleave, 10, 3 );
		try {
			$this->assertWPError( $this->manager->replace_artwork( $id, $outer ) );
			$this->assertTrue( $changed );
			$this->assertIsArray( $winner );
			$this->assertSame( $winner, $records->source( $id ) );
			$this->assertNotNull( $storage->resolve( $winner['upload']['path'] ) );
		} finally {
			remove_filter( 'wp_check_filetype_and_ext', $interleave );
			if ( is_array( $winner ) ) {
				$storage->delete( $winner['upload']['path'] );
			}
		}
	}

	/** An image set cannot broaden ownership or turn upload authority into publication authority. */
	public function test_image_sets_preserve_tenant_and_publication_capability_guards(): void {
		$file               = $this->image_file( 728, 90, 1 );
		$file['renditions'] = array( $this->image_file( 320, 50, 2 ) );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'aggr_advertiser' ) ) );
		$this->assertWPError( $this->manager->upload( $this->campaign_id, $this->placement_id, $file, 'https://example.com/denied', 'Foreign set' ) );
		$this->assertSame( array(), ( new Creative_Repository() )->ids_for_campaign( $this->campaign_id ) );
		wp_set_current_user( $this->owner );
		$id = $this->upload_set();
		$this->assertWPError( Plugin::instance()->container()->get( Creative_Promoter::class )->promote( $id ) );
		$this->assertFalse( ( new Image_Resource_Repository() )->ready( $id ) );
		$this->assertNull( ( new Image_Resource_Repository() )->publication( $id ) );
	}

	/** A map changed while artwork waits cannot leave stale input marked ready or publishable. */
	public function test_placement_policy_is_revalidated_at_review(): void {
		$id = $this->upload_set();
		$this->assertTrue( ( new Placement_Repository() )->set_size_map( $this->placement_id, array( 0 => '728x90' ) ) );
		$container = Plugin::instance()->container();
		$coverage  = $container->get( \Aggressive\Ads\Workflow\Coverage_Service::class )->assess( $this->campaign_id );
		$this->assertSame( \Aggressive\Ads\Workflow\Coverage_Service::STATE_INVALID_IMAGE_SET, $coverage[0]['state'] );
		$problems = $container->get( \Aggressive\Ads\Workflow\Campaign_Validator::class )->validate( $this->campaign_id )->problems();
		$this->assertContains( \Aggressive\Ads\Domain\Campaign_Rules::ERROR_IMAGE_SET, array_column( $problems, 'code' ) );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertWPError( $container->get( Creative_Promoter::class )->promote( $id ) );
		$this->assertNull( ( new Image_Resource_Repository() )->publication( $id ) );
		$this->assertTrue(
			( new Placement_Repository() )->set_size_map(
				$this->placement_id,
				array(
					0   => '320x50',
					768 => '728x90',
				)
			)
		);
		$this->assertIsInt( $container->get( Creative_Promoter::class )->promote( $id ) );
		$this->published = array_column( ( new Image_Resource_Repository() )->publication( $id )['resources'], 'attachment_id' );
	}

	/** Real six-member manifests do not add per-candidate resource reads at the 500-row cap. */
	public function test_six_members_and_five_hundred_candidates_keep_the_delivery_budgets(): void {
		global $wpdb;
		$sizes = array( '320x50', '300x250', '160x600', '300x600', '970x90', '728x90' );
		$this->assertTrue( ( new Placement_Repository() )->set_size_map( $this->placement_id, array_combine( array( 0, 400, 500, 600, 700, 768 ), $sizes ) ) );
		$file               = $this->image_file( 728, 90, 1 );
		$file['renditions'] = array();
		foreach ( array_slice( $sizes, 0, 5 ) as $key => $size ) {
			$dimensions           = array_map( 'intval', explode( 'x', $size ) );
			$file['renditions'][] = $this->image_file( $dimensions[0], $dimensions[1], $key + 2 );
		}
		$result = $this->manager->upload( $this->campaign_id, $this->placement_id, $file, 'https://example.com/large-set', 'All sizes' );
		$this->assertIsArray( $result );
		$this->assertCount( 6, ( new Image_Resource_Repository() )->source( $result['id'] )['manifest']['resources'] );
		$too_many                 = $file;
		$too_many['renditions'][] = $this->image_file( 728, 90, 9 );
		$this->assertWPError( $this->manager->upload( $this->campaign_id, $this->placement_id, $too_many, 'https://example.com/large-set', 'Too many' ) );
		$this->approve_on_running_campaign( $result['id'] );
		$assignments = new \Aggressive\Ads\Repository\Creative_Assignment_Repository();
		$row         = $assignments->for_campaign( $this->campaign_id )[0];
		unset( $row['id'] );
		$row['compat_key'] = null;
		// Replicate valid delivery tuples only for load; upload, review and publication above use real writers.
		for ( $i = 0; $i < 500; ++$i ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Bounded load fixture preserving the actual reviewed revision/ownership tuple.
			$this->assertSame( 1, $wpdb->insert( $assignments->table_name(), $row ) );
		}
		$this->assertCount( 500, $assignments->candidates_for_placement( $this->placement_id, time(), 1000 ) );
		$settings = Plugin::instance()->container()->get( \Aggressive\Ads\Core\Settings::class );
		$document = $settings->get();
		$document['modules'][ \Aggressive\Ads\Domain\Settings_Schema::MODULE_NATIVE_DELIVERY ] = true;
		$this->assertTrue( $settings->save( $document ) );
		$fill = Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Fill_Service::class );
		Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Fill_Cache::class )->delete( $this->placement_id );
		$slug  = get_post_field( 'post_name', $this->placement_id );
		$start = $wpdb->num_queries;
		$cold  = $fill->for_slug( $slug, 0, 320 );
		$this->assertLessThanOrEqual( 12, $wpdb->num_queries - $start );
		$this->assertIsArray( $cold['creative'] );
		$this->assertSame( 320, $cold['creative']['width'] );
		$this->assertLessThanOrEqual( 16384, strlen( wp_json_encode( $cold ) ) );
		$start = $wpdb->num_queries;
		$this->assertIsArray( $fill->for_slug( $slug, 0, 1024 )['creative'] );
		$this->assertLessThanOrEqual( 4, $wpdb->num_queries - $start );
		$cache = Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Fill_Cache::class )->get( $this->placement_id );
		$this->assertCount( 500, $cache['assignment_rows'] );
		$this->assertLessThanOrEqual( 1048576, strlen( wp_json_encode( $cache ) ) );
		$this->assertArrayNotHasKey( 'manifest', $cache['assignment_rows'][0] );
		$identity = ( new \Aggressive\Ads\Workflow\Fill_Token() )->parse( $cold['creative']['token'] );
		wp_cache_flush();
		$start = $wpdb->num_queries;
		$this->assertTrue( $fill->accepts( $identity ) );
		$this->assertLessThanOrEqual( 8, $wpdb->num_queries - $start );
	}

	/** Actual raster byte lengths, independent of client size declarations, enforce the aggregate bound. */
	public function test_aggregate_limit_accepts_exactly_two_mebibytes_and_refuses_one_more_byte(): void {
		update_post_meta( $this->placement_id, Placement_Repository::META_MAX_BYTES, Image_Set::MAX_BYTES );
		$file   = $this->image_file( 728, 90, 1 );
		$mobile = $this->image_file( 320, 50, 2 );
		foreach ( array( $file, $mobile ) as $input ) {
			$original = file_get_contents( $input['tmp_name'] );
			file_put_contents( $input['tmp_name'], $original . str_repeat( "\0", 1048576 - strlen( $original ) ) );
		}
		$file['renditions'] = array( $mobile );
		$file['size']       = 1;
		$result             = $this->manager->upload( $this->campaign_id, $this->placement_id, $file, 'https://example.com/limit', 'At the limit' );
		$this->assertIsArray( $result );
		$manifest = ( new Image_Resource_Repository() )->source( $result['id'] )['manifest'];
		$this->assertSame( Image_Set::MAX_BYTES, array_sum( array_column( $manifest['resources'], 'bytes' ) ) );
		file_put_contents( $mobile['tmp_name'], "\0", FILE_APPEND );
		$this->assertWPError( $this->manager->upload( $this->campaign_id, $this->placement_id, $file, 'https://example.com/limit', 'Over the limit' ) );
		$this->assertSame( array( $result['id'] ), ( new Creative_Repository() )->ids_for_campaign( $this->campaign_id ) );
	}

	/** A failure on a later raster removes only this attempt's public files and can be retried. */
	public function test_partial_publication_compensates_and_retains_the_private_source_for_retry(): void {
		$id      = $this->upload_set();
		$records = new Image_Resource_Repository();
		$source  = $records->source( $id );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$seen     = 0;
		$fail     = static function ( bool $maybe_empty, array $post ) use ( &$seen ): bool {
			return 'attachment' === ( $post['post_type'] ?? '' ) && 2 === ++$seen ? true : $maybe_empty;
		};
		$promoter = Plugin::instance()->container()->get( Creative_Promoter::class );
		add_filter( 'wp_insert_post_empty_content', $fail, 10, 2 );
		try {
			$this->assertWPError( $promoter->promote( $id ) );
		} finally {
			remove_filter( 'wp_insert_post_empty_content', $fail );
		}
		$this->assertSame( 2, $seen );
		$this->assertNull( $records->publication( $id ) );
		$this->assertSame( $source, $records->source( $id ) );
		$this->assertSame( 0, (int) get_post_meta( $id, Creative_Repository::META_ATTACHMENT_ID, true ) );
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'meta_key'    => Creative_Repository::META_IS_CREATIVE,
					'meta_value'  => $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test-only lookup of attachments marked for one freshly created revision.
					'fields'      => 'ids',
				)
			)
		);
		$this->assertIsInt( $promoter->promote( $id ) );
		$this->published = array_column( $records->publication( $id )['resources'], 'attachment_id' );
		$this->assertTrue( $records->ready( $id ) );
	}

	/** The source lock serializes independent database connections, not only callbacks in one process. */
	public function test_source_publication_claim_is_site_scoped_and_cross_connection(): void {
		$id      = $this->upload_set();
		$records = new Image_Resource_Repository();
		$other   = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$name    = 'aggr_image_' . get_current_blog_id() . '_' . $id;
		$this->assertSame( '1', $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s,0)', $name ) ) );
		try {
			$this->assertSame( '', $records->claim( $id ) );
			$this->assertWPError( Plugin::instance()->container()->get( Creative_Promoter::class )->promote( $id ) );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			$other->close();
		}
		$lock = $records->claim( $id );
		$this->assertNotSame( '', $lock );
		$records->release( $lock );
	}

	/** The campaign is already live; this exercises the actual staff approval of an additional ad. */
	private function approve_on_running_campaign( int $id ): void {
		$campaigns = new \Aggressive\Ads\Repository\Campaign_Repository();
		$this->assertTrue( $campaigns->update_status( $this->campaign_id, \Aggressive\Ads\Core\Post_Statuses::LIVE ) );
		update_post_meta( $this->campaign_id, \Aggressive\Ads\Repository\Campaign_Repository::META_START_TS, time() - 60 );
		update_post_meta( $this->campaign_id, \Aggressive\Ads\Repository\Campaign_Repository::META_END_TS, time() + DAY_IN_SECONDS );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( $this->campaign_id, Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Creative_Approval::class )->approve( $id ) );
		$migrator = Plugin::instance()->container()->get( \Aggressive\Ads\Install\Creative_Assignment_Migrator::class );
		$migrator->run_batch();
		$this->assertTrue( $migrator->is_complete() );
		$receipt = ( new Image_Resource_Repository() )->publication( $id );
		$this->assertIsArray( $receipt );
		$this->published = array_merge( $this->published, array_column( $receipt['resources'], 'attachment_id' ) );
	}
}
