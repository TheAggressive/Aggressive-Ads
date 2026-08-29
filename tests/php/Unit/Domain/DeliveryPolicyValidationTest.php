<?php
/**
 * A policy this engine cannot read must not be storable.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Frequency_Rules;
use Aggressive\Ads\Domain\Schedule_Rules;
use Aggressive\Ads\Domain\Targeting_Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Serve time is deliberately permissive: an unrecognised targeting node passes
 * rather than excluding, because refusing mid-fill would blank live inventory
 * over a typo. The cost of that choice is that a malformed rule targets nobody,
 * silently and forever — so the save has to be strict, and this is where that
 * strictness is defined.
 */
final class DeliveryPolicyValidationTest extends TestCase {

	/**
	 * Targeting trees and whether they are storable.
	 *
	 * @return array<string, array{mixed, bool}>
	 */
	public static function targeting_trees(): array {
		$leaf = array(
			'dimension' => 'country',
			'operator'  => 'eq',
			'value'     => 'US',
		);

		return array(
			'no targeting at all'          => array( array(), true ),
			'a bare leaf'                  => array( $leaf, true ),
			'a group'                      => array(
				array(
					'operator' => 'AND',
					'rules'    => array( $leaf ),
				),
				true,
			),
			'an empty group'               => array(
				array(
					'operator' => 'OR',
					'rules'    => array(),
				),
				true,
			),
			'presence needs no value'      => array(
				array(
					'dimension' => 'utm_source',
					'operator'  => 'exists',
				),
				true,
			),
			'not an object'                => array( 'nope', false ),
			'neither rules nor dimension'  => array( array( 'field' => 'country' ), false ),
			'unknown comparison'           => array(
				array(
					'dimension' => 'country',
					'operator'  => 'sounds_like',
					'value'     => 'US',
				),
				false,
			),
			'unknown group operator'       => array(
				array(
					'operator' => 'XOR',
					'rules'    => array( $leaf ),
				),
				false,
			),
			'empty dimension'              => array(
				array(
					'dimension' => '   ',
					'operator'  => 'eq',
					'value'     => 'US',
				),
				false,
			),
			'comparison with no value'     => array(
				array(
					'dimension' => 'country',
					'operator'  => 'eq',
				),
				false,
			),
			'in without a list'            => array(
				array(
					'dimension' => 'country',
					'operator'  => 'in',
					'value'     => 'US',
				),
				false,
			),
			'a malformed child in a group' => array(
				array(
					'operator' => 'AND',
					'rules'    => array( $leaf, array( 'field' => 'country' ) ),
				),
				false,
			),
		);
	}

	#[DataProvider( 'targeting_trees' )]
	public function test_targeting_trees_are_accepted_or_refused( mixed $tree, bool $valid ): void {
		$this->assertSame( $valid, array() === Targeting_Rules::validate( $tree ) );
	}

	/**
	 * The typo that motivated write-time validation.
	 *
	 * `field` instead of `dimension` is the natural mistake, and at serve time
	 * it passes — the node is unrecognised, so it excludes nobody and the rule
	 * silently does nothing. Caught here instead.
	 */
	public function test_the_field_typo_is_refused_rather_than_ignored(): void {
		$typo = array(
			'operator' => 'AND',
			'rules'    => array(
				array(
					'field' => 'country',
					'op'    => 'equals',
					'value' => 'US',
				),
			),
		);

		$this->assertNotSame( array(), Targeting_Rules::validate( $typo ) );

		// And the reason it matters: serve time lets it through.
		$this->assertTrue(
			Targeting_Rules::evaluate_node( $typo, array() ),
			'Serve time is expected to pass an unrecognised node; that is why the save must refuse it.'
		);
	}

	/** A tree deep enough to be expensive is refused rather than evaluated. */
	public function test_an_absurdly_deep_tree_is_refused(): void {
		$node = array(
			'dimension' => 'country',
			'operator'  => 'eq',
			'value'     => 'US',
		);

		for ( $i = 0; $i <= Targeting_Rules::MAX_DEPTH + 1; $i++ ) {
			$node = array(
				'operator' => 'AND',
				'rules'    => array( $node ),
			);
		}

		$this->assertNotSame( array(), Targeting_Rules::validate( $node ) );
	}

