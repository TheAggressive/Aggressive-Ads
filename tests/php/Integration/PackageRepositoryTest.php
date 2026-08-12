<?php
/**
 * Package catalogue persistence.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Integration;

use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Repository\Package_Repository;
use WP_UnitTestCase;

/**
 * Package_Repository against WordPress metadata and queries.
 */
final class PackageRepositoryTest extends WP_UnitTestCase {

	/**
	 * Active entries are ordered and inactive entries remain private.
	 *
	 * @return void
	 */
	public function test_active_catalogue_is_bounded_ordered_and_complete(): void {
		$repository  = new Package_Repository();
		$placement_a = self::factory()->post->create( array( 'post_type' => Post_Types::PLACEMENT ) );
		$placement_b = self::factory()->post->create( array( 'post_type' => Post_Types::PLACEMENT ) );
		$inactive    = $this->make_package( 'Hidden package', false );
		$zulu        = $this->make_package( 'Zulu package', true );
		$alpha       = $this->make_package( 'Alpha package', true );

		add_post_meta( $alpha, Package_Repository::META_PLACEMENT_ID, $placement_a );
		add_post_meta( $alpha, Package_Repository::META_PLACEMENT_ID, $placement_b );
		add_post_meta( $alpha, Package_Repository::META_PLACEMENT_ID, $placement_a );
		update_post_meta( $alpha, Package_Repository::META_DURATION_DAYS, 30 );
		update_post_meta( $alpha, Package_Repository::META_PRICE_CENTS, 12500 );
		update_post_meta( $alpha, Package_Repository::META_CURRENCY, 'usd' );
		update_post_meta( $alpha, Package_Repository::META_IS_DEFAULT, 1 );
		update_post_meta( $zulu, Package_Repository::META_CUSTOM_DURATION, 1 );

		$this->assertSame( array( $alpha, $zulu ), $repository->active_ids() );
		$this->assertNotContains( $inactive, $repository->active_ids() );
		$this->assertSame( array( $placement_a, $placement_b ), $repository->placement_ids( $alpha ) );
		$this->assertSame( 'Alpha package', $repository->name( $alpha ) );
		$this->assertSame( 30, $repository->duration_days( $alpha ) );
		$this->assertSame( 12500, $repository->price_cents( $alpha ) );
		$this->assertSame( 'USD', $repository->currency( $alpha ) );
		$this->assertSame( $alpha, $repository->default_id() );
		$this->assertFalse( $repository->has_custom_duration( $alpha ) );
		$this->assertTrue( $repository->has_custom_duration( $zulu ) );
	}

	/**
	 * A post of another type never becomes a package through metadata alone.
	 *
	 * @return void
	 */
	public function test_exists_checks_the_post_type(): void {
		$repository = new Package_Repository();
		$post_id    = self::factory()->post->create();

		update_post_meta( $post_id, Package_Repository::META_IS_ACTIVE, 1 );

		$this->assertFalse( $repository->exists( $post_id ) );
		$this->assertFalse( $repository->is_active( $post_id ) );
	}

	/**
	 * Makes one package fixture.
	 *
	 * @param string $title  Display title.
	 * @param bool   $active Whether it is offered.
	 * @return int
	 */
	private function make_package( string $title, bool $active ): int {
		$package_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PACKAGE,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		update_post_meta( $package_id, Package_Repository::META_IS_ACTIVE, $active ? 1 : 0 );

		return $package_id;
	}
}
