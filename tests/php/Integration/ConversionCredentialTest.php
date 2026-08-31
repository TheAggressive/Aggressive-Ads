<?php
/**
 * Issuing and revoking server-to-server credentials.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Conversion_Credential;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Conversion_Credential_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Conversion_Credential_Manager;
use WP_UnitTestCase;

/**
 * What this issues is a bearer secret that reports into somebody's spend, so
 * the assertions here are about the two things that make that safe: it cannot
 * be read back, and it can be cut off.
 */
final class ConversionCredentialTest extends WP_UnitTestCase {

	/**
	 * Credential persistence.
	 *
	 * @var Conversion_Credential_Repository
	 */
	private Conversion_Credential_Repository $credentials;

	/**
	 * Production credential workflow.
	 *
	 * @var Conversion_Credential_Manager
	 */
	private Conversion_Credential_Manager $manager;

	/**
	 * Organization the credential reports for.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * Installs roles, the table and one organization.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$container = Plugin::instance()->container();

		$this->credentials = $container->get( Conversion_Credential_Repository::class );
		$this->manager     = $container->get( Conversion_Credential_Manager::class );

		$this->credentials->install_table();

		$this->org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Bright Angle Media',
			)
		);
		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, 0 );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * **The secret exists once, and nothing can produce it again.**
	 *
	 * "We cannot show you the token again" has to be a fact about the code
	 * rather than a policy in the interface, or somebody eventually adds a read
	 * route to be helpful.
	 */
	public function test_the_secret_is_never_readable_after_it_is_issued(): void {
		$issued = $this->manager->issue( $this->org_id, 'Shop integration' );

		$this->assertIsArray( $issued );
		$this->assertTrue( Conversion_Credential::is_valid_token( $issued['token'] ) );

		$row = $this->credentials->find( $issued['id'] );

		$this->assertIsArray( $row );
		$this->assertArrayNotHasKey( 'token_hash', $row, 'The verifier left the repository.' );
		$this->assertStringNotContainsString( $issued['token'], (string) wp_json_encode( $row ) );
		$this->assertStringNotContainsString( $issued['token'], (string) wp_json_encode( $this->credentials->all() ) );
	}

	/**
	 * **Two credentials are two different secrets.**
	 *
	 * A minting bug that returned a constant would pass every other test here:
	 * it would still verify, still revoke, and still be scoped correctly.
	 */
	public function test_each_credential_is_a_distinct_secret(): void {
		$first  = $this->manager->issue( $this->org_id, 'First' );
		$second = $this->manager->issue( $this->org_id, 'Second' );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertNotSame( $first['token'], $second['token'] );
		$this->assertNotSame( $first['id'], $second['id'] );
	}

	/**
	 * **A credential verifies, and stops verifying once revoked.**
	 */
	public function test_a_credential_verifies_until_it_is_revoked(): void {
		$issued = $this->manager->issue( $this->org_id, 'Shop integration' );

		$this->assertIsArray( $issued );

		$verified = $this->manager->authenticate( $issued['token'] );

		$this->assertIsArray( $verified );
		$this->assertSame( $this->org_id, $verified['org_id'] );

		$this->assertTrue( $this->manager->revoke( $issued['id'] ) );
		$this->assertNull( $this->manager->authenticate( $issued['token'] ) );
	}

	/**
	 * **Revoking twice is success, not a conflict.**
	 *
	 * The operator's intent — "this secret must not work" — is already
	 * satisfied, and a 409 during an incident sends somebody looking for a
	 * second problem that does not exist.
	 */
	public function test_revoking_twice_is_still_success(): void {
		$issued = $this->manager->issue( $this->org_id, 'Shop integration' );

		$this->assertIsArray( $issued );
		$this->assertTrue( $this->manager->revoke( $issued['id'] ) );
		$this->assertTrue( $this->manager->revoke( $issued['id'] ) );
	}

