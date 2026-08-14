<?php
/**
 * The private creative stream.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\REST\Creative_File_Controller;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Workflow\Creative_Uploader;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The highest-value endpoint in the system.
 *
 * Two properties carry most of the weight: an unauthorized read is
 * indistinguishable from a missing one, and the response is bytes rather than
 * a redirect to a URL that would outlive the session.
 */
final class CreativeFileTest extends WP_UnitTestCase {

	/**
	 * Private storage.
	 *
	 * @var Private_Storage
	 */
	private Private_Storage $storage;

	/**
	 * Creative persistence.
	 *
	 * @var Creative_Repository
	 */
	private Creative_Repository $creatives;

	/**
	 * An advertiser who owns the creative.
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * An advertiser from a different organization.
	 *
	 * @var int
	 */
	private int $stranger;

	/**
	 * A staff reviewer.
	 *
	 * @var int
	 */
	private int $reviewer;

	/**
	 * The creative under test.
	 *
	 * @var int
	 */
	private int $creative_id;

	/**
	 * Temporary files.
	 *
	 * @var array<int, string>
	 */
	private array $temporary = array();

	/**
	 * Builds two organizations, their users, and one stored creative.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->storage   = new Private_Storage();
		$this->creatives = new Creative_Repository();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->owner    = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->stranger = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->reviewer = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );

		$org = $this->organization( $this->owner );
		$this->organization( $this->stranger );

		$this->creative_id = $this->stored_creative( $org );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Removes temporary files.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->temporary as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}

		$this->temporary = array();

		parent::tear_down();
	}

	/**
	 * Creates an organization owned by a user.
	 *
	 * @param int $owner Owning user id.
	 * @return int
	 */
	private function organization( int $owner ): int {
		$org = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $org, Org_Repository::META_OWNER_USER, $owner );

