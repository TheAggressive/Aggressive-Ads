<?php
/**
 * Campaign summaries exercise real settings, native writers, and shared readers.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Notification\Scheduled_Report_Mailer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Campaign_Report_View_Data;
use Aggressive\Ads\Portal\Delivery_View_Data;
use Aggressive\Ads\Repository\Campaign_Report_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Workflow\Rollup_Reconciler;
use WP_UnitTestCase;

final class CampaignReportTest extends WP_UnitTestCase {

	/**
	 * Current settings writer.
	 *
	 * @var Line_Item_Repository
	 */
	private Line_Item_Repository $items;
	/**
	 * Bounded native reader.
	 *
	 * @var Campaign_Report_Repository
	 */
	private Campaign_Report_Repository $reports;
	/**
	 * Shared presenter.
	 *
	 * @var Campaign_Report_View_Data
	 */
	private Campaign_Report_View_Data $view;
	/**
	 * Current tenant.
	 *
	 * @var int
	 */
	private int $org;
	/**
	 * Fresh lookups should not reuse another test's persistent projection cache.
	 *
	 * @var bool
	 */
	private bool $external;

	/** Build actual membership; report reads cannot create delivery settings. */
	public function set_up(): void {
		parent::set_up();
		$this->external = true === wp_using_ext_object_cache();
		wp_using_ext_object_cache( false );
		$services      = Plugin::instance()->container();
		$this->items   = $services->get( Line_Item_Repository::class );
		$this->reports = $services->get( Campaign_Report_Repository::class );
		$this->view    = $services->get( Campaign_Report_View_Data::class );
		$user          = (int) self::factory()->user->create( array( 'role' => 'aggr_advertiser' ) );
		$org           = $services->get( Org_Repository::class )->create_for_owner( 'Report goals ' . $user, $user );
		$this->assertIsInt( $org );
		$this->org = $org;
		$this->reporting( true );
		update_option( 'timezone_string', 'UTC' );
		update_option( Rollup_Reconciler::OPTION, '2026-11-30', false );
	}

	/** Restore request-local test state. */
	public function tear_down(): void {
		wp_using_ext_object_cache( $this->external );
		$_GET = array();
		parent::tear_down();
	}

	/** Historical period metrics are separate from current settings and recorded totals. */
	public function test_selected_period_and_current_goal_are_distinct_and_shared_with_email(): void {
		$plan = $this->plan();
		$this->deliver( $plan, '2026-11-03', 20 );
		$this->deliver( $plan, '2026-11-10', 30 );
		$summary = $this->summary();
		$this->assertCount( 1, $summary['rows'] );
		$row     = $summary['rows'][0];
		$fields  = array_column( $row['fields'], 'value', 'label' );
		$metrics = array_column( $row['metrics'], 'value', 'label' );
		$this->assertSame( '100 Impressions', $fields['Current goal'] );
		$this->assertSame( '50', $fields['Recorded goal units so far'] );
		$this->assertSame( '50.0%', $fields['Goal progress'] );
		$this->assertSame( 'Within schedule tolerance', $fields['Goal pacing'] );
		$this->assertSame( '20', $metrics['Impressions'] );
		$this->assertSame( 'Not measured', $metrics['Conversions'] );
		$this->assertStringContainsString( '2026-11-01 to 2026-11-07 (UTC)', $summary['period'] );
		$this->assertStringContainsString( 'current settings', implode( ' ', $summary['notes'] ) );
		$message = Plugin::instance()->container()->get( Scheduled_Report_Mailer::class )->message( $this->org, $this->period(), $this->now() );
		$this->assertIsArray( $message );
		foreach ( array_merge( $row['fields'], $row['metrics'] ) as $field ) {
			$this->assertStringContainsString( $field['label'] . ': ' . $field['value'], $message['body'] );
		}
		$this->assertStringContainsString( $summary['freshness'], $message['body'] );
		$this->assertLessThanOrEqual( 32 * 1024, strlen( $message['body'] ) );
	}

	/** Distinct goal units never share a denominator or each other's counters. */
	public function test_multiple_plans_keep_goal_units_and_delivery_isolated(): void {
		$first  = $this->plan();
		$second = $this->extra_plan( $first );
		$this->deliver( $first, '2026-11-03', 5 );
		$this->deliver( $second, '2026-11-03', 20 );
		$writer = new Rollup_Repository();
		for ( $i = 0; $i < 2; ++$i ) {
			$this->assertTrue( $writer->increment( 'clicks', 1, $second['campaign_id'], '2026-11-03', $second['id'], $this->org ) );
		}
		$summary = $this->summary();
		$rows    = array_column( $summary['rows'], null, 'id' );
		$this->assertSame( '5', array_column( $rows[ $first['id'] ]['fields'], 'value', 'label' )['Recorded goal units so far'] );
		$this->assertSame( '2', array_column( $rows[ $second['id'] ]['fields'], 'value', 'label' )['Recorded goal units so far'] );
	}

	/** An out-of-tenant line-item row cannot expose its real parent campaign. */
	public function test_organization_and_parent_ownership_are_both_required(): void {
		global $wpdb;
		$own     = $this->plan( 'Owned campaign' );
		$foreign = $this->plan( 'Foreign campaign', 'impressions', 100, $this->org + 9999 );
		$this->deliver( $foreign, '2026-11-03', 9 );
		$this->assertCount( 1, $this->summary()['rows'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulate stale/forged child scope; the parent ownership guard must still exclude it.
		$this->assertSame( 1, $wpdb->update( $this->items->table_name(), array( 'organization_id' => $this->org ), array( 'id' => $foreign['id'] ) ) );
		$summary = $this->summary();
		$this->assertSame( array( $own['id'] ), array_column( $summary['rows'], 'id' ) );
		$this->assertStringNotContainsString( 'Foreign campaign', implode( "\n", $this->view->email_lines( $summary ) ) );
	}

	/** Attribution stores the delivery's tenant; later reads must not absorb another tenant's facts. */
	public function test_projection_rows_require_organization_scope(): void {
		$plan   = $this->plan();
		$writer = new Rollup_Repository();
		$this->assertTrue( $writer->increment( 'impressions', 1, $plan['campaign_id'], '2026-11-03', $plan['id'], $this->org + 9999 ) );
		$summary = $this->summary();
		$this->assertSame( '0', array_column( $summary['rows'][0]['metrics'], 'value', 'label' )['Impressions'] );
	}

	/** Empty history can mean zero impressions and unmeasured conversions. */
	public function test_zero_and_unmeasured_metrics_are_not_collapsed(): void {
		$this->plan();
		$metrics = array_column( $this->summary()['rows'][0]['metrics'], 'value', 'label' );
		$this->assertSame( '0', $metrics['Impressions'] );
		$this->assertSame( '0', $metrics['Clicks'] );
		$this->assertSame( '—', $metrics['CTR'] );
		$this->assertSame( 'Not measured', $metrics['Viewable'] );
		$this->assertSame( 'Not measured', $metrics['Conversions'] );
	}

	/** Unattributed campaign history cannot quietly become a plan's measured zero. */
	public function test_legacy_attribution_marks_current_and_period_totals_unavailable(): void {
		$plan = $this->plan();
		$this->assertTrue( ( new Rollup_Repository() )->increment( 'impressions', 1, $plan['campaign_id'], '2026-11-03', 0, $this->org ) );
		$summary = $this->summary();
		$this->assertSame( 'Not measured', array_column( $summary['rows'][0]['metrics'], 'value', 'label' )['Impressions'] );
		$this->assertSame( 'Not measured', array_column( $summary['rows'][0]['fields'], 'value', 'label' )['Recorded goal units so far'] );
		$this->assertStringContainsString( 'predates delivery-plan attribution', implode( ' ', $summary['notes'] ) );
	}

	/** New settings do not claim to be the settings in force in an old report period. */
	public function test_goal_edits_are_current_and_do_not_mutate_period_metrics(): void {
		$plan = $this->plan();
		$this->deliver( $plan, '2026-11-03', 5 );
		$this->assertIsInt( $this->items->update( $plan['id'], $plan['campaign_id'], array( 'goal_amount' => 500 ), $plan['revision'] ) );
		$summary = $this->summary();
		$this->assertSame( '500 Impressions', array_column( $summary['rows'][0]['fields'], 'value', 'label' )['Current goal'] );
		$this->assertSame( '5', array_column( $summary['rows'][0]['metrics'], 'value', 'label' )['Impressions'] );
	}

	/** Page cursors do not require an unbounded offset scan. */
	public function test_keyset_pagination_discloses_omitted_plans(): void {
		for ( $i = 0; $i < 12; ++$i ) {
			$this->plan( 'Paged campaign ' . $i );
		}
		$first = $this->reports->page( $this->org );
		$this->assertIsArray( $first );
		$this->assertCount( 10, $first['rows'] );
		$this->assertTrue( $first['more'] );
		$second = $this->reports->page( $this->org, $first['next'] );
		$this->assertIsArray( $second );
		$this->assertCount( 2, $second['rows'] );
		$this->assertFalse( $second['more'] );
		$this->assertSame( array(), array_intersect( array_column( $first['rows'], 'id' ), array_column( $second['rows'], 'id' ) ) );
		$summary = $this->summary();
		$this->assertStringContainsString( 'up to 10', $summary['limit_note'] );
		$this->assertStringContainsString( 'plans_before=', $summary['next_url'] );
	}

	/** Bounded names and escaped rendering preserve the actual text context. */
	public function test_names_are_bounded_and_email_cannot_inject_extra_lines(): void {
		$plan    = $this->plan( str_repeat( 'Long name ', 1000 ) );
		$summary = $this->summary();
		$this->assertLessThanOrEqual( 512, strlen( $summary['rows'][0]['campaign'] ) );
		$this->assertStringEndsWith( '…', $summary['rows'][0]['campaign'] );
		$this->assertIsInt( $this->items->update( $plan['id'], $plan['campaign_id'], array( 'name' => "<script>name</script>\nBcc: nobody@example.test" ), $plan['revision'] ) );
		$summary = $this->summary();
		$this->assertStringNotContainsString( "\n", $summary['rows'][0]['plan'] );
		$this->assertStringNotContainsString( '<script>', $summary['rows'][0]['plan'] );
	}

	/** A missing index or failed SQL is a visible refusal, never zero success. */
	public function test_failed_projection_read_is_error_and_recovers_without_mail(): void {
		global $wpdb;
		$this->plan();
		$break  = static fn ( string $sql ): string => str_contains( $sql, 'org_campaign_line_day' ) && str_starts_with( $sql, 'SELECT campaign_id' ) ? 'SELECT * FROM aggr_missing_campaign_report_test' : $sql;
		$errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$this->assertWPError( $this->view->for_org( $this->org, $this->period(), $this->now() ) );
			$this->assertWPError( Plugin::instance()->container()->get( Scheduled_Report_Mailer::class )->message( $this->org, $this->period(), $this->now() ) );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $errors );
		}
		$this->assertCount( 1, $this->summary()['rows'] );
	}

	/** The Reporting gate runs before candidate or projection queries. */
	public function test_reporting_off_omits_the_summary_without_querying_plans(): void {
		$this->plan();
		$this->reporting( false );
		$reads   = array();
		$capture = static function ( string $sql ) use ( &$reads ): string {
			if ( str_contains( $sql, 'organization_report' ) || str_contains( $sql, 'org_campaign_line_' ) ) {
				$reads[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $capture );
		try {
			$this->assertSame( array(), $this->view->for_org( $this->org, $this->period(), $this->now() ) );
			$this->assertSame( array(), $reads );
		} finally {
			remove_filter( 'query', $capture );
		}
	}

	/** Local midnight selects quarter-hour history, while current goal totals remain UTC. */
	public function test_local_period_respects_offset_boundary(): void {
		$plan   = $this->plan();
		$writer = new Rollup_Repository();
		foreach ( array( '2026-11-02 18:00:00 UTC', '2026-11-02 18:15:00 UTC' ) as $instant ) {
			$this->assertTrue( $writer->increment( 'impressions', 1, $plan['campaign_id'], '', $plan['id'], $this->org, 0, strtotime( $instant ) ) );
		}
		update_option( \Aggressive\Ads\Repository\Report_Bucket_Repository::OPTION_SINCE, strtotime( '2026-10-01 UTC' ), false );
		update_option( \Aggressive\Ads\Repository\Report_Bucket_Repository::OPTION_NEXT, 0, false );
		$period = Report_Period::between( '2026-11-03', '2026-11-03', 'Asia/Kathmandu' );
		$this->assertNotNull( $period );
		$summary = $this->view->for_org( $this->org, $period, $this->now() );
		$this->assertIsArray( $summary );
		$this->assertSame( '1', array_column( $summary['rows'][0]['metrics'], 'value', 'label' )['Impressions'] );
		$this->assertSame( '2', array_column( $summary['rows'][0]['fields'], 'value', 'label' )['Recorded goal units so far'] );
	}

	/** Dashboard scope comes from membership even when a URL supplies a foreign org. */
	public function test_dashboard_scope_and_output_guards_are_attached(): void {
		$own = $this->plan( 'Visible own campaign' );
		$this->plan( 'Foreign scope secret', 'impressions', 100, $this->org + 9999 );
		$owners = Plugin::instance()->container()->get( Org_Repository::class )->user_ids_for_org( $this->org );
		wp_set_current_user( $owners[0] );
		$_GET      = array(
			'from'   => '2026-11-01',
			'to'     => '2026-11-07',
			'tz'     => 'utc',
			'org_id' => (string) ( $this->org + 9999 ),
		);
		$translate = static fn ( string $translated, string $text, string $domain ): string => 'aggressive-ads' === $domain && 'Current goal' === $text ? '<b>Current goal marker</b>' : $translated;
		add_filter( 'gettext', $translate, 10, 3 );
		ob_start();
		try {
			require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-reports.php';
			$html = (string) ob_get_contents();
		} finally {
			ob_end_clean();
			remove_filter( 'gettext', $translate, 10 );
		}
		$this->assertStringContainsString( 'Visible own campaign', $html );
		$this->assertStringNotContainsString( 'Foreign scope secret', $html );
		$this->assertStringContainsString( '&lt;b&gt;Current goal marker&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>Current goal marker</b>', $html );
		$this->assertStringContainsString( '/campaigns/' . $own['campaign_id'] . '/', $html );
	}

	/** Unsupported monetary/audience goals do not manufacture native progress. */
	public function test_unsupported_goal_units_stay_unmeasured(): void {
		$this->plan( 'Spend plan', 'spend', 100 );
		$this->plan( 'Share plan', 'share_of_voice', 50 );
		foreach ( $this->summary()['rows'] as $row ) {
			$fields = array_column( $row['fields'], 'value', 'label' );
			$this->assertStringContainsString( '(Not measured)', $fields['Current goal'] );
			$this->assertSame( 'Not measured', $fields['Recorded goal units so far'] );
			$this->assertSame( 'Not measured', $fields['Goal progress'] );
			$this->assertSame( 'Not measured', $fields['Goal pacing'] );
		}
	}

	/** An invalid browser cursor cannot be coerced into a different id or tenant. */
	public function test_browser_cursor_is_strictly_validated(): void {
		foreach ( array( array( '42' ), '-1', '1 OR 1=1', '9223372036854775808', '0' ) as $raw ) {
			$_GET['plans_before'] = $raw;
			$this->assertSame( 0, Campaign_Report_View_Data::requested_cursor() );
		}
		$_GET['plans_before'] = '42';
		$this->assertSame( 42, Campaign_Report_View_Data::requested_cursor() );
	}

	/**
	 * Create through the real campaign/default-plan writer, then revise its goal.
	 *
	 * @param string $name Campaign identity.
	 * @param string $goal Native/unsupported goal type.
	 * @param int $amount Configured amount.
	 * @param int|null $org Optional foreign tenant fixture.
	 * @return array<string, mixed>
	 */
	private function plan( string $name = 'Report campaign', string $goal = 'impressions', int $amount = 100, ?int $org = null ): array {
		$campaign = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => 'aggr_live',
				'post_title'  => $name,
			)
		);
		update_post_meta( $campaign, Campaign_Repository::META_ORG_ID, $org ?? $this->org );
		update_post_meta( $campaign, Campaign_Repository::META_START_TS, strtotime( '2026-11-01 00:00:00 UTC' ) );
		update_post_meta( $campaign, Campaign_Repository::META_END_TS, strtotime( '2026-12-01 00:00:00 UTC' ) );
		$row = $this->items->ensure_default( $campaign );
		$this->assertIsArray( $row );
		$this->assertIsInt(
			$this->items->update(
				$row['id'],
				$campaign,
				array(
					'goal_type'   => $goal,
					'goal_amount' => $amount,
				),
				$row['revision']
			)
		);
		$result = $this->items->default_for_campaign( $campaign );
		$this->assertIsArray( $result );
		return $result;
	}

	/**
	 * A second delivery plan on the same campaign exercises per-plan attribution.
	 *
	 * @param array<string, mixed> $first Real default plan.
	 * @return array<string, mixed>
	 */
	private function extra_plan( array $first ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Multi-plan creation is not a public workflow yet; seed its physical identity, then use the real settings writer.
		$wpdb->insert(
			$this->items->table_name(),
			array(
				'campaign_id'     => $first['campaign_id'],
				'organization_id' => $this->org,
				'name'            => 'Click plan',
				'name_is_derived' => 0,
				'status'          => 'live',
				'start_at_ts'     => $first['start_at_ts'],
				'end_at_ts'       => $first['end_at_ts'],
			)
		);
		$id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $id );
		$this->assertIsInt(
			$this->items->update(
				$id,
				$first['campaign_id'],
				array(
					'goal_type'   => 'clicks',
					'goal_amount' => 10,
				),
				1
			)
		);
		$plans = array_column( $this->items->for_campaign( $first['campaign_id'] ), null, 'id' );
		return $plans[ $id ];
	}

	/**
	 * Increment actual daily and bucket projections through the native writer.
	 *
	 * @param array<string, mixed> $plan Stored plan.
	 * @param string $day UTC delivery day.
	 * @param int $count Impression count.
	 */
	private function deliver( array $plan, string $day, int $count ): void {
		$writer = new Rollup_Repository();
		for ( $i = 0; $i < $count; ++$i ) {
			$this->assertTrue( $writer->increment( 'impressions', 1, $plan['campaign_id'], $day, $plan['id'], $plan['organization_id'] ) );
		}
	}

	/**
	 * Error-aware shared view; no fixture supplies the calculated output.
	 *
	 * @return array<string, mixed>
	 */
	private function summary(): array {
		$result = $this->view->for_org( $this->org, $this->period(), $this->now() );
		$this->assertIsArray( $result );
		return $result;
	}

	/** Selected period is already closed at the snapshot clock. */
	private function period(): Report_Period {
		$period = Report_Period::between( '2026-11-01', '2026-11-07', 'UTC' );
		$this->assertNotNull( $period );
		return $period;
	}

	/** A literal midpoint of the configured flight. */
	private function now(): int {
		return strtotime( '2026-11-16 00:00:00 UTC' );
	}

	/**
	 * Change the actual reporting module gate.
	 *
	 * @param bool $enabled Whether reporting surfaces are enabled.
	 */
	private function reporting( bool $enabled ): void {
		$settings = Plugin::instance()->container()->get( Settings::class );
		$data     = $settings->get();
		$data['modules'][ Settings_Schema::MODULE_REPORTING ] = $enabled;
		$this->assertTrue( $settings->save( $data ) );
	}
}
