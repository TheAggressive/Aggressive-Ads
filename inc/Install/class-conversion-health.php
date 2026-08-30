<?php
/**
 * Whether conversion tracking is recording anything.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Conversion_Definition;
use Aggressive\Ads\Repository\Conversion_Definition_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;

/**
 * Tells an operator whether conversions can be recorded, and whether they are.
 *
 * Conversion tracking fails silently in a way delivery does not. An ad that
 * cannot serve leaves an empty slot somebody notices; a conversion that cannot
 * be recorded looks exactly like a campaign nobody converted on. The three
 * states below are indistinguishable from a report and completely different to
 * act on: no definition exists, a definition exists but nothing reports to it,
 * and everything works and the number is genuinely low.
 *
 * **Refusal reasons are not counted here, and that is a deliberate limit.**
 * `Conversion_Attribution` keeps them apart — an invalid lineage is abuse or a
 * bug, an out-of-window report is usually a window set too short — but a
 * refusal writes nothing, so counting them means a write per refused request on
 * a public endpoint, which is a cost an attacker chooses. See
 * `docs/open-work.md`; what is here is derived from data the site already has.
 *
 * Deliberately not organization-scoped, like the viewability test beside it.
 */
final class Conversion_Health implements Service {

	/**
	 * Constructor.
	 *
	 * @param Conversion_Definition_Repository $definitions What counts as a conversion.
	 * @param Rollup_Repository                $rollups     Reporting projection.
	 */
	public function __construct(
		private readonly Conversion_Definition_Repository $definitions,
		private readonly Rollup_Repository $rollups
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

		$tests['direct']['aggr_conversions'] = array(
			'label' => __( 'Advertising conversions are being recorded', 'aggressive-ads' ),
			'test'  => array( $this, 'run_test' ),
		);

		return $tests;
	}

	/**
	 * Five answers, and only two of them are worth acting on.
	 *
	 * A quiet site and a site that has defined no conversion are both ordinary,
	 * and calling either a problem is how a person learns to dismiss Site
	 * Health — a cost paid by the next warning that matters.
	 *
	 * @return array<string, mixed> Site Health direct-test result.
	 */
	public function run_test(): array {
		$accepting = $this->accepting_definitions();

		if ( 0 === $accepting ) {
			return $this->result(
				'recommended',
				__( 'No conversions are defined', 'aggressive-ads' ),
				__( 'Nothing can be recorded until a conversion exists. Add one under Advertising → Conversions, then put its reporting key on the page an advertiser sends people to.', 'aggressive-ads' )
			);
		}

		$day    = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$totals = $this->rollups->day_conversions( $day );

		if ( $totals['clicks'] <= 0 ) {
			return $this->result(
				'good',
				__( 'No advertisements were clicked yesterday', 'aggressive-ads' ),
				__( 'There is nothing to attribute yet. A conversion is only recorded against a click or a view, so this is normal on a new installation or a quiet site.', 'aggressive-ads' )
			);
		}

		if ( null === $totals['conversions'] ) {
			return $this->result(
				'good',
				__( 'Yesterday was not measured for conversions', 'aggressive-ads' ),
				__( 'These clicks predate conversion tracking. Days from here on will carry a count.', 'aggressive-ads' )
			);
		}

		if ( 0 === $totals['conversions'] ) {
			return $this->result(
				'recommended',
				__( 'Advertisements were clicked yesterday and no conversions were recorded', 'aggressive-ads' ),
				__( 'This can simply mean nobody converted. It can also mean the reporting key is not on the advertiser’s page, or that the click token is being stripped from the destination URL before it arrives. Check that a click lands on a URL carrying an aggr_ct parameter.', 'aggressive-ads' )
			);
		}

		return $this->result(
			'good',
			sprintf(
				/* translators: %s: number of conversions recorded. */
				_n( '%s conversion was recorded yesterday', '%s conversions were recorded yesterday', $totals['conversions'], 'aggressive-ads' ),
				number_format_i18n( $totals['conversions'] )
			),
			__( 'Conversions are being reported and attributed.', 'aggressive-ads' )
		);
	}

	/**
	 * How many definitions would accept a report right now.
	 *
	 * An archived definition is not a definition for this purpose: it exists,
	 * it is readable, and it refuses every report. Counting it would let a site
	 * that has retired everything look configured.
	 */
	private function accepting_definitions(): int {
		$accepting = 0;

		foreach ( $this->definitions->all() as $definition ) {
			if ( Conversion_Definition::accepts_reports( (string) $definition['status'] ) ) {
				++$accepting;
			}
		}

		return $accepting;
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
			'test'        => 'aggr_conversions',
		);
	}
}
