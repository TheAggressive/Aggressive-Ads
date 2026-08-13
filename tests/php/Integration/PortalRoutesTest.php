<?php
/**
 * The portal URL grammar.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Portal\Routes;
use WP_UnitTestCase;

/**
 * Where the portal lives, and what a link to it looks like.
 *
 * Small, but the admin guard sends people here — a base segment that came back
 * empty or unsanitised would redirect advertisers to a URL that does not
 * resolve, which reads as the portal being broken.
 */
final class PortalRoutesTest extends WP_UnitTestCase {

	/**
	 * Removes any filter a test added.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'aggr_portal_base' );

		parent::tear_down();
	}

	/**
	 * The default base segment.
	 *
	 * @return void
	 */
	public function test_the_default_base(): void {
		$this->assertSame( 'advertiser', Routes::base() );
	}

	/**
	 * A site may move the portal.
	 *
	 * @return void
	 */
	public function test_the_base_is_filterable(): void {
		add_filter( 'aggr_portal_base', static fn (): string => 'advertisers' );

		$this->assertSame( 'advertisers', Routes::base() );
	}

	/**
	 * A filter returning something unusable falls back rather than producing a
	 * broken URL.
	 *
	 * @param mixed $value What the filter returns.
	 * @return void
	 *
	 * @dataProvider data_unusable_bases
	 */
	public function test_an_unusable_base_falls_back( $value ): void {
		add_filter( 'aggr_portal_base', static fn () => $value );

		$this->assertSame( 'advertiser', Routes::base() );
	}

	/**
	 * Filter returns that must not be used.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function data_unusable_bases(): array {
		return array(
			'empty'      => array( '' ),
			'whitespace' => array( '   ' ),
			'null'       => array( null ),
			'array'      => array( array( 'advertiser' ) ),
			'integer'    => array( 42 ),
			'slashes'    => array( '///' ),
		);
	}

	/**
	 * A hostile base is sanitised into a slug rather than used verbatim.
	 *
	 * @return void
	 */
	public function test_a_hostile_base_is_sanitised(): void {
		add_filter( 'aggr_portal_base', static fn (): string => '../../wp-admin' );

		$base = Routes::base();

		$this->assertStringNotContainsString( '..', $base );
		$this->assertStringNotContainsString( '/', $base );
	}

	/**
	 * The dashboard URL.
	 *
	 * @return void
	 */
	public function test_the_dashboard_url(): void {
		$this->assertSame( home_url( '/advertiser/' ), Routes::url() );
	}

	/**
	 * A route and an object build the documented three-segment grammar.
	 *
	 * @return void
	 */
	public function test_a_route_and_object_url(): void {
		$this->assertSame( home_url( '/advertiser/campaigns/' ), Routes::url( 'campaigns' ) );
		$this->assertSame( home_url( '/advertiser/campaigns/123/' ), Routes::url( 'campaigns', 123 ) );
	}

	/**
	 * A route segment is encoded, so it cannot add path segments of its own.
	 *
	 * @return void
	 */
	public function test_a_route_segment_cannot_add_path(): void {
		$url = Routes::url( '../wp-admin' );

		$this->assertStringNotContainsString( '/../', $url );
		$this->assertStringContainsString( '/advertiser/', $url );
	}

	/**
	 * A non-positive object id is left out rather than producing `/0/`.
	 *
	 * @return void
	 */
	public function test_a_zero_object_id_is_omitted(): void {
		$this->assertSame( home_url( '/advertiser/campaigns/' ), Routes::url( 'campaigns', 0 ) );
		$this->assertSame( home_url( '/advertiser/campaigns/' ), Routes::url( 'campaigns', -5 ) );
	}
}
