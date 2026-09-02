<?php
/**
 * Operational checks for native delivery throughput and durability.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Security;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Workflow\Event_Retention;
use Aggressive\Ads\Workflow\Fill_Cache;
use Aggressive\Ads\Workflow\Rollup_Reconciler;

/**
 * Makes production delivery dependencies visible before traffic finds them.
 */
final class Delivery_Health implements Service {

	/**
	 * Constructor.
	 *
	 * @param Event_Repository  $events  Event ledger schema.
	 * @param Rollup_Repository $rollups Reporting projection schema.
	 */
	public function __construct(
		private readonly Event_Repository $events,
		private readonly Rollup_Repository $rollups
	) {
	}

	/** Registers the direct Site Health test. */
	public function init(): void {
		add_filter( 'site_status_tests', array( $this, 'register_test' ) );
	}

	/**
	 * Adds the delivery capacity test without replacing other checks.
	 *
	 * @param array<string, mixed> $tests Existing Site Health tests.
	 * @return array<string, mixed>
	 */
	public function register_test( array $tests ): array {
		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}

		$tests['direct']['aggr_delivery_capacity'] = array(
			'label' => __( 'Advertising delivery has its production dependencies', 'aggressive-ads' ),
			'test'  => array( $this, 'run_test' ),
		);

		return $tests;
	}

	/**
	 * Verifies durable tables, persistent cache capacity, and maintenance cron.
	 *
	 * @return array<string, mixed> Site Health result.
	 */
	public function run_test(): array {
		if ( ! $this->events->table_exists() || ! $this->rollups->table_exists() ) {
			return $this->result(
				'critical',
				__( 'Advertising tracking tables are missing', 'aggressive-ads' ),
				__( 'Run the plugin database upgrade before serving ads. Impressions and clicks cannot be durably recorded.', 'aggressive-ads' )
			);
		}

		if ( ! wp_using_ext_object_cache() ) {
			return $this->result(
				'recommended',
				__( 'Persistent object caching is required for high-volume advertising', 'aggressive-ads' ),
				__( 'Install and monitor Redis or Memcached before operating large placements. Without it, candidate lists rebuild in each PHP request and rate limits use database locks.', 'aggressive-ads' )
			);
		}

		$probe_key = 'aggr_health_' . wp_generate_uuid4();
		$count_key = $probe_key . '_counter';
		$probe     = range( 1, 1_000 );
		$written   = wp_cache_set( $probe_key, $probe, Fill_Cache::GROUP, MINUTE_IN_SECONDS );
		$read      = wp_cache_get( $probe_key, Fill_Cache::GROUP );
		$added     = wp_cache_add( $count_key, 0, Fill_Cache::GROUP, MINUTE_IN_SECONDS );
		$count     = wp_cache_incr( $count_key, 1, Fill_Cache::GROUP );
		wp_cache_delete( $probe_key, Fill_Cache::GROUP );
		wp_cache_delete( $count_key, Fill_Cache::GROUP );

		if ( ! $written || $probe !== $read || ! $added || 1 !== $count ) {
			return $this->result(
				'critical',
				__( 'Persistent advertising cache failed its capacity probe', 'aggressive-ads' ),
				__( 'The configured object cache could not round-trip a representative 1,000-creative identifier list and atomically increment a rate-limit counter. Check cache connectivity, serialization, item limits, and atomic increment support.', 'aggressive-ads' )
			);
		}

		if ( false === wp_next_scheduled( Rollup_Reconciler::HOOK ) || false === wp_next_scheduled( Event_Retention::HOOK ) ) {
			return $this->result(
				'recommended',
				__( 'Advertising maintenance jobs are not scheduled', 'aggressive-ads' ),
				__( 'Configure a real system cron to invoke WordPress cron and confirm that rollup reconciliation and bounded event retention remain scheduled.', 'aggressive-ads' )
			);
		}

		return $this->result(
			'good',
			__( 'Advertising delivery dependencies are healthy', 'aggressive-ads' ),
			__( 'Tracking tables exist, the 1,000-creative and atomic-counter cache probes succeeded, and projection and retention jobs are scheduled.', 'aggressive-ads' ) . $this->projection_state()
		);
	}

	/**
	 * How far the projection is reconciled, and which code wrote it.
	 *
	 * Both questions were previously answerable only by opening the database,
	 * which is what P13's observability contract says must not be necessary:
	 * "is this day final" and "which projector produced these numbers" are the
	 * two an operator asks when a report looks wrong.
	 *
	 * **Reported in the description, never in the status**, and that is a
	 * decision rather than caution. More than one projector version present is
	 * the *expected* state during a rollout — the reconciler rewrites older
	 * days over the following nights — so raising it to `recommended` would
	 * fire on every legitimate upgrade until it caught up. Noise is what
	 * teaches people to stop reading Site Health.
	 *
	 * The versions are exact rather than approximate, unlike the conversion
	 * refusal counts, because they come from a `DISTINCT` over the projection
	 * rather than from a lossy counter.
	 */
	private function projection_state(): string {
		$through = (string) get_option( Rollup_Reconciler::OPTION, '' );

		$state = '' === $through
			? ' ' . __( 'No day has been reconciled yet.', 'aggressive-ads' )
			: ' ' . sprintf(
				/* translators: %s: last fully reconciled UTC day, e.g. 2026-09-01. */
				__( 'Reconciled through %s (UTC).', 'aggressive-ads' ),
				esc_html( $through )
			);

		$versions = $this->rollups->projector_versions();

		if ( count( $versions ) < 2 ) {
			return $state;
		}

		return $state . ' ' . sprintf(
			/* translators: %s: comma-separated projector version numbers, e.g. 1, 2. */
			__( 'Counters were written by more than one projector (%s). This is normal while an upgrade is still reconciling older days; if it persists, those days were written by code that is no longer running and can be reprojected.', 'aggressive-ads' ),
			esc_html( implode( ', ', array_map( 'strval', $versions ) ) )
		);
	}

	/**
	 * Builds the common Site Health result shape.
	 *
	 * @param string $status      good|recommended|critical.
	 * @param string $label       Result heading.
	 * @param string $description Result explanation.
	 * @return array<string, mixed>
	 */
	private function result( string $status, string $label, string $description ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Performance', 'aggressive-ads' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '',
			'test'        => 'aggr_delivery_capacity',
		);
	}
}
