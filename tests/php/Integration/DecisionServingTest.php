<?php
/**
 * Assignment backfill gate on native fill.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Decision_Engine;
use Aggressive\Ads\Domain\Decision_Outcome;
use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;
use Aggressive\Ads\Workflow\Decision_Metrics;
use Aggressive\Ads\Admin\Report_Data;
use Aggressive\Ads\Domain\No_Fill_Reason;
use Aggressive\Ads\Workflow\Fill_Service;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Paid fill stays empty until the P2 backfill completion marker is set.
 */
final class DecisionServingTest extends WP_UnitTestCase {

	/**
	 * Settings document.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Fixture placement.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Fixture campaign, which is also the assignment's line item id.
	 *
	 * @var int
	 */
	private int $campaign_id = 0;

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();
		( new Installer( new Audit_Repository(), new Roles() ) )->install_delivery_tables();

		$this->settings     = Plugin::instance()->container()->get( Settings::class );
		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => 'decision-gate',
			)
		);

		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );

		Plugin::instance()->container()->get( Creative_Assignment_Repository::class )->install_table();
		$this->seed_assignment();
		$this->enable_native();

		do_action( 'rest_api_init', rest_get_server() );

		add_filter(
			'wp_get_attachment_image_src',
			static fn (): array => array( 'https://example.org/creative.png', 728, 90, false )
		);
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		delete_option( Creative_Assignment_Migrator::OPTION_DONE );
		remove_all_filters( 'wp_get_attachment_image_src' );
		parent::tear_down();
	}

	public function test_serving_ready_is_false_while_backfill_is_incomplete(): void {
		delete_option( Creative_Assignment_Migrator::OPTION_DONE );

		$engine = Plugin::instance()->container()->get( Decision_Engine::class );

		$this->assertFalse( $engine->serving_ready() );
		$this->assertSame( 'backfill_pending', $engine->serving_status() );
	}

	public function test_serving_ready_is_true_when_backfill_finished_and_table_exists(): void {
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		$engine = Plugin::instance()->container()->get( Decision_Engine::class );

		$this->assertTrue( $engine->serving_ready() );
		$this->assertSame( 'assignments', $engine->serving_status() );
	}

	public function test_paid_fill_is_withheld_until_backfill_completes(): void {
		delete_option( Creative_Assignment_Migrator::OPTION_DONE );

		$fill = Plugin::instance()->container()->get( Fill_Service::class );
		$data = $fill->for_slug( 'decision-gate' );

		$this->assertIsArray( $data );
		$this->assertNull( $data['creative'] );

		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		$data = $fill->for_slug( 'decision-gate' );

		$this->assertIsArray( $data );
		$this->assertIsArray( $data['creative'] );
		$this->assertSame( 'https://example.org/creative.png', $data['creative']['image'] );
	}

	public function test_expired_schedule_withholds_paid_fill(): void {
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		global $wpdb;
		$assignments = Plugin::instance()->container()->get( Creative_Assignment_Repository::class );

		// Set end_at_ts in the past.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture mutation.
		$wpdb->update(
			$assignments->table_name(),
			array(
				'start_at_ts' => 1_000_000,
				'end_at_ts'   => 1_500_000,
			),
			array( 'placement_id' => $this->placement_id )
		);

		$fill = Plugin::instance()->container()->get( Fill_Service::class );
		$data = $fill->for_slug( 'decision-gate' );

		$this->assertIsArray( $data );
		$this->assertNull( $data['creative'] );
	}

	/**
	 * **A first fill is counted as supply, through the production path.**
	 *
	 * The counter and the thing that increments it have to meet in a test. A
	 * column that only fixtures populate is the frequency-capping defect this
	 * repository already records: `get_count()` was correct, nothing called
	 * `increment()`, and every test arranged its own count so all of them
	 * passed.
	 *
	 * So this hits the fill route with `n=0` the way the client writes it,
	 * flushes the request the way `shutdown` does, and reads the row back.
	 * Passing the sequence into `for_slug()` by hand is how this test once
	 * stayed green while `fillSlot` never sent `n` at all.
	 *
	 * @return void
	 */
	public function test_a_first_fill_is_recorded_as_a_page_opportunity(): void {
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		$payload = $this->fill_via_route( 0 );

		/*
		 * The creative, not just a payload. A 200 with no creative is a slot
		 * that decided nothing — which is how the first version of this test
		 * read zero counters and still looked reasonable.
		 */
		$this->assertIsArray( $payload );
		$this->assertIsArray( $payload['creative'], 'Nothing was served, so there is no decision to have counted.' );

		Plugin::instance()->container()->get( Decision_Metrics::class )->flush();

		$this->assertSame( 1, $this->counted( Decision_Outcome::REQUEST, Opportunity::PAGE ) );
		$this->assertSame( 1, $this->counted( Decision_Outcome::FILL, Opportunity::PAGE ) );

		// And nothing was filed as supply that was not.
		$this->assertSame( 0, $this->counted( Decision_Outcome::REQUEST, Opportunity::REFRESH ) );

		$report = Plugin::instance()->container()->get( Report_Data::class )
			->fill( Plugin::instance()->container()->get( Report_Data::class )->period( 30 ), $this->placement_id );

		$this->assertSame( 1, $report['requests'] );
		$this->assertSame( 0, $report['refresh']['requests'] );
	}

	/**
	 * **A rotation is delivery, and is not supply.**
	 *
	 * This is the whole point of the grain: the impression is real, and the
	 * opportunity is not independent inventory. Counting it as supply is what
	 * would let P16 forecast a `setInterval`.
	 *
	 * The sequence arrives on the query string, which is the only path a
	 * browser has. A test that calls `for_slug( …, 3 )` is still arranging
	 * the number the production client used to omit.
	 *
	 * @return void
	 */
	public function test_a_rotation_is_recorded_as_a_refresh_and_not_as_supply(): void {
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		$this->permit_refresh( true, 1, 10 );

		$rotation = $this->fill_via_route( 1 );

		$this->assertIsArray( $rotation );
		$this->assertIsArray( $rotation['creative'], 'The rotation served nothing, so there is no decision to have counted.' );

		Plugin::instance()->container()->get( Decision_Metrics::class )->flush();

		$this->assertSame( 1, $this->counted( Decision_Outcome::REQUEST, Opportunity::REFRESH ) );
		$this->assertSame(
			0,
			$this->counted( Decision_Outcome::REQUEST, Opportunity::PAGE ),
			'A refresh was counted as a page opportunity, which is supply invented from a timer.'
		);

		/*
		 * And the surface a publisher reads agrees. Asking the table and then
		 * the report used to be two different answers: the column was right
		 * and every reader summed it away.
		 */
		$report = Plugin::instance()->container()->get( Report_Data::class )
			->fill( Plugin::instance()->container()->get( Report_Data::class )->period( 30 ), $this->placement_id );

		$this->assertSame( 0, $report['requests'], 'The fill report counted a refresh as page supply.' );
		$this->assertSame( 1, $report['refresh']['requests'] );
	}

	/**
	 * **A request does not inherit the last request's kind.**
	 *
	 * The kind is one field on a service that outlives a single request under
	 * any long-running SAPI — FrankenPHP, RoadRunner, a pooled worker — so a
	 * value left set files the next request's counts under the previous one's.
	 * The reset exists for that, and until this test existed nothing proved it:
	 * deleting the reset changed no assertion anywhere.
	 *
	 * The failure it prevents is supply quietly disappearing. A rotation
	 * followed by a real page view would record two refreshes and no page
	 * opportunity, so a placement's inventory would shrink toward zero the
	 * busier its rotation got.
	 *
	 * **The page batch is the path that was actually exposed.** A single-slot
	 * fill declares its kind from the sequence it was given, so it is safe
	 * whatever ran before it. `for_slots()` had no sequence and declared
	 * nothing, which is why this exercises that one.
	 *
	 * @return void
	 */
	public function test_a_page_batch_is_not_filed_under_the_previous_kind(): void {
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		$this->permit_refresh( true, 1, 10 );

		$fill    = Plugin::instance()->container()->get( Fill_Service::class );
		$metrics = Plugin::instance()->container()->get( Decision_Metrics::class );

		// A rotation, flushed the way `shutdown` flushes one.
		$this->assertIsArray( $fill->for_slug( 'decision-gate', 2 )['creative'] );
		$metrics->flush();

		// Then a page load asking for every slot at once, in the same process.
		$batch = $fill->for_slots( array( 'decision-gate' ) );

		$this->assertIsArray( $batch['decision-gate'] ?? null, 'The page batch served nothing to count.' );
		$metrics->flush();

		$this->assertSame(
			1,
			$this->counted( Decision_Outcome::REQUEST, Opportunity::PAGE ),
			'A page view was filed under the previous request\'s kind, so supply went missing.'
		);
		$this->assertSame( 1, $this->counted( Decision_Outcome::REQUEST, Opportunity::REFRESH ) );
	}

	/**
	 * **A creative of the wrong size is refused, not stretched into the slot.**
	 *
	 * Nothing checked this before P15 and nothing needed to: a placement had
	 * one size and creative upload enforced it, so every candidate matched by
	 * construction. A responsive placement breaks that — it serves several
	 * sizes and may hold artwork for only one — and without the gate the engine
	 * puts a 728x90 into a 320x50 slot: a broken page, and an advertiser billed
	 * for an impression their creative did not fit.
	 *
	 * Driven through `Fill_Service` rather than the stage, because a gate that
	 * reads a fact nothing puts there is the defect this repository keeps
	 * finding. The fixture creative is 728x90; asking the placement to serve a
	 * viewport that resolves to 320x50 is what makes the two disagree.
	 *
	 * @return void
	 */
	public function test_a_creative_of_the_wrong_size_is_not_served(): void {
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		$placements = Plugin::instance()->container()->get( Placement_Repository::class );

		$this->assertTrue(
			$placements->set_size_map(
				$this->placement_id,
				array(
					0   => '320x50',
					768 => '728x90',
				)
			)
		);

		$fill = Plugin::instance()->container()->get( Fill_Service::class );

		// Wide: the placement serves 728x90 and the creative is 728x90.
		$wide = $fill->for_slug( 'decision-gate', 0, 1024 );

		$this->assertIsArray( $wide );
		$this->assertIsArray( $wide['creative'], 'A matching creative was refused.' );

		// Narrow: the placement serves 320x50 and nothing that size exists.
		$narrow = $fill->for_slug( 'decision-gate', 0, 375 );

		$this->assertIsArray( $narrow );
		$this->assertNull(
			$narrow['creative'],
			'A 728x90 creative was served into a slot reserving 320x50.'
		);
	}

	/**
	 * The refusal is reported as a missing size, not as a broken campaign.
	 *
	 * It is the one no-fill a publisher can act on directly — supply artwork
	 * for that breakpoint — so folding it into "every advertisement was
	 * ineligible" would send them looking at campaigns instead.
	 *
	 * @return void
	 */
	public function test_a_size_refusal_is_recorded_as_its_own_reason(): void {
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		$placements = Plugin::instance()->container()->get( Placement_Repository::class );

		$placements->set_size_map(
			$this->placement_id,
			array(
				0   => '320x50',
				768 => '728x90',
			)
		);

		$fill = Plugin::instance()->container()->get( Fill_Service::class );

		$fill->for_slug( 'decision-gate', 0, 375 );

		Plugin::instance()->container()->get( Decision_Metrics::class )->flush();

		$this->assertSame(
			1,
			$this->counted( No_Fill_Reason::SIZE_UNAVAILABLE, Opportunity::PAGE ),
			'The size refusal was filed under some other reason.'
		);
	}

	/**
	 * A placement that has never been made responsive is unaffected.
	 *
	 * Every existing placement is a fixed map over its single size, so the gate
	 * compares that size against itself and refuses nothing. Without this the
	 * assertions above would pass on a gate that simply refused everything.
	 *
	 * @return void
	 */
	public function test_a_fixed_placement_still_serves_at_every_width(): void {
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		$fill = Plugin::instance()->container()->get( Fill_Service::class );

		foreach ( array( 0, 375, 1024, 4000 ) as $width ) {
			$payload = $fill->for_slug( 'decision-gate', 0, $width );

			$this->assertIsArray( $payload );
			$this->assertIsArray(
				$payload['creative'],
				'A fixed placement stopped serving at ' . $width . 'px.'
			);
		}
	}

	/**
	 * A placement that forbids refresh does not refresh, whatever was asked.
	 *
	 * The fixture placement carries no policy, so the strict default applies —
	 * which is also the assertion that the default is doing something.
	 *
	 * @return void
	 */
	public function test_a_placement_that_forbids_refresh_refuses_the_fill(): void {
		$fill = Plugin::instance()->container()->get( Fill_Service::class );

		$this->assertNull( $fill->for_slug( 'decision-gate', 1 ), 'A forbidden refresh was served.' );

		// The first fill on the same placement is untouched.
		$this->assertIsArray( $fill->for_slug( 'decision-gate', 0 ) );
	}

	/**
	 * A claimed sequence past the per-view cap is refused, not served.
	 *
	 * The sequence arrives from a browser. This is the bound that turns "we
	 * trust the count" into "we trust it inside a number the publisher set".
	 *
	 * @return void
	 */
	public function test_a_sequence_past_the_cap_is_refused(): void {
		$this->permit_refresh( true, 1, 2 );

		$fill = Plugin::instance()->container()->get( Fill_Service::class );

		$this->assertIsArray( $fill->for_slug( 'decision-gate', 2 ), 'The cap itself must be servable.' );
		$this->assertNull( $fill->for_slug( 'decision-gate', 3 ) );
		$this->assertNull( $fill->for_slug( 'decision-gate', 400 ) );
	}

	/**
	 * One fill the way a browser asks for it: the route, with `n` on the query.
	 *
	 * @param int $sequence Fill number within the page view, zero-based.
	 * @return array<string, mixed>|null
	 */
	private function fill_via_route( int $sequence ): ?array {
		$request = new WP_REST_Request( 'GET', '/aggr/v1/fill/decision-gate' );
		$request->set_query_params( array( 'n' => $sequence ) );

		$response = rest_get_server()->dispatch( $request );

		if ( 200 !== $response->get_status() ) {
			return null;
		}

		$data = $response->get_data();

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Counts recorded for one outcome and inventory kind today.
	 *
	 * @param string $outcome     Stored outcome code.
	 * @param string $opportunity `Domain\Opportunity` kind.
	 * @return int
	 */
	private function counted( string $outcome, string $opportunity ): int {
		global $wpdb;

		$table = Plugin::instance()->container()->get( Decision_Rollup_Repository::class )->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Reading this plugin's own counter table in a test.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(events), 0) FROM {$table} WHERE placement_id = %d AND day_utc = %s AND outcome = %s AND opportunity = %s",
				$this->placement_id,
				gmdate( 'Y-m-d' ),
				$outcome,
				$opportunity
			)
		);
	}

	/**
	 * Gives the fixture placement a refresh policy.
	 *
	 * @param bool $enabled      Whether refresh is permitted.
	 * @param int  $seconds      Shortest interval permitted.
	 * @param int  $max_per_view Refreshes permitted per page view.
	 * @return void
	 */
	private function permit_refresh( bool $enabled, int $seconds, int $max_per_view ): void {
		$this->assertTrue(
			Plugin::instance()->container()->get( Placement_Repository::class )
				->set_refresh_policy( $this->placement_id, $enabled, $seconds, $max_per_view )
		);
	}

	private function enable_native(): void {
		$document = $this->settings->get();
		$document['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] = true;

		$this->assertTrue( $this->settings->save( $document ) );
	}

	/**
	 * **A campaign sold against a category serves on that category, and only
	 * there.**
	 *
	 * This is the half of contextual selling that had never existed.
	 * `Targeting_Rules` could always evaluate a fact set — the comparator for
	 * an array-valued fact was already there — but nothing ever put the page
	 * into the facts, so a publisher's category rule matched nobody. A test of
	 * the comparator alone passes over exactly that defect, which is why this
	 * one goes through `Fill_Service` and the real fill path.
	 *
	 * Both directions are asserted. "Serves on the sports post" alone would
	 * pass over targeting that was never applied at all.
	 *
	 * @return void
	 */
	public function test_a_category_targeted_campaign_serves_only_on_that_category(): void {
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		$this->target_on(
			array(
				'dimension' => 'categories',
				'cmp'       => 'contains',
				'value'     => 'sports',
			)
		);

		$sports  = $this->post_in( 'sports' );
		$recipes = $this->post_in( 'recipes' );

		$fill = Plugin::instance()->container()->get( Fill_Service::class );

		$on_topic = $fill->for_slug( 'decision-gate', 0, 1024, $sports );

		$this->assertIsArray( $on_topic );
		$this->assertIsArray(
			$on_topic['creative'],
			'A campaign targeting "sports" did not serve on a sports post.'
		);

		$off_topic = $fill->for_slug( 'decision-gate', 0, 1024, $recipes );

		$this->assertIsArray( $off_topic );
		$this->assertNull(
			$off_topic['creative'],
			'A campaign targeting "sports" served on a recipes post.'
		);
	}

	/**
	 * A fill that reports no page is not silently on-topic.
	 *
	 * Every fill from a page cached before this shipped arrives without a page
	 * id. A targeted campaign must not serve into that gap — "we do not know
	 * where this is" is not "this is sports".
	 *
	 * @return void
	 */
	public function test_a_targeted_campaign_does_not_serve_without_a_page(): void {
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		$this->target_on(
			array(
				'dimension' => 'categories',
				'cmp'       => 'contains',
				'value'     => 'sports',
			)
		);

		$fill    = Plugin::instance()->container()->get( Fill_Service::class );
		$payload = $fill->for_slug( 'decision-gate', 0, 1024, 0 );

		$this->assertIsArray( $payload );
		$this->assertNull(
			$payload['creative'],
			'A targeted campaign served into a fill that reported no page.'
		);
	}

	/**
	 * An untargeted campaign is unaffected by page context.
	 *
	 * The overwhelming majority of inventory has no targeting, and a change
	 * that made those campaigns depend on a page id would break every fill
	 * from an archive or a cached page.
	 *
	 * @return void
	 */
	public function test_an_untargeted_campaign_serves_whatever_the_page(): void {
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		$fill = Plugin::instance()->container()->get( Fill_Service::class );

		foreach ( array( 0, $this->post_in( 'recipes' ) ) as $page ) {
			$payload = $fill->for_slug( 'decision-gate', 0, 1024, $page );

			$this->assertIsArray( $payload );
			$this->assertIsArray(
				$payload['creative'],
				'An untargeted campaign was refused for page ' . $page . '.'
			);
		}
	}

	/**
	 * A published post in one category.
	 *
	 * @param string $category Category slug.
	 * @return int
	 */
	private function post_in( string $category ): int {
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );

		wp_set_object_terms( $post_id, array( $category ), 'category', false );

		return $post_id;
	}

	/**
	 * Puts a targeting rule on the seeded line item.
	 *
	 * Written straight to the row the decision path actually reads, rather than
	 * to a hand-built candidate array — `candidates_for_placement()` returns
	 * the assignment's columns and targeting lives on `aggr_line_items`, so a
	 * fixture that supplied it any other way would prove nothing.
	 *
	 * @param array<string, mixed> $leaf One targeting leaf.
	 * @return void
	 */
	private function target_on( array $leaf ): void {
		global $wpdb;

		$line_items = Plugin::instance()->container()->get( Line_Item_Repository::class );
		$default    = $line_items->ensure_default( $this->campaign_id );

		$this->assertIsArray( $default, 'No line item exists to carry a targeting rule.' );

		$line_item_id = (int) $default['id'];
		$assignments  = Plugin::instance()->container()->get( Creative_Assignment_Repository::class );

		/*
		 * Point the seeded assignment at the real line item.
		 *
		 * `seed_assignment()` uses the campaign id as the line item id, which
		 * is fine for every test that reads nothing off the line item. This one
		 * does, and `enrich()` joins on `line_item_id` — so without this the
		 * targeting rule would sit on a row the decision path never loads and
		 * the campaign would serve everywhere, passing the first assertion and
		 * failing the second for the wrong reason.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture for this plugin's own table.
		$wpdb->update(
			$assignments->table_name(),
			array( 'line_item_id' => $line_item_id ),
			array( 'campaign_id' => $this->campaign_id ),
			array( '%d' ),
			array( '%d' )
		);

		$encoded = wp_json_encode(
			array(
				'op'    => 'AND',
				'rules' => array( $leaf ),
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture for this plugin's own table.
		$updated = $wpdb->update(
			$line_items->table_name(),
			array( 'targeting_rules' => $encoded ),
			array( 'id' => $line_item_id ),
			array( '%s' ),
			array( '%d' )
		);

		$this->assertSame(
			1,
			$updated,
			'The targeting rule was not written to a line item, so this test would prove nothing.'
		);
	}

	private function seed_assignment(): void {
		global $wpdb;

		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::LIVE,
			)
		);
		add_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID, $this->placement_id );

		$this->campaign_id = $campaign_id;

		$attachment_id = (int) self::factory()->attachment->create_object(
			array(
				'file'           => 'creative.png',
				'post_mime_type' => 'image/png',
			)
		);

		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $creative_id, Creative_Repository::META_CAMPAIGN_ID, $campaign_id );
		update_post_meta( $creative_id, Creative_Repository::META_PLACEMENT_ID, $this->placement_id );
		update_post_meta( $creative_id, Creative_Repository::META_CLICK_URL, 'https://example.com/paid' );
		update_post_meta( $creative_id, Creative_Repository::META_ALT_TEXT, 'Paid' );
		update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, $attachment_id );
		update_post_meta( $creative_id, Creative_Repository::META_WIDTH, 728 );
		update_post_meta( $creative_id, Creative_Repository::META_HEIGHT, 90 );

		$assignments = Plugin::instance()->container()->get( Creative_Assignment_Repository::class );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture for this plugin's own table.
		$wpdb->insert(
			$assignments->table_name(),
			array(
				'line_item_id'  => $campaign_id,
				'campaign_id'   => $campaign_id,
				'placement_id'  => $this->placement_id,
				'revision_id'   => $creative_id,
				'status'        => Assignment_Rules::LIVE,
				'weight'        => 100,
				'click_url'     => 'https://example.com/paid',
				'attachment_id' => $attachment_id,
				'alt_text'      => 'Paid',
				'width'         => 728,
				'height'        => 90,
				'revision'      => 1,
			)
		);
	}
}
