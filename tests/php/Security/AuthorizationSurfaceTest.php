<?php
/**
 * Closed authorization contract for the REST namespace.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Security;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Makes every new REST surface an explicit authorization decision.
 */
final class AuthorizationSurfaceTest extends WP_UnitTestCase {

	/** Register the plugin routes on the real WordPress REST server. */
	public function set_up(): void {
		parent::set_up();

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * The complete method and route inventory under the plugin namespace.
	 *
	 * @return list<string>
	 */
	private static function expected_handlers(): array {
		return array(
			'DELETE /aggr/v1/campaigns/(?P<campaign_id>\d+)/creative-assignments/(?P<id>\d+)/assignment',
			'DELETE /aggr/v1/creative-replacements/(?P<id>\d+)',
			'DELETE /aggr/v1/creatives/(?P<id>\d+)',
			'DELETE /aggr/v1/organizations/(?P<id>\d+)/members/(?P<user_id>\d+)',
			'DELETE /aggr/v1/settings/reviewers/(?P<id>\d+)',
			'GET /aggr/v1/campaigns',
			'GET /aggr/v1/campaigns/(?P<campaign_id>\d+)/creative-assignments',
			'GET /aggr/v1/campaigns/(?P<campaign_id>\d+)/line-items',
			'GET /aggr/v1/campaigns/(?P<id>\d+)',
			'GET /aggr/v1/conversion-definitions',
			'GET /aggr/v1/creatives/(?P<id>\d+)/file',
			'GET /aggr/v1/fill/(?P<slot>[a-z0-9-]+)',
			'GET /aggr/v1/organizations',
			'GET /aggr/v1/organizations/(?P<id>\d+)/detail',
			'GET /aggr/v1/packages',
			'GET /aggr/v1/placements',
			'GET /aggr/v1/placements/(?P<id>\d+)/decision',
			'GET /aggr/v1/review/campaigns/(?P<id>\d+)',
			'GET /aggr/v1/review/queue',
			'PATCH /aggr/v1/campaigns/(?P<id>\d+)',
			'PATCH /aggr/v1/campaigns/(?P<campaign_id>\d+)/creative-assignments/(?P<id>\d+)',
			'PATCH /aggr/v1/campaigns/(?P<campaign_id>\d+)/line-items/(?P<id>\d+)',
			'PATCH /aggr/v1/conversion-definitions/(?P<id>\d+)',
			'PATCH /aggr/v1/organizations/(?P<id>\d+)',
			'PATCH /aggr/v1/packages/(?P<id>\d+)',
			'PATCH /aggr/v1/placements/(?P<id>\d+)',
			'POST /aggr/v1/acting-as',
			'POST /aggr/v1/campaigns',
			'POST /aggr/v1/campaigns/for-advertiser',
			'POST /aggr/v1/campaigns/(?P<id>\d+)/copy',
			'POST /aggr/v1/campaigns/(?P<id>\d+)/creatives',
			'POST /aggr/v1/campaigns/(?P<id>\d+)/transitions',
			'POST /aggr/v1/conversion-definitions',
			'POST /aggr/v1/conversions',
			'POST /aggr/v1/creative-replacements/(?P<id>\d+)/decision',
			'POST /aggr/v1/creatives/(?P<id>\d+)/replacement',
			'POST /aggr/v1/decisions',
			'POST /aggr/v1/i',
			'POST /aggr/v1/organizations/(?P<id>\d+)/members',
			'POST /aggr/v1/organizations/(?P<id>\d+)/owner',
			'POST /aggr/v1/organizations/(?P<id>\d+)/state',
			'POST /aggr/v1/packages/catalogue',
			'POST /aggr/v1/placements/catalogue',
			'POST /aggr/v1/review/creatives/(?P<id>\d+)/publish',
			'POST /aggr/v1/review/campaigns/(?P<id>\d+)/changes',
			'POST /aggr/v1/review/campaigns/(?P<id>\d+)/notes',
			'POST /aggr/v1/review/campaigns/(?P<id>\d+)/request',
			'POST /aggr/v1/settings',
			'POST /aggr/v1/settings/reviewers',
		);
	}

	/**
	 * Plugin route handlers, keyed by method and route pattern.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function handlers(): array {
		$handlers = array();

		foreach ( rest_get_server()->get_routes() as $route => $endpoints ) {
			if ( ! str_starts_with( $route, '/aggr/v1/' ) ) {
				continue;
			}

			foreach ( $endpoints as $endpoint ) {
				if ( ! is_array( $endpoint ) || ! isset( $endpoint['methods'] ) || ! is_array( $endpoint['methods'] ) ) {
					continue;
				}

				foreach ( array_keys( array_filter( $endpoint['methods'] ) ) as $method ) {
					$handlers[ $method . ' ' . $route ] = $endpoint;
				}
			}
		}

		ksort( $handlers );

		return $handlers;
	}

	/** Every route is inventoried and every method has a callable real gate. */
	public function test_every_route_and_method_has_an_explicit_permission_callback(): void {
		$handlers = $this->handlers();
		$expected = self::expected_handlers();
		sort( $expected );

		$this->assertSame( $expected, array_keys( $handlers ), 'The REST surface changed without an authorization review.' );

		foreach ( $handlers as $key => $handler ) {
			$this->assertArrayHasKey( 'permission_callback', $handler, $key );
			$this->assertIsCallable( $handler['permission_callback'], $key );
			$this->assertNotSame( '__return_true', $handler['permission_callback'], $key );
		}
	}

	/**
	 * Anonymous and bare authenticated callers reach only native delivery.
	 *
	 * A subscriber is deliberately used instead of an advertiser: merely being
	 * authenticated must not satisfy a feature gate. The two public endpoints
	 * remain allowlisted because cached page delivery cannot use a WP session.
	 */
	public function test_default_deny_for_anonymous_and_bare_authenticated_callers(): void {
		$public = array(
			'GET /aggr/v1/fill/(?P<slot>[a-z0-9-]+)',
			'POST /aggr/v1/decisions',
			'POST /aggr/v1/i',

			/*
			 * Reported by the advertiser's own page, so it cannot use a
			 * WordPress session either — and unlike the beacon it is not even
			 * same-origin. What protects it is not authentication: the request
			 * carries a signed token it cannot forge, spends one outcome
			 * exactly once against a database unique key, and can only credit a
			 * definition the campaign's organization owns.
			 */
			'POST /aggr/v1/conversions',
		);

		foreach ( array( 0, self::factory()->user->create( array( 'role' => 'subscriber' ) ) ) as $user_id ) {
			wp_set_current_user( $user_id );

			foreach ( $this->handlers() as $key => $handler ) {
				list( $method, $route ) = explode( ' ', $key, 2 );

				$request = new WP_REST_Request( $method, $route );
				$result  = call_user_func( $handler['permission_callback'], $request );
				$allowed = true === $result;

				$this->assertSame( in_array( $key, $public, true ), $allowed, $key . ' violated the default-deny contract.' );
			}
		}
	}
}
