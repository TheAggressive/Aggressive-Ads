<?php
/**
 * Identity map invariants.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Identity_Maps;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The maps must land on the identifiers the rest of the plugin actually uses,
 * and every new slug must fit the varchar(20) columns.
 */
final class IdentityMapsTest extends TestCase {

	/**
	 * New post types are exactly Post_Types::all().
	 *
	 * @return void
	 */
	public function test_post_type_values_match_the_live_slugs(): void {
		$this->assertSame( Post_Types::all(), array_values( Identity_Maps::post_types() ) );
	}

	/**
	 * New statuses are exactly Post_Statuses::all().
	 *
	 * @return void
	 */
	public function test_status_values_match_the_live_slugs(): void {
		$expected = Post_Statuses::all();
		$actual   = array_values( Identity_Maps::statuses() );
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual );
	}

	/**
	 * New table names are the schema constants.
	 *
	 * @return void
	 */
	public function test_table_values_match_the_schema(): void {
		$this->assertSame(
			array( Schema::AUDIT_TABLE, Schema::ORG_ACCESS_TABLE ),
			array_values( Identity_Maps::tables() )
		);
	}

	/**
	 * New role slugs are the Roles constants.
	 *
	 * @return void
	 */
	public function test_role_values_match_the_live_slugs(): void {
		$this->assertSame(
			array( Roles::ADVERTISER, Roles::REVIEWER ),
			array_values( Identity_Maps::roles() )
		);
	}

	/**
	 * Every capability this plugin grants has a migration source.
	 *
	 * @return void
	 */
	public function test_every_granted_capability_has_a_migration_source(): void {
		$mapped = array_values( Identity_Maps::capabilities() );

		foreach ( Capabilities::all() as $cap ) {
			$this->assertContains( $cap, $mapped, "{$cap} has no migration source." );
		}
	}

	/**
	 * Maps are 1:1. A collision would silently overwrite a row during migration.
	 *
	 * @return void
	 */
	public function test_maps_are_one_to_one(): void {
		foreach ( array(
			Identity_Maps::post_types(),
			Identity_Maps::statuses(),
			Identity_Maps::tables(),
			Identity_Maps::option_keys(),
			Identity_Maps::roles(),
			Identity_Maps::cron_hooks(),
			Identity_Maps::capabilities(),
		) as $map ) {
			$this->assertSame( $map, array_unique( $map ) );
			$this->assertSame( array(), array_intersect( array_keys( $map ), array_values( $map ) ) );
		}
	}

	/**
	 * New post types and statuses fit the MySQL columns.
	 *
	 * @return void
	 */
	public function test_new_slugs_fit_varchar_20(): void {
		foreach ( array_merge( Identity_Maps::post_types(), Identity_Maps::statuses() ) as $slug ) {
			$this->assertLessThanOrEqual( 20, strlen( $slug ), $slug );
		}
	}

	/**
	 * Previous slugs also fit, because they are still read during migration.
	 *
	 * @return void
	 */
	public function test_legacy_slugs_fit_varchar_20(): void {
		foreach ( array_merge( array_keys( Identity_Maps::post_types() ), array_keys( Identity_Maps::statuses() ) ) as $slug ) {
			$this->assertLessThanOrEqual( 20, strlen( $slug ), $slug );
		}
	}
}
