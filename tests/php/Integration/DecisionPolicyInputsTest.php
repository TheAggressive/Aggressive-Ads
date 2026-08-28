<?php
/**
 * The delivery policy has to reach the stages that read it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Decision_Engine;
use WP_UnitTestCase;

/**
 * The gap this file exists for.
 *
 * `candidates_for_placement()` returns the assignment's own columns. Priority,
 * pacing, caps, targeting and frequency all live on the line item, and nothing
 * carried them across — so every stage fell back to its default and a
 * configured policy changed nothing at serve time. P5, P6, P8 and P9 were all
 * `[x]` in that state, with passing tests, because each stage was tested
 * against a hand-built row that had the fields the real query never returns.
 *
 * These tests go through the engine so a row that loses a field fails here.
 */
final class DecisionPolicyInputsTest extends WP_UnitTestCase {

	/**
	 * Placement under test.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Campaign owning the assignment.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * Line item carrying the delivery policy.
	 *
	 * @var int
	 */
	private int $line_item_id;

	public function set_up(): void {
		parent::set_up();

		$installer = new Installer( new Audit_Repository(), new Roles() );
		$installer->install_delivery_tables();
		$installer->install_line_items();

		$container = Plugin::instance()->container();
		$container->get( Creative_Assignment_Repository::class )->install_table();

		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => 'policy-slot',
			)
		);
		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );

		$this->seed();
	}

	public function tear_down(): void {
		delete_option( Creative_Assignment_Migrator::OPTION_DONE );

		parent::tear_down();
	}

	/** A live campaign, one line item, one live assignment on the placement. */
	private function seed(): void {
		global $wpdb;

		$this->campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::LIVE,
			)
		);
		add_post_meta( $this->campaign_id, Campaign_Repository::META_PLACEMENT_ID, $this->placement_id );

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
		update_post_meta( $creative_id, Creative_Repository::META_CAMPAIGN_ID, $this->campaign_id );
		update_post_meta( $creative_id, Creative_Repository::META_PLACEMENT_ID, $this->placement_id );
		update_post_meta( $creative_id, Creative_Repository::META_CLICK_URL, 'https://example.com/paid' );
		update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, $attachment_id );

		$line_items = Plugin::instance()->container()->get( Line_Item_Repository::class );
		$default    = $line_items->ensure_default( $this->campaign_id );

		$this->assertIsArray( $default, 'The fixture produced no default line item to hang a policy on.' );

		$this->line_item_id = (int) $default['id'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture for this plugin's own table.
		$wpdb->insert(
			Plugin::instance()->container()->get( Creative_Assignment_Repository::class )->table_name(),
			array(
				'line_item_id'  => $this->line_item_id,
				'campaign_id'   => $this->campaign_id,
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

	/** Writes delivery policy straight onto the line item. */
	private function set_policy( array $columns ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture for this plugin's own table.
		$wpdb->update(
			Plugin::instance()->container()->get( Line_Item_Repository::class )->table_name(),
			$columns,
			array( 'id' => $this->line_item_id )
		);
	}

	/** The engine, bypassing the fill cache so each test sees its own policy. */
	private function decide( int $now ): array {
		$engine = Plugin::instance()->container()->get( Decision_Engine::class );

		return $engine->decide( $this->placement_id, $now, 12345 );
	}

	/**
	 * The fixture serves before any policy is applied.
	 *
	 * Asserted first so every exclusion below is the policy doing it, rather
	 * than a fixture that never produced a winner in the first place.
	 */
	public function test_the_fixture_serves_without_a_policy(): void {
		$decision = $this->decide( time() );

		$this->assertTrue(
			$decision['result']->has_winner(),
			'The fixture produced no winner, so nothing below proves an exclusion.'
		);
	}

	/**
	 * A lifetime cap already met withholds the ad.
	 *
	 * Pacing read `lifetime_cap` and `delivered_lifetime` from the candidate
	 * row, and the row carried neither, so the stage compared zero against zero
	 * and passed everything.
	 */
	public function test_a_met_lifetime_cap_withholds_delivery(): void {
		$this->set_policy( array( 'lifetime_cap' => 5 ) );

		Plugin::instance()->container()->get( Rollup_Repository::class )
			->increment( 'impressions', $this->placement_id, $this->campaign_id );

		$this->assertTrue(
			$this->decide( time() )['result']->has_winner(),
			'One impression against a cap of five must still serve.'
		);

		for ( $i = 0; $i < 4; $i++ ) {
			Plugin::instance()->container()->get( Rollup_Repository::class )
				->increment( 'impressions', $this->placement_id, $this->campaign_id );
		}

		$this->assertFalse(
			$this->decide( time() )['result']->has_winner(),
			'A lifetime cap that has been reached still served.'
		);
	}

	/**
	 * A targeting rule that cannot match withholds the ad.
	 *
	 * The stage reads `targeting_rules`; the column of that name was never
	 * selected, so every candidate arrived untargeted.
	 */
	public function test_an_unsatisfiable_targeting_rule_withholds_delivery(): void {
		$this->set_policy(
			array(
				'targeting_rules' => wp_json_encode(
					array(
						'operator' => 'and',
						'rules'    => array(
							array(
								'dimension' => 'country',
								'operator'  => 'eq',
								'value'     => 'ZZ',
							),
						),
					)
				),
			)
		);

		$this->assertFalse(
			$this->decide( time() )['result']->has_winner(),
			'A targeting rule the request cannot satisfy still served.'
		);
	}

	/**
	 * Priority reaches the row it is read from.
	 *
	 * Asserted on the value rather than on a winner, because one candidate wins
	 * its own tier whatever the number is — the defect was the number never
	 * arriving, not the comparison being wrong.
	 */
	public function test_priority_reaches_the_candidate_row(): void {
		$this->set_policy( array( 'priority' => 25 ) );

		$rows = Plugin::instance()->container()->get( Decision_Engine::class )
			->cached_rows( $this->placement_id, time() );

		$this->assertNotEmpty( $rows );
		$this->assertSame(
			25,
			(int) ( $rows[0]['priority'] ?? 0 ),
			'The line item priority never reached the candidate row.'
		);
	}

	/**
	 * The policy read carries no field the assignment owns.
	 *
	 * The enrichment merges policy *under* the candidate row, so a collision
	 * would be decided by merge order — but the stronger guarantee is that
	 * there is nothing to collide with. An assignment may only narrow its
	 * parent's window, so a policy carrying `start_at_ts` or `end_at_ts` could
	 * silently widen one an advertiser was already refused.
	 *
	 * Asserted against the read rather than the merge, because an earlier
	 * version of this test compared a window the policy never selected and so
	 * could not fail.
	 */
	public function test_the_policy_read_carries_no_assignment_owned_field(): void {
		$this->set_policy( array( 'priority' => 30 ) );

		$policies = Plugin::instance()->container()->get( Line_Item_Repository::class )
			->delivery_policies_for( array( $this->line_item_id ) );

		$this->assertArrayHasKey( $this->line_item_id, $policies, 'The fixture line item was not read back.' );

		$policy = $policies[ $this->line_item_id ];

		$this->assertSame( 30, (int) $policy['priority'], 'The policy read returned nothing useful.' );

		foreach ( array( 'start_at_ts', 'end_at_ts', 'status', 'weight', 'placement_id', 'revision' ) as $owned ) {
			$this->assertArrayNotHasKey(
				$owned,
				$policy,
				"Delivery policy carries {$owned}, which the assignment owns and may only narrow."
			);
		}
	}
}