	/**
	 * **The first revocation time survives the second attempt.**
	 *
	 * That timestamp is the answer to "when did we cut this off" in an
	 * incident, and a second call overwriting it would move the answer.
	 */
	public function test_a_second_revocation_does_not_move_the_time(): void {
		$issued = $this->manager->issue( $this->org_id, 'Shop integration' );

		$this->assertIsArray( $issued );
		$this->assertTrue( $this->credentials->revoke( $issued['id'] ) );

		/*
		 * Backdated, and the test is worthless without it. Both revocations
		 * otherwise land in the same second, so an unguarded `UPDATE` writes the
		 * value that was already there, MySQL reports no affected rows, and the
		 * assertions below pass over a query with no `WHERE revoked_at_ts = 0`
		 * at all. Sabotaging the guard changed nothing until this line existed.
		 */
		$backdated = time() - DAY_IN_SECONDS;

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture control over a revocation time.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revoked_at_ts = %d WHERE id = %d',
				$this->credentials->table_name(),
				$backdated,
				$issued['id']
			)
		);

		$this->assertFalse(
			$this->credentials->revoke( $issued['id'] ),
			'A second revoke reported that it was the one that revoked it.'
		);

		$after = $this->credentials->find( $issued['id'] );

		$this->assertIsArray( $after );
		$this->assertSame(
			$backdated,
			$after['revoked_at_ts'],
			'A second revoke moved the answer to "when did we cut this off".'
		);
	}

	/**
	 * **Only staff who manage settings may issue or revoke.**
	 *
	 * Checked in the workflow as well as the route, because a route is one
	 * caller and a workflow that trusts having been reached grants whatever the
	 * next caller forgets to check.
	 */
	public function test_issuing_and_revoking_need_the_settings_capability(): void {
		$issued = $this->manager->issue( $this->org_id, 'Shop integration' );

		$this->assertIsArray( $issued );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		$denied_issue = $this->manager->issue( $this->org_id, 'Mine now' );

		$this->assertWPError( $denied_issue );
		$this->assertSame( 'aggr_forbidden', $denied_issue->get_error_code() );

		$denied_revoke = $this->manager->revoke( $issued['id'] );

		$this->assertWPError( $denied_revoke );
		$this->assertSame( 'aggr_forbidden', $denied_revoke->get_error_code() );

		$row = $this->credentials->find( $issued['id'] );

		$this->assertIsArray( $row );
		$this->assertSame( 0, $row['revoked_at_ts'], 'An advertiser revoked a credential.' );
	}

	/**
	 * **A credential may not be scoped to the publisher's own organization.**
	 *
	 * An org-0 definition accepts a conversion from any campaign, because the
	 * visitor reporting it is anonymous. A credential with that scope would be
	 * one integration able to report against every advertiser on the site.
	 */
	public function test_a_credential_cannot_be_scoped_to_organization_zero(): void {
		$denied = $this->manager->issue( 0, 'Everything' );

		$this->assertWPError( $denied );
		$this->assertSame( 'aggr_credential_org_invalid', $denied->get_error_code() );
	}

	/**
	 * **A credential may not be scoped to an organization that does not exist.**
	 */
	public function test_a_credential_cannot_be_scoped_to_a_missing_organization(): void {
		$denied = $this->manager->issue( 999999, 'Nobody' );

		$this->assertWPError( $denied );
		$this->assertSame( 'aggr_credential_org_invalid', $denied->get_error_code() );
	}

	/**
	 * **Issuing and revoking are both audited, and the secret is not.**
	 *
	 * The audit log is readable by `aggr_view_audit_log`, which was never meant
	 * to be a way of reporting conversions.
	 */
	public function test_the_decisions_are_audited_without_the_secret(): void {
		$issued = $this->manager->issue( $this->org_id, 'Shop integration' );

		$this->assertIsArray( $issued );
		$this->assertSame( 1, $this->audit_rows( 'Conversion credential issued.' ) );

		$this->assertTrue( $this->manager->revoke( $issued['id'] ) );
		$this->assertSame( 1, $this->audit_rows( 'Conversion credential revoked.' ) );

		global $wpdb;
		$table = ( new Audit_Repository() )->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion against this plugin's table.
		$rows = (string) wp_json_encode( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A ) );

		$this->assertStringNotContainsString( $issued['token'], $rows, 'The audit log carried the secret.' );
	}

	/**
	 * **A denied attempt is audited too.**
	 *
	 * Somebody without the capability trying to issue a reporting credential is
	 * exactly the event an operator wants to find later.
	 */
	public function test_a_denied_attempt_is_audited(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		$this->assertWPError( $this->manager->issue( $this->org_id, 'Mine now' ) );
		$this->assertSame( 1, $this->audit_rows( 'Conversion credential issue denied.' ) );
	}

	/**
	 * **A label has to say something.**
	 */
	public function test_a_credential_needs_a_usable_label(): void {
		$denied = $this->manager->issue( $this->org_id, '   ' );

		$this->assertWPError( $denied );
		$this->assertSame( 'aggr_credential_label_invalid', $denied->get_error_code() );
	}

	/**
	 * How many audit rows carry one message.
	 *
	 * @param string $message Audit message.
	 */
	private function audit_rows( string $message ): int {
		global $wpdb;

		$table = ( new Audit_Repository() )->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion against this plugin's table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE message = %s', $table, $message ) );
	}
}
