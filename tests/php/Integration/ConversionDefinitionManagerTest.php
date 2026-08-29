<?php
/**
 * Capability, audit and validation ordering for definition writes.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Conversion_Definition;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Conversion_Definition_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Conversion_Definition_Manager;
use WP_UnitTestCase;

/**
 * The manager repeats the capability check the route already made, and this is
 * where that repetition is proven to be real rather than decorative.
 *
 * A route is one caller. A workflow that trusts having been reached grants
 * whatever the next caller forgets to check, and the next caller here will be
 * the conversion ingestion path.
 */
final class ConversionDefinitionManagerTest extends WP_UnitTestCase {

	/**
	 * Manager under test.
	 *
	 * @var Conversion_Definition_Manager
	 */
	private Conversion_Definition_Manager $manager;

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

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$container = Plugin::instance()->container();

		$this->definitions = $container->get( Conversion_Definition_Repository::class );
		$this->definitions->install_table();

		$this->audit = $container->get( Audit_Repository::class );
		$this->audit->install_table();

		$this->manager = $container->get( Conversion_Definition_Manager::class );
	}

	/**
	 * A valid definition.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 * @return array<string, mixed>
	 */
	private static function input( array $overrides = array() ): array {
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
	 * The most recent audit outcome for definitions.
	 */
	private function last_outcome(): ?string {
		global $wpdb;

		$table = $this->audit->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		$outcome = $wpdb->get_var( $wpdb->prepare( "SELECT outcome FROM {$table} WHERE object_type = %s ORDER BY id DESC LIMIT 1", 'conversion_definition' ) );

		return is_string( $outcome ) ? $outcome : null;
	}

	public function test_a_manager_can_create_one(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$id = $this->manager->create( self::input() );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'ok', $this->last_outcome() );
	}

	/**
	 * An advertiser calling the workflow directly is refused and recorded.
	 */
	public function test_an_advertiser_is_refused_and_the_denial_is_audited(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		$result = $this->manager->create( self::input() );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_forbidden', $result->get_error_code() );
		$this->assertSame( 0, $this->definitions->count(), 'A refused create must write nothing.' );
		$this->assertSame( 'denied', $this->last_outcome() );
	}

	/**
	 * Nobody at all is refused too.
	 */
	public function test_an_anonymous_caller_is_refused(): void {
		wp_set_current_user( 0 );

		$result = $this->manager->create( self::input() );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_forbidden', $result->get_error_code() );
		$this->assertSame( 0, $this->definitions->count() );
	}

	/**
	 * **The capability is checked before the input is validated.**
	 *
	 * Ordering, not politeness. Validating first would let an unauthorized
	 * caller tell a valid definition from an invalid one by the error they got
	 * back — a probe that maps out what the server will accept without ever
	 * being allowed to write.
	 */
	public function test_an_unauthorized_caller_cannot_tell_valid_input_from_invalid(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		$valid   = $this->manager->create( self::input() );
		$invalid = $this->manager->create(
			self::input(
				array(
					'name'     => '',
					'currency' => 'nope',
				)
			)
		);

		$this->assertWPError( $valid );
		$this->assertWPError( $invalid );
		$this->assertSame(
			$valid->get_error_code(),
			$invalid->get_error_code(),
			'Valid and invalid input must be indistinguishable to somebody who may not write either.'
		);
	}

	/**
	 * An update against a stale revision is a distinct, actionable error.
	 */
	public function test_a_stale_update_says_so(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$id = $this->manager->create( self::input() );

		$this->assertIsInt( $id );
		$this->assertTrue( $this->manager->update( $id, self::input( array( 'name' => 'First' ) ), 1 ) );

		$stale = $this->manager->update( $id, self::input( array( 'name' => 'Second' ) ), 1 );

		$this->assertWPError( $stale );
		$this->assertSame( 'aggr_conversion_definition_stale', $stale->get_error_code() );

		$row = $this->definitions->find( $id );

		$this->assertIsArray( $row );
		$this->assertSame( 'First', $row['name'] );
	}

	/**
	 * Updating something that never existed is a different error from a stale one.
	 */
	public function test_updating_a_missing_definition_says_so(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = $this->manager->update( 987654, self::input(), 1 );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_conversion_definition_not_found', $result->get_error_code() );
	}

	/**
	 * The audit context never carries the public key.
	 *
	 * It is the credential a page presents to report a conversion, and the
	 * audit log is read by more people and kept longer than the screen that
	 * shows it.
	 */
	public function test_the_audit_context_does_not_leak_the_public_key(): void {
		global $wpdb;

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$id = $this->manager->create( self::input() );

		$this->assertIsInt( $id );

		$row = $this->definitions->find( $id );

		$this->assertIsArray( $row );
		$this->assertNotSame( '', $row['public_key'], 'The fixture must have a key before its absence means anything.' );

		$table = $this->audit->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		$contexts = $wpdb->get_col( $wpdb->prepare( "SELECT context FROM {$table} WHERE object_type = %s", 'conversion_definition' ) );

		$this->assertNotEmpty( $contexts );

		foreach ( $contexts as $context ) {
			$this->assertStringNotContainsString( $row['public_key'], (string) $context );
		}
	}
}
