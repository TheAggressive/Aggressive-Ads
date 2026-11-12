<?php
/**
 * Scheduled report authorization, bounded retries, and ambiguous delivery recovery.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Notification\Scheduled_Report_Mailer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Delivery_View_Data;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Report_Subscription_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Rollup_Reconciler;
use Aggressive\Ads\Workflow\Scheduled_Reports;
use WP_UnitTestCase;

final class ScheduledReportsTest extends WP_UnitTestCase {

	use \Aggressive\Ads\Tests\Redirect_Trap;

	/**
	 * Scheduling workflow.
	 *
	 * @var Scheduled_Reports
	 */
	private Scheduled_Reports $worker;
	/**
	 * Fresh durable state.
	 *
	 * @var Report_Subscription_Repository
	 */
	private Report_Subscription_Repository $subscriptions;
	/**
	 * Organization writer and membership reader.
	 *
	 * @var Org_Repository
	 */
	private Org_Repository $orgs;
	/**
	 * Subscriber account.
	 *
	 * @var int
	 */
	private int $user;
	/**
	 * Subscriber organization.
	 *
	 * @var int
	 */
	private int $org;
	/**
	 * Measured campaign.
	 *
	 * @var int
	 */
	private int $campaign;
	/**
	 * Timezone restored after each test.
	 *
	 * @var string
	 */
	private string $original_zone;
	/**
	 * Original external-cache flag.
	 *
	 * @var bool
	 */
	private bool $external;
	/**
	 * Intercepted transport submissions.
	 *
	 * @var array
	 */
	private array $messages = array();
	/**
	 * Transport acceptance switch.
	 *
	 * @var bool
	 */
	private bool $accept = true;
	/**
	 * Simulate an ambiguous transport exception.
	 *
	 * @var bool
	 */
	private bool $throw = false;
	/**
	 * Mail interceptor removed on teardown.
	 *
	 * @var \Closure
	 */
	private \Closure $capture;

	/** Build real member/campaign records; every mail call is intercepted. */
	public function set_up(): void {
		( new Report_Subscription_Repository() )->install_table();
		parent::set_up();
		$this->external = true === wp_using_ext_object_cache();
		wp_using_ext_object_cache( false );
		$this->original_zone = (string) get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'UTC' );
		$this->capture = function ( $before, array $args ) {
			if ( null !== $before ) {
				return $before;
			}
			$this->messages[] = $args;
			if ( $this->throw ) {
				throw new \RuntimeException( 'private-transport-detail@example.test' );
			}
			return $this->accept;
		};
		add_filter( 'pre_wp_mail', $this->capture, 10, 2 );
		$container           = Plugin::instance()->container();
		$this->worker        = $container->get( Scheduled_Reports::class );
		$this->subscriptions = $container->get( Report_Subscription_Repository::class );
		$this->orgs          = $container->get( Org_Repository::class );
		$this->user          = (int) self::factory()->user->create(
			array(
				'role'       => 'aggr_advertiser',
				'user_email' => 'report-member-' . wp_rand() . '@example.test',
			)
		);
		$org                 = $this->orgs->create_for_owner( 'Report organization ' . $this->user, $this->user );
		$this->assertIsInt( $org );
		$this->org      = $org;
		$this->campaign = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => 'aggr_live',
				'post_title'  => 'Report campaign',
			)
		);
		update_post_meta( $this->campaign, Campaign_Repository::META_ORG_ID, $this->org );
		$this->reporting( true );
		update_option( Rollup_Reconciler::OPTION, '2026-10-31', false );
		$this->messages = array();
	}

	/** Restore request-global state and transport interception. */
	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', $this->capture, 10 );
		update_option( 'timezone_string', $this->original_zone );
		wp_using_ext_object_cache( $this->external );
		parent::tear_down();
	}

	/** Default-off upgrades do not create recipients or send merely on page load. */
	public function test_defaults_are_off_and_hooks_are_attached(): void {
		$this->assertNull( $this->subscriptions->for_member( $this->org, $this->user ) );
		$this->assertSame( 0, $this->worker->run( $this->due() ) );
		$this->assertSame( array(), $this->messages );
		$this->assertNotFalse( has_action( Scheduled_Reports::HOOK, array( $this->worker, 'run_scheduled' ) ) );
		$this->assertNotFalse( has_action( 'init', array( $this->worker, 'ensure_scheduled' ) ) );
		$this->assertSame( 300, wp_get_schedules()[ Scheduled_Reports::RECURRENCE ]['interval'] );
	}

	/** Real writes reach the same formatting as the portal without a signed-in session. */
	public function test_weekly_report_uses_shared_facts_and_freshness_and_sends_once(): void {
		$this->seed_delivery();
		$this->subscribe();
		wp_set_current_user( 0 );
		$this->assertSame( 1, $this->worker->run( $this->due() ) );
		$this->assertSame( 0, $this->worker->run( $this->due() ) );
		$this->assertCount( 1, $this->messages );
		$mail = $this->messages[0];
		$this->assertSame( (string) get_userdata( $this->user )->user_email, $mail['to'] );
		$this->assertSame( array(), $mail['attachments'] );
		$this->assertStringContainsString( '2026-10-26 to 2026-11-01 (UTC)', $mail['message'] );
		$this->assertStringContainsString( 'Provisional', $mail['message'] );
		$this->assertStringContainsString( 'still being counted', $mail['message'] );
		$this->assertStringContainsString( 'Source: native delivery.', $mail['message'] );
		$period  = \Aggressive\Ads\Domain\Report_Cadence::period( 'weekly', $this->due(), 'UTC' );
		$display = Plugin::instance()->container()->get( Delivery_View_Data::class );
		foreach ( $display->counts( $this->org, $period ) as $tile ) {
			$this->assertStringContainsString( $tile['label'] . ': ' . $tile['value'], $mail['message'] );
		}
		$this->assertStringContainsString( '/advertiser/', $mail['message'] );
		$this->assertStringContainsString( 'from=2026-10-26', $mail['message'] );
		$this->assertStringContainsString( '/account/#aggr-report-emails', $mail['message'] );
		$row = $this->row();
		$this->assertSame( 'queued', $row['state'] );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertSame( strtotime( '2026-11-09 09:00:00 UTC' ), (int) $row['due_at'] );
	}

	/** A delayed worker sends one current closed period, never every missed week. */
	public function test_missed_cron_sends_only_the_latest_period(): void {
		$this->subscribe();
		$now = strtotime( '2026-11-27 15:00:00 UTC' );
		$this->assertSame( 1, $this->worker->run( $now ) );
		$this->assertCount( 1, $this->messages );
		$this->assertStringContainsString( '2026-11-16 to 2026-11-22', $this->messages[0]['message'] );
		$this->assertGreaterThan( $now, (int) $this->row()['due_at'] );
	}

	/** Calendar monthly delivery uses the full previous month. */
	public function test_monthly_delivery_and_unchanged_save_do_not_rearm_work(): void {
		$this->assertTrue( $this->worker->configure( $this->org, $this->user, 'monthly', strtotime( '2026-10-31 12:00:00 UTC' ) ) );
		$now = strtotime( '2026-11-01 09:00:00 UTC' );
		$this->assertSame( 1, $this->worker->run( $now ) );
		$revision = $this->row()['revision'];
		$this->assertTrue( $this->worker->configure( $this->org, $this->user, 'monthly', $now ) );
		$this->assertSame( $revision, $this->row()['revision'] );
		$this->assertSame( 0, $this->worker->run( $now ) );
		$this->assertStringContainsString( '2026-10-01 to 2026-10-31', $this->messages[0]['message'] );
	}

	/** Explicit transport failure retries only at its due time and stops after four attempts. */
	public function test_failures_back_off_and_exhaust_instead_of_retrying_forever(): void {
		$this->subscribe();
		$this->accept = false;
		$now          = $this->due();
		foreach ( array( 300, 1800, 7200 ) as $index => $delay ) {
			$this->assertSame( 0, $this->worker->run( $now ) );
			$this->assertSame( 'retry', $this->row()['state'] );
			$this->assertSame( $index + 1, (int) $this->row()['attempts'] );
			$this->assertSame( $now + $delay, (int) $this->row()['due_at'] );
			$this->assertSame( 0, $this->worker->run( $now + $delay - 1 ) );
			$now += $delay;
		}
		$this->assertSame( 0, $this->worker->run( $now ) );
		$this->assertSame( 'failed', $this->row()['state'] );
		$this->assertSame( 4, (int) $this->row()['attempts'] );
		$this->assertCount( 4, $this->messages );
		$this->assertGreaterThan( $now, (int) $this->row()['due_at'] );
	}

	/** Successful retry is a single queued report, and future sweeps do not resend it. */
	public function test_failed_transport_recovers_on_a_bounded_retry(): void {
		$this->subscribe();
		$this->accept = false;
		$this->assertSame( 0, $this->worker->run( $this->due() ) );
		$this->accept = true;
		$this->assertSame( 1, $this->worker->run( $this->due() + 300 ) );
		$this->assertSame( 0, $this->worker->run( $this->due() + 301 ) );
		$this->assertCount( 2, $this->messages );
		$this->assertSame( 'queued', $this->row()['state'] );
	}

	/** Thrown calls may have been accepted; automatically resending would risk duplication. */
	public function test_ambiguous_transport_is_not_automatically_resent(): void {
		$this->subscribe();
		$this->throw = true;
		$this->assertSame( 0, $this->worker->run( $this->due() ) );
		$this->assertSame( 'uncertain', $this->row()['state'] );
		$this->assertSame( 'transport_uncertain', $this->row()['last_error'] );
		$this->throw = false;
		$this->assertSame( 0, $this->worker->run( $this->due() + 7200 ) );
		$this->assertCount( 1, $this->messages );
		$this->assertSame( 1, $this->worker->run( strtotime( '2026-11-09 09:00:00 UTC' ) ) );
	}

	/** Persisted sending means a previous process could have handed the message off. */
	public function test_interrupted_sender_is_marked_uncertain_without_sending_again(): void {
		$this->subscribe();
		$row = $this->row();
		$this->assertTrue(
			$this->subscriptions->transition(
				$row,
				array(
					'state'        => 'sending',
					'period_start' => '2026-10-26',
					'period_end'   => '2026-11-01',
					'attempts'     => 1,
				)
			)
		);
		$this->assertSame( 0, $this->worker->run( $this->due() ) );
		$this->assertSame( 'uncertain', $this->row()['state'] );
		$this->assertSame( array(), $this->messages );
	}

	/** Revocation after failure closes the retry path, as do suspension and opting out. */
	public function test_retry_rechecks_membership_and_stops_after_revocation(): void {
		$this->subscribe();
		$this->accept = false;
		$this->worker->run( $this->due() );
		delete_post_meta( $this->org, Org_Repository::META_OWNER_USER );
		$this->accept = true;
		$this->assertSame( 0, $this->worker->run( $this->due() + 300 ) );
		$this->assertSame( 'blocked', $this->row()['state'] );
		$this->assertSame( 0, (int) $this->row()['enabled'] );
		$this->assertCount( 1, $this->messages );
	}

	/** Reporting-off leaves preferences intact but prevents any mail/read attempt. */
	public function test_reporting_off_pauses_and_disabled_preferences_stay_off(): void {
		$this->subscribe();
		$this->reporting( false );
		$this->assertSame( 0, $this->worker->run( $this->due() ) );
		$this->assertSame( 1, (int) $this->row()['enabled'] );
		$this->assertTrue( $this->worker->configure( $this->org, $this->user, 'off' ) );
		$this->reporting( true );
		$this->assertSame( 0, $this->worker->run( $this->due() + 7 * DAY_IN_SECONDS ) );
		$this->assertSame( array(), $this->messages );
	}

	/** SQL failure does not produce an authoritative emailed row of zeros. */
	public function test_report_read_failure_retries_without_sending_empty_metrics(): void {
		global $wpdb;
		$this->subscribe();
		$fail = static fn ( string $sql ): string => str_starts_with( $sql, 'SELECT COALESCE(SUM(r.impressions)' ) ? 'SELECT aggr_missing_report_column' : $sql;
		$old  = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$this->assertSame( 0, $this->worker->run( $this->due() ) );
			$this->assertSame( 'retry', $this->row()['state'] );
			$this->assertSame( array(), $this->messages );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $old );
		}
		$this->assertSame( 1, $this->worker->run( $this->due() + 300 ) );
	}

	/** A recursive or overlapping worker cannot reserve the same subscription. */
	public function test_reentrant_worker_is_refused_while_sending(): void {
		$this->subscribe();
		$nested   = null;
		$callback = function ( $before ) use ( &$nested ) {
			$nested = $this->worker->run( $this->due() );
			return $before;
		};
		add_filter( 'pre_wp_mail', $callback, 5 );
		try {
			$this->assertSame( 1, $this->worker->run( $this->due() ) );
			$this->assertSame( 0, $nested );
			$this->assertCount( 1, $this->messages );
		} finally {
			remove_filter( 'pre_wp_mail', $callback, 5 );
		}
	}

	/** Recurrence repairs missing and drifted schedules. */
	public function test_worker_schedule_repairs_recurrence_and_uninstall_adapter_removes_it(): void {
		Scheduled_Reports::unschedule();
		wp_schedule_event( time() + 10, 'daily', Scheduled_Reports::HOOK );
		$this->worker->ensure_scheduled();
		$this->assertSame( Scheduled_Reports::RECURRENCE, wp_get_schedule( Scheduled_Reports::HOOK ) );
		$this->worker->ensure_scheduled();
		$this->assertSame( Scheduled_Reports::RECURRENCE, wp_get_schedule( Scheduled_Reports::HOOK ) );
		Scheduled_Reports::unschedule();
		$this->assertFalse( wp_next_scheduled( Scheduled_Reports::HOOK ) );
	}

	/** A nonce never grants access to an anonymous or incapable account. */
	public function test_preference_handler_requires_capability_and_nonce(): void {
		$actions = Plugin::instance()->container()->get( \Aggressive\Ads\Portal\Report_Preference_Actions::class );
		$handler = static function (): \Closure {
			return static function (): never {
				throw new \RuntimeException( 'denied' );
			};
		};
		add_filter( 'wp_die_handler', $handler );
		try {
			$incapable = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
			foreach ( array( 0, $this->user, $incapable ) as $user ) {
				wp_set_current_user( $user );
				$_POST    = array( 'cadence' => 'weekly' );
				$_REQUEST = $incapable === $user ? array( '_wpnonce' => wp_create_nonce( \Aggressive\Ads\Portal\Report_Preference_Actions::SAVE_ACTION ) ) : array();
				try {
					$actions->handle_save();
					$this->fail( 'Unprotected preference write.' );
				} catch ( \RuntimeException $error ) {
					$this->assertSame( 'denied', $error->getMessage() );
				}
				$this->assertNull( $this->subscriptions->for_member( $this->org, $this->user ) );
			}
		} finally {
			remove_filter( 'wp_die_handler', $handler );
			$_POST    = array();
			$_REQUEST = array();
		}
	}

	/** Submitted tenant/account ids are ignored; only the current member changes. */
	public function test_preference_handler_uses_session_scope_and_optout_works_while_paused(): void {
		$other     = (int) self::factory()->user->create( array( 'role' => 'aggr_advertiser' ) );
		$other_org = $this->orgs->create_for_owner( 'Other member', $other );
		$this->assertIsInt( $other_org );
		wp_set_current_user( $this->user );
		$actions = Plugin::instance()->container()->get( \Aggressive\Ads\Portal\Report_Preference_Actions::class );
		try {
			$_POST                = array(
				'cadence' => 'weekly',
				'user_id' => $other,
				'org_id'  => $other_org,
			);
			$_REQUEST['_wpnonce'] = wp_create_nonce( \Aggressive\Ads\Portal\Report_Preference_Actions::SAVE_ACTION );
			$locations            = $this->trap_redirects( static fn () => $actions->handle_save() );
			$this->assertCount( 1, $locations );
			$this->assertStringContainsString( 'report_notice=saved', $locations[0] );
			$this->assertStringEndsWith( '#aggr-report-emails', $locations[0] );
			$this->assertSame( 'weekly', $this->row()['cadence'] );
			$this->assertNull( $this->subscriptions->for_member( $other_org, $other ) );
			$view = $actions->view_data();
			$this->assertSame( 'weekly', $view['cadence'] );
			$this->assertNotEmpty( $view['next'] );
			$this->assertSame( 'Scheduled', $view['status'] );
			$this->assertSame( array(), $this->messages );
			$this->reporting( false );
			$_POST['cadence'] = 'off';
			$this->assertCount( 1, $this->trap_redirects( static fn () => $actions->handle_save() ) );
			$this->assertSame( '0', $this->row()['enabled'] );
			$this->assertSame( '', $actions->view_data()['next'] );
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
	}

	/** A failed settings read remains visible instead of claiming Off. */
	public function test_account_read_failure_is_visible(): void {
		global $wpdb;
		wp_set_current_user( $this->user );
		$break  = fn ( string $sql ): string => str_contains( $sql, 'SELECT * FROM `' . $this->subscriptions->table_name() . '` WHERE org_id=' ) ? 'SELECT * FROM aggr_missing_subscription_test' : $sql;
		$errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$actions = Plugin::instance()->container()->get( \Aggressive\Ads\Portal\Report_Preference_Actions::class );
			$this->assertSame( array( 'unavailable' => true ), $actions->view_data() );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $errors );
		}
	}

	/** Account deletion removes its own row; a different member survives. */
	public function test_deletion_hooks_remove_only_the_deleted_member_or_organization(): void {
		$member = (int) self::factory()->user->create( array( 'role' => 'aggr_advertiser' ) );
		$this->assertTrue( $this->orgs->add_member( $this->org, $member ) );
		$this->assertTrue( $this->worker->configure( $this->org, $member, 'weekly', $this->due() - 3600 ) );
		$this->subscribe();
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$this->assertTrue( wp_delete_user( $member, $this->user ) );
		$this->assertNull( $this->subscriptions->for_member( $this->org, $member ) );
		$this->assertIsArray( $this->row() );
		wp_delete_post( $this->org, true );
		$this->assertNull( $this->subscriptions->for_member( $this->org, $this->user ) );
	}

	/** Access revoked during report generation prevents transport submission. */
	public function test_authorization_is_rechecked_after_report_generation(): void {
		$this->subscribe();
		$revoke = function ( string $sql ): string {
			if ( str_starts_with( $sql, 'SELECT COALESCE(SUM(r.impressions)' ) ) {
				$user = new \WP_User( $this->user );
				$user->add_cap( Capabilities::ACCESS_PORTAL, false );
			}
			return $sql;
		};
		add_filter( 'query', $revoke );
		try {
			$this->assertSame( 0, $this->worker->run( $this->due() ) );
			$this->assertSame( array(), $this->messages );
			$this->assertSame( 'blocked', $this->row()['state'] );
		} finally {
			remove_filter( 'query', $revoke );
		}
	}

	/** Account reflects actual retry, acceptance, and uncertain worker outcomes. */
	public function test_account_discloses_delivery_outcomes_and_missing_cron(): void {
		$this->subscribe();
		wp_set_current_user( $this->user );
		$this->worker->ensure_scheduled();
		$actions      = Plugin::instance()->container()->get( \Aggressive\Ads\Portal\Report_Preference_Actions::class );
		$this->accept = false;
		$this->assertSame( 0, $this->worker->run( $this->due() ) );
		$this->assertSame( 'Waiting to retry.', $actions->view_data()['status'] );
		$this->accept = true;
		$this->assertSame( 1, $this->worker->run( $this->due() + 300 ) );
		$view = $actions->view_data();
		$this->assertSame( 'Queued for delivery. Inbox delivery is not confirmed.', $view['status'] );
		$this->assertNotEmpty( $view['last_queued'] );
		$this->throw = true;
		$this->assertSame( 0, $this->worker->run( (int) $this->row()['due_at'] ) );
		$this->assertSame( 'The last delivery could not be confirmed and will not be resent automatically.', $actions->view_data()['status'] );
		Scheduled_Reports::unschedule();
		try {
			$this->assertSame( 'Report scheduling is unavailable. Please contact site staff.', $actions->view_data()['status'] );
		} finally {
			$this->worker->ensure_scheduled();
		}
	}

	/** Product identity is retained literally in the subject and bounded headers. */
	public function test_white_label_identity_does_not_expand_header_replacement_backreferences(): void {
		$settings                      = Plugin::instance()->container()->get( Settings::class );
		$data                          = $settings->get();
		$data['brand']['product_name'] = 'Report $1 & Partners';
		$this->assertTrue( $settings->save( $data ) );
		$this->subscribe();
		$this->assertSame( 1, $this->worker->run( $this->due() ) );
		$mail = $this->messages[0];
		$this->assertStringStartsWith( 'Report $1 & Partners — ', $mail['subject'] );
		$this->assertCount( 2, $mail['headers'] );
		$this->assertStringStartsWith( 'From: Report $1 & Partners <', $mail['headers'][0] );
		$this->assertSame( 'Content-Type: text/plain; charset=UTF-8', $mail['headers'][1] );
		$this->assertStringNotContainsString( "\r", implode( '', $mail['headers'] ) );
		$this->assertStringNotContainsString( "\n", implode( '', $mail['headers'] ) );
	}

	/** An independent request cannot invalidate this worker's in-process meta cache. */
	public function test_send_time_authorization_discards_cached_membership(): void {
		global $wpdb;
		$this->subscribe();
		$changed = false;
		$revoke  = function ( string $sql ) use ( &$changed, $wpdb ): string {
			if ( ! $changed && str_starts_with( $sql, 'SELECT COALESCE(SUM(r.impressions)' ) ) {
				$changed = true;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Simulate a second request's committed revocation without invalidating this request's in-memory post-meta cache.
				$this->assertSame(
					1,
					$wpdb->update(
						$wpdb->postmeta,
						array( 'meta_value' => '0' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The simulated revocation updates one indexed post_id/meta_key, not a meta-value search.
						array(
							'post_id'  => $this->org,
							'meta_key' => Org_Repository::META_OWNER_USER,
						)
					)
				);
			}
			return $sql;
		};
		add_filter( 'query', $revoke );
		try {
			$this->assertSame( 0, $this->worker->run( $this->due() ) );
			$this->assertTrue( $changed );
			$this->assertSame( array(), $this->messages );
			$this->assertSame( 'blocked', $this->row()['state'] );
		} finally {
			remove_filter( 'query', $revoke );
			clean_post_cache( $this->org );
		}
	}

	/** Account ids, not copied addresses, identify the current recipient. */
	public function test_current_account_address_is_resolved_at_send_time(): void {
		$this->subscribe();
		$this->assertSame(
			$this->user,
			wp_update_user(
				array(
					'ID'         => $this->user,
					'user_email' => 'updated-report-recipient@example.test',
				)
			)
		);
		$this->messages = array();
		$this->assertSame( 1, $this->worker->run( $this->due() ) );
		$this->assertSame( 'updated-report-recipient@example.test', $this->messages[0]['to'] );
	}

	/** A suspended tenant cannot receive its pending report. */
	public function test_suspension_disables_pending_delivery(): void {
		$this->subscribe();
		$this->assertTrue( $this->orgs->set_state( $this->org, Org_Repository::STATE_SUSPENDED ) );
		$this->assertSame( 0, $this->worker->run( $this->due() ) );
		$this->assertSame( array(), $this->messages );
		$this->assertSame( 0, (int) $this->row()['enabled'] );
	}

	/** A capability or arbitrary recipient id cannot replace actual organization membership. */
	public function test_other_organizations_and_nonmember_administrators_cannot_subscribe(): void {
		$other = (int) self::factory()->user->create( array( 'role' => 'aggr_advertiser' ) );
		$admin = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertWPError( $this->worker->configure( $this->org, $other, 'weekly' ) );
		$this->assertWPError( $this->worker->configure( $this->org, $admin, 'weekly' ) );
		$this->assertNull( $this->subscriptions->for_member( $this->org, $other ) );
		$this->assertWPError( $this->worker->configure( $this->org, $this->user, 'daily' ) );
		$this->assertTrue( $this->worker->configure( $this->org, $this->user, 'off' ) );
		$this->assertNull( $this->subscriptions->for_member( $this->org, $this->user ) );
	}

	/** Old expected-state snapshots cannot overwrite an opt-out or cadence change. */
	public function test_stale_transition_cannot_reenable_a_disabled_subscription(): void {
		$this->subscribe();
		$old = $this->row();
		$this->assertTrue( $this->worker->configure( $this->org, $this->user, 'off' ) );
		$this->assertFalse( $this->subscriptions->transition( $old, array( 'state' => 'sending' ) ) );
		$this->assertSame( 0, $this->worker->run( $this->due() ) );
		$this->assertSame( array(), $this->messages );
	}

	/** Site clock changes move future work instead of mailing a mismatched portal range. */
	public function test_timezone_change_reschedules_without_sending_the_old_window(): void {
		$this->subscribe();
		update_option( 'timezone_string', 'Asia/Kathmandu' );
		$this->assertSame( 0, $this->worker->run( $this->due() ) );
		$row = $this->row();
		$this->assertSame( 'Asia/Kathmandu', $row['timezone'] );
		$this->assertGreaterThan( $this->due(), (int) $row['due_at'] );
		$this->assertSame( array(), $this->messages );
		$this->assertSame( 1, $this->worker->run( (int) $row['due_at'] ) );
		$this->assertStringContainsString( '(Asia/Kathmandu)', $this->messages[0]['message'] );
		$this->assertStringContainsString( 'Not measured', $this->messages[0]['message'] );
		$this->assertStringNotContainsString( 'Impressions: 0', $this->messages[0]['message'] );
	}

	/** Losing the completion write after transport acceptance must never trigger another send. */
	public function test_failed_completion_write_preserves_uncertain_recovery(): void {
		global $wpdb;
		$this->subscribe();
		$table = $this->subscriptions->table_name();
		$fail  = static fn ( string $sql ): string => str_starts_with( $sql, 'UPDATE `' . $table . '`' ) && str_contains( $sql, "`state` = 'queued'" ) ? 'UPDATE aggr_missing_completion_table SET id=0' : $sql;
		$old   = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$this->assertSame( 0, $this->worker->run( $this->due() ) );
			$this->assertCount( 1, $this->messages );
			$this->assertSame( 'sending', $this->row()['state'] );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $old );
		}
		$this->assertSame( 0, $this->worker->run( $this->due() + 300 ) );
		$this->assertSame( 'uncertain', $this->row()['state'] );
		$this->assertCount( 1, $this->messages );
	}

	/** The second connection proves contention, rather than same-session reentrancy. */
	public function test_another_database_session_blocks_an_overlapping_worker(): void {
		global $wpdb;
		$this->subscribe();
		$lock  = 'aggr_report_' . substr( hash( 'sha256', get_current_blog_id() . ':worker' ), 0, 48 );
		$other = new \wpdb( $wpdb->dbuser, $wpdb->dbpassword, $wpdb->dbname, $wpdb->dbhost );
		$this->assertSame( 1, (int) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) );
		try {
			$this->assertSame( 0, $this->worker->run( $this->due() ) );
			$this->assertSame( array(), $this->messages );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			$other->close();
		}
		$this->assertSame( 1, $this->worker->run( $this->due() ) );
	}

	/** A batch limit is visible in actual recipient calls and the next pass makes progress. */
	public function test_worker_sends_at_most_twenty_messages_per_pass(): void {
		$this->subscribe();
		for ( $i = 0; $i < 20; ++$i ) {
			$id = (int) self::factory()->user->create( array( 'role' => 'aggr_advertiser' ) );
			$this->assertTrue( $this->orgs->add_member( $this->org, $id ) );
			$this->assertTrue( $this->worker->configure( $this->org, $id, 'weekly', strtotime( '2026-11-02 08:00:00 UTC' ) ) );
		}
		$this->assertSame( 20, $this->worker->run( $this->due() ) );
		$this->assertCount( 20, $this->messages );
		$this->assertSame( 1, $this->worker->run( $this->due() ) );
		$this->assertCount( 21, array_unique( array_column( $this->messages, 'to' ) ) );
	}

	/** Transport details, account addresses and report values never enter the audit payload. */
	public function test_audit_context_contains_no_addresses_or_report_contents(): void {
		global $wpdb;
		$this->subscribe();
		$this->throw = true;
		$this->worker->run( $this->due() );
		$table = ( new \Aggressive\Ads\Repository\Audit_Repository() )->table_name();
		$rows  = $wpdb->get_col( $wpdb->prepare( 'SELECT context FROM %i WHERE object_type=%s AND org_id=%d', $table, 'report_subscription', $this->org ) );
		$this->assertCount( 2, $rows );
		$text = implode( "\n", $rows );
		$this->assertStringNotContainsString( '@', $text );
		$this->assertStringNotContainsString( 'private-transport-detail', $text );
		$this->assertStringNotContainsString( 'Impressions:', $text );
	}

	/** Shared measured writer, including nullable viewability/conversion counters. */
	private function seed_delivery(): void {
		$rollups = new Rollup_Repository();
		foreach ( array(
			'impressions' => 10,
			'clicks'      => 2,
			'viewables'   => 1,
			'conversions' => 1,
		) as $column => $count ) {
			for ( $i = 0; $i < $count; ++$i ) {
				$this->assertTrue( $rollups->increment( $column, 1, $this->campaign, '2026-10-29', 0, $this->org ) );
			}
		}
	}

	/** Enable before the next Monday release, without sending immediately. */
	private function subscribe(): void {
		$this->assertTrue( $this->worker->configure( $this->org, $this->user, 'weekly', strtotime( '2026-11-02 08:00:00 UTC' ) ) );
	}

	/** Literal release clock for stable period/retry tests. */
	private function due(): int {
		return strtotime( '2026-11-02 09:00:00 UTC' );
	}

	/**
	 * Read the durable outcome.
	 *
	 * @return array<string, mixed> Current durable state.
	 */
	private function row(): array {
		$row = $this->subscriptions->for_member( $this->org, $this->user );
		$this->assertIsArray( $row );
		return $row;
	}

	/**
	 * Change the actual reporting module gate.
	 *
	 * @param bool $enabled Reporting module gate.
	 */
	private function reporting( bool $enabled ): void {
		$settings = Plugin::instance()->container()->get( Settings::class );
		$data     = $settings->get();
		$data['modules'][ Settings_Schema::MODULE_REPORTING ] = $enabled;
		$this->assertTrue( $settings->save( $data ) );
	}
}
