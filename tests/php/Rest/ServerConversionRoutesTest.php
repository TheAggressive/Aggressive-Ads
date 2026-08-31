<?php
/**
 * Server-to-server conversion reporting, end to end.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Conversion_Definition;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Conversion_Credential_Repository;
use Aggressive\Ads\Repository\Conversion_Definition_Repository;
use Aggressive\Ads\Repository\Conversion_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Conversion_Credential_Manager;
use Aggressive\Ads\Workflow\Fill_Token;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The credentialed half of P12.
 *
 * `allow_s2s` was stored, validated and exposed through REST for a whole phase
 * while nothing read it. Everything here goes through the real route with a
 * real credential, because the defect being fixed was precisely a stored flag
 * with no reader — a test that called the recorder directly would have been
 * satisfied by the same nothing.
 */
final class ServerConversionRoutesTest extends WP_UnitTestCase {

	/**
	 * Durable conversion ledger.
	 *
	 * @var Conversion_Repository
	 */
	private Conversion_Repository $conversions;

	/**
	 * What counts as a conversion.
	 *
	 * @var Conversion_Definition_Repository
	 */
	private Conversion_Definition_Repository $definitions;

	/**
	 * Credential persistence.
	 *
	 * @var Conversion_Credential_Repository
	 */
	private Conversion_Credential_Repository $credentials;

	/**
	 * Interaction lineage.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $events;

	/**
	 * Token minting and hashing.
	 *
	 * @var Fill_Token
	 */
	private Fill_Token $tokens;

	/**
	 * Production credential workflow.
	 *
	 * @var Conversion_Credential_Manager
	 */
	private Conversion_Credential_Manager $manager;

	/**
	 * Placement the fill was for.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Campaign owning the fill.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * Creative that was served.
	 *
	 * @var int
	 */
	private int $creative_id;

	/**
	 * Organization owning the campaign.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * A second organization, which must never be able to report into the first.
	 *
	 * @var int
	 */
	private int $other_org_id;

