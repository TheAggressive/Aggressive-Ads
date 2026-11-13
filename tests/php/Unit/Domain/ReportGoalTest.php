<?php
/**
 * Current goal units and schedule progress, independent of reporting windows.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Report_Goal;
use Aggressive\Ads\Domain\Pacing_Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportGoalTest extends TestCase {

	/**
	 * Pure cases name real absence and status conditions rather than supplying report labels.
	 *
	 * @param array<string, mixed> $changes Plan overrides.
	 * @param array{impressions: int, clicks: int, viewables: int|null, conversions: int|null}|null $totals Recorded native counts.
	 * @param int $now Snapshot clock.
	 * @param string $state Expected classification.
	 * @param int|null $actual Expected goal units.
	 */
	#[DataProvider( 'cases' )]
	public function test_current_goal_evaluation( array $changes, ?array $totals, int $now, string $state, ?int $actual ): void {
		$plan   = array_replace(
			array(
				'goal_type'   => 'impressions',
				'goal_amount' => 100,
				'pacing_mode' => 'even',
				'status'      => 'live',
				'start_at_ts' => 100,
				'end_at_ts'   => 200,
			),
			$changes
		);
		$result = Report_Goal::evaluate( $plan, $totals, $now );
		$this->assertSame( $state, $result['state'] );
		$this->assertSame( $actual, $result['actual'] );
		if ( null !== $result['completion'] ) {
			$this->assertSame( $actual / (int) $plan['goal_amount'], $result['completion'] );
		}
	}

	/**
	 * Literal inputs for the current snapshot, never fabricated historical goals.
	 *
	 * @return array<string, array{array<string, mixed>, array{impressions: int, clicks: int, viewables: int|null, conversions: int|null}|null, int, string, int|null}>
	 */
	public static function cases(): array {
		$half = array(
			'impressions' => 50,
			'clicks'      => 12,
			'viewables'   => 0,
			'conversions' => 3,
		);
		$zero = array(
			'impressions' => 0,
			'clicks'      => 0,
			'viewables'   => null,
			'conversions' => null,
		);
		return array(
			'no goal'                     => array( array( 'goal_type' => 'none' ), $half, 150, 'no_goal', null ),
			'zero target'                 => array( array( 'goal_amount' => 0 ), $half, 150, 'no_goal', 50 ),
			'unavailable history'         => array( array(), null, 150, 'not_measured', null ),
			'spend unavailable'           => array( array( 'goal_type' => 'spend' ), $half, 150, 'not_measured', null ),
			'share unavailable'           => array( array( 'goal_type' => 'share_of_voice' ), $half, 150, 'not_measured', null ),
			'clicks use clicks'           => array(
				array(
					'goal_type'   => 'clicks',
					'goal_amount' => 12,
				),
				$half,
				150,
				'met',
				12,
			),
			'conversions use conversions' => array(
				array(
					'goal_type'   => 'conversions',
					'goal_amount' => 3,
				),
				$half,
				150,
				'met',
				3,
			),
			'unmeasured conversions'      => array( array( 'goal_type' => 'conversions' ), $zero, 150, 'not_measured', null ),
			'measured zero'               => array( array(), $zero, 150, 'behind', 0 ),
			'within tolerance'            => array( array(), $half, 150, 'on_pace', 50 ),
			'upper tolerance boundary'    => array( array(), array_replace( $half, array( 'impressions' => 80 ) ), 170, 'on_pace', 80 ),
			'lower tolerance boundary'    => array( array(), array_replace( $half, array( 'impressions' => 70 ) ), 180, 'on_pace', 70 ),
			'ahead'                       => array( array(), $half, 120, 'ahead', 50 ),
			'behind'                      => array( array(), $half, 190, 'behind', 50 ),
			'goal met'                    => array( array( 'goal_amount' => 40 ), $half, 150, 'met', 50 ),
			'draft'                       => array( array( 'status' => 'draft' ), $half, 150, 'draft', 50 ),
			'ready'                       => array( array( 'status' => 'ready' ), $half, 150, 'ready', 50 ),
			'unknown mode'                => array( array( 'pacing_mode' => 'unknown' ), $half, 150, 'not_measured', 50 ),
			'unknown status'              => array( array( 'status' => 'unknown' ), $half, 150, 'not_measured', 50 ),
			'paused'                      => array( array( 'status' => 'paused' ), $half, 150, 'paused', 50 ),
			'completed short'             => array( array( 'status' => 'completed' ), $half, 210, 'completed', 50 ),
			'cancelled'                   => array( array( 'status' => 'cancelled' ), $half, 150, 'cancelled', 50 ),
			'not started'                 => array( array(), $half, 99, 'not_started', 50 ),
			'asap'                        => array( array( 'pacing_mode' => 'asap' ), $half, 150, 'asap', 50 ),
			'unscheduled'                 => array( array( 'end_at_ts' => 0 ), $half, 150, 'no_schedule', 50 ),
			'inverted schedule'           => array( array( 'end_at_ts' => 90 ), $half, 150, 'no_schedule', 50 ),
		);
	}

	/** Progress outside a flight is clamped, and serving/reporting share that fraction. */
	public function test_elapsed_flight_is_shared_and_bounded(): void {
		$this->assertSame( 0.0, Pacing_Rules::flight_progress( 100, 200, 50 ) );
		$this->assertSame( 0.5, Pacing_Rules::flight_progress( 100, 200, 150 ) );
		$this->assertSame( 1.0, Pacing_Rules::flight_progress( 100, 200, 250 ) );
		$this->assertSame( 0.0, Pacing_Rules::flight_progress( 100, 100, 150 ) );
	}
}
