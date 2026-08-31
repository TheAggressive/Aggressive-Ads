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
use Aggressive\Ads\Domain\Conversion_Attribution;
use Aggressive\Ads\Repository\Conversion_Definition_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Workflow\Conversion_Metrics;

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
 * **Refusal reasons are counted, and they are the second half of the answer.**
 * The five states above say whether anything is being recorded; the refusals
 * say why not, and they are not variations on one problem — an invalid lineage
 * is abuse or a bug, an out-of-window report is usually a window set too short,
 * and a server report to a definition that does not permit one is a switch
 * nobody turned on. `Conversion_Metrics` records why counting them is
 * affordable.
 *
 * **They inform the description and never the status.** The counts are
 * approximate by construction, and a status is what makes somebody act. What
 * decides good-or-recommended stays derived from the ledger and the rollup,
 * which are exact.
 *
 * Deliberately not organization-scoped, like the viewability test beside it.
 */
final class Conversion_Health implements Service {

	/**
	 * Constructor.
	 *
	 * @param Conversion_Definition_Repository $definitions What counts as a conversion.
	 * @param Rollup_Repository                $rollups     Reporting projection.
	 * @param Conversion_Metrics               $metrics     Refusal counters.
	 */
	public function __construct(
		private readonly Conversion_Definition_Repository $definitions,
		private readonly Rollup_Repository $rollups,
		private readonly Conversion_Metrics $metrics
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
					. $this->refusals()
			);
		}

		return $this->result(
			'good',
			sprintf(
				/* translators: %s: number of conversions recorded. */
				_n( '%s conversion was recorded yesterday', '%s conversions were recorded yesterday', $totals['conversions'], 'aggressive-ads' ),
				number_format_i18n( $totals['conversions'] )
			),
			__( 'Conversions are being reported and attributed.', 'aggressive-ads' ) . $this->refusals()
		);
	}

	/**
	 * What was refused, in words, or nothing at all when nothing was.
	 *
	 * Appended to a description rather than given its own result, because a
	 * refusal is context for the answer above it and not an answer of its own:
	 * a site recording conversions and refusing a few is healthy, and a site
	 * recording none while refusing hundreds is a specific, fixable mistake.
	 *
	 * Three reasons at most. Every refusal reason at once is a paragraph nobody
	 * reads, and they are sorted by count, so the three shown are the three
	 * worth acting on.
	 */
	private function refusals(): string {
		$counts = $this->metrics->refusal_counts();

		if ( array() === $counts ) {
			return '';
		}

		$labels  = self::reason_labels();
		$phrases = array();

		foreach ( array_slice( $counts, 0, 3, true ) as $reason => $count ) {
			if ( ! isset( $labels[ $reason ] ) ) {
				continue;
			}

			$phrases[] = sprintf(
				/* translators: 1: number of refused reports. 2: why they were refused, such as "arrived after the attribution window". */
				__( '%1$s %2$s', 'aggressive-ads' ),
				number_format_i18n( $count ),
				$labels[ $reason ]
			);
		}

		if ( array() === $phrases ) {
			return '';
		}

		$since = $this->metrics->counting_since();

		return ' ' . sprintf(
			/* translators: 1: date counting began. 2: a list of refusal counts, such as "12 arrived after the attribution window". */
			__( 'Reports refused since %1$s, approximately: %2$s.', 'aggressive-ads' ),
			wp_date( (string) get_option( 'date_format' ), $since ),
			implode( __( '; ', 'aggressive-ads' ), $phrases )
		);
	}

	/**
	 * What each refusal reason means to the person reading it.
	 *
	 * Named plainly rather than by code, and each one says what to go and look
	 * at. `Conversion_Attribution` deliberately gives a *client* one answer for
	 * several of these — telling a stranger which definitions exist would make
	 * the endpoint an oracle — but this is a staff screen, where the distinction
	 * is the entire value.
	 *
	 * @return array<string, string>
	 */
	private static function reason_labels(): array {
		return array(
			Conversion_Attribution::NO_DEFINITION      => __( 'named a reporting key this site does not have', 'aggressive-ads' ),
			Conversion_Attribution::DEFINITION_CLOSED  => __( 'named a conversion that has been archived', 'aggressive-ads' ),
			Conversion_Attribution::FOREIGN_DEFINITION => __( 'named a conversion belonging to another advertiser', 'aggressive-ads' ),
			Conversion_Attribution::NO_INTERACTION     => __( 'carried no click this site recorded', 'aggressive-ads' ),
			Conversion_Attribution::OUT_OF_WINDOW      => __( 'arrived after the attribution window had closed', 'aggressive-ads' ),
			Conversion_Attribution::S2S_NOT_PERMITTED  => __( 'came from a server, for a conversion that does not accept server reports', 'aggressive-ads' ),
			Conversion_Attribution::FOREIGN_CREDENTIAL => __( 'used a credential scoped to another advertiser', 'aggressive-ads' ),
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
