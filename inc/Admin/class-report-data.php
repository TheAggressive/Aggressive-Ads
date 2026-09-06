<?php
/**
 * Fill and no-fill figures for the staff reporting screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Domain\Decision_Outcome;
use Aggressive\Ads\Domain\No_Fill_Reason;
use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Domain\Report_Request;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Workflow\Reporting_Read;

/**
 * Turns P13's decision counters into something a publisher can read.
 *
 * **These counters have had no reader since the day they shipped.** P13 stored
 * a request, a fill and a structured reason for every opportunity, bounded and
 * indexed and reconcilable, and deliberately built no screen — its own scope
 * boundary handed that here. Until now the only way to answer "why is this slot
 * empty" was a database client.
 *
 * Assembly only. The SQL is `Decision_Rollup_Repository`'s and the rendering is
 * `Reports_Screen`'s, the same split every other admin screen here uses.
 */
final class Report_Data {

	/**
	 * Windows a reader may choose, in days.
	 *
	 * The advertiser dashboard offers the same list, so it is defined once.
	 * Two screens with their own copies agree until one is edited.
	 *
	 * @var list<int>
	 */
	public const WINDOWS = Reporting_Read::WINDOWS;

	/**
	 * Constructor.
	 *
	 * @param Decision_Rollup_Repository $decisions  Per-placement outcome counters.
	 * @param Placement_Repository       $placements Placement catalogue.
	 * @param Reporting_Read             $reporting  The gate and the freshness watermark.
	 */
	public function __construct(
		private readonly Decision_Rollup_Repository $decisions,
		private readonly Placement_Repository $placements,
		private readonly Reporting_Read $reporting
	) {
	}

	/**
	 * Whether the screen may show figures at all.
	 *
	 * The same gate as every advertiser surface. A publisher screen that kept
	 * reporting while the site owner had switched Reporting off would be the
	 * one place the setting did not mean what it says.
	 */
	public function surfaces(): bool {
		return $this->reporting->surfaces();
	}

	/**
	 * A validated window from request input, falling back to the default.
	 *
	 * Delegated to `Report_Request` because the advertiser dashboard reads a
	 * range from a query string too, and two screens that parse the same
	 * parameters separately agree until the day one of them is edited.
	 *
	 * **The refusal flag is deliberately dropped here.** The dashboard offers
	 * two date inputs, where a range somebody typed can fail and saying so is
	 * the difference between a report and a wrong answer. This screen offers a
	 * closed list, so the only way to reach a refusal is to edit the URL by
	 * hand — and the window it settled on is printed at the top either way.
	 *
	 * @param int $days Requested preset, or 0.
	 */
	public function period( int $days ): Report_Period {
		return Report_Request::resolve( '', '', $days, self::WINDOWS, gmdate( 'Y-m-d' ), Reporting_Read::DEFAULT_DAYS )->period;
	}

	/**
	 * Placements a reader may filter by, ordered as the catalogue is.
	 *
	 * @return list<array{id: int, name: string}>
	 */
	public function placements(): array {
		$out = array();

		foreach ( $this->placements->all_ids() as $id ) {
			$out[] = array(
				'id'   => (int) $id,
				'name' => $this->placements->name( (int) $id ),
			);
		}

		return $out;
	}

	/**
	 * Fill figures for a window, site-wide or for one placement.
	 *
	 * `fill_rate` is null when nothing was asked for, not zero: a placement
	 * nobody requested did not fail to fill. That is the same distinction the
	 * advertiser tiles make between an em dash and a measured zero, and it is
	 * the one a rate cannot express without a denominator.
	 *
	 * `unaccounted` exists because P13's invariant — requests equal fills plus
	 * every no-fill reason — is a property of the engine rather than of the
	 * table, and a screen that quietly normalised a discrepancy away would hide
	 * exactly the defect worth finding. It is zero on a healthy site.
	 *
	 * @param Report_Period $period       Bounded UTC range.
	 * @param int           $placement_id One placement, or 0 for the whole site.
	 * @return array{requests: int, fills: int, fill_rate: float|null, unaccounted: int, reasons: list<array{code: string, label: string, events: int, share: float|null}>, refresh: array{requests: int, fills: int, fill_rate: float|null, unaccounted: int, reasons: list<array{code: string, label: string, events: int, share: float|null}>}}
	 */
	public function fill( Report_Period $period, int $placement_id = 0 ): array {
		$page    = $this->figures( $period, $placement_id, Opportunity::PAGE );
		$refresh = $this->figures( $period, $placement_id, Opportunity::REFRESH );

		$page['refresh'] = $refresh;

		return $page;
	}