		return $org;
	}

	/**
	 * Creates a creative with a real stored file.
	 *
	 * @param int $org_id Owning organization.
	 * @return int
	 */
	private function stored_creative( int $org_id ): int {
		$image = imagecreatetruecolor( 20, 10 );

		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();

		$temp = wp_tempnam( 'aggr-rest' );
		file_put_contents( $temp, $bytes );
		$this->temporary[] = $temp;

		$accepted = ( new Creative_Uploader( $this->storage ) )->accept(
			array(
				'name'     => 'spring poster.png',
				'tmp_name' => $temp,
				'error'    => UPLOAD_ERR_OK,
				'size'     => strlen( $bytes ),
			)
		);

		$this->assertIsArray( $accepted );

		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		$this->creatives->record_upload( $creative_id, $accepted );
		update_post_meta( $creative_id, Creative_Repository::META_ORG_ID, $org_id );

		return $creative_id;
	}

	/**
	 * Dispatches a request for a creative's file.
	 *
	 * @param int $creative_id Creative post id.
	 * @return \WP_REST_Response
	 */
	private function request( int $creative_id ): \WP_REST_Response {
		return rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/aggr/v1/creatives/' . $creative_id . '/file' )
		);
	}

	/**
	 * The route exists.
	 *
	 * @return void
	 */
	public function test_the_route_is_registered(): void {
		$this->assertArrayHasKey(
			'/aggr/v1/creatives/(?P<id>\d+)/file',
			rest_get_server()->get_routes()
		);
	}

	/**
	 * The owner may read their own creative.
	 *
	 * @return void
	 */
	public function test_the_owner_may_read_the_file(): void {
		wp_set_current_user( $this->owner );

		$response = $this->request( $this->creative_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'image/png', $response->get_headers()['Content-Type'] );
	}

	/**
	 * Staff may read any organization's creative, which is what review needs.
	 *
	 * @return void
	 */
	public function test_staff_may_read_the_file(): void {
		wp_set_current_user( $this->reviewer );

		$this->assertSame( 200, $this->request( $this->creative_id )->get_status() );
	}

	/**
	 * **Another organization gets 404, not 403.**
	 *
	 * A 403 on a real id and a 404 on a fake one is a working object-id
	 * oracle: an attacker enumerates the id space and learns which creatives
	 * exist, and from that how many customers there are and when they
	 * onboarded.
	 *
	 * @return void
	 */
	public function test_another_organization_gets_404_not_403(): void {
		wp_set_current_user( $this->stranger );

		$response = $this->request( $this->creative_id );

		$this->assertSame( 404, $response->get_status() );
		$this->assertNotSame( 403, $response->get_status() );
	}

	/**
	 * **A real-but-forbidden id and an imaginary one are indistinguishable.**
	 *
	 * Same status, same code, same message. Anything that differs is the
	 * oracle.
	 *
	 * @return void
	 */
	public function test_forbidden_and_missing_are_indistinguishable(): void {
		wp_set_current_user( $this->stranger );

		$forbidden = $this->request( $this->creative_id );
		$missing   = $this->request( 999999 );

		$this->assertSame( $forbidden->get_status(), $missing->get_status() );
		$this->assertSame(
			wp_json_encode( $forbidden->get_data() ),
			wp_json_encode( $missing->get_data() ),
			'A forbidden creative answers differently from one that does not exist.'
		);
	}

	/**
	 * A logged-out visitor is refused before any object is considered.
	 *
	 * @return void
	 */
	public function test_a_logged_out_visitor_is_refused(): void {
		wp_set_current_user( 0 );

		$this->assertContains( $this->request( $this->creative_id )->get_status(), array( 401, 403 ) );
	}

	/**
	 * **The response is bytes, never a redirect.**
	 *
	 * A redirect hands the caller a URL that outlives their session and can be
	 * pasted anywhere, turning authorization into a one-time check on a
	 * permanent capability.
	 *
	 * @return void
	 */
	public function test_the_response_never_redirects(): void {
		wp_set_current_user( $this->owner );

		$response = $this->request( $this->creative_id );
		$headers  = $response->get_headers();

		$this->assertLessThan( 300, $response->get_status() );
		$this->assertArrayNotHasKey( 'Location', $headers );

		foreach ( array_keys( $headers ) as $header ) {
			$this->assertNotSame( 'location', strtolower( (string) $header ) );
		}
	}

	/**
	 * The headers that keep a browser and a cache honest.
	 *
	 * @return void
	 */
	public function test_the_protective_headers_are_sent(): void {
		wp_set_current_user( $this->owner );

		$headers = $this->request( $this->creative_id )->get_headers();

		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'private', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'inline;', $headers['Content-Disposition'] );
	}

	/**
	 * **The Content-Type comes from the allowlist, not from stored data.**
	 *
	 * Serving a stored string as a header means one bad write becomes a
	 * content type the browser will execute.
	 *
	 * @return void
	 */
	public function test_the_content_type_ignores_a_poisoned_stored_value(): void {
		update_post_meta( $this->creative_id, Creative_Repository::META_MIME, 'text/html' );

		wp_set_current_user( $this->owner );

		$response = $this->request( $this->creative_id );

		$this->assertSame( 404, $response->get_status(), 'A creative stored as text/html was served.' );
	}

	/**
	 * A stored path that escapes the private root is refused.
	 *
	 * @return void
	 */
	public function test_a_traversal_path_is_refused(): void {
		update_post_meta( $this->creative_id, Creative_Repository::META_PRIVATE_PATH, '../../wp-config.php' );

		wp_set_current_user( $this->owner );

		$this->assertSame( 404, $this->request( $this->creative_id )->get_status() );
	}

	/**
	 * A creative with no stored file is refused.
	 *
	 * @return void
	 */
	public function test_a_creative_without_a_file_is_refused(): void {
		update_post_meta( $this->creative_id, Creative_Repository::META_PRIVATE_PATH, '' );

		wp_set_current_user( $this->owner );

		$this->assertSame( 404, $this->request( $this->creative_id )->get_status() );
	}

	/**
	 * The download filename is safe to put in a header, and carries the
	 * extension of what is actually being served.
	 *
	 * @return void
	 */
	public function test_the_filename_is_neutralised(): void {
		update_post_meta( $this->creative_id, Creative_Repository::META_ORIGINAL_NAME, 'evil"; rm -rf /.png' );

		wp_set_current_user( $this->owner );

		$disposition = (string) $this->request( $this->creative_id )->get_headers()['Content-Disposition'];

		$this->assertStringNotContainsString( '"; rm', $disposition );
		$this->assertStringEndsWith( '.png"', $disposition );
	}

	/**
	 * The path is never disclosed in a response header.
	 *
	 * The private root's unguessability is the layer that actually holds on an
	 * nginx host, so leaking it in a header would undo the storage design.
	 *
	 * @return void
	 */
	public function test_the_private_path_is_never_disclosed(): void {
		wp_set_current_user( $this->owner );

		$headers = $this->request( $this->creative_id )->get_headers();
		$root    = $this->storage->root();

		foreach ( $headers as $value ) {
			$this->assertStringNotContainsString( $root, (string) $value );
			$this->assertStringNotContainsString( Private_Storage::DIRECTORY, (string) $value );
		}
	}

	/**
	 * The prepared decision is what the tests above exercise; assert it
	 * directly too, so a refactor of the response cannot quietly drop the
	 * authorization.
	 *
	 * @return void
	 */
	public function test_prepare_authorizes_independently_of_the_response(): void {
		$controller = Plugin::instance()->container()->get( Creative_File_Controller::class );

		wp_set_current_user( $this->stranger );
		$this->assertInstanceOf( \WP_Error::class, $controller->prepare( $this->creative_id ) );

		wp_set_current_user( $this->owner );
		$prepared = $controller->prepare( $this->creative_id );

		$this->assertIsArray( $prepared );
		$this->assertSame( 'image/png', $prepared['mime'] );
		$this->assertGreaterThan( 0, $prepared['bytes'] );
	}
}
