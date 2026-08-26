<?php
/**
 * Site Health: is the P2 backfill actually finished?
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;

/**
 * The one thing watching a migration nothing else reads yet.
 *
 * Native fill still selects Campaigns and Creative posts, which is what makes
 * the backfill safe to run on a live site — and also what makes it invisible.
 * A backfill that stalls, or that skips rows it should not, produces no symptom
 * at all until something depends on it, and by then the cause is several
 * releases old.
 *
 * So this counts. A gap is a number a publisher can see in Tools → Site Health
 * without knowing what a compatibility assignment is, and without anybody
 * having to build a shadow-read to find it.
 *
 * **What it does not do**, stated so nobody assumes otherwise: it compares
 * counts, not field values. It will notice a creative with no assignment; it
 * will not notice an assignment whose denormalized columns disagree with its
 * revision. That is deliberate for now — the editing surfaces read values from
 * the revision rather than the snapshot, so a disagreement has no consequence
 * until the write path creates revisions and serving reads this table. When
 * that changes, this check has to grow a field comparison, and that is the
 * moment to add it rather than now.
 */
final class Assignment_Health implements Service {

	/**
	 * Builds the check.
	 *
	 * @param Creative_Assignment_Repository $assignments Assignment persistence.
	 * @param Creative_Assignment_Migrator   $migrator    Backfill state.
	 */
	public function __construct(
		private readonly Creative_Assignment_Repository $assignments,
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

		$tests['direct']['aggr_creative_assignments'] = array(
			'label' => __( 'Every creative has a delivery assignment', 'aggressive-ads' ),
			'test'  => array( $this, 'run_test' ),
		);

		return $tests;
	}

	/**
	 * Counts creatives without an assignment.
	 *
	 * @return array<string, mixed> Site Health direct-test result.
	 */
	public function run_test(): array {
		if ( ! $this->assignments->table_exists() ) {
			return $this->result(
				'critical',
				__( 'The creative assignment table is missing', 'aggressive-ads' ),
				__( 'A database upgrade has not completed. Deactivating and reactivating the plugin runs it again.', 'aggressive-ads' )
			);
		}

		$missing = $this->assignments->creatives_without_assignment();

		if ( 0 === $missing ) {
			return $this->result(
				'good',
				__( 'Every creative has a delivery assignment', 'aggressive-ads' ),
				__( 'The delivery model upgrade has covered every creative on this site.', 'aggressive-ads' )
			);
		}

		/*
		 * Unfinished is not a fault. The backfill runs in batches on cron and a
		 * large catalogue legitimately takes many ticks, so a site part-way
		 * through is working exactly as designed and must not be told it has a
		 * problem. A *stalled* one is a different matter, and the difference is
		 * whether the backfill still considers itself running.
		 */
		if ( ! $this->migrator->is_complete() ) {
			return $this->result(
				'recommended',
				__( 'The delivery model upgrade is still running', 'aggressive-ads' ),
				sprintf(
					/* translators: %d: number of creatives not yet migrated. */
					__( '%d creatives are waiting. This runs in the background and nothing is affected while it does — advertising continues to serve normally.', 'aggressive-ads' ),
					$missing
				)
			);
		}

		return $this->result(
			'recommended',
			__( 'Some creatives have no delivery assignment', 'aggressive-ads' ),
			sprintf(
				/* translators: %d: number of creatives left behind. */
				__( '%d creatives were skipped by the delivery model upgrade, which reports itself finished. Advertising still serves normally. A creative with no campaign or placement is expected here; anything else is worth reporting.', 'aggressive-ads' ),
				$missing
			)
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
			'test'        => 'aggr_creative_assignments',
		);
	}
}
