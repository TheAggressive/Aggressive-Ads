<?php
/**
 * Advertiser-facing delivery numbers for the portal dashboard.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Domain\Report_Request;
use Aggressive\Ads\Domain\Reporting_Rules;
use Aggressive\Ads\Workflow\Reporting_Read;

/**
 * Keeps delivery reporting out of the multi-screen `View_Data` coordinator,
 * the way `Catalogue_View_Data` already keeps the catalogue out of it.
 *
 * One job — turning org-scoped rollups into something a tile can print — and
 * one rule the rest of the portal does not have to carry: **an absent number, a
 * zero and an unmeasured metric are three different answers**, and this is the
 * only place that decides which is which. Every formatter below is private for
 * that reason: the rule is enforced by there being no way to render one of
 * these figures without coming through here.
 */
final class Delivery_View_Data {

	/**
	 * Constructor.
	 *
	 * @param Reporting_Read $reporting Org-scoped rollup reads and the surface gate.
	 */
	public function __construct( private readonly Reporting_Read $reporting ) {
	}

	/**
	 * What the current request asked to see.
	 *
	 * Read here rather than in the template: a page whose tiles, chart, caption
	 * and export each parsed the query string separately would eventually
	 * disagree with itself, and the disagreement would look like a reporting
	 * bug rather than a plumbing one.
	 *
	 * **Deliberately not memoized.** This service is a container singleton and
	 * the container outlives a request in every context that reuses it, so an
	 * instance property holding request state is a value from somebody else's
	 * page waiting to be served as yours. Resolving is string parsing over four
	 * parameters; caching it would trade a measurable nothing for that.
	 *
	 * Nothing is trusted. The dates go to `Report_Request`, which refuses
	 * anything it cannot turn into a bounded period, and the organization is
	 * never read from the request at all.
	 */
	public function request(): Report_Request {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A read-only range filter on the caller's own data; every value is validated by Report_Request and the organization comes from the session, never the request.
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		$days = isset( $_GET['days'] ) ? absint( wp_unslash( $_GET['days'] ) ) : 0;
		// phpcs:enable

		return Report_Request::resolve(
			$from,
			$to,
			$days,
			Reporting_Read::WINDOWS,
			gmdate( 'Y-m-d' ),
			Reporting_Read::DEFAULT_DAYS
		);
	}

	/**
	 * The window every delivery tile on the dashboard covers.
	 */
	public function period(): Report_Period {
		return $this->request()->period;
	}

	/**
	 * The slice of the window the export will actually produce.
	 *
	 * **Truncated rather than refused**, and the button says the number. The
	 * export's cap is tighter than a report's because it assembles the whole
	 * document in memory before sending a byte — a different constraint from
	 * what a read may examine, which is why the two numbers are allowed to
	 * differ. Truncation keeps the most recent days, which are the ones somebody
	 * downloading today is asking about.
	 */
	public function export_period(): Report_Period {
		$period = $this->period();

		if ( $period->days <= Report_Actions::MAX_DAYS ) {
			return $period;
		}

		return Report_Period::trailing( Report_Actions::MAX_DAYS, $period->end );
	}

	/**
	 * Whether the requested range was refused and the default shown instead.
	 */
	public function range_rejected(): bool {
		return $this->request()->rejected;
	}

	/**
	 * The range in words, timezone included.
	 *
	 * **UTC is stated, not implied.** Both projections are keyed by a UTC day
	 * and neither carries an hour, so a publisher or advertiser east or west of
	 * UTC is reading days that do not line up with their own. A report that
	 * left that unsaid would be quietly wrong for most of the world for the
	 * hours that matter most.
	 *
	 * The dates themselves rather than "last 30 days", now that the window is
	 * the reader's to choose: a label naming a length cannot describe a range
	 * somebody typed, and one that said "last 30 days" over an August report
	 * would be worse than no label.
	 */
	public function range_label(): string {
		$period = $this->period();

		return sprintf(
			/* translators: 1: first day of the range. 2: last day of the range. */
			__( '%1$s to %2$s (UTC)', 'aggressive-ads' ),
			$this->day_label( $period->start ),
			$this->day_label( $period->end )
		);
	}

	/**
	 * One UTC day in the site's date format.
	 *
	 * @param string $day_utc `Y-m-d`.
	 */
	private function day_label( string $day_utc ): string {
		$timestamp = strtotime( $day_utc . ' UTC' );

		return false === $timestamp
			? $day_utc
			: (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $timestamp );
	}

