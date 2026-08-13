<?php
/**
 * Capability alias behaviour.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Security;

use Aggressive\Ads\Security\Capability_Alias;
use stdClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The alias must be symmetric: holding either name answers both checks.
 */
final class CapabilityAliasTest extends TestCase {

	/**
	 * A role that still holds the previous publish cap can use the new name.
	 *
	 * @return void
	 */
	public function test_legacy_publish_cap_satisfies_the_new_name(): void {
		$alias = new Capability_Alias();

		$result = $alias->alias(
			array( 'laao_ads_publish_to_adsanity' => true ),
			array(),
			array(),
			new stdClass()
		);

		$this->assertTrue( $result['aggr_publish'] );
		$this->assertTrue( $result['laao_ads_publish_to_adsanity'] );
	}

	/**
	 * A role granted the new name still satisfies a check for the previous one.
	 *
	 * @return void
	 */
	public function test_new_publish_cap_satisfies_the_legacy_name(): void {
		$alias = new Capability_Alias();

		$result = $alias->alias(
			array( 'aggr_publish' => true ),
			array(),
			array(),
			new stdClass()
		);

		$this->assertTrue( $result['aggr_publish'] );
		$this->assertTrue( $result['laao_ads_publish_to_adsanity'] );
	}

	/**
	 * Generated post-type capabilities are aliased the same way.
	 *
	 * @return void
	 */
	public function test_generated_post_type_caps_are_aliased(): void {
		$alias = new Capability_Alias();

		$result = $alias->alias(
			array( 'edit_laao_ads_campaigns' => true ),
			array(),
			array(),
			new stdClass()
		);

		$this->assertTrue( $result['edit_aggr_campaigns'] );
		$this->assertTrue( $result['edit_laao_ads_campaigns'] );
	}
}
