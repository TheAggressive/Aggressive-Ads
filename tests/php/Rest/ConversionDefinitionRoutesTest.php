<?php
/**
 * Conversion definition REST authorization and input handling.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Domain\Conversion_Definition;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Conversion_Definition_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A definition carries the public key a page reports conversions against, so
 * reading one is as sensitive as writing one and both are asserted here.
 */
final class ConversionDefinitionRoutesTest extends WP_UnitTestCase {

	/**
	 * Definition persistence.
	 *
	 * @var Conversion_Definition_Repository
	 */
	private Conversion_Definition_Repository $definitions;

	/**
	 * Audit persistence.
	 *
	 * @var Audit_Repository
	 */
	private Audit_Repository $audit;

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

	/**
	 * A reviewer, who reviews campaigns but does not configure measurement.
	 *
	 * @var int
	 */
	private int $reviewer;

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->definitions = Plugin::instance()->container()->get( Conversion_Definition_Repository::class );
		$this->definitions->install_table();

		$this->audit = Plugin::instance()->container()->get( Audit_Repository::class );
		$this->audit->install_table();

		$this->manager    = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->advertiser = (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->reviewer   = (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * A valid create body.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 * @return array<string, mixed>
	 */
	private static function body( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'                 => 'Purchase',
				'org_id'               => 12,
				'window_seconds'       => 2592000,
				'default_value_micros' => 4990000,
				'currency'             => 'USD',
				'allow_s2s'            => true,
				'status'               => Conversion_Definition::STATUS_ACTIVE,
			),
			$overrides
		);
	}

	/**
	 * The fixture's capability assumption, asserted rather than assumed.
	 *
	 * If an administrator ever stopped holding this capability, every
	 * authorization test below would pass by failing to reach the thing it
	 * guards. That is the shape of a test that passes for the wrong reason.
	 */
	public function test_the_fixture_users_have_the_capabilities_this_file_assumes(): void {
		$this->assertTrue( user_can( $this->manager, Capabilities::MANAGE_SETTINGS ) );
		$this->assertFalse( user_can( $this->advertiser, Capabilities::MANAGE_SETTINGS ) );
		$this->assertFalse( user_can( $this->reviewer, Capabilities::MANAGE_SETTINGS ) );
	}

	public function test_a_manager_can_create_and_read_definitions(): void {
		wp_set_current_user( $this->manager );

		$created = $this->request( 'POST', '/conversion-definitions', self::body() );

		$this->assertSame( 201, $created->get_status() );

		$definition = $created->get_data()['definition'];

		$this->assertSame( 'Purchase', $definition['name'] );
		$this->assertTrue( Conversion_Definition::is_valid_public_key( $definition['public_key'] ) );
		$this->assertTrue( $definition['accepts_reports'] );

		$listed = $this->request( 'GET', '/conversion-definitions' );

		$this->assertSame( 200, $listed->get_status() );
		$this->assertCount( 1, $listed->get_data()['definitions'] );
	}

	/**
	 * Every verb is refused to everyone without the capability.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function verbs(): array {
		return array(
			'reading'  => array( 'GET', '/conversion-definitions' ),
			'creating' => array( 'POST', '/conversion-definitions' ),
		);
	}

	/**
	 * Asserts one verb against an advertiser.
	 *
	 * @dataProvider verbs
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Route.
	 */
	public function test_an_advertiser_is_refused( string $method, string $path ): void {
		wp_set_current_user( $this->advertiser );

		$this->assertSame( 403, $this->request( $method, $path, self::body() )->get_status() );
	}

	/**
	 * Asserts one verb against a reviewer.
	 *
	 * A reviewer approves campaigns and must still not configure measurement:
	 * the two capabilities are separate on purpose, and nothing else asserts it.
	 *
	 * @dataProvider verbs
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Route.
	 */
	public function test_a_reviewer_is_refused( string $method, string $path ): void {
		wp_set_current_user( $this->reviewer );

		$this->assertSame( 403, $this->request( $method, $path, self::body() )->get_status() );
	}