	/**
	 * Frequency policies and whether they are storable.
	 *
	 * @return array<string, array{mixed, bool}>
	 */
	public static function frequency_policies(): array {
		return array(
			'no policy'              => array( array(), true ),
			'a daily cap'            => array(
				array(
					'enabled'         => true,
					'max_impressions' => 3,
					'window'          => 'day',
					'level'           => 'line_item',
				),
				true,
			),
			'a custom window'        => array(
				array(
					'enabled'         => true,
					'max_impressions' => 1,
					'window'          => 'custom',
					'window_seconds'  => 1800,
					'level'           => 'creative',
				),
				true,
			),
			'disabled needs no max'  => array(
				array( 'enabled' => false ),
				true,
			),
			'not an object'          => array( 'nope', false ),
			'unknown window'         => array(
				array(
					'enabled'         => true,
					'max_impressions' => 3,
					'window'          => 'fortnight',
				),
				false,
			),
			'unknown level'          => array(
				array(
					'enabled'         => true,
					'max_impressions' => 3,
					'level'           => 'organization',
				),
				false,
			),
			'enabled without a max'  => array(
				array( 'enabled' => true ),
				false,
			),
			'a zero cap'             => array(
				array(
					'enabled'         => true,
					'max_impressions' => 0,
				),
				false,
			),
			'custom without seconds' => array(
				array(
					'enabled'         => true,
					'max_impressions' => 2,
					'window'          => 'custom',
				),
				false,
			),
		);
	}

	#[DataProvider( 'frequency_policies' )]
	public function test_frequency_policies_are_accepted_or_refused( mixed $config, bool $valid ): void {
		$this->assertSame( $valid, array() === Frequency_Rules::validate( $config ) );
	}

	/**
	 * Delivery settings and whether they are storable.
	 *
	 * @return array<string, array{mixed, bool}>
	 */
	public static function delivery_settings(): array {
		return array(
			'nothing set'            => array( array(), true ),
			'a real timezone'        => array( array( 'timezone' => 'America/New_York' ), true ),
			'a weekday morning'      => array(
				array(
					'dayparts' => array(
						array(
							'days'         => array( 1, 2, 3, 4, 5 ),
							'start_minute' => 540,
							'end_minute'   => 720,
						),
					),
				),
				true,
			),
			'midnight to midnight'   => array(
				array(
					'dayparts' => array(
						array(
							'start_minute' => 0,
							'end_minute'   => 1440,
						),
					),
				),
				true,
			),
			'not an object'          => array( 'nope', false ),
			'an invented timezone'   => array( array( 'timezone' => 'Middle/Earth' ), false ),
			'dayparts not a list'    => array( array( 'dayparts' => 'weekdays' ), false ),
			'a day out of range'     => array(
				array( 'dayparts' => array( array( 'days' => array( 9 ) ) ) ),
				false,
			),
			'a minute past midnight' => array(
				array( 'dayparts' => array( array( 'end_minute' => 1441 ) ) ),
				false,
			),
			'a negative minute'      => array(
				array( 'dayparts' => array( array( 'start_minute' => -1 ) ) ),
				false,
			),
		);
	}

	#[DataProvider( 'delivery_settings' )]
	public function test_delivery_settings_are_accepted_or_refused( mixed $settings, bool $valid ): void {
		$this->assertSame( $valid, array() === Schedule_Rules::validate_delivery_settings( $settings ) );
	}

	/**
	 * Every operator the evaluator implements is one the validator accepts.
	 *
	 * The two lists are written separately, and a comparison added to one and
	 * not the other would be either unreachable or unstorable — both of which
	 * look like the rule simply not working.
	 */
	public function test_the_validator_accepts_every_operator_the_evaluator_has(): void {
		foreach ( Targeting_Rules::comparison_operators() as $operator ) {
			$leaf = array(
				'dimension' => 'country',
				'operator'  => $operator,
				'value'     => array( 'US' ),
			);

			$this->assertSame(
				array(),
				Targeting_Rules::validate( $leaf ),
				"The validator refuses {$operator}, which the evaluator implements."
			);
		}
	}
}
