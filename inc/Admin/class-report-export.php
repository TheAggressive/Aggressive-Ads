<?php
/**
 * The fill report as a spreadsheet.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Core\Csv_Download;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Csv_Writer;
use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Capabilities;

/**
 * Hands a publisher the fill figures as a file, under the same rules as the
 * advertiser export.
 *
 * **Separate from `Reports_Screen` because streaming a download is not
 * rendering a page.** One ends in `exit` and writes headers; the other prints
 * into an admin wrapper. They share a data source and nothing else, and putting
 * them together would have pushed the screen towards the file-length gate for
 * the sake of a false economy.
 *
 * **Long format, one row per day and outcome.** A column per reason would mean
 * the column set changed the first time a new reason occurred, and somebody's
 * spreadsheet is pointed at a column. Long format pivots and never moves.
 */
final class Report_Export implements Service {

	public const EXPORT_ACTION = 'aggr_export_fill_report';

	/**
	 * Longest window this export will assemble.
	 *
	 * Matches the advertiser export and for the same reason: the whole document
	 * is built in memory before a byte is sent, which is a tighter constraint
	 * than what a read may examine. This one is per placement per day per
	 * outcome, so it is the denser of the two.
	 */
	public const MAX_DAYS = 31;

	/**
	 * Constructor.
	 *
	 * @param Report_Data                $data       Windows, labels and the reporting gate.
	 * @param Decision_Rollup_Repository $decisions  Per-day outcome counters.
	 * @param Placement_Repository       $placements Placement names.
	 */
	public function __construct(
		private readonly Report_Data $data,
		private readonly Decision_Rollup_Repository $decisions,
		private readonly Placement_Repository $placements
	) {
	}

	/**
	 * Attaches the export handler.
	 */
	public function init(): void {
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
	}

	/**
	 * Streams the fill report and stops.
	 *
	 * @return void
	 */
	public function handle_export(): void {
		if ( ! is_user_logged_in() || ! current_user_can( Capabilities::VIEW_REPORTS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'aggressive-ads' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::EXPORT_ACTION );

		// 404, not 403. Reporting being off is a site configuration rather than
		// a statement about this user, exactly as it is on the portal export.
		if ( ! $this->data->surfaces() ) {
			wp_die(
				esc_html__( 'Reporting is not available on this site.', 'aggressive-ads' ),
				'',
				array( 'response' => 404 )
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() ran above.
		$days      = isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 0;
		$placement = isset( $_POST['placement'] ) ? absint( wp_unslash( $_POST['placement'] ) ) : 0;
		// phpcs:enable

		$period = $this->export_period( $days );
		$rows   = $this->decisions->daily_outcomes( $period->start, $period->end, $placement );

		Csv_Download::send( $this->document( $rows ), $this->filename( $period, $placement ) );
	}

	/**
	 * The window this export will actually cover.
	 *
	 * **Truncated rather than refused**, keeping the most recent days, which
	 * are the ones somebody downloading today is asking about. A range refused
	 * by `Report_Request` is malformed and falls back to the default; a valid
	 * range longer than this handler can assemble in memory is a real request
	 * this surface cannot fully answer, and the honest response is a slice of
	 * it. The button that submits this names the number it will produce.
	 *
	 * Public for the same reason `document()` is: `handle_export()` ends in
	 * `exit`, so anything only reachable through it is unassertable — and
	 * mutation testing found this truncation surviving its own deletion.
	 *
	 * @param int $days Requested preset, or 0.
	 */
	public function export_period( int $days ): Report_Period {
		$period = $this->data->period( $days );

		return $period->days > self::MAX_DAYS
			? Report_Period::trailing( self::MAX_DAYS, $period->end )
			: $period;
	}

	/**
	 * Builds the CSV body.
	 *
	 * **Public because it is the seam, and the seam is the point.** Nothing here
	 * reads a request, a session or a screen: given rows, it returns bytes. That
	 * is what makes a scheduled report a later phase's scheduling problem rather
	 * than a rewrite of this one, and it is the only way to assert the bytes a
	 * publisher receives — `handle_export()` ends in `exit`.
	 *
	 * The outcome is written twice on purpose: a sentence for the person reading
	 * the file, and the code beside it for anyone joining this to something else.
	 * Rendering only the code would put a slug in front of a reader; rendering
	 * only the sentence would make the file untranslatable to a machine.
	 *
	 * @param list<array{day: string, placement_id: int, outcome: string, opportunity?: string, events: int}> $rows Counter rows.
	 */
	public function document( array $rows ): string {
		$header = array(
			__( 'Date (UTC)', 'aggressive-ads' ),
			__( 'Placement', 'aggressive-ads' ),
			__( 'Placement ID', 'aggressive-ads' ),
			__( 'Outcome', 'aggressive-ads' ),
			__( 'Code', 'aggressive-ads' ),
			__( 'Opportunity', 'aggressive-ads' ),
			__( 'Kind', 'aggressive-ads' ),
			__( 'Events', 'aggressive-ads' ),
		);

		$labels = $this->outcome_labels();
		$kinds  = Report_Data::opportunity_labels();
		$names  = array();
		$body   = array();

		foreach ( $rows as $row ) {
			$placement_id = $row['placement_id'];
			$kind         = $row['opportunity'] ?? Opportunity::PAGE;

			if ( ! isset( $names[ $placement_id ] ) ) {
				$names[ $placement_id ] = $this->placements->name( $placement_id );
			}

			$body[] = array(
				$row['day'],
				$names[ $placement_id ],
				$placement_id,
				$labels[ $row['outcome'] ] ?? $row['outcome'],
				$row['outcome'],
				$kinds[ $kind ] ?? $kind,
				$kind,
				$row['events'],
			);
		}

		return Csv_Writer::document( $header, $body );
	}

	/**
	 * Sentences for every outcome, lifecycle names included.
	 *
	 * `Report_Data` labels the no-fill reasons; a request and a fill are not
	 * reasons and are named here rather than pushed into that map, which is
	 * derived from the reason vocabulary and should stay that way.
	 *
	 * @return array<string, string>
	 */
	private function outcome_labels(): array {
		return Report_Data::reason_labels() + array(
			\Aggressive\Ads\Domain\Decision_Outcome::REQUEST => __( 'Requested', 'aggressive-ads' ),
			\Aggressive\Ads\Domain\Decision_Outcome::FILL => __( 'Filled', 'aggressive-ads' ),
		);
	}

	/**
	 * A download name that is safe as a filename and useful in a folder.
	 *
	 * Placement names are staff-controlled rather than advertiser-controlled,
	 * which lowers the risk and changes nothing: it goes through
	 * `sanitize_file_name()` before it reaches a header either way.
	 *
	 * Public alongside the other two seams, and for the same reason: it is a
	 * pure function of its arguments, and the only alternative is asserting it
	 * through a method that ends in `exit`.
	 *
	 * @param Report_Period $period    Window exported.
	 * @param int           $placement Placement id, or 0.
	 */
	public function filename( Report_Period $period, int $placement ): string {
		$scope = 0 === $placement
			? 'all-placements'
			: sanitize_file_name( $this->placements->name( $placement ) );

		return sprintf( 'fill-report-%s-%s-to-%s.csv', '' === $scope ? 'placement' : $scope, $period->start, $period->end );
	}
}
