<?php
/**
 * Site Health: assignment backfill readiness for serving.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Workflow\Decision_Engine;

/**
 * Fill reads assignments only after the P2 backfill has finished.
 */
final class Decision_Health implements Service {

	/**
	 * Builds the health check.
	 *
	 * @param Decision_Engine              $engine   Serving readiness.
	 * @param Creative_Assignment_Migrator $migrator Backfill completion.
	 */
	public function __construct(
		private readonly Decision_Engine $engine,
		private readonly Creative_Assignment_Migrator $migrator
	) {
	}

	/** Registers the direct test. */
	public function init(): void {
		add_filter( 'site_status_tests', array( $this, 'register_test' ) );
	}

	/**
	 * Adds the test to Site Health.
	 *
	 * @param array<string, mixed> $tests Registered tests.
	 * @return array<string, mixed>
	 */
	public function register_test( array $tests ): array {
		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}

		$tests['direct']['aggr_decision_serving_path'] = array(
			'label' => __( 'Advertising serving path', 'aggressive-ads' ),
			'test'  => array( $this, 'run_test' ),
		);

		return $tests;
	}

	/**
	 * Reports whether assignment serving is ready.
	 *
	 * @return array<string, mixed> Site Health direct-test result.
	 */
	public function run_test(): array {
		if ( $this->engine->serving_ready() ) {
			return $this->result(
				'good',
				__( 'Native fill is serving from creative assignments', 'aggressive-ads' ),
				__( 'The assignment backfill has finished and the decision engine is the only serving path.', 'aggressive-ads' )
			);
		}

		if ( ! $this->migrator->is_complete() ) {
			return $this->result(
				'recommended',
				__( 'The assignment backfill is still running', 'aggressive-ads' ),
				__( 'Paid slots stay empty until every creative has a delivery assignment. House ads still serve when configured.', 'aggressive-ads' )
			);
		}

		return $this->result(
			'critical',
			__( 'The creative assignment table is not ready', 'aggressive-ads' ),
			__( 'The assignment backfill reports finished but the serving table is missing. Deactivate and reactivate the plugin to repair the schema.', 'aggressive-ads' )
		);
	}

	/**
	 * Builds a Site Health result.
	 *
	 * @param string $status      Site Health status.
	 * @param string $label       Result heading.
	 * @param string $description Result explanation.
	 * @return array<string, mixed>
	 */
	private function result( string $status, string $label, string $description ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Advertising', 'aggressive-ads' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '',
			'test'        => 'aggr_decision_serving_path',
		);
	}
}
