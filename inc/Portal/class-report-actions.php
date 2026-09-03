<?php
/**
 * Portal CSV export of delivery performance.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Core\Csv_Download;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Csv_Writer;
use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Domain\Report_Request;
use Aggressive\Ads\Domain\Reporting_Rules;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Reporting_Read;

/**
 * Hands an advertiser their own delivery numbers as a spreadsheet.
 *
 * Scoped the same way every other portal read is: the organization comes from
 * the signed-in user, never from the request. An `org_id` parameter here would
 * be an org-scoped tenancy boundary re-implemented in a place nobody thinks to
 * test, which is exactly where one gets it wrong.
 */
final class Report_Actions implements Service {

	public const EXPORT_ACTION = 'aggr_export_report';

	/**
	 * Longest window the export will assemble.
	 *
	 * **Tighter than `Report_Period::MAX_DAYS`, and for a different reason.** A
	 * screen's range is bounded by what a read may examine; an export is bounded
	 * by the document it assembles in memory before sending a byte. Collapsing
	 * the two into one number would mean one of them had been chosen for the
	 * wrong constraint, so they stay separate and the tighter one wins here.
	 */
	public const MAX_DAYS = 31;

	/**
	 * Default window, matching what the dashboard already implies.
	 */
	public const DEFAULT_DAYS = 30;

	/**
	 * Constructor.
	 *
	 * @param Reporting_Read $reporting Gate and rollup reads.
	 * @param Org_Repository $orgs      Organization membership.
	 */
	public function __construct(
		private readonly Reporting_Read $reporting,
		private readonly Org_Repository $orgs
	) {
	}

	/**
	 * Attaches the export handler.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
	}

	/**
	 * Streams the signed-in advertiser's org-scoped performance CSV.
	 *
	 * @return void
	 */
	public function handle_export(): void {
		if ( ! is_user_logged_in() || ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'aggressive-ads' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::EXPORT_ACTION );

		// 404, not 403. Reporting being off is a site configuration, not a
		// statement about this user, and the portal's rule throughout is that
		// an absent surface is absent rather than forbidden.
		if ( ! $this->reporting->surfaces() ) {
			wp_die(
				esc_html__( 'Reporting is not available on this site.', 'aggressive-ads' ),
				'',
				array( 'response' => 404 )
			);
		}

		$org_id = $this->current_org_id();

		if ( 0 === $org_id ) {
			wp_die(
				esc_html__( 'There is no organization to report on.', 'aggressive-ads' ),
				'',
				array( 'response' => 404 )
			);
		}

		$period = $this->requested_period();
		$rows   = $this->reporting->daily_rows_for_org( $org_id, $period );

		Csv_Download::send( $this->document( $rows ), $this->filename( $org_id, $period->days ) );
	}

	/**
	 * The signed-in user's organization, or 0.
	 *
	 * @return int
	 */
	private function current_org_id(): int {
		$org_ids = $this->orgs->org_ids_for_user( get_current_user_id() );
		$first   = reset( $org_ids );

		return is_int( $first ) ? $first : 0;
	}

	/**
	 * The window this export will cover.
	 *
	 * Read through `Report_Request` so the export and the screen agree about
	 * what a range means, then truncated to this handler's own tighter cap.
	 *
	 * **Truncated rather than refused, and only here.** A range refused by
	 * `Report_Request` is a malformed one and falls back to the default; a
	 * valid range longer than this handler can assemble in memory is a real
	 * request that this surface cannot fully answer, and the honest response is
	 * the most recent slice of it rather than an error page. The button that
	 * submits this names the number of days it will actually produce.
	 */
	private function requested_period(): Report_Period {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() ran in the caller before this is reached.
		$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$days = isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 0;
		// phpcs:enable

		$period = Report_Request::resolve(
			$from,
			$to,
			$days,
			Reporting_Read::WINDOWS,
			gmdate( 'Y-m-d' ),
			self::DEFAULT_DAYS
		)->period;

		if ( $period->days <= self::MAX_DAYS ) {
			return $period;
		}

		return Report_Period::trailing( self::MAX_DAYS, $period->end );
	}

	/**
	 * Builds the CSV body.
	 *
	 * CTR is written as a rounded percentage because that is what a reader
	 * expects in a spreadsheet, and left empty — not 0 — when there were no
	 * impressions, for the same reason `Reporting_Rules::ctr()` returns null:
	 * "0%" claims the ad was seen and ignored. Conversions are left empty on the
	 * same principle when the day predates measurement, and an empty cell is
	 * meaningfully different from a `0` to every spreadsheet that will open this.
	 *
	 * **Columns are appended, never reordered or reinterpreted.** Somebody has
	 * a spreadsheet pointed at column D. Adding conversions on the end is safe;
	 * inserting it after clicks would silently change what four existing
	 * columns mean in every workbook already built on this export.
	 *
	 * Public because it is the seam the tests use. `handle_export()` ends in
	 * `exit`, so asserting on the bytes an advertiser actually receives is only
	 * possible either here or in a separate process — and a test that runs in
	 * its own process to reach a private method is a test nobody maintains.
	 * This is a pure function of its argument; exposing it grants nothing.
	 *
	 * @param list<array{day: string, campaign_id: int, campaign: string, impressions: int, clicks: int, conversions?: int|null}> $rows Export rows.
	 */
	public function document( array $rows ): string {
		$header = array(
			__( 'Date (UTC)', 'aggressive-ads' ),
			__( 'Campaign', 'aggressive-ads' ),
			__( 'Campaign ID', 'aggressive-ads' ),
			__( 'Impressions', 'aggressive-ads' ),
			__( 'Clicks', 'aggressive-ads' ),
			__( 'CTR %', 'aggressive-ads' ),
			__( 'Conversions', 'aggressive-ads' ),
		);

		$body = array();

		foreach ( $rows as $row ) {
			$ctr         = Reporting_Rules::ctr( $row['impressions'], $row['clicks'] );
			$conversions = $row['conversions'] ?? null;

			$body[] = array(
				$row['day'],
				$row['campaign'],
				$row['campaign_id'],
				$row['impressions'],
				$row['clicks'],
				null === $ctr ? '' : round( $ctr * 100, 2 ),
				null === $conversions ? '' : $conversions,
			);
		}

		return Csv_Writer::document( $header, $body );
	}

	/**
	 * A download name that is safe as a filename and useful in a folder.
	 *
	 * The organization name is advertiser-controlled, so it goes through
	 * sanitize_file_name() before it is ever put in a header.
	 *
	 * @param int $org_id Organization post id.
	 * @param int $days   Window length.
	 */
	private function filename( int $org_id, int $days ): string {
		$name = sanitize_file_name( $this->orgs->name( $org_id ) );
		$name = '' === $name ? 'advertising' : $name;

		return sprintf( '%s-performance-%dd-%s.csv', $name, $days, gmdate( 'Y-m-d' ) );
	}
}
