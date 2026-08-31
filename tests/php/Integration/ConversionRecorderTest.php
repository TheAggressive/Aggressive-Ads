<?php
/**
 * Recording a conversion, end to end against real MySQL.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Conversion_Attribution;
use Aggressive\Ads\Domain\Conversion_Definition;
use Aggressive\Ads\Domain\Conversion_Rules;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Conversion_Definition_Repository;
use Aggressive\Ads\Repository\Conversion_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Workflow\Conversion_Metrics;
use Aggressive\Ads\Workflow\Conversion_Recorder;
use Aggressive\Ads\Workflow\Fill_Token;
use WP_UnitTestCase;

/**
 * The read half and the write half meeting, which is the thing that has gone
 * wrong here before: frequency capping shipped complete and capped nobody
 * because nothing called `increment()`. Every assertion below goes through the
 * production recorder rather than arranging its own rows.
 */
final class ConversionRecorderTest extends WP_UnitTestCase {

	private const CLICK_AT = 1700000000;

	/**
	 * Recorder under test.
	 *
	 * @var Conversion_Recorder
	 */
	private Conversion_Recorder $recorder;

	/**
	 * Conversion ledger.
	 *
	 * @var Conversion_Repository
	 */
	private Conversion_Repository $conversions;

	/**
	 * Definition persistence.
	 *
	 * @var Conversion_Definition_Repository
	 */
	private Conversion_Definition_Repository $definitions;

	/**
	 * Event ledger.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $events;

	/**
	 * Reporting projection.
	 *
	 * @var Rollup_Repository
	 */
	private Rollup_Repository $rollups;

	/**
	 * Token minting.
	 *
	 * @var Fill_Token
	 */
	private Fill_Token $tokens;

	/**
	 * Refusal counters.
	 *
	 * @var Conversion_Metrics
	 */
	private Conversion_Metrics $metrics;

	/**
	 * Placement the fill was for.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Campaign owning the fill.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * Creative that was served.
	 *
	 * @var int
	 */
	private int $creative_id;

	/**
	 * Organization owning the campaign.
	 *
	 * @var int
	 */
	private int $org_id = 4321;

	public function set_up(): void {
		parent::set_up();

		$container = Plugin::instance()->container();

		$this->recorder    = $container->get( Conversion_Recorder::class );
		$this->conversions = $container->get( Conversion_Repository::class );
		$this->definitions = $container->get( Conversion_Definition_Repository::class );
		$this->events      = $container->get( Event_Repository::class );
		$this->rollups     = $container->get( Rollup_Repository::class );
		$this->tokens      = $container->get( Fill_Token::class );
		$this->metrics     = $container->get( Conversion_Metrics::class );

		$this->metrics->reset();

		$this->conversions->install_table();
		$this->definitions->install_table();
		$this->events->install_table();
		$this->rollups->install_table();

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);

