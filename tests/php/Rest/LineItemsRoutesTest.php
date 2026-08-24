<?php
/**
 * Campaign line-item REST isolation and concurrency.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Line_Item_Validator;
use WP_REST_Request;
use WP_UnitTestCase;

final class LineItemsRoutesTest extends WP_UnitTestCase {

	/**
	 * Owning advertiser user id.
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * Unrelated advertiser user id.
	 *
	 * @var int
	 */
	private int $stranger;

	/**
	 * Owning campaign id.
	 *
	 * @var int
	 */
	private int $campaign;

	/**
	 * Line-item persistence.
	 *
	 * @var Line_Item_Repository
	 */
	private Line_Item_Repository $line_items;

	public function set_up(): void {
		parent::set_up();
		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();
		$this->owner    = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->stranger = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->campaign = $this->campaign_for( $this->owner, 'Owned campaign' );
		$this->campaign_for( $this->stranger, 'Other campaign' );
		$this->line_items = Plugin::instance()->container()->get( Line_Item_Repository::class );
		$this->line_items->install_table();
		Plugin::instance()->container()->get( Ownership::class )->flush_cache();
		do_action( 'rest_api_init', rest_get_server() );
	}

	public function test_owner_can_read_the_safe_campaign_scoped_shape(): void {
		wp_set_current_user( $this->owner );
		$response = $this->request( 'GET', "/campaigns/{$this->campaign}/line-items" );
		$row      = $response->get_data()['line_items'][0];

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Owned campaign', $row['name'] );
		$this->assertArrayNotHasKey( 'organization_id', $row );
		$this->assertArrayNotHasKey( 'default_key', $row );
	}

	public function test_other_tenant_and_missing_campaign_have_the_same_answer(): void {
		wp_set_current_user( $this->stranger );
		$forbidden = $this->request( 'GET', "/campaigns/{$this->campaign}/line-items" );
		$missing   = $this->request( 'GET', '/campaigns/999999/line-items' );

		$this->assertSame( 404, $forbidden->get_status() );
		$this->assertSame( wp_json_encode( $missing->get_data() ), wp_json_encode( $forbidden->get_data() ) );
	}

	public function test_owner_update_is_validated_audited_and_optimistically_locked(): void {
		wp_set_current_user( $this->owner );
		$row     = $this->line_items->ensure_default( $this->campaign );
		$path    = "/campaigns/{$this->campaign}/line-items/{$row['id']}";
		$invalid = $this->request(
			'PATCH',
			$path,
			array(
				'revision'     => 1,
				'daily_cap'    => 2000,
				'lifetime_cap' => 1000,
			)
		);
		$this->assertSame( 422, $invalid->get_status() );
		$this->assertSame( 'aggr_line_item_cap_invalid', $invalid->get_data()['code'] );

		$updated = $this->request(
			'PATCH',
			$path,
			array(
				'revision'      => 1,
				'pricing_model' => 'cpm',
				'pacing_mode'   => 'asap',
				'daily_cap'     => 1000,
			)
		);
		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( 2, $updated->get_data()['revision'] );
		$this->assertSame( 'cpm', $updated->get_data()['pricing_model'] );

		$stale = $this->request(
			'PATCH',
			$path,
			array(
				'revision' => 1,
				'weight'   => 999,
			)
		);
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 100, $this->line_items->default_for_campaign( $this->campaign )['weight'] );
	}

	public function test_cross_campaign_line_item_id_is_not_found(): void {
		wp_set_current_user( $this->stranger );
		$row      = $this->line_items->ensure_default( $this->campaign );
		$response = $this->request(
			'PATCH',
			"/campaigns/{$this->campaign}/line-items/{$row['id']}",
			array(
				'revision' => 1,
				'weight'   => 200,
			)
		);

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_empty_updates_and_fractional_numbers_are_rejected(): void {
		wp_set_current_user( $this->owner );
		$row   = $this->line_items->ensure_default( $this->campaign );
		$empty = $this->request(
			'PATCH',
			"/campaigns/{$this->campaign}/line-items/{$row['id']}",
			array( 'revision' => 1 )
		);

		$this->assertSame( 422, $empty->get_status() );
		$this->assertSame( 'aggr_line_item_fields_required', $empty->get_data()['code'] );

		$invalid = ( new Line_Item_Validator() )->validate( array( 'daily_cap' => '1.5' ), $row );
		$this->assertWPError( $invalid );
		$this->assertSame( 'aggr_line_item_value_invalid', $invalid->get_error_code() );
	}

	/**
	 * Malformed whole-number values, and what `absint()` would have made of them.
	 *
	 * Each is accepted by `is_numeric()`, which is the check these replace, and
	 * each becomes a different number once coerced. That is the failure being
	 * guarded: a lossy write reported as a successful one.
	 *
	 * @return array<string, array{mixed, int}>
	 */
	public static function lossy_values(): array {
		return array(
			'decimal string'   => array( '1.5', 1 ),
			'decimal float'    => array( 1.5, 1 ),
			'currency decimal' => array( '10.99', 10 ),
			'exponent'         => array( '1e3', 1000 ),
			'padded digits'    => array( ' 12 ', 12 ),
			'signed'           => array( '+5', 5 ),
			'trailing decimal' => array( '7.0', 7 ),
		);
	}

	/**
	 * The route refuses a lossy value rather than truncating it.
	 *
	 * Sent through the registered REST route, not handed to the validator.
	 * `Line_Item_Validator` never sees the transported form: `absint()` runs
	 * first as the argument's sanitize_callback, so by the time the validator is
	 * reached `"10.99"` is already the integer 10 and looks perfectly valid.
	 * Calling the validator directly therefore proves nothing about what a
	 * client can actually store, which is why this drives the transport.
	 *
	 * @dataProvider lossy_values
	 *
	 * @param mixed $sent     Value as a client would send it.
	 * @param int   $coerced  What absint() would have silently stored.
	 */
	public function test_the_route_refuses_a_lossy_whole_number( mixed $sent, int $coerced ): void {
		wp_set_current_user( $this->owner );

		$row = $this->line_items->ensure_default( $this->campaign );

		// Assert the fixture is real before asserting on it: a budget that
		// already equalled the coerced value would pass the final check for the
		// wrong reason.
		$this->assertNotSame( $coerced, (int) $row['budget_cents'] );

		$response = $this->request(
			'PATCH',
			"/campaigns/{$this->campaign}/line-items/{$row['id']}",
			array(
				'revision'     => (int) $row['revision'],
				'budget_cents' => $sent,
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );

		// And nothing was written. The status alone would pass if the route
		// rejected the request after already storing the truncated value.
		$after = $this->line_items->ensure_default( $this->campaign );

		$this->assertSame( (int) $row['budget_cents'], (int) $after['budget_cents'] );
		$this->assertSame( (int) $row['revision'], (int) $after['revision'] );
	}

	/**
	 * Every numeric field the phase names refuses a lossy value, not just one.
	 *
	 * The closure contract lists "amounts, caps, priority, weight and revision".
	 * They share two argument builders, so one field would exercise the logic —
	 * but not the wiring. A field wired to the wrong builder, or to none, is the
	 * regression this catches, and it is invisible to a test that only sends a
	 * budget.
	 */
	public function test_every_named_numeric_field_refuses_a_decimal(): void {
		wp_set_current_user( $this->owner );

		$fields = array(
			'goal_amount',
			'budget_cents',
			'daily_cap',
			'lifetime_cap',
			'priority',
			'weight',
		);

		foreach ( $fields as $field ) {
			$row = $this->line_items->ensure_default( $this->campaign );

			$response = $this->request(
				'PATCH',
				"/campaigns/{$this->campaign}/line-items/{$row['id']}",
				array(
					'revision' => (int) $row['revision'],
					$field     => '1.5',
				)
			);

			$this->assertSame(
				400,
				$response->get_status(),
				"{$field} accepted a decimal value."
			);
		}

		// `revision` is the same contract and cannot be sent alongside itself,
		// so it gets its own request.
		$row      = $this->line_items->ensure_default( $this->campaign );
		$response = $this->request(
			'PATCH',
			"/campaigns/{$this->campaign}/line-items/{$row['id']}",
			array(
				'revision'     => '1.5',
				'budget_cents' => 100,
			)
		);

		$this->assertSame( 400, $response->get_status(), 'revision accepted a decimal value.' );
	}

	/**
	 * Whole numbers still get through, in both forms a client may send.
	 *
	 * The negative half. A validator that rejected everything would satisfy
	 * every assertion above and break the route entirely.
	 */
	public function test_the_route_accepts_whole_numbers_as_int_and_as_string(): void {
		wp_set_current_user( $this->owner );

		foreach ( array( 2500, '3600' ) as $sent ) {
			$row = $this->line_items->ensure_default( $this->campaign );

			$response = $this->request(
				'PATCH',
				"/campaigns/{$this->campaign}/line-items/{$row['id']}",
				array(
					'revision'     => (int) $row['revision'],
					'budget_cents' => $sent,
				)
			);

			$this->assertSame( 200, $response->get_status() );

			$after = $this->line_items->ensure_default( $this->campaign );

			$this->assertSame( (int) $sent, (int) $after['budget_cents'] );
		}
	}

	private function campaign_for( int $user_id, string $title ): int {
		$org = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $org, Org_Repository::META_OWNER_USER, $user_id );
		$campaign = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
				'post_title'  => $title,
				'post_author' => $user_id,
			)
		);
		update_post_meta( $campaign, Campaign_Repository::META_ORG_ID, $org );

		return $campaign;
	}

	/**
	 * Dispatches one line-item REST request.
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