	/**
	 * Installs the tables, one live campaign and two organizations.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$container = Plugin::instance()->container();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->conversions = $container->get( Conversion_Repository::class );
		$this->definitions = $container->get( Conversion_Definition_Repository::class );
		$this->credentials = $container->get( Conversion_Credential_Repository::class );
		$this->events      = $container->get( Event_Repository::class );
		$this->tokens      = $container->get( Fill_Token::class );
		$this->manager     = $container->get( Conversion_Credential_Manager::class );

		$this->conversions->install_table();
		$this->definitions->install_table();
		$this->credentials->install_table();
		$this->events->install_table();
		$container->get( Rollup_Repository::class )->install_table();

		$this->org_id       = $this->organization( 'Bright Angle Media' );
		$this->other_org_id = $this->organization( 'Another Advertiser' );

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, '1' );

		$this->campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::LIVE,
			)
		);
		update_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, $this->org_id );

		$this->creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		$settings = $container->get( Settings::class );
		$document = $settings->get();
		$document['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] = true;
		$settings->save( $document );

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * **A credentialed report is recorded, with the value the reporter stated.**
	 *
	 * The whole point of the credential: an authenticated server knows what the
	 * order was worth, and an anonymous browser does not.
	 */
	public function test_a_credentialed_report_records_the_stated_value(): void {
		$token = $this->credential();

		$response = $this->report(
			$token,
			$this->body(
				array(
					'value_micros' => 12500000,
					'currency'     => 'USD',
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 1, $this->conversion_count() );
		$this->assertSame( 12500000, $this->stored_value() );
	}

	/**
	 * **Stating nothing falls back to the definition, not to zero.**
	 *
	 * A signup integration has no money to report, and a row worth 0 must mean
	 * "the definition says it is worth nothing" rather than "the reporter forgot".
	 */
	public function test_a_report_that_states_no_value_uses_the_definitions(): void {
		$token = $this->credential();

		$this->assertSame( 201, $this->report( $token, $this->body() )->get_status() );
		$this->assertSame( 4990000, $this->stored_value(), "The definition's default must survive." );
	}

	/**
	 * **The browser route cannot state a value, whatever it posts.**
	 *
	 * This is the property the whole two-route split exists for. `record()` has
	 * no parameter a value could arrive through, so posting one at the public
	 * endpoint changes nothing — and asserting it here means the split cannot be
	 * quietly collapsed back into one route with a conditional.
	 */
	public function test_the_browser_route_ignores_a_value_it_is_handed(): void {
		$request = new WP_REST_Request( 'POST', '/aggr/v1/conversions' );
		$request->set_body_params(
			array_merge(
				$this->body(),
				array(
					'value_micros' => 999000000,
					'currency'     => 'USD',
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame(
			4990000,
			$this->stored_value(),
			'An anonymous browser declared what its own conversion was worth.'
		);
	}

	/**
	 * **A definition that does not permit server reporting refuses one.**
	 *
	 * The read that `allow_s2s` never had.
	 */
	public function test_a_definition_that_forbids_server_reporting_refuses(): void {
		$token = $this->credential();

		$response = $this->report(
			$token,
			$this->body( array(), array( 'allow_s2s' => false ) )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'aggr_conversion_refused', $response->as_error()->get_error_code() );
		$this->assertSame( 0, $this->conversion_count() );
	}

	/**
	 * **A credential cannot report into another organization's definition.**
	 */
	public function test_a_credential_for_another_organization_is_refused(): void {
		$token = $this->credential( $this->other_org_id );

		$response = $this->report( $token, $this->body() );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, $this->conversion_count() );
	}

	/**
	 * **Every refusal is the same answer.**
	 *
	 * A credential holder must not be able to learn which public keys exist, or
	 * which of them belong to somebody else, by reading the differences between
	 * refusals.
	 */
	public function test_the_server_refusals_are_indistinguishable(): void {
		$mine    = $this->credential();
		$foreign = $this->credential( $this->other_org_id );

		$answers = array(
			$this->report( $mine, $this->body( array( 'definition' => str_repeat( 'a', 32 ) ) ) ),
			$this->report( $mine, $this->body( array(), array( 'allow_s2s' => false ) ) ),
			$this->report( $mine, $this->body( array(), array( 'status' => Conversion_Definition::STATUS_ARCHIVED ) ) ),
			$this->report( $foreign, $this->body() ),
		);

		foreach ( $answers as $index => $response ) {
			$this->assertSame( 400, $response->get_status(), "Refusal {$index} differed by status." );
			$this->assertSame(
				'aggr_conversion_refused',
				$response->as_error()->get_error_code(),
				"Refusal {$index} differed by code."
			);
		}

		$this->assertSame( 0, $this->conversion_count() );
	}

	/**
	 * **No credential, no report.**
	 */
	public function test_a_report_without_a_credential_is_unauthorized(): void {
		$request = new WP_REST_Request( 'POST', '/aggr/v1/conversions/server' );
		$request->set_body_params( $this->body() );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 0, $this->conversion_count() );
	}

	/**
	 * **A revoked credential stops working, and the attempt is recorded.**
	 *
	 * Revocation is the operator's only tool against a leaked secret, so this is
	 * the assertion the feature stands on. The audit row is the other half: it
	 * is how an operator learns the leak is still being used.
	 */
	public function test_a_revoked_credential_is_refused_and_audited(): void {
		$issued = $this->issue();
		$token  = $issued['token'];

		$this->assertSame( 201, $this->report( $token, $this->body() )->get_status() );

		wp_set_current_user( $this->staff() );
		$this->assertTrue( $this->manager->revoke( $issued['id'] ) );
		wp_set_current_user( 0 );

		$response = $this->report(
			$token,
			$this->body( array( 'idempotency_key' => 'order-2200-second' ) )
		);

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 1, $this->conversion_count(), 'A revoked credential recorded a conversion.' );
		$this->assertGreaterThan(
			0,
			$this->audit_rows( 'Revoked conversion credential was presented.' ),
			'Nothing told the operator that a revoked secret is still in use.'
		);
	}

	/**
	 * **A garbage bearer never reaches the database.**
	 *
	 * The shape is checked before any lookup, so an anonymous caller posting
	 * arbitrary bytes costs a regular expression rather than an indexed read.
	 */
	public function test_a_malformed_bearer_is_unauthorized(): void {
		$real = $this->credential();

		$headers = array(
			'not-a-token',
			'Bearer',
			'',
			'Basic abcdef',

			/*
			 * A real secret under the wrong scheme, and under none.
			 *
			 * Without these the scheme rule is untested: `Basic abcdef` is
			 * refused by the shape check whatever the scheme rule says, so
			 * deleting the scheme comparison changed nothing. Found by
			 * sabotaging it.
			 */
			'Basic ' . $real,
			$real,
			'Bearer ' . $real . ' extra',
		);

		foreach ( $headers as $header ) {
			$request = new WP_REST_Request( 'POST', '/aggr/v1/conversions/server' );
			$request->set_header( 'authorization', $header );
			$request->set_body_params( $this->body() );

			$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status(), $header );
		}

		$this->assertSame( 0, $this->conversion_count() );
	}

	/**
	 * **A well-formed secret this site never issued is refused as a credential.**
	 *
	 * Not as a bad report. The distinction matters because the shape check and
	 * the lookup are different guards: a token of the right length and charset
	 * gets past the first one, and only the database says it is not real.
	 * Without this, removing the `authenticate()` refusal left the request
	 * running on organization 0 and failing later as an ordinary 400 — which
	 * every other test accepted.
	 */
	public function test_an_unknown_but_well_formed_credential_is_unauthorized(): void {
		$unknown = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );

		$response = $this->report( $unknown, $this->body() );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'aggr_credential_invalid', $response->as_error()->get_error_code() );
		$this->assertSame( 0, $this->conversion_count() );
	}

	/**
	 * **A value without a currency is refused rather than assumed.**
	 *
	 * Defaulting the currency is how a shop denominated in euros silently
	 * reports dollars, and nothing downstream could ever detect it — the row
	 * would look exactly like a correct one.
	 */
	public function test_a_value_without_a_currency_is_refused(): void {
		$token = $this->credential();

		$response = $this->report( $token, $this->body( array( 'value_micros' => 12500000 ) ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'aggr_conversion_value_incomplete', $response->as_error()->get_error_code() );
		$this->assertSame( 0, $this->conversion_count() );
	}

	/**
	 * **A currency the definition does not use is refused, not converted.**
	 *
	 * This plugin holds no exchange rate, and two currencies under one
	 * definition make every total it produces a meaningless sum.
	 */
	public function test_a_currency_the_definition_does_not_use_is_refused(): void {
		$token = $this->credential();

		$response = $this->report(
			$token,
			$this->body(
				array(
					'value_micros' => 12500000,
					'currency'     => 'EUR',
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, $this->conversion_count() );
	}

	/**
	 * **A repeated report is success and counts once.**
	 *
	 * A retrying server is the normal case for this endpoint, and answering 409
	 * would make every correct integration look broken.
	 */
	public function test_a_repeated_report_counts_once(): void {
		$token = $this->credential();
		$body  = $this->body();

		$this->assertSame( 201, $this->report( $token, $body )->get_status() );
		$this->assertSame( 200, $this->report( $token, $body )->get_status() );
		$this->assertSame( 1, $this->conversion_count() );
	}

	/**
	 * **The row records that it arrived server-side.**
	 *
	 * Without it an operator cannot tell a reported conversion from a browser
	 * one, which is the first question after a value looks wrong.
	 */
	public function test_the_row_records_its_source(): void {
		$token = $this->credential();

		$this->report( $token, $this->body() );

		global $wpdb;
		$table = $this->conversions->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		$this->assertSame( 's2s', (string) $wpdb->get_var( "SELECT source FROM {$table} LIMIT 1" ) );
	}

	/**
	 * One organization.
	 *
	 * @param string $name Organization title.
	 */
	private function organization( string $name ): int {
		$org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);

		update_post_meta( $org_id, Org_Repository::META_OWNER_USER, 0 );

		return $org_id;
	}

	/**
	 * A user who may manage credentials.
	 */
	private function staff(): int {
		return (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Issues one credential through the production workflow.
	 *
	 * @param int $org_id Scope, defaulting to the campaign's organization.
	 * @return array{id: int, token: string}
	 */
	private function issue( int $org_id = 0 ): array {
		$previous = get_current_user_id();

		wp_set_current_user( $this->staff() );

		$issued = $this->manager->issue( $org_id > 0 ? $org_id : $this->org_id, 'Shop integration' );

		wp_set_current_user( $previous );

		$this->assertIsArray( $issued, 'The credential fixture failed, so nothing below is being tested.' );

		return $issued;
	}

	/**
	 * A credential's plaintext.
	 *
	 * @param int $org_id Scope, defaulting to the campaign's organization.
	 */
	private function credential( int $org_id = 0 ): string {
		return $this->issue( $org_id )['token'];
	}

	/**
	 * One definition's public key.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 */
	private function definition_key( array $overrides = array() ): string {
		$id = $this->definitions->create(
			array_merge(
				array(
					'name'                 => 'Purchase',
					'org_id'               => $this->org_id,
					'window_seconds'       => 2592000,
					'default_value_micros' => 4990000,
					'currency'             => 'USD',
					'allow_s2s'            => true,
					'status'               => Conversion_Definition::STATUS_ACTIVE,
				),
				$overrides
			)
		);

		$row = $this->definitions->find( $id );

		$this->assertIsArray( $row );

		return $row['public_key'];
	}

	/**
	 * Mints a token and records its click an hour ago.
	 */
	private function clicked_token(): string {
		$token = $this->tokens->mint( $this->placement_id, $this->campaign_id, $this->creative_id )['token'];
		$hash  = $this->tokens->hash( $token );

		$this->assertTrue(
			$this->events->insert(
				Event_Repository::TYPE_CLICK,
				$this->placement_id,
				$this->campaign_id,
				$this->creative_id,
				$hash,
				str_repeat( 'c', 64 )
			)
		);

		global $wpdb;
		$table = $this->events->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture control over an event timestamp.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_at_ts = %d WHERE token_hash = %s", time() - HOUR_IN_SECONDS, $hash ) );

		return $token;
	}

	/**
	 * A valid report body.
	 *
	 * @param array<string, mixed> $overrides            Body fields to replace.
	 * @param array<string, mixed> $definition_overrides Definition fields to replace.
	 * @return array<string, mixed>
	 */
	private function body( array $overrides = array(), array $definition_overrides = array() ): array {
		return array_merge(
			array(
				'token'           => $this->clicked_token(),
				'definition'      => $this->definition_key( $definition_overrides ),
				'idempotency_key' => 'order-1099-abcdef',
				'occurred_at'     => time(),
			),
			$overrides
		);
	}

	/**
	 * Dispatches one credentialed report.
	 *
	 * @param string               $token Bearer plaintext.
	 * @param array<string, mixed> $body  Request body.
	 */
	private function report( string $token, array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/aggr/v1/conversions/server' );
		$request->set_header( 'authorization', 'Bearer ' . $token );
		$request->set_body_params( $body );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Rows in the conversion ledger.
	 */
	private function conversion_count(): int {
		global $wpdb;

		$table = $this->conversions->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * The value stored on the only conversion row.
	 */
	private function stored_value(): int {
		global $wpdb;

		$table = $this->conversions->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		return (int) $wpdb->get_var( "SELECT value_micros FROM {$table} LIMIT 1" );
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
