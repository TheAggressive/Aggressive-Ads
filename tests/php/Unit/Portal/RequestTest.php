<?php
/**
 * The portal URL grammar.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Portal;

use Aggressive\Ads\Portal\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The grammar decides whether a request is real at all, before a controller
 * runs. It calls no WordPress, so every hostile shape is cheap to check.
 */
final class RequestTest extends TestCase {

	/**
	 * An empty route is the dashboard.
	 *
	 * @return void
	 */
	public function test_an_empty_route_is_the_dashboard(): void {
		$request = Request::from( '' );

		$this->assertNotNull( $request );
		$this->assertTrue( $request->is_dashboard() );
		$this->assertSame( 0, $request->object_id );
	}

	/**
	 * Every declared route parses.
	 *
	 * @return void
	 */
	public function test_every_declared_route_parses(): void {
		foreach ( Request::routes() as $route ) {
			$this->assertNotNull( Request::from( $route ), "{$route} did not parse" );
		}
	}

	/**
	 * Public account screens are explicit and never object-scoped.
	 *
	 * @return void
	 */
	public function test_public_routes_are_not_object_scoped(): void {
		$this->assertSame(
			array(
				Request::ROUTE_LOGIN,
				Request::ROUTE_SIGNUP,
				Request::ROUTE_FORGOT_PASSWORD,
				Request::ROUTE_SET_PASSWORD,
				Request::ROUTE_CONFIRM_EMAIL,
			),
			Request::public_routes()
		);

		foreach ( Request::public_routes() as $route ) {
			$request = Request::from( $route );

			$this->assertNotNull( $request );
			$this->assertTrue( $request->is_public() );
			$this->assertNull( Request::from( $route, '42' ) );
		}
	}

	/**
	 * An object segment becomes an id.
	 *
	 * @return void
	 */
	public function test_an_object_segment_becomes_an_id(): void {
		$request = Request::from( 'campaigns', '412' );

		$this->assertNotNull( $request );
		$this->assertSame( 'campaigns', $request->route );
		$this->assertSame( 412, $request->object_id );
		$this->assertTrue( $request->has_object() );
		$this->assertFalse( $request->is_dashboard() );
	}

	/**
	 * A route nobody declared is not a route.
	 *
	 * An allowlist rather than a template lookup that happens to miss: a
	 * missing template is a 404 by accident, and accidents change when files
	 * are added.
	 *
	 * @return void
	 */
	public function test_an_undeclared_route_is_refused(): void {
		$this->assertNull( Request::from( 'admin' ) );
		$this->assertNull( Request::from( 'wp-login' ) );
		$this->assertNull( Request::from( 'campaign' ) );
	}

	/**
	 * Hostile segments never parse.
	 *
	 * @param string $route          Route segment.
	 * @param string $object_segment Object segment.
	 * @return void
	 */
	#[DataProvider( 'data_hostile_segments' )]
	public function test_hostile_segments_are_refused( string $route, string $object_segment ): void {
		$this->assertNull( Request::from( $route, $object_segment ) );
	}

	/**
	 * Segments that must never parse.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function data_hostile_segments(): array {
		return array(
			'traversal route'     => array( '../wp-admin', '' ),
			'traversal object'    => array( 'campaigns', '../../wp-config.php' ),
			'slash in route'      => array( 'campaigns/edit', '' ),
			'null byte'           => array( "campaigns\0", '' ),
			'newline'             => array( "campaigns\n", '' ),
			'object not numeric'  => array( 'campaigns', 'abc' ),
			'object zero'         => array( 'campaigns', '0' ),
			'object negative'     => array( 'campaigns', '-1' ),
			'object leading zero' => array( 'campaigns', '007' ),
			'object float'        => array( 'campaigns', '1.5' ),
			'sql-ish object'      => array( 'campaigns', '1 OR 1=1' ),
			'over-long route'     => array( str_repeat( 'a', Request::MAX_SEGMENT_LENGTH + 1 ), '' ),
			'over-long object'    => array( 'campaigns', str_repeat( '1', 40 ) ),
			'encoded traversal'   => array( '%2e%2e%2fwp-admin', '' ),
		);
	}

	/**
	 * A template name is derived, never taken from the URL.
	 *
	 * @return void
	 */
	public function test_template_names_are_derived(): void {
		$list   = Request::from( 'campaigns' );
		$detail = Request::from( 'campaigns', '9' );

		$this->assertNotNull( $list );
		$this->assertNotNull( $detail );
		$this->assertSame( 'campaigns.php', $list->template() );
		$this->assertSame( 'campaigns-detail.php', $detail->template() );
	}

	/**
	 * A template name can never contain a separator, whatever arrived.
	 *
	 * The router turns this into a path, so a segment that survived into a
	 * filename would be a traversal.
	 *
	 * @return void
	 */
	public function test_a_template_name_never_contains_a_separator(): void {
		foreach ( Request::routes() as $route ) {
			$objects = in_array( $route, Request::public_routes(), true ) ? array( '' ) : array( '', '5' );

			foreach ( $objects as $object ) {
				$request = Request::from( $route, $object );

				$this->assertNotNull( $request );
				$this->assertStringNotContainsString( '/', $request->template() );
				$this->assertStringNotContainsString( '\\', $request->template() );
				$this->assertStringNotContainsString( '..', $request->template() );
			}
		}
	}

	/**
	 * Surrounding whitespace is tolerated rather than fatal.
	 *
	 * @return void
	 */
	public function test_surrounding_whitespace_is_tolerated(): void {
		$request = Request::from( '  campaigns  ', ' 12 ' );

		$this->assertNotNull( $request );
		$this->assertSame( 12, $request->object_id );
	}
}