	/**
	 * Figures for one inventory kind.
	 *
	 * **The kind is required.** Passing nothing and summing would put a
	 * refresh back into the page numbers, which is the defect the column
	 * exists to prevent and the one a screen that "just wants a total" will
	 * reintroduce the first time somebody forgets.
	 *
	 * @param Report_Period $period       Bounded UTC range.
	 * @param int           $placement_id One placement, or 0 for the whole site.
	 * @param string        $opportunity  `Domain\Opportunity` kind.
	 * @return array{requests: int, fills: int, fill_rate: float|null, unaccounted: int, reasons: list<array{code: string, label: string, events: int, share: float|null}>}
	 */
	private function figures( Report_Period $period, int $placement_id, string $opportunity ): array {
		$totals = $placement_id > 0
			? $this->decisions->totals_for_placement( $placement_id, $period->start, $period->end, $opportunity )
			: $this->decisions->totals( $period->start, $period->end, $opportunity );

		$requests = (int) ( $totals[ Decision_Outcome::REQUEST ] ?? 0 );
		$fills    = (int) ( $totals[ Decision_Outcome::FILL ] ?? 0 );
		$labels   = self::reason_labels();
		$reasons  = array();
		$counted  = 0;

		foreach ( $totals as $code => $events ) {
			if ( Decision_Outcome::REQUEST === $code || Decision_Outcome::FILL === $code ) {
				continue;
			}

			$events   = (int) $events;
			$counted += $events;

			$reasons[] = array(
				'code'   => (string) $code,
				'label'  => $labels[ $code ] ?? $labels[ No_Fill_Reason::UNKNOWN ],
				'events' => $events,
				'share'  => $requests > 0 ? $events / $requests : null,
			);
		}

		return array(
			'requests'    => $requests,
			'fills'       => $fills,
			'fill_rate'   => $requests > 0 ? $fills / $requests : null,
			'unaccounted' => max( 0, $requests - $fills - $counted ),
			'reasons'     => $reasons,
		);
	}

	/**
	 * Which days in the window may still change, or '' when none.
	 *
	 * @param Report_Period $period Window being reported.
	 */
	public function freshness_note( Report_Period $period ): string {
		$freshness = $this->reporting->freshness( $period );
		$from      = $freshness['unreconciled_from'];

		if ( Report_Period::RECONCILED === $freshness['state'] || null === $from ) {
			return '';
		}

		$timestamp = strtotime( $from . ' UTC' );

		return sprintf(
			/* translators: %s: a date, e.g. 30 August 2026. */
			__( 'Figures from %s onward are still being counted.', 'aggressive-ads' ),
			false === $timestamp ? $from : (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $timestamp )
		);
	}

	/**
	 * A sentence for every inventory kind the counters store.
	 *
	 * Derived from `Opportunity::all()` in the test, so a kind added there
	 * without a sentence here is a missing key rather than a raw slug in a
	 * spreadsheet.
	 *
	 * @return array<string, string>
	 */
	public static function opportunity_labels(): array {
		return array(
			Opportunity::PAGE    => __( 'Page', 'aggressive-ads' ),
			Opportunity::REFRESH => __( 'Refresh', 'aggressive-ads' ),
		);
	}

	/**
	 * A sentence for every reason the engine can record.
	 *
	 * **A code is never rendered raw**, the rule `Conversion_Health` already
	 * follows for refusals. `targeting_mismatch` tells a publisher nothing they
	 * can act on; "the visitor did not match the campaign's targeting" tells
	 * them where to look. Derived from `No_Fill_Reason::all()` so a reason added
	 * there without a sentence here is a missing key rather than a silent gap —
	 * `fill()` falls back to the unknown label, and the test asserts the map is
	 * complete.
	 *
	 * @return array<string, string>
	 */
	public static function reason_labels(): array {
		return array(
			No_Fill_Reason::NO_CANDIDATES       => __( 'No advertisement was assigned to this slot', 'aggressive-ads' ),
			No_Fill_Reason::ALL_INELIGIBLE      => __( 'Every assigned advertisement was ineligible', 'aggressive-ads' ),
			No_Fill_Reason::SIZE_UNAVAILABLE    => __( 'No advertisement was the size this screen asked for', 'aggressive-ads' ),
			No_Fill_Reason::SCHEDULE_EXCLUDED   => __( 'Outside every assigned campaign’s schedule', 'aggressive-ads' ),
			No_Fill_Reason::TARGETING_MISMATCH  => __( 'The visitor did not match the targeting rules', 'aggressive-ads' ),
			No_Fill_Reason::FREQUENCY_CAPPED    => __( 'The visitor had already seen it enough times', 'aggressive-ads' ),
			No_Fill_Reason::PACING_THROTTLED    => __( 'Held back to spread delivery across the day', 'aggressive-ads' ),
			No_Fill_Reason::COMPETITIVE_EXCLUDE => __( 'Excluded by a competing advertisement on the page', 'aggressive-ads' ),
			No_Fill_Reason::PIPELINE_ERROR      => __( 'The decision failed and no advertisement was served', 'aggressive-ads' ),
			No_Fill_Reason::UNKNOWN             => __( 'No reason was recorded', 'aggressive-ads' ),
		);
	}
}