	/**
	 * A sentence naming the first day whose numbers may still move, or ''.
	 *
	 * Empty means every day in range has been rebuilt from the event ledger and
	 * will not change. Anything else names the boundary rather than disclaiming
	 * the whole table, because "these numbers might be wrong" tells a reader
	 * nothing they can act on and a date tells them which rows to re-check.
	 *
	 * @param Report_Period|null $period Range being described.
	 */
	public function freshness_note( ?Report_Period $period = null ): string {
		$freshness = $this->reporting->freshness( $period ?? $this->period() );
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
	 * Native delivery totals for one organization over the reporting window.
	 *
	 * Empty when the reporting surface is off, so the template does not render
	 * a row of zeros that look like traffic.
	 *
	 * @param int                $org_id Organization to report on.
	 * @param Report_Period|null $period Range, or the dashboard's own window.
	 * @return array<int, array{label: string, value: string}>
	 */
	public function counts( int $org_id, ?Report_Period $period = null ): array {
		if ( ! $this->reporting->surfaces() ) {
			return array();
		}

		$read     = $this->reporting->totals_with_comparison( $org_id, $period ?? $this->period() );
		$totals   = $read['current'];
		$was      = $read['previous'];
		$ctr      = Reporting_Rules::ctr( $totals['impressions'], $totals['clicks'] );
		$ctr_was  = Reporting_Rules::ctr( $was['impressions'], $was['clicks'] );
		$view     = Reporting_Rules::viewability( $totals['impressions'], $totals['viewables'] );
		$view_was = Reporting_Rules::viewability( $was['impressions'], $was['viewables'] );

		return array(
			$this->tile(
				__( 'Impressions', 'aggressive-ads' ),
				(string) number_format_i18n( $totals['impressions'] ),
				Reporting_Rules::change( $totals['impressions'], $was['impressions'] )
			),
			$this->tile(
				__( 'Clicks', 'aggressive-ads' ),
				(string) number_format_i18n( $totals['clicks'] ),
				Reporting_Rules::change( $totals['clicks'], $was['clicks'] )
			),
			$this->tile(
				__( 'CTR', 'aggressive-ads' ),
				$this->format_ctr( $ctr ),
				Reporting_Rules::point_change( $ctr, $ctr_was ),
				true
			),
			$this->tile(
				__( 'Viewable', 'aggressive-ads' ),
				$this->format_viewability( $totals['impressions'], $totals['viewables'] ),
				Reporting_Rules::point_change( $view, $view_was ),
				true
			),
			$this->tile(
				__( 'Conversions', 'aggressive-ads' ),
				$this->format_count( $totals['conversions'] ),
				Reporting_Rules::change( $totals['conversions'], $was['conversions'] )
			),
		);
	}

	/**
	 * One tile, with its comparison already rendered.
	 *
	 * `direction` exists to hang a colour on and carries no meaning of its own:
	 * the sign is in `change` as text, so a reader in high contrast, in
	 * monochrome, or with a colour vision deficiency loses nothing. Empty
	 * `change` means there was no comparison to draw, which is a real state and
	 * not a zero.
	 *
	 * @param string     $label  Tile label.
	 * @param string     $value  Formatted figure.
	 * @param float|null $delta  Signed change, or null when incomparable.
	 * @param bool       $points Whether $delta is percentage points rather than a proportion.
	 * @return array{label: string, value: string, change: string, direction: string}
	 */
	private function tile( string $label, string $value, ?float $delta, bool $points = false ): array {
		return array(
			'label'     => $label,
			'value'     => $value,
			'change'    => $this->format_change( $delta, $points ),
			'direction' => $this->direction( $delta ),
		);
	}

	/**
	 * A signed change against the previous window, or '' when there is none.
	 *
	 * Rates read in percentage points and counts in percent, because they are
	 * different quantities: CTR going from 1.0% to 1.5% is half a point and a
	 * 50% rise, and only one of those is what a reader takes "CTR up 50%" to
	 * mean. See `Reporting_Rules::point_change()`.
	 *
	 * The sign is composed rather than translated. It is arithmetic notation,
	 * not language, and building it into four separate strings would give
	 * translators four chances to drop the one character a reader must not
	 * misread at a glance.
	 *
	 * @param float|null $delta  Signed change.
	 * @param bool       $points Whether $delta is percentage points.
	 */
	private function format_change( ?float $delta, bool $points = false ): string {
		if ( null === $delta ) {
			return '';
		}

		// A proportion and a point difference are both stored as fractions, so
		// both render by the same factor; only the unit differs. U+2212 is the
		// minus sign, which a hyphen only resembles.
		$signed = ( $delta < 0 ? "\u{2212}" : '+' ) . number_format_i18n( abs( $delta ) * 100, 1 );

		if ( $points ) {
			/* translators: %s: signed change in percentage points, e.g. +0.5. */
			return sprintf( __( '%s pp vs previous period', 'aggressive-ads' ), $signed );
		}

		/* translators: %s: signed percentage change, e.g. +12.4. */
		return sprintf( __( '%s%% vs previous period', 'aggressive-ads' ), $signed );
	}

	/**
	 * Styling hook for a change, never the change's meaning.
	 *
	 * @param float|null $delta Signed change.
	 */
	private function direction( ?float $delta ): string {
		if ( null === $delta ) {
			return '';
		}

		if ( $delta > 0 ) {
			return 'up';
		}

		return $delta < 0 ? 'down' : 'flat';
	}

	/**
	 * Daily impressions across the chosen window, for the sparkline.
	 *
	 * Empty when Reporting is off, so the template omits the chart rather than
	 * drawing a flat line that looks like traffic.
	 *
	 * **The chart follows the tiles.** A page whose figures covered one window
	 * and whose chart covered another would invite the reader to compare them,
	 * and every such comparison would be wrong.
	 *
	 * A weekday is a useful label over a week and a meaningless one over a
	 * quarter, where the same seven names repeat thirteen times, so longer
	 * windows label by date instead.
	 *
	 * @param int $org_id Organization to report on.
	 * @return list<array{day: string, label: string, impressions: int, height: int}>
	 */
	public function series( int $org_id ): array {
		if ( ! $this->reporting->surfaces() ) {
			return array();
		}

		$raw = $this->reporting->series_for_org( $org_id, $this->period() );
		$max = 0;

		foreach ( $raw as $row ) {
			$max = max( $max, $row['impressions'] );
		}

		$series = array();

		$format = count( $raw ) > 7 ? 'j M' : 'D';

		foreach ( $raw as $row ) {
			$timestamp = strtotime( $row['day'] . ' UTC' );

			$series[] = array(
				'day'         => $row['day'],
				'label'       => false === $timestamp ? $row['day'] : (string) wp_date( $format, $timestamp ),
				'impressions' => $row['impressions'],
				'height'      => Reporting_Rules::bar_height( $row['impressions'], $max ),
			);
		}

		return $series;
	}

	/**
	 * CTR as a percentage, or an em dash when there were no impressions.
	 *
	 * @param float|null $ratio Clicks per impression.
	 */
	private function format_ctr( ?float $ratio ): string {
		if ( null === $ratio ) {
			return __( '—', 'aggressive-ads' );
		}

		return sprintf(
			/* translators: %s: click-through rate as a percentage, e.g. 1.2. */
			__( '%s%%', 'aggressive-ads' ),
			number_format_i18n( $ratio * 100, 1 )
		);
	}

	/**
	 * A measured count, or the statement that nothing measured it.
	 *
	 * **Two answers here where viewability has three, and the missing one is
	 * the em dash.** A rate needs a denominator, so "nothing to divide by" is a
	 * distinct state; a count does not, so a measured zero is simply zero and
	 * is a real answer — the campaign delivered and nobody converted, which is
	 * worth knowing. Only "nobody was counting" has to be said in words.
	 *
	 * @param int|null $value Counted outcomes, or null when unmeasured.
	 */
	private function format_count( ?int $value ): string {
		if ( null === $value ) {
			return __( 'Not measured', 'aggressive-ads' );
		}

		return (string) number_format_i18n( $value );
	}

	/**
	 * Viewability as a percentage, or which kind of absence it is.
	 *
	 * Three answers, and collapsing any two of them would mislead. **Not
	 * measured** is a day before viewability shipped, or one where the script
	 * never ran — reporting it as `0%` claims nobody saw the ads, which is the
	 * alarming reading and the false one. An em dash is the ordinary "nothing
	 * delivered yet". `0.0%` is a real measurement of nothing being seen, and
	 * is the one worth investigating.
	 *
	 * @param int      $impressions Delivered impressions.
	 * @param int|null $viewables   Views recorded, or null when unmeasured.
	 * @return string
	 */
	private function format_viewability( int $impressions, ?int $viewables ): string {
		if ( null === $viewables ) {
			return __( 'Not measured', 'aggressive-ads' );
		}

		$rate = Reporting_Rules::viewability( $impressions, $viewables );

		if ( null === $rate ) {
			return __( '—', 'aggressive-ads' );
		}

		return sprintf(
			/* translators: %s: share of impressions that were viewable, e.g. 62.5. */
			__( '%s%%', 'aggressive-ads' ),
			number_format_i18n( $rate * 100, 1 )
		);
	}
}
