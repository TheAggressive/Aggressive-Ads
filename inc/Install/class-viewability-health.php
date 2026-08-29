<?php
/**
 * Whether viewability is being measured at all.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Reporting_Rules;
use Aggressive\Ads\Repository\Rollup_Repository;

/**
 * Reports the viewable-to-served ratio for the last closed UTC day.
 *
 * The portal tile shows the same `0.0%` whether nobody saw the ads or the
 * measuring script never ran, and those are very different problems. From the
 * site's side the second is knowable: a day that delivered impressions and
 * recorded no views at all is almost always a script that is not executing.
 *
 * Deliberately not organization-scoped. This answers an operator's question
 * about the installation, not an advertiser's about their campaign.
 */
final class Viewability_Health implements Service {

	/**
	 * Constructor.
	 *
	 * @param Rollup_Repository $rollups Reporting projection.
	 */
	public function __construct( private readonly Rollup_Repository $rollups ) {
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

		$tests['direct']['aggr_viewability'] = array(
			'label' => __( 'Advertising viewability is being measured', 'aggressive-ads' ),
			'test'  => array( $this, 'run_test' ),
		);

		return $tests;
	}

	/**
	 * Four answers, and only one of them is a problem.
	 *
	 * Nothing delivered and nothing measured are both ordinary states — a site
	 * that has not served an ad yet, or one whose first measured day has not
	 * closed. Saying "critical" to either is how a person learns to dismiss
	 * Site Health, and that cost is paid by the next warning that matters.
	 *
	 * @return array<string, mixed> Site Health direct-test result.
	 */
	public function run_test(): array {
		$day    = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$totals = $this->rollups->day_viewability( $day );

		if ( $totals['impressions'] <= 0 ) {
			return $this->result(
				'good',
				__( 'No advertisements were delivered yesterday', 'aggressive-ads' ),
				__( 'There is nothing to measure yet. This is normal on a new installation or a quiet site.', 'aggressive-ads' )
			);
		}

		if ( null === $totals['viewables'] ) {
			return $this->result(
				'good',
				__( 'Yesterday was not measured for viewability', 'aggressive-ads' ),
				__( 'These impressions predate viewability measurement. Days from here on will carry a rate.', 'aggressive-ads' )
			);
		}

		$rate = Reporting_Rules::viewability( $totals['impressions'], $totals['viewables'] );

		if ( null === $rate || 0.0 === $rate ) {
			return $this->result(
				'recommended',
				__( 'Advertisements were delivered yesterday but none were measured as seen', 'aggressive-ads' ),
				__( 'A zero rate across a whole day usually means the measuring script is not running rather than that nobody saw the advertisements. Check that the placement block is on the page and that JavaScript is not being stripped by a cache or optimizer.', 'aggressive-ads' )
			);
		}

		return $this->result(
			'good',
			sprintf(
				/* translators: %s: percentage of impressions measured as viewable. */
				__( '%s%% of yesterday\'s advertisements were seen', 'aggressive-ads' ),
				number_format_i18n( $rate * 100, 1 )
			),
			__( 'Viewability is being measured and reported.', 'aggressive-ads' )
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
			'test'        => 'aggr_viewability',
		);
	}
}
