<?php
/**
 * Static HTML5 uses the real upload, publication and delivery writers/readers.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Image_Resource_Repository;
use Aggressive\Ads\Workflow\Creative_Manager;
use Aggressive\Ads\Workflow\Creative_Promoter;
use Aggressive\Ads\Workflow\Image_Resources;
use Aggressive\Ads\Storage\Private_Storage;
use WP_UnitTestCase;

final class StaticHtml5Test extends WP_UnitTestCase {

	use CreativeFixtures;

	/**
	 * Tenant owner.
	 *
	 * @var int
	 */
	private int $owner;
	/**
	 * Staff reviewer and publisher.
	 *
	 * @var int
	 */
	private int $staff;
	/**
	 * Editable campaign.
	 *
	 * @var int
	 */
	private int $campaign_id;
	/**
	 * Required raster size.
	 *
	 * @var int
	 */
	private int $placement_id;
	/**
	 * Generated upload files.
	 *
	 * @var list<string>
	 */
	private array $temporary = array();
	/**
	 * Published resources owned by this test.
	 *
	 * @var list<int>
	 */
	private array $published = array();

	public function set_up(): void {
		parent::set_up();
		$this->owner        = (int) self::factory()->user->create( array( 'role' => 'aggr_advertiser' ) );
		$this->staff        = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->placement_id = $this->placement( 'Static HTML5 test', '728x90' );
		$this->campaign_id  = $this->campaign( $this->owner, $this->org( $this->owner ), array( $this->placement_id ) );
		wp_set_current_user( $this->staff );
	}

	public function tear_down(): void {
		foreach ( $this->published as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->remove_creative_files( $this->campaign_id );
		foreach ( $this->temporary as $path ) {
			wp_delete_file( $path );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_real_upload_publication_and_typed_delivery(): void {
		$file                     = $this->package( '<!doctype html><html><head><meta charset="utf-8"><style>.banner{color:red;display:flex}</style></head><body><div class="banner">Reviewed static ad</div></body></html>' );
		$file['fallback']['name'] = 'fallback<img>.png';
		$result                   = Plugin::instance()->container()->get( Creative_Manager::class )->upload( $this->campaign_id, $this->placement_id, $file, 'https://example.com/ad', 'Static HTML5 ad' );
		$this->assertIsArray( $result );
		$id      = $result['id'];
		$records = new Image_Resource_Repository();
		$source  = $records->source( $id );
		$this->assertIsArray( $source );
		$this->assertSame( 2, $source['version'] );
		$this->assertSame( 'html5', ( new Creative_Repository() )->details( $id )['kind'] );
		$this->assertFalse( $records->ready( $id ) );
		$base = Plugin::instance()->container()->get( Creative_Promoter::class )->promote( $id );
		$this->assertIsInt( $base );
		$receipt = $records->publication( $id );
		$this->assertIsArray( $receipt );
		$this->published = array_merge( array_column( $receipt['resources'], 'attachment_id' ), array( $receipt['html5']['attachment_id'] ) );
		$this->assertTrue( $records->ready( $id ) );
		$this->assertSame( '', $records->source( $id )['upload']['path'] );
		$this->assertSame( $base, Plugin::instance()->container()->get( Creative_Promoter::class )->promote( $id ) );
		$resolved = ( new Image_Resources( $records, Plugin::instance()->container()->get( Private_Storage::class ) ) )->published( $id );
		$this->assertIsArray( $resolved );
		$this->assertStringContainsString( 'Reviewed static ad', $resolved['package']['document'] );
		$this->assertStringNotContainsString( '<script', $resolved['package']['document'] );
		$this->assertStringNotContainsString( '<', file_get_contents( get_attached_file( $receipt['html5']['attachment_id'] ) ) );
		$row     = array_merge(
			array(
				'revision_id'     => $id,
				'campaign_id'     => $this->campaign_id,
				'placement_id'    => $this->placement_id,
				'organization_id' => $receipt['org_id'],
				'attachment_id'   => $base,
			),
			$records->projection( $id )
		);
		$payload = Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Decision_Engine::class )->payload_from_row( $row, $this->placement_id, '728x90' );
		$this->assertIsArray( $payload );
		$this->assertSame( 'html5-static', $payload['format'] );
		$this->assertSame( $source['html5']['document']['sha256'], $payload['documentHash'] );
		$this->assertStringEndsWith( '.json', $payload['document'] );
	}

	public function test_advertiser_cannot_select_html5_by_multipart_shape(): void {
		$file = $this->package( '<div>Static ad</div>' );
		wp_set_current_user( $this->owner );
		$result = Plugin::instance()->container()->get( Creative_Manager::class )->upload( $this->campaign_id, $this->placement_id, $file, 'https://example.com/ad', 'Ad' );
		$this->assertWPError( $result );
		$this->assertSame( 'aggr_html5_forbidden', $result->get_error_code() );
		$this->assertSame( array(), ( new Creative_Repository() )->for_campaign( $this->campaign_id ) );
	}

	public function test_script_input_is_refused_before_any_creative_is_created(): void {
		$result = Plugin::instance()->container()->get( Creative_Manager::class )->upload( $this->campaign_id, $this->placement_id, $this->package( '<div>Ad</div><script>new RTCPeerConnection()</script>' ), 'https://example.com/ad', 'Ad' );
		$this->assertWPError( $result );
		$this->assertSame( 'aggr_html5_invalid', $result->get_error_code() );
		$this->assertSame( array(), ( new Creative_Repository() )->for_campaign( $this->campaign_id ) );
	}

	/** A failed second resource removes only this attempt's first resource and keeps source bytes. */
	public function test_publication_compensates_and_retries_without_orphan_resources(): void {
		$id      = $this->upload();
		$records = new Image_Resource_Repository();
		$before  = $records->source( $id );
		$reject  = static fn ( bool $maybe_empty, array $post ): bool => 'application/json' === ( $post['post_mime_type'] ?? '' ) || $maybe_empty;
		add_filter( 'wp_insert_post_empty_content', $reject, 10, 2 );
		try {
			$this->assertWPError( Plugin::instance()->container()->get( Creative_Promoter::class )->promote( $id ) );
		} finally {
			remove_filter( 'wp_insert_post_empty_content', $reject, 10 );
		}
		$this->assertNull( $records->publication( $id ) );
		$this->assertSame( $before, $records->source( $id ) );
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'  => 'attachment',
					'meta_key'   => Creative_Repository::META_IS_CREATIVE,
					'meta_value' => $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test-only lookup for resources owned by one fresh revision.
					'fields'     => 'ids',
				)
			)
		);
		$this->publish( $id );
		$this->assertTrue( $records->ready( $id ) );
	}

	/** Source bytes must still match at the publication boundary, before any public file exists. */
	public function test_changed_compiled_source_cannot_publish(): void {
		$id                   = $this->upload();
		$records              = new Image_Resource_Repository();
		$source               = $records->source( $id );
		$storage              = Plugin::instance()->container()->get( Private_Storage::class );
		$artifact             = json_decode( $storage->read( $source['upload']['path'], \Aggressive\Ads\Domain\Html5_Profile::MAX_ARTIFACT ), true );
		$artifact['document'] = base64_encode( '<script>alert(1)</script>' );
		file_put_contents( $storage->resolve( $source['upload']['path'] ), wp_json_encode( $artifact ) );
		$this->assertWPError( Plugin::instance()->container()->get( Creative_Promoter::class )->promote( $id ) );
		$this->assertNull( $records->publication( $id ) );
		$this->assertFalse( $records->ready( $id ) );
	}

	/** Stored metadata cannot change the approved document, even if its fallback is unchanged. */
	public function test_current_manifest_tampering_refuses_cached_fill_and_authoritative_token_reads(): void {
		$id = $this->upload();
		$this->publish( $id );
		$records = new Image_Resource_Repository();
		$row     = array_merge(
			array(
				'revision_id'  => $id,
				'campaign_id'  => $this->campaign_id,
				'placement_id' => $this->placement_id,
			),
			$records->projection( $id )
		);
		$this->assertIsArray( $records->delivery( $row, '728x90' ) );
		$source                                = $records->source( $id );
		$source['html5']['document']['sha256'] = str_repeat( 'a', 64 );
		update_post_meta( $id, Creative_Repository::META_IMAGE_SOURCE, wp_slash( wp_json_encode( $source ) ) );
		$this->assertFalse( $records->ready( $id ) );
		$this->assertNull( $records->delivery( $row, '728x90' ) );
		$this->assertNull(
			$records->delivery(
				array(
					'creative_id'  => $id,
					'campaign_id'  => $this->campaign_id,
					'placement_id' => $this->placement_id,
				),
				''
			)
		);
	}

	/** Public source is inert JSON; tampering cannot become bytes offered for review or copying. */
	public function test_changed_public_envelope_refuses_republication_and_copying(): void {
		$id = $this->upload();
		$this->publish( $id );
		$receipt = ( new Image_Resource_Repository() )->publication( $id );
		file_put_contents( get_attached_file( $receipt['html5']['attachment_id'] ), '{"version":1,"document":"changed"}' );
		$this->assertWPError( Plugin::instance()->container()->get( Creative_Promoter::class )->promote( $id ) );
		$this->assertNull( Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Creative_Source::class )->resolve( $id ) );
	}

	/** Copying after private purge retains the archive, compiled output and fallback but grants no approval. */
	public function test_copy_reconstructs_complete_source_after_private_purge(): void {
		$id = $this->upload();
		$this->publish( $id );
		$source = Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Creative_Source::class )->upload( $id );
		$this->assertIsArray( $source );
		try {
			$copy = Plugin::instance()->container()->get( Creative_Manager::class )->upload( $this->campaign_id, $this->placement_id, $source['file'], 'https://example.com/copy', 'Copied ad' );
		} finally {
			foreach ( $source['temporary'] as $path ) {
				wp_delete_file( $path );
			}
		}
		$this->assertIsArray( $copy );
		$records = new Image_Resource_Repository();
		$this->assertSame( $records->source( $id )['html5'], $records->source( $copy['id'] )['html5'] );
		$this->assertNull( $records->publication( $copy['id'] ) );
		$this->assertFalse( $records->ready( $copy['id'] ) );
	}

	/** Source edits and approval share the existing nonreentrant, site-scoped claim. */
	public function test_lock_refuses_overlapping_replacement_and_publication(): void {
		$id      = $this->upload();
		$records = new Image_Resource_Repository();
		$before  = $records->source( $id );
		$lock    = $records->claim( $id );
		$this->assertNotSame( '', $lock );
		try {
			$this->assertWPError( Plugin::instance()->container()->get( Creative_Manager::class )->replace_artwork( $id, $this->package( '<div>Replacement</div>' ) ) );
			$this->assertWPError( Plugin::instance()->container()->get( Creative_Promoter::class )->promote( $id ) );
		} finally {
			$records->release( $lock );
		}
		$this->assertSame( $before, $records->source( $id ) );
		$this->publish( $id );
		$this->assertFalse( ( new Creative_Repository() )->set_text( $id, 'https://example.com/mutation', 'Changed' ) );
	}

	/** Human approval of an original cannot authorize changed text on a cloned revision. */
	public function test_text_clone_needs_new_review_without_recreating_shared_resources(): void {
		$id = $this->upload();
		$this->publish( $id );
		( new \Aggressive\Ads\Repository\Campaign_Repository() )->update_status( $this->campaign_id, \Aggressive\Ads\Core\Post_Statuses::LIVE );
		$change = Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Creative_Change_Manager::class )->request_text_change( $id, 'https://example.com/new-destination', 'Updated description' );
		$this->assertIsArray( $change );
		$records = new Image_Resource_Repository();
		$this->assertFalse( $records->ready( $change['id'] ) );
		$this->assertTrue( Plugin::instance()->container()->get( \Aggressive\Ads\Repository\Creative_Revision_Repository::class )->is_text_only_revision( $change['id'] ) );
		$clean_source                                  = $records->source( $change['id'] );
		$changed_source                                = $clean_source;
		$changed_source['html5']['document']['sha256'] = str_repeat( 'a', 64 );
		update_post_meta( $change['id'], Creative_Repository::META_IMAGE_SOURCE, wp_slash( wp_json_encode( $changed_source ) ) );
		$this->assertFalse( Plugin::instance()->container()->get( \Aggressive\Ads\Repository\Creative_Revision_Repository::class )->is_text_only_revision( $change['id'] ) );
		update_post_meta( $change['id'], Creative_Repository::META_IMAGE_SOURCE, wp_slash( wp_json_encode( $clean_source ) ) );
		$this->assertIsInt( Plugin::instance()->container()->get( Creative_Promoter::class )->promote( $change['id'] ) );
		$this->assertTrue( $records->ready( $change['id'] ) );
		$this->assertSame( $records->publication( $id )['html5'], $records->publication( $change['id'] )['html5'] );
	}

	/** Shared candidate/token paths keep their existing query and payload ceilings for this profile. */
	public function test_real_approval_and_five_hundred_candidates_preserve_delivery_budgets(): void {
		global $wpdb;
		$id = $this->upload();
		( new \Aggressive\Ads\Repository\Campaign_Repository() )->update_status( $this->campaign_id, \Aggressive\Ads\Core\Post_Statuses::LIVE );
		update_post_meta( $this->campaign_id, \Aggressive\Ads\Repository\Campaign_Repository::META_START_TS, time() - 60 );
		update_post_meta( $this->campaign_id, \Aggressive\Ads\Repository\Campaign_Repository::META_END_TS, time() + DAY_IN_SECONDS );
		$this->assertIsInt( Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Creative_Approval::class )->approve( $id ) );
		$migrator = Plugin::instance()->container()->get( \Aggressive\Ads\Install\Creative_Assignment_Migrator::class );
		$migrator->run_batch();
		$this->assertTrue( $migrator->is_complete() );
		$records         = new Image_Resource_Repository();
		$receipt         = $records->publication( $id );
		$this->published = array_merge( $this->published, array_column( $receipt['resources'], 'attachment_id' ), array( $receipt['html5']['attachment_id'] ) );
		$assignments     = new \Aggressive\Ads\Repository\Creative_Assignment_Repository();
		$row             = $assignments->for_campaign( $this->campaign_id )[0];
		$this->assertSame( 2, (int) $row['image_version'] );
		$this->assertSame( 'live', $row['status'] );
		unset( $row['id'] );
		$row['compat_key'] = null;
		for ( $i = 0; $i < 500; ++$i ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Bounded load fixture preserves the actual upload/approval tuple, rather than inventing readiness or kind.
			$this->assertSame( 1, $wpdb->insert( $assignments->table_name(), $row ) );
		}
		$settings = Plugin::instance()->container()->get( \Aggressive\Ads\Core\Settings::class );
		$document = $settings->get();
		$document['modules'][ \Aggressive\Ads\Domain\Settings_Schema::MODULE_NATIVE_DELIVERY ] = true;
		$this->assertTrue( $settings->save( $document ) );
		$fill  = Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Fill_Service::class );
		$cache = Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Fill_Cache::class );
		$cache->delete( $this->placement_id );
		$slug  = get_post_field( 'post_name', $this->placement_id );
		$start = $wpdb->num_queries;
		$cold  = $fill->for_slug( $slug );
		$this->assertLessThanOrEqual( 12, $wpdb->num_queries - $start );
		$this->assertIsArray( $cold['creative'] );
		$this->assertSame( 'html5-static', $cold['creative']['format'] );
		$this->assertLessThanOrEqual( 16384, strlen( wp_json_encode( $cold ) ) );
		$start = $wpdb->num_queries;
		$this->assertIsArray( $fill->for_slug( $slug )['creative'] );
		$this->assertLessThanOrEqual( 4, $wpdb->num_queries - $start );
		$stored = $cache->get( $this->placement_id );
		$this->assertCount( 500, $stored['assignment_rows'] );
		$this->assertLessThanOrEqual( 1048576, strlen( wp_json_encode( $stored ) ) );
		$this->assertArrayNotHasKey( 'document', $stored['assignment_rows'][0] );
		$identity = ( new \Aggressive\Ads\Workflow\Fill_Token() )->parse( $cold['creative']['token'] );
		wp_cache_flush();
		$start = $wpdb->num_queries;
		$this->assertTrue( $fill->accepts( $identity ) );
		$this->assertLessThanOrEqual( 8, $wpdb->num_queries - $start );
		$this->assertIsArray( $fill->for_slots( array( $slug ) )[ $slug ]['creative'] );
	}

	/** Current placement limits are rechecked rather than trusting the earlier ingest policy. */
	public function test_tightened_document_limit_refuses_publication(): void {
		$placements = new \Aggressive\Ads\Repository\Placement_Repository();
		$placements->set_max_bytes( $this->placement_id, 200000 );
		$file = $this->package( '<div>' . str_repeat( 'Bounded document. ', 3000 ) . '</div>' );
		// Store rather than deflate: this fixture measures byte policy, not expansion-ratio refusal.
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $file['tmp_name'] ) );
		$this->assertTrue( $zip->setCompressionName( 'index.html', \ZipArchive::CM_STORE ) );
		$zip->close();
		$result = Plugin::instance()->container()->get( Creative_Manager::class )->upload( $this->campaign_id, $this->placement_id, $file, 'https://example.com/ad', 'Ad' );
		$this->assertIsArray( $result );
		$placements->set_max_bytes( $this->placement_id, 10240 );
		$this->assertWPError( Plugin::instance()->container()->get( Creative_Promoter::class )->promote( $result['id'] ) );
		$this->assertFalse( ( new Image_Resource_Repository() )->ready( $result['id'] ) );
	}

	/** Actual compiled bytes accept the exact ceiling and reject one more byte within the shared budget. */
	public function test_exact_document_ceiling_and_work_budget(): void {
		$limit = \Aggressive\Ads\Domain\Html5_Profile::MAX_BYTES;
		( new \Aggressive\Ads\Repository\Placement_Repository() )->set_max_bytes( $this->placement_id, $limit );
		$empty = ( new \Aggressive\Ads\Workflow\Html5_Compiler() )->compile( array( 'index.html' => '<div></div>' ), array(), new \Aggressive\Ads\Workflow\Html5_Budget() );
		$html  = '<div>' . str_repeat( 'x', $limit - strlen( $empty ) ) . '</div>';
		$file  = $this->package( $html );
		$zip   = new \ZipArchive();
		$this->assertTrue( $zip->open( $file['tmp_name'] ) );
		$this->assertTrue( $zip->setCompressionName( 'index.html', \ZipArchive::CM_STORE ) );
		$zip->close();
		$memory  = memory_get_usage( true );
		$started = hrtime( true );
		$result  = Plugin::instance()->container()->get( Creative_Manager::class )->upload( $this->campaign_id, $this->placement_id, $file, 'https://example.com/boundary', 'At the ceiling' );
		$this->assertIsArray( $result );
		$this->assertSame( $limit, ( new Image_Resource_Repository() )->source( $result['id'] )['html5']['document']['bytes'] );
		$this->assertLessThanOrEqual( 67108864, memory_get_peak_usage( true ) - $memory );
		$this->assertLessThanOrEqual( 5_000_000_000, hrtime( true ) - $started );
		$this->assertTrue( $zip->open( $file['tmp_name'] ) );
		$this->assertTrue( $zip->addFromString( 'index.html', $html . 'x' ) );
		$this->assertTrue( $zip->setCompressionName( 'index.html', \ZipArchive::CM_STORE ) );
		$zip->close();
		$this->assertWPError( Plugin::instance()->container()->get( Creative_Manager::class )->upload( $this->campaign_id, $this->placement_id, $file, 'https://example.com/boundary', 'One byte too many' ) );
	}

	/** Authenticated private reads expose only authorized bytes, with a navigable sandbox response. */
	public function test_private_preview_and_fallback_preserve_tenant_scope(): void {
		$id         = $this->upload();
		$controller = Plugin::instance()->container()->get( \Aggressive\Ads\REST\Creative_File_Controller::class );
		$request    = new \WP_REST_Request( 'GET', '/aggr/v1/creatives/' . $id . '/preview' );
		$request->set_param( 'id', $id );
		$response = $controller->preview( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'sandbox;', $response->get_headers()['Content-Security-Policy'] );
		$this->assertStringContainsString( 'frame-src about:', $response->get_headers()['Content-Security-Policy'] );
		$this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( 'image/png', $controller->prepare( $id )['mime'] );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'aggr_advertiser' ) ) );
		$this->assertSame( 404, $controller->preview( $request )->get_status() );
		$this->assertWPError( $controller->prepare( $id ) );
	}

	/**
	 * The publisher's privileges must not promote a supplied display filename into title markup.
	 *
	 * @dataProvider publication_title_profiles
	 * @param bool $html5 Static envelope or the legacy image path.
	 */
	public function test_publication_titles_treat_upload_names_as_plain_text( bool $html5 ): void {
		$file = $html5 ? $this->package( '<div>Static ad</div>' ) : $this->image_file( 728, 90, 1 );
		if ( $html5 ) {
			$file['fallback']['name'] = 'upload<b>name.png';
		} else {
			$file['name'] = 'upload<b>name.png';
			wp_set_current_user( $this->owner );
		}
		$result = Plugin::instance()->container()->get( Creative_Manager::class )->upload( $this->campaign_id, $this->placement_id, $file, 'https://example.com/title', 'Ad' );
		$this->assertIsArray( $result );
		wp_set_current_user( $this->staff );
		$base = Plugin::instance()->container()->get( Creative_Promoter::class )->promote( $result['id'] );
		$this->assertIsInt( $base );
		$receipt         = ( new Image_Resource_Repository() )->publication( $result['id'] );
		$this->published = array_merge( $this->published, null !== $receipt ? array_merge( array_column( $receipt['resources'], 'attachment_id' ), array( $receipt['html5']['attachment_id'] ) ) : array( $base ) );
		$this->assertSame( 'uploadname.png', get_post_field( 'post_title', $base ) );
	}

	/**
	 * Both publication writers consume supplied names under staff authority.
	 *
	 * @return array<string,array{bool}>
	 */
	public static function publication_title_profiles(): array {
		return array(
			'static HTML5 fallback' => array( true ),
			'legacy image'          => array( false ),
		);
	}

	/** Runtime readiness is wired into Site Health and does not qualify scripts or verify every resource. */
	public function test_runtime_health_check_is_registered_and_reports_its_limits(): void {
		$tests = apply_filters( 'site_status_tests', array() );
		$this->assertArrayHasKey( 'aggr_static_html5', $tests['direct'] );
		$callback = $tests['direct']['aggr_static_html5']['test'];
		$this->assertSame( 'static_html5', $callback[1] );
		$result = call_user_func( $callback );
		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'Script and external modes remain unavailable', $result['description'] );
		$this->assertStringContainsString( 'does not verify every published resource', $result['description'] );
	}

	/**
	 * Upload through the same authorized workflow as forms and REST.
	 *
	 * @return int Created revision.
	 */
	private function upload(): int {
		$result = Plugin::instance()->container()->get( Creative_Manager::class )->upload( $this->campaign_id, $this->placement_id, $this->package( '<div style="color:red">Static ad</div>' ), 'https://example.com/ad', 'Static ad' );
		$this->assertIsArray( $result );
		return $result['id'];
	}

	/**
	 * Track only the actual resources created by this production publication call.
	 *
	 * @param int $id Uploaded revision.
	 */
	private function publish( int $id ): void {
		$this->assertIsInt( Plugin::instance()->container()->get( Creative_Promoter::class )->promote( $id ) );
		$receipt = ( new Image_Resource_Repository() )->publication( $id );
		$this->assertIsArray( $receipt );
		$this->published = array_merge( $this->published, array_column( $receipt['resources'], 'attachment_id' ), array( $receipt['html5']['attachment_id'] ) );
	}

	/**
	 * Build a real ZIP without supplying publication or eligibility facts.
	 *
	 * @param string $html Untrusted package entry point.
	 * @return array<string,mixed>
	 */
	private function package( string $html ): array {
		$path              = wp_tempnam( 'aggr-static-test' );
		$this->temporary[] = $path;
		$zip               = new \ZipArchive();
		$this->assertTrue( $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
		$this->assertTrue( $zip->addFromString( 'index.html', $html ) );
		$this->assertTrue( $zip->close() );
		return array(
			'name'     => 'creative.zip',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'fallback' => $this->image_file( 728, 90, 1 ),
		);
	}
}
