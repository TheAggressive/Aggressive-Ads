<?php
/**
 * One campaign-summary read and presentation contract for portal and email.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Domain\Report_Goal;
use Aggressive\Ads\Admin\Line_Item_Labels;
use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Repository\Campaign_Report_Repository;
use Aggressive\Ads\Workflow\Reporting_Read;

/**
 * Current settings are labelled separately from selected-period delivery.
 *
 * @phpstan-type Field array{label: string, value: string}
 * @phpstan-type CampaignRow array{id: int, campaign_id: int, campaign: string, plan: string, url: string, fields: list<Field>, metrics: list<Field>}
 * @phpstan-type Summary array{title: string, period: string, snapshot: string, freshness: string, notes: list<string>, rows: list<CampaignRow>, more: bool, next_url: string, reset_url: string, limit_note: string}
 */
final class Campaign_Report_View_Data {

	/**
	 * Compose without querying data or reading a browser request.
	 *
	 * @param Campaign_Report_Repository $reports Bounded native read model.
	 * @param Reporting_Read             $reporting Gate, zone and history disclosures.
	 * @param Delivery_View_Data         $display Shared metric formatting.
	 */
	public function __construct(
		private readonly Campaign_Report_Repository $reports,
		private readonly Reporting_Read $reporting,
		private readonly Delivery_View_Data $display
	) {}