	/**
	 * Asserts one verb against nobody at all.
	 *
	 * @dataProvider verbs
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Route.
	 */
	public function test_an_anonymous_client_is_refused( string $method, string $path ): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->request( $method, $path, self::body() )->get_status() );
	}

	/**
	 * A refused create writes nothing.
	 *
	 * The status code is the visible half; this is the half that matters.
	 */
	public function test_a_refused_create_leaves_no_definition(): void {
		wp_set_current_user( $this->advertiser );

		$this->request( 'POST', '/conversion-definitions', self::body() );

		$this->assertSame( 0, $this->definitions->count() );
	}

	/**
	 * A refused request never reaches the workflow at all.
	 *
	 * The route's `permission_callback` answers first, so the manager — and its
	 * audit row — are never reached. That is the intended shape: an
	 * unauthenticated probe that wrote an audit row on every attempt would be
	 * an unbounded write anybody could drive. The manager's own denial path is
	 * defence in depth for internal callers and is asserted in
	 * `ConversionDefinitionManagerTest`, where it actually runs.
	 */
	public function test_a_refused_request_writes_no_audit_row(): void {
		global $wpdb;

		wp_set_current_user( $this->advertiser );

		$table = $this->audit->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$this->request( 'POST', '/conversion-definitions', self::body() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$this->assertSame( $before, $after );
	}

	/**
	 * **A client cannot choose the public key**, end to end.
	 *
	 * It is the credential a page presents to report a conversion. A caller who
	 * could set it could set it to one they had learned somewhere else, and
	 * report conversions against a definition that was never theirs.
	 *
	 * Three layers refuse it and only one is load-bearing: the controller's
	 * field allowlist, the domain validator's fixed return shape, and the
	 * repository minting the key itself. Deleting either of the first two leaves
	 * this test green, because the repository still mints — so the guarantee is
	 * asserted directly against the repository in
	 * `ConversionDefinitionRepositoryTest::test_a_supplied_public_key_is_ignored`,
	 * which is the test that fails when the real guard goes. This one records
	 * that the whole path behaves, which is worth knowing separately.
	 */
	public function test_a_client_cannot_set_the_public_key(): void {
		wp_set_current_user( $this->manager );

		$forged = str_repeat( 'f', 32 );

		$created = $this->request( 'POST', '/conversion-definitions', self::body( array( 'public_key' => $forged ) ) );

		$this->assertSame( 201, $created->get_status() );
		$this->assertNotSame( $forged, $created->get_data()['definition']['public_key'] );
		$this->assertNull( $this->definitions->find_by_public_key( $forged ) );
	}

	/**
	 * Nor the revision, the id, or the creation time.
	 *
	 * All three are columns, and none is a client's to write. A caller who could
	 * set `revision` could defeat the concurrency check by claiming to be
	 * current.
	 */
	public function test_a_client_cannot_set_server_owned_columns(): void {
		wp_set_current_user( $this->manager );

		$created = $this->request(
			'POST',
			'/conversion-definitions',
			self::body(
				array(
					'id'            => 4242,
					'revision'      => 99,
					'created_at_ts' => 1,
				)
			)
		);

		$definition = $created->get_data()['definition'];

		$this->assertNotSame( 4242, $definition['id'] );
		$this->assertSame( 1, $definition['revision'], 'A new definition starts at revision 1 whatever was asked for.' );
	}

	/**
	 * A stale update is refused with a status the client can act on.
	 */
	public function test_a_stale_update_is_a_conflict(): void {
		wp_set_current_user( $this->manager );

		$id = $this->definitions->create( self::stored() );

		$first = $this->request(
			'PATCH',
			"/conversion-definitions/{$id}",
			self::body(
				array(
					'name'     => 'First',
					'revision' => 1,
				)
			)
		);

		$this->assertSame( 200, $first->get_status() );

		$second = $this->request(
			'PATCH',
			"/conversion-definitions/{$id}",
			self::body(
				array(
					'name'     => 'Second',
					'revision' => 1,
				)
			)
		);

		$this->assertSame( 409, $second->get_status() );

		$row = $this->definitions->find( $id );

		$this->assertIsArray( $row );
		$this->assertSame( 'First', $row['name'], 'The losing write must not have landed.' );
	}

	/**
	 * An update with no revision at all is refused, not treated as current.
	 */
	public function test_an_update_without_a_revision_is_refused(): void {
		wp_set_current_user( $this->manager );

		$id = $this->definitions->create( self::stored() );

		$response = $this->request( 'PATCH', "/conversion-definitions/{$id}", self::body( array( 'name' => 'Nope' ) ) );

		$this->assertSame( 409, $response->get_status() );

		$row = $this->definitions->find( $id );

		$this->assertIsArray( $row );
		$this->assertNotSame( 'Nope', $row['name'] );
	}

	/**
	 * A definition that does not exist and one that never did answer alike.
	 */
	public function test_a_missing_definition_is_not_enumerable(): void {
		wp_set_current_user( $this->manager );

		$response = $this->request( 'PATCH', '/conversion-definitions/987654', self::body( array( 'revision' => 1 ) ) );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Invalid input is refused with the offending fields named.
	 */
	public function test_invalid_input_names_its_problems(): void {
		wp_set_current_user( $this->manager );

		$response = $this->request(
			'POST',
			'/conversion-definitions',
			self::body(
				array(
					'name'     => '',
					'currency' => 'nope',
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, $this->definitions->count() );

		$data = $response->as_error()?->get_error_data();

		$this->assertIsArray( $data );
		$this->assertContains( 'name', $data['fields'] );
		$this->assertContains( 'currency', $data['fields'] );
	}

	/**
	 * Responses carrying public keys are never cached.
	 *
	 * A shared cache holding a staff response and replaying it to somebody else
	 * would hand over every reporting credential on the site.
	 */
	public function test_definition_responses_are_not_cacheable(): void {
		wp_set_current_user( $this->manager );

		$this->definitions->create( self::stored() );

		$listed = $this->request( 'GET', '/conversion-definitions' );

		$this->assertSame( 'no-store', $listed->get_headers()['Cache-Control'] ?? '' );

		$created = $this->request( 'POST', '/conversion-definitions', self::body( array( 'name' => 'Another' ) ) );

		$this->assertSame( 'no-store', $created->get_headers()['Cache-Control'] ?? '' );
	}

	/**
	 * One stored definition.
	 *
	 * @return array{name: string, org_id: int, window_seconds: int, default_value_micros: int, currency: string, allow_s2s: bool, status: string}
	 */
	private static function stored(): array {
		return array(
			'name'                 => 'Purchase',
			'org_id'               => 12,
			'window_seconds'       => 2592000,
			'default_value_micros' => 0,
			'currency'             => '',
			'allow_s2s'            => false,
			'status'               => Conversion_Definition::STATUS_ACTIVE,
		);
	}

	/**
	 * Dispatches one definition REST request.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $path   Route below the namespace.
	 * @param array<string, mixed> $body   Optional body.
	 */
	private function request( string $method, string $path, array $body = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, '/aggr/v1' . $path );

		if ( array() !== $body ) {
			$request->set_body_params( $body );
		}

		return rest_get_server()->dispatch( $request );
	}
}