		$this->campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::LIVE,
			)
		);

		update_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, $this->org_id );

		$this->creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * One definition.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 * @return array{id: int, public_key: string}
	 */
	private function definition( array $overrides = array() ): array {
		$id = $this->definitions->create(
			array_merge(
				array(
					'name'                 => 'Purchase',
					'org_id'               => $this->org_id,
					'window_seconds'       => 2592000,
					'default_value_micros' => 4990000,
					'currency'             => 'USD',
					'allow_s2s'            => false,
					'status'               => Conversion_Definition::STATUS_ACTIVE,
				),
				$overrides
			)
		);

		$row = $this->definitions->find( $id );

		$this->assertIsArray( $row, 'The definition fixture must exist.' );

		return array(
			'id'         => $id,
			'public_key' => $row['public_key'],
		);
	}

	/**
	 * Mints a token and records the click that makes it attributable.
	 *
	 * @param bool $with_click Whether to record the click.
	 * @return array{token: string, hash: string, parsed: array<string, mixed>}
	 */
	private function clicked_token( bool $with_click = true ): array {
		$minted = $this->tokens->mint( $this->placement_id, $this->campaign_id, $this->creative_id );
		$hash   = $this->tokens->hash( $minted['token'] );

		if ( $with_click ) {
			$this->assertTrue(
				$this->events->insert(
					Event_Repository::TYPE_CLICK,
					$this->placement_id,
					$this->campaign_id,
					$this->creative_id,
					$hash,
					str_repeat( 'c', 64 )
				),
				'The click fixture must exist before anything is attributed to it.'
			);

			$this->backdate_click( $hash );
		}

		$parsed = $this->tokens->parse( $minted['token'], true );

		$this->assertIsArray( $parsed );

		return array(
			'token'  => $minted['token'],
			'hash'   => $hash,
			'parsed' => $parsed,
		);
	}

	/**
	 * Moves the recorded click to a fixed past moment.
	 *
	 * `insert()` stamps `time()`, and the window assertions need a known
	 * distance rather than "a moment ago". Written directly because there is no
	 * production reason for a repository to backdate an event.
	 *
	 * @param string $hash Token digest.
	 */
	private function backdate_click( string $hash ): void {
		global $wpdb;

		$table = $this->events->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture control over an event timestamp.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_at_ts = %d WHERE token_hash = %s", self::CLICK_AT, $hash ) );
	}

	/**
	 * Rows in the conversion ledger.
	 */
	private function conversion_count(): int {
		global $wpdb;

		$table = $this->conversions->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * **The whole point: a click becomes a conversion.**
	 */
	public function test_a_clicked_token_records_a_conversion(): void {
		$definition = $this->definition();
		$click      = $this->clicked_token();

		$result = $this->recorder->record(
			$click['parsed'],
			$click['hash'],
			$definition['public_key'],
			'order-1099-abcdef',
			self::CLICK_AT + 3600,
			Conversion_Rules::SOURCE_BROWSER
		);

		$this->assertSame( Conversion_Recorder::RECORDED, $result['outcome'] );
		$this->assertSame( Conversion_Attribution::ACCEPTED, $result['reason'] );
		$this->assertSame( 1, $this->conversion_count() );
	}

	/**
	 * The stored row carries the facts the server resolved, not the ones the
	 * client sent.
	 */
	public function test_the_stored_row_is_attributed_from_server_state(): void {
		global $wpdb;

		$definition = $this->definition();
		$click      = $this->clicked_token();

		$this->recorder->record(
			$click['parsed'],
			$click['hash'],
			$definition['public_key'],
			'order-1099-abcdef',
			self::CLICK_AT + 3600,
			Conversion_Rules::SOURCE_BROWSER
		);

		$table = $this->conversions->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		$row = $wpdb->get_row( "SELECT * FROM {$table} LIMIT 1", ARRAY_A );

		$this->assertIsArray( $row );
		$this->assertSame( $this->campaign_id, (int) $row['campaign_id'] );
		$this->assertSame( $this->creative_id, (int) $row['creative_id'] );
		$this->assertSame( $this->placement_id, (int) $row['placement_id'] );
		$this->assertSame( $definition['id'], (int) $row['definition_id'] );
		$this->assertSame( 'click', $row['attributed_event'] );

		// From the definition, never the request. A browser may not price its
		// own outcome.
		$this->assertSame( 4990000, (int) $row['value_micros'] );
		$this->assertSame( 'USD', $row['currency'] );
	}

	/**
	 * The same outcome reported twice counts once, through the production path.
	 */
	public function test_a_repeated_report_counts_once(): void {
		$definition = $this->definition();
		$click      = $this->clicked_token();

		$first = $this->recorder->record( $click['parsed'], $click['hash'], $definition['public_key'], 'order-1099-abcdef', self::CLICK_AT + 3600, Conversion_Rules::SOURCE_BROWSER );
		$again = $this->recorder->record( $click['parsed'], $click['hash'], $definition['public_key'], 'order-1099-abcdef', self::CLICK_AT + 3600, Conversion_Rules::SOURCE_BROWSER );

		$this->assertSame( Conversion_Recorder::RECORDED, $first['outcome'] );
		$this->assertSame( Conversion_Recorder::DUPLICATE, $again['outcome'] );
		$this->assertSame( 1, $this->conversion_count() );
	}

	/**
	 * Two definitions from one click both count.
	 *
	 * The assertion that fails if conversions are ever moved into `aggr_events`,
	 * asserted here through the recorder rather than the repository.
	 */
	public function test_two_definitions_from_one_click_both_count(): void {
		$purchase = $this->definition( array( 'name' => 'Purchase' ) );
		$signup   = $this->definition(
			array(
				'name'                 => 'Signup',
				'default_value_micros' => 0,
				'currency'             => '',
			)
		);
		$click    = $this->clicked_token();

		$this->recorder->record( $click['parsed'], $click['hash'], $purchase['public_key'], 'order-1099-abcdef', self::CLICK_AT + 60, Conversion_Rules::SOURCE_BROWSER );
		$this->recorder->record( $click['parsed'], $click['hash'], $signup['public_key'], 'signup-1099-abcdef', self::CLICK_AT + 60, Conversion_Rules::SOURCE_BROWSER );

		$this->assertSame( 2, $this->conversion_count() );
	}

	/**
	 * A token that was served but never clicked credits nothing.
	 */
	public function test_a_token_that_never_clicked_records_nothing(): void {
		$definition = $this->definition();
		$click      = $this->clicked_token( false );

		$result = $this->recorder->record( $click['parsed'], $click['hash'], $definition['public_key'], 'order-1099-abcdef', self::CLICK_AT + 60, Conversion_Rules::SOURCE_BROWSER );

		$this->assertSame( Conversion_Recorder::FAILED, $result['outcome'] );
		$this->assertSame( Conversion_Attribution::NO_INTERACTION, $result['reason'] );
		$this->assertSame( 0, $this->conversion_count() );
	}

	/**
	 * A report past the definition's window credits nothing.
	 */
	public function test_a_report_past_the_window_records_nothing(): void {
		$definition = $this->definition( array( 'window_seconds' => 3600 ) );
		$click      = $this->clicked_token();

		$result = $this->recorder->record( $click['parsed'], $click['hash'], $definition['public_key'], 'order-1099-abcdef', self::CLICK_AT + 3601, Conversion_Rules::SOURCE_BROWSER );

		$this->assertSame( Conversion_Attribution::OUT_OF_WINDOW, $result['reason'] );
		$this->assertSame( 0, $this->conversion_count() );
	}

	/**
	 * **Tenancy, through the production path.**
	 *
	 * A definition belonging to another organization cannot be credited against
	 * this campaign's click, however valid the token is.
	 */
	public function test_another_organizations_definition_credits_nothing(): void {
		$definition = $this->definition( array( 'org_id' => $this->org_id + 1 ) );
		$click      = $this->clicked_token();

		$result = $this->recorder->record( $click['parsed'], $click['hash'], $definition['public_key'], 'order-1099-abcdef', self::CLICK_AT + 60, Conversion_Rules::SOURCE_BROWSER );

		$this->assertSame( Conversion_Attribution::FOREIGN_DEFINITION, $result['reason'] );
		$this->assertSame( 0, $this->conversion_count() );
	}

	public function test_an_archived_definition_credits_nothing(): void {
		$definition = $this->definition( array( 'status' => Conversion_Definition::STATUS_ARCHIVED ) );
		$click      = $this->clicked_token();

		$result = $this->recorder->record( $click['parsed'], $click['hash'], $definition['public_key'], 'order-1099-abcdef', self::CLICK_AT + 60, Conversion_Rules::SOURCE_BROWSER );

		$this->assertSame( Conversion_Attribution::DEFINITION_CLOSED, $result['reason'] );
		$this->assertSame( 0, $this->conversion_count() );
	}

	public function test_an_unknown_definition_credits_nothing(): void {
		$click = $this->clicked_token();

		$result = $this->recorder->record( $click['parsed'], $click['hash'], str_repeat( 'd', 32 ), 'order-1099-abcdef', self::CLICK_AT + 60, Conversion_Rules::SOURCE_BROWSER );

		$this->assertSame( Conversion_Attribution::NO_DEFINITION, $result['reason'] );
		$this->assertSame( 0, $this->conversion_count() );
	}

	/**
	 * Ends the request the way production does, minus core's output flushing.
	 *
	 * `wp_ob_end_flush_all` is hooked to `shutdown` by core and closes the
	 * output buffers PHPUnit is holding, which makes every test that fires this
	 * action risky. Dropping that one handler leaves the hook this service
	 * actually listens to intact, which is the thing being proven.
	 */
	private function end_request(): void {
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );

		do_action( 'shutdown' );
	}

	/**
	 * **A refusal counted through the production recorder, not beside it.**
	 *
	 * This is the assertion the counter exists for. Every stage of this plugin
	 * that shipped a working counter nothing called — frequency capping is the
	 * one that reached production — passed its own tests by arranging the count
	 * it then read back. So the report here is refused by the real recorder and
	 * the number is read from the real option.
	 *
	 * `shutdown` is fired rather than `flush()` called, because the hook is the
	 * production writer and a test that called the method directly would pass
	 * over a service nobody remembered to initialise.
	 */
	public function test_a_refusal_is_counted_by_the_recorder(): void {
		$definition = $this->definition( array( 'window_seconds' => 3600 ) );
		$click      = $this->clicked_token();

		$this->assertSame( array(), $this->metrics->refusal_counts(), 'Nothing may be counted before the report.' );

		$result = $this->recorder->record( $click['parsed'], $click['hash'], $definition['public_key'], 'order-1099-abcdef', self::CLICK_AT + 3601, Conversion_Rules::SOURCE_BROWSER );

		$this->assertSame( Conversion_Attribution::OUT_OF_WINDOW, $result['reason'] );
		$this->assertSame( array(), $this->metrics->refusal_counts(), 'The count must not be durable before the request ends.' );

		// What production does at the end of the request, and the only thing
		// that makes the count survive it.
		$this->end_request();

		$this->assertSame(
			array( Conversion_Attribution::OUT_OF_WINDOW => 1 ),
			$this->metrics->refusal_counts(),
			'The recorder refused the report and counted nothing.'
		);
		$this->assertGreaterThan( 0, $this->metrics->counting_since() );
	}

	/**
	 * **A refusal before the ledger read is counted too.**
	 *
	 * `write()` returns from four places and the cheap refusals return first,
	 * from a branch that never reaches the event ledger at all. A counter
	 * placed at the end of the successful path would miss exactly the refusals
	 * an operator most needs to see, because those are the ones a
	 * misconfiguration produces in bulk.
	 */
	public function test_a_refusal_that_never_reads_the_ledger_is_counted(): void {
		$click = $this->clicked_token();

		$this->recorder->record( $click['parsed'], $click['hash'], str_repeat( 'd', 32 ), 'order-1099-abcdef', self::CLICK_AT + 60, Conversion_Rules::SOURCE_BROWSER );

		$this->end_request();

		$this->assertSame(
			array( Conversion_Attribution::NO_DEFINITION => 1 ),
			$this->metrics->refusal_counts()
		);
	}

	/**
	 * **A recorded conversion counts no refusal**, which is the half worth more.
	 *
	 * A counter that also fired on success would report a broken integration on
	 * a site where everything works, and the operator would learn to ignore it.
	 */
	public function test_a_recorded_conversion_counts_nothing(): void {
		$definition = $this->definition();
		$click      = $this->clicked_token();

		$result = $this->recorder->record( $click['parsed'], $click['hash'], $definition['public_key'], 'order-1099-abcdef', self::CLICK_AT + 60, Conversion_Rules::SOURCE_BROWSER );

		$this->assertSame( Conversion_Recorder::RECORDED, $result['outcome'] );
		$this->assertSame( 1, $this->conversion_count(), 'The fixture must record, or the next assertion passes for the wrong reason.' );

		$this->end_request();

		$this->assertSame( array(), $this->metrics->refusal_counts() );
		$this->assertSame( 0, $this->metrics->counting_since() );
	}

	/**
	 * **A refusal on the definition never touches the event ledger.**
	 *
	 * This endpoint is public. A request naming a definition that does not
	 * exist must cost one indexed read, not a read plus a seek into the
	 * highest-volume table in the schema — the cheap path is the one an
	 * attacker repeats.
	 */
	public function test_an_unknown_definition_never_queries_the_event_ledger(): void {
		global $wpdb;

		$click = $this->clicked_token();

		// Warm the memoised table_exists() so the fixture is not what is counted.
		$this->recorder->record( $click['parsed'], $click['hash'], str_repeat( 'd', 32 ), 'warm-up-00000001', self::CLICK_AT + 60, Conversion_Rules::SOURCE_BROWSER );

		$before = $wpdb->num_queries;

		$this->recorder->record( $click['parsed'], $click['hash'], str_repeat( 'e', 32 ), 'order-1099-abcdef', self::CLICK_AT + 60, Conversion_Rules::SOURCE_BROWSER );

		$this->assertSame(
			1,
			$wpdb->num_queries - $before,
			'An unknown definition must cost exactly the definition lookup and nothing else.'
		);
	}

	/**
	 * The rollup projection lands, on the day the outcome happened.
	 */
	public function test_the_conversion_is_projected_onto_the_day_it_occurred(): void {
		global $wpdb;

		$definition = $this->definition();
		$click      = $this->clicked_token();

		// Three days after the click, which is the ordinary case and the one
		// that would land on the wrong day if receipt time were used.
		$occurred = self::CLICK_AT + ( 3 * DAY_IN_SECONDS );

		$this->recorder->record( $click['parsed'], $click['hash'], $definition['public_key'], 'order-1099-abcdef', $occurred, Conversion_Rules::SOURCE_BROWSER );

		$table = $this->rollups->table_name();
		$day   = gmdate( 'Y-m-d', $occurred );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE day_utc = %s AND campaign_id = %d", $day, $this->campaign_id ), ARRAY_A );

		$this->assertIsArray( $row, 'The conversion must project onto the day it occurred.' );
		$this->assertSame( 1, (int) $row['conversions'] );

		/*
		 * And it must not have invented a day of unseen impressions. A
		 * conversion-only row is a day this site may have served nothing, so
		 * `viewables` stays NULL rather than becoming a measured zero.
		 */
		$this->assertNull( $row['viewables'], 'A conversion must not mark a day as viewability-measured.' );
		$this->assertSame( 0, (int) $row['impressions'] );
	}

	/**
	 * **The reconcile repairs a projection that did not land.**
	 *
	 * The contract for every other measurement event: the ledger is durable
	 * truth and the counter is a projection, so a failed write is repaired
	 * rather than lost. Conversions live in their own table, so the repair is a
	 * second statement — and an untested second statement is a repair nobody
	 * has ever seen work.
	 *
	 * The counter is corrupted deliberately rather than by failing the write,
	 * because what must be proven is that the reconcile rebuilds the number
	 * *exactly* from the ledger, not merely that it runs.
	 */
	public function test_the_reconcile_rebuilds_the_counter_exactly(): void {
		global $wpdb;

		$definition = $this->definition();
		$occurred   = self::CLICK_AT + 3600;
		$day        = gmdate( 'Y-m-d', $occurred );


		foreach ( array( 'order-aaaa1111', 'order-bbbb2222', 'order-cccc3333' ) as $key ) {
			$click = $this->clicked_token();

			$this->assertSame(
				Conversion_Recorder::RECORDED,
				$this->recorder->record( $click['parsed'], $click['hash'], $definition['public_key'], $key, $occurred, Conversion_Rules::SOURCE_BROWSER )['outcome']
			);
		}

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		$this->assertSame( 3, (int) $wpdb->get_var( $wpdb->prepare( "SELECT conversions FROM {$table} WHERE day_utc = %s AND campaign_id = %d", $day, $this->campaign_id ) ) );

		// Corrupt it, the way a failed projection would have left it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture damage, so the repair has something to repair.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET conversions = 99 WHERE day_utc = %s AND campaign_id = %d", $day, $this->campaign_id ) );

		$this->assertTrue( $this->rollups->reconcile_day( $day ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		$repaired = (int) $wpdb->get_var( $wpdb->prepare( "SELECT conversions FROM {$table} WHERE day_utc = %s AND campaign_id = %d", $day, $this->campaign_id ) );

		$this->assertSame( 3, $repaired, 'The reconcile must rebuild the exact ledger count, not approach it.' );
	}

	/**
	 * The reconcile is idempotent: running it twice changes nothing.
	 *
	 * It runs hourly and is restartable, so a second pass over a day it already
	 * repaired must not double the number.
	 */
	public function test_reconciling_twice_changes_nothing(): void {
		global $wpdb;

		$definition = $this->definition();
		$occurred   = self::CLICK_AT + 3600;
		$day        = gmdate( 'Y-m-d', $occurred );


		$click = $this->clicked_token();
		$this->recorder->record( $click['parsed'], $click['hash'], $definition['public_key'], 'order-aaaa1111', $occurred, Conversion_Rules::SOURCE_BROWSER );

		$this->assertTrue( $this->rollups->reconcile_day( $day ) );
		$this->assertTrue( $this->rollups->reconcile_day( $day ) );

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT conversions FROM {$table} WHERE day_utc = %s AND campaign_id = %d", $day, $this->campaign_id ) ) );
	}

	/**
	 * **A day with impressions and no conversions reconciles to NULL, never zero.**
	 *
	 * And it does so with no boundary option, which is why there is not one.
	 * Viewability needed `OPTION_VIEWABILITY_SINCE` because its reconcile writes
	 * a row for every day that has events, so a pre-measurement day was swept up
	 * and zeroed. The conversion reconcile selects from the conversion ledger,
	 * so a day with none produces no rows and touches nothing.
	 *
	 * A boundary option shipped in the first draft of this work and was deleted
	 * after sabotaging it changed no test — a guard that cannot fail protects
	 * nothing, and keeping it would have implied a safety this does not need.
	 */
	public function test_a_day_before_tracking_began_is_not_rewritten_to_zero(): void {
		global $wpdb;

		$before = '2026-03-02';

		$this->assertTrue( $this->rollups->increment( 'impressions', $this->placement_id, $this->campaign_id, $before, 0 ) );
		$this->assertTrue( $this->rollups->reconcile_day( $before ) );

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE day_utc = %s AND campaign_id = %d", $before, $this->campaign_id ), ARRAY_A );

		$this->assertIsArray( $row );
		$this->assertNull( $row['conversions'], 'A day before tracking must stay unmeasured, not become a measured zero.' );
	}

	/**
	 * A day nobody converted stays NULL, not zero.
	 *
	 * The distinction the column exists for, one phase after viewability made
	 * the same one: an impression is no evidence that conversion tracking is
	 * running, because the publisher may have defined no conversion at all.
	 */
	public function test_a_delivery_does_not_mark_the_day_as_conversion_measured(): void {
		global $wpdb;

		$this->assertTrue( $this->rollups->increment( 'impressions', $this->placement_id, $this->campaign_id, '2026-03-02', 0 ) );

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE day_utc = %s AND campaign_id = %d", '2026-03-02', $this->campaign_id ), ARRAY_A );

		$this->assertIsArray( $row );
		$this->assertSame( 1, (int) $row['impressions'] );
		$this->assertNull( $row['conversions'], 'An impression must not claim conversions were being counted.' );
		$this->assertSame( 0, (int) $row['viewables'], 'But it must still mark the day as viewability-measured.' );
	}
}