	/**
	 * Cursor affects only the authenticated organization's indexed id range.
	 */
	public static function requested_cursor(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only cursor; integer validation and server-resolved organization scope apply independently.
		if ( ! isset( $_GET['plans_before'] ) || ! is_string( $_GET['plans_before'] ) ) {
			return 0;
		}
		$raw = sanitize_text_field( wp_unslash( $_GET['plans_before'] ) );
		// phpcs:enable
		$value = filter_var( $raw, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		return false === $value ? 0 : $value;
	}

	/**
	 * Settings and counters are current; selected-period metrics retain their own date bounds.
	 *
	 * @param int           $org_id Organization resolved by the portal session or authorized worker.
	 * @param Report_Period $period Selected reporting period.
	 * @param int           $now Current snapshot clock.
	 * @param int           $before Exclusive cursor, zero for the email/first page.
	 * @return Summary|array{}|\WP_Error
	 */
	public function for_org( int $org_id, Report_Period $period, int $now, int $before = 0 ): array|\WP_Error {
		if ( ! $this->reporting->surfaces() ) {
			return array();
		}
		$page = $this->reports->page( $org_id, $before );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$current = $this->reports->totals( $org_id, $page['rows'], null, $now );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$selected = $this->reports->totals( $org_id, $page['rows'], $period, $now );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$rows = array();
		foreach ( $page['rows'] as $plan ) {
			$plan                 = Line_Item_Labels::labelled( $plan );
			$plan['status_label'] = sanitize_text_field( (string) $plan['status_label'] );
			$plan['goal_label']   = sanitize_text_field( (string) $plan['goal_label'] );
			$id                   = (int) $plan['id'];
			$goal                 = Report_Goal::evaluate( $plan, $current['totals'][ $id ], $now );
			$fields               = array(
				array(
					'label' => __( 'Current plan status', 'aggressive-ads' ),
					'value' => (string) $plan['status_label'],
				),
				array(
					'label' => __( 'Current goal', 'aggressive-ads' ),
					'value' => $this->goal_label( $plan ),
				),
				array(
					'label' => __( 'Recorded goal units so far', 'aggressive-ads' ),
					'value' => 'no_goal' === $goal['state'] ? __( '—', 'aggressive-ads' ) : ( null === $goal['actual'] ? __( 'Not measured', 'aggressive-ads' ) : (string) number_format_i18n( $goal['actual'] ) ),
				),
				array(
					'label' => __( 'Goal progress', 'aggressive-ads' ),
					'value' => null === $goal['completion'] ? ( 'no_goal' === $goal['state'] ? __( '—', 'aggressive-ads' ) : __( 'Not measured', 'aggressive-ads' ) ) : sprintf( /* translators: %s: percent of the configured goal recorded, possibly above 100. */ __( '%s%%', 'aggressive-ads' ), number_format_i18n( $goal['completion'] * 100, 1 ) ),
				),
				array(
					'label' => __( 'Goal pacing', 'aggressive-ads' ),
					'value' => $this->pace_label( $goal['state'] ),
				),
				array(
					'label' => __( 'Current schedule', 'aggressive-ads' ),
					'value' => $this->schedule( $plan, $period ),
				),
				array(
					'label' => __( 'Settings last updated (UTC)', 'aggressive-ads' ),
					'value' => (int) $plan['updated_at_ts'] > 0 ? gmdate( 'Y-m-d H:i', (int) $plan['updated_at_ts'] ) : __( 'Not available', 'aggressive-ads' ),
				),
			);
			$measured             = $this->reporting->history_available( $period ) ? $selected['totals'][ $id ] : null;
			$rows[]               = array(
				'id'          => $id,
				'campaign_id' => (int) $plan['campaign_id'],
				'campaign'    => sanitize_text_field( wp_specialchars_decode( (string) $plan['campaign_name'], ENT_QUOTES ) ),
				'plan'        => $plan['name_is_derived'] ? __( 'Default delivery plan', 'aggressive-ads' ) : sanitize_text_field( wp_specialchars_decode( (string) $plan['name'], ENT_QUOTES ) ),
				'url'         => Routes::url( Request::ROUTE_CAMPAIGNS, (int) $plan['campaign_id'] ),
				'fields'      => $fields,
				'metrics'     => array_values( $this->display->metric_values( $measured ) ),
			);
		}
		$notes = array(
			__( 'Even pacing compares goal completion with elapsed schedule, allowing 10 percentage points. ASAP plans have no even-schedule target.', 'aggressive-ads' ),
			__( 'Delivery plans are listed newest first, regardless of whether they delivered during the selected period.', 'aggressive-ads' ),
			__( 'Goals, schedules and pacing use current settings, not settings reconstructed at the end of the selected period.', 'aggressive-ads' ),
			__( 'Current goal progress uses retained UTC daily delivery, includes the open day, and may change as reconciliation finishes. Spend and share-of-voice progress are not measured.', 'aggressive-ads' ),
		);
		if ( $selected['limited'] || $current['limited'] ) {
			$notes[] = __( 'Some delivery totals exceed the summary read limit and are shown as Not measured. No partial total is presented as complete.', 'aggressive-ads' );
		}
		if ( $selected['legacy'] || $current['legacy'] ) {
			$notes[] = __( 'Some campaign history predates delivery-plan attribution. Progress for those plans is Not measured.', 'aggressive-ads' );
		}
		$args = array(
			'from' => $period->start,
			'to'   => $period->end,
		);
		if ( 'UTC' === $period->timezone ) {
			$args['tz'] = 'utc';
		}
		$reset                = add_query_arg( $args, Routes::url() ) . '#aggr-campaign-goals';
		$args['plans_before'] = $page['next'];
		return array(
			'title'      => __( 'Campaign goals and delivery', 'aggressive-ads' ),
			'period'     => sprintf( /* translators: 1: first selected date. 2: last selected date. 3: reporting timezone. */ __( 'Selected-period delivery: %1$s to %2$s (%3$s)', 'aggressive-ads' ), $period->start, $period->end, $period->timezone ),
			'snapshot'   => sprintf( /* translators: %s: generation timestamp in UTC. */ __( 'Current settings and recorded progress, generated %s UTC.', 'aggressive-ads' ), gmdate( 'Y-m-d H:i', $now ) ),
			'freshness'  => $this->display->freshness_note( $period ),
			'notes'      => $notes,
			'rows'       => $rows,
			'more'       => $page['more'],
			'next_url'   => $page['more'] ? add_query_arg( $args, Routes::url() ) . '#aggr-campaign-goals' : '',
			'reset_url'  => $before > 0 ? $reset : '',
			'limit_note' => $page['more'] ? sprintf( /* translators: %s: maximum delivery plans displayed in a summary. */ __( 'Showing up to %s delivery plans. Additional plans are available on the next page.', 'aggressive-ads' ), number_format_i18n( Campaign_Report_Repository::PAGE_SIZE ) ) : '',
		);
	}

	/**
	 * Goal amount only when a native unit has a defined meaning.
	 *
	 * @param array<string, mixed> $plan Current settings.
	 */
	private function goal_label( array $plan ): string {
		if ( 'none' === $plan['goal_type'] || (int) $plan['goal_amount'] <= 0 ) {
			return (string) $plan['goal_label'];
		}
		if ( ! in_array( $plan['goal_type'], array( 'impressions', 'clicks', 'conversions' ), true ) ) {
			return sprintf( /* translators: 1: unsupported goal type. 2: measurement availability. */ __( '%1$s (%2$s)', 'aggressive-ads' ), $plan['goal_label'], __( 'Not measured', 'aggressive-ads' ) );
		}
		return sprintf( /* translators: 1: configured goal amount. 2: native goal unit. */ __( '%1$s %2$s', 'aggressive-ads' ), number_format_i18n( (int) $plan['goal_amount'] ), $plan['goal_label'] );
	}

	/**
	 * Explain current state without promising future delivery or representing a throttle decision.
	 *
	 * @param string $state Pure goal evaluation result.
	 */
	private function pace_label( string $state ): string {
		return match ( $state ) {
			'no_goal' => __( 'No delivery goal', 'aggressive-ads' ),
			'met' => __( 'Goal met', 'aggressive-ads' ),
			'draft' => __( 'Draft', 'aggressive-ads' ),
			'ready' => __( 'Ready', 'aggressive-ads' ),
			'paused' => __( 'Paused', 'aggressive-ads' ),
			'completed' => __( 'Completed', 'aggressive-ads' ),
			'cancelled' => __( 'Cancelled', 'aggressive-ads' ),
			'not_started' => __( 'Not started', 'aggressive-ads' ),
			'asap' => __( 'As soon as possible', 'aggressive-ads' ),
			'no_schedule' => __( 'No complete schedule', 'aggressive-ads' ),
			'ahead' => __( 'Ahead of schedule', 'aggressive-ads' ),
			'behind' => __( 'Behind schedule', 'aggressive-ads' ),
			'on_pace' => __( 'Within schedule tolerance', 'aggressive-ads' ),
			default => __( 'Not measured', 'aggressive-ads' ),
		};
	}

	/**
	 * Current schedule in the selected report zone, with unknown dates stated explicitly.
	 *
	 * @param array<string, mixed> $plan Current settings.
	 * @param Report_Period        $period Report zone.
	 */
	private function schedule( array $plan, Report_Period $period ): string {
		$zone  = new \DateTimeZone( $period->timezone );
		$start = (int) $plan['start_at_ts'] > 0 ? wp_date( 'Y-m-d H:i T', (int) $plan['start_at_ts'], $zone ) : __( 'Not set', 'aggressive-ads' );
		$end   = (int) $plan['end_at_ts'] > 0 ? wp_date( 'Y-m-d H:i T', (int) $plan['end_at_ts'], $zone ) : __( 'Not set', 'aggressive-ads' );
		return sprintf( /* translators: 1: current start date/time. 2: current end date/time. */ __( '%1$s to %2$s', 'aggressive-ads' ), $start, $end );
	}

	/**
	 * Print the same field labels and values as the portal; no second report arithmetic.
	 *
	 * @param Summary|array{} $summary Shared formatted read model.
	 * @return list<string>
	 */
	public function email_lines( array $summary ): array {
		if ( array() === $summary ) {
			return array();
		}
		$lines = array( '', $summary['title'], $summary['snapshot'], $summary['period'], $summary['freshness'], ...$summary['notes'], '' );
		if ( array() === $summary['rows'] ) {
			$lines[] = __( 'No delivery plans are available in this summary.', 'aggressive-ads' );
		}
		foreach ( $summary['rows'] as $row ) {
			$lines[] = $row['campaign'] . ' — ' . $row['plan'];
			foreach ( $row['fields'] as $field ) {
				$lines[] = $field['label'] . ': ' . $field['value'];
			}
			$lines[] = $summary['period'];
			foreach ( $row['metrics'] as $metric ) {
				$lines[] = $metric['label'] . ': ' . $metric['value'];
			}
			$lines[] = $row['url'];
			$lines[] = '';
		}
		if ( '' !== $summary['limit_note'] ) {
			$lines[] = $summary['limit_note'];
			$lines[] = $summary['next_url'];
		}
		return $lines;
	}
}
