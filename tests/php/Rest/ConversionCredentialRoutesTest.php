<?php
/**
 * The credential list a staff screen reads, over HTTP.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Conversion_Credential_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `ConversionCredentialTest` covers issuing, revoking and verifying through the
 * manager. This covers the one thing the screen depends on and the manager does
 * not know about: the listing, and what the route adds to it so a person can
 * read it — the scope's name, the times in the site's timezone, and whether the
 * credential is live.
 *
 * The secret is asserted absent from the listing here as well as in the
 * repository test, deliberately. It is absent there because nothing above the
 * repository has it; the assertion that matters to an operator is that the
 * response a browser receives does not carry it either, and that is a different
 * piece of code.
 */
final class ConversionCredentialRoutesTest extends WP_UnitTestCase {

	/**
	 * Credential persistence.
	 *
	 * @var Conversion_Credential_Repository
	 */
	private Conversion_Credential_Repository $credentials;

	/**
	 * Organization the credentials report for.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * A user holding the managing capability.
	 *
	 * @var int
	 */
	private int $manager;

	/**
	 * An advertiser, who must not.
	 *
	 * @var int
	 */
	private int $advertiser;

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$container = Plugin::instance()->container();

		$this->credentials = $container->get( Conversion_Credential_Repository::class );
		$this->credentials->install_table();

		$container->get( Audit_Repository::class )->install_table();

		$this->org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Bright Angle Media',
			)
		);
		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, 0 );

		$this->manager    = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->advertiser = (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * The fixture's capability assumption, asserted rather than assumed.
	 */
	public function test_the_fixture_users_have_the_capabilities_this_file_assumes(): void {
		$this->assertTrue( user_can( $this->manager, Capabilities::MANAGE_SETTINGS ) );
		$this->assertFalse( user_can( $this->advertiser, Capabilities::MANAGE_SETTINGS ) );
	}

	/**
	 * Issues one credential over the route, as the screen does.
	 *
	 * @param string $label Staff-facing name.
	 * @return array{id: int, token: string}
	 */
	private function issue( string $label ): array {
		$request = new WP_REST_Request( 'POST', '/aggr/v1/conversion-credentials' );
		$request->set_param( 'org_id', $this->org_id );
		$request->set_param( 'label', $label );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );

		/**
		 * The created credential.
		 *
		 * @var array{id: int, token: string} $data
		 */
		$data = $response->get_data();

		return $data;
	}

	/**
	 * The listing, as a manager.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function listing(): array {
		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/aggr/v1/conversion-credentials' )
		);

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'credentials', $data );
		$this->assertIsArray( $data['credentials'] );

		return $data['credentials'];
	}

	/**
	 * **A live credential is listed with everything the screen renders.**
	 *
	 * Each of these is added by the route rather than stored, so each one is a
	 * cell that renders blank if it stops being added: an advertiser nobody can
	 * identify, an issue date nobody can compare against the audit log.
	 */
	public function test_a_credential_is_listed_with_its_scope_and_its_times(): void {
		wp_set_current_user( $this->manager );

		$issued = $this->issue( 'Shop integration' );
		$rows   = $this->listing();

		$this->assertCount( 1, $rows );

		$row = $rows[0];

		$this->assertSame( $issued['id'], $row['id'] );
		$this->assertSame( 'Shop integration', $row['label'] );
		$this->assertSame( $this->org_id, $row['org_id'] );
		$this->assertSame( 'Bright Angle Media', $row['org_name'] );
		$this->assertTrue( $row['live'] );
		$this->assertNotSame( '', $row['created_at'] );

		// Never used and never revoked are both the empty string, so the screen
		// says "Never" and "Live" rather than printing the epoch.
		$this->assertSame( '', $row['last_used_at'] );
		$this->assertSame( '', $row['revoked_at'] );
	}

	/**
	 * **The response a browser receives carries no secret.**
	 *
	 * The whole listing is searched for the plaintext rather than one field
	 * checked, because a future field that happened to embed it — a message, a
	 * snippet, a curl example — would pass a per-key assertion.
	 */
	public function test_the_listing_never_carries_the_secret(): void {
		wp_set_current_user( $this->manager );

		$issued = $this->issue( 'Shop integration' );

		$this->assertStringNotContainsString(
			$issued['token'],
			(string) wp_json_encode( $this->listing() )
		);
	}

	/**
	 * **A revoked credential stays in the list, and says so.**
	 *
	 * An operator reviewing an incident needs to see what was cut off and when.
	 * A list that dropped it would make a revocation indistinguishable from a
	 * credential that never existed.
	 */
	public function test_a_revoked_credential_is_listed_as_revoked(): void {
		wp_set_current_user( $this->manager );

		$issued = $this->issue( 'Leaked integration' );

		$revoked = rest_get_server()->dispatch(
			new WP_REST_Request( 'DELETE', '/aggr/v1/conversion-credentials/' . $issued['id'] )
		);

		$this->assertSame( 200, $revoked->get_status() );

		$rows = $this->listing();

		$this->assertCount( 1, $rows, 'Revoking removed the row instead of marking it.' );
		$this->assertFalse( $rows[0]['live'] );
		$this->assertNotSame( '', $rows[0]['revoked_at'] );
	}

	/**
	 * A scope whose organization has been deleted still lists.
	 *
	 * The screen falls back to the id, which is worth having: a credential
	 * pointing at a deleted advertiser is exactly the one somebody wants to
	 * revoke, and a row that failed to render would hide it.
	 */
	public function test_a_deleted_organization_leaves_the_row_readable(): void {
		wp_set_current_user( $this->manager );

		$this->issue( 'Shop integration' );

		wp_delete_post( $this->org_id, true );

		$rows = $this->listing();

		$this->assertCount( 1, $rows );
		$this->assertSame( '', $rows[0]['org_name'] );
		$this->assertSame( $this->org_id, $rows[0]['org_id'] );
	}

	/**
	 * Reading the list needs the same capability as issuing one.
	 *
	 * A credential list names every integration on the site and which advertiser
	 * each reports for. There is no browse-only tier here.
	 */
	public function test_an_advertiser_cannot_read_the_list(): void {
		wp_set_current_user( $this->manager );
		$this->issue( 'Shop integration' );

		wp_set_current_user( $this->advertiser );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/aggr/v1/conversion-credentials' )
		);

		$this->assertSame( 403, $response->get_status() );
	}
}
