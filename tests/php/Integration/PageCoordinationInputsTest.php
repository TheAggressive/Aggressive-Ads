<?php
/**
 * Page coordination has to see the settings it coordinates on.
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
use Aggressive\Ads\Workflow\Fill_Service;
use WP_UnitTestCase;

/**
 * Roadblocks, competitive separation and category exclusivity are all
 * configured inside `delivery_settings`, and until that column was carried onto
 * the candidate row none of them could fire. P7 was tested the same way P5, P6,
 * P8 and P9 were — against hand-built rows holding keys the production query
 * did not return — so the gap was invisible from the suite.
 *
 * Asset deduplication is the exception: it reads `asset_id`, which the
 * candidate query has always returned, so it was the one page rule that worked.
 *
 * These go through `Fill_Service::for_slots()`, the batch path a page actually
 * uses, rather than through the coordinator directly.
 */
final class PageCoordinationInputsTest extends WP_UnitTestCase {

	/**
	 * Slot slugs, in the order a page requests them.
	 *
	 * @var array<int, string>
	 */
	private array $slugs = array( 'page-slot-a', 'page-slot-b' );

	/**
	 * Placement ids, keyed by slug.
	 *
	 * @var array<string, int>
	 */
	private array $placements = array();

	/**
	 * The line item carrying page-coordination settings.
	 *
	 * @var int
	 */
	private int $line_item_id = 0;

	public function set_up(): void {
		parent::set_up();

		$installer = new Installer( new Audit_Repository(), new Roles() );
		$installer->install_delivery_tables();
		$installer->install_line_items();

		Plugin::instance()->container()->get( Creative_Assignment_Repository::class )->install_table();
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		foreach ( $this->slugs as $slug ) {
			$placement_id = (int) self::factory()->post->create(
				array(
					'post_type'   => Post_Types::PLACEMENT,
					'post_status' => 'publish',
					'post_name'   => $slug,
				)
			);

			update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
			update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );

			$this->placements[ $slug ] = $placement_id;
		}

		$this->seed();
		$this->enable_native();

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

	/** Native delivery on, so the batch path answers at all. */
	private function enable_native(): void {
		$settings = Plugin::instance()->container()->get( Settings::class );
		$document = $settings->get();

		$document['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] = true;

		$this->assertTrue( $settings->save( $document ) );
	}

	/** One campaign, one line item, and a live assignment on each placement. */
	private function seed(): void {
		global $wpdb;

		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::LIVE,
			)
		);

		$line_items         = Plugin::instance()->container()->get( Line_Item_Repository::class );
		$default            = $line_items->ensure_default( $campaign_id );
		$this->line_item_id = is_array( $default ) ? (int) $default['id'] : 0;

		$this->assertGreaterThan( 0, $this->line_item_id, 'The fixture produced no line item.' );

		foreach ( $this->slugs as $index => $slug ) {
			$placement_id = $this->placements[ $slug ];

			add_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID, $placement_id );

			// Two candidates per slot, so weighted selection has a choice to
			// make. With one, the winner is the same whatever the seed and a
			// determinism test cannot fail.
			for ( $rank = 0; $rank < 2; $rank++ ) {
				$this->seed_assignment( $campaign_id, $placement_id, $index, $rank );
			}
		}
	}

	/**
	 * One live assignment on a placement.
	 *
	 * @param int $campaign_id  Campaign post id.
	 * @param int $placement_id Placement post id.
	 * @param int $index        Slot position, for a distinct asset per slot.
	 * @param int $rank         Candidate position within the slot.
	 * @return void
	 */
	private function seed_assignment( int $campaign_id, int $placement_id, int $index, int $rank ): void {
		global $wpdb;

		$attachment_id = (int) self::factory()->attachment->create_object(
			array(
				'file'           => "creative-{$index}-{$rank}.png",
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
		update_post_meta( $creative_id, Creative_Repository::META_PLACEMENT_ID, $placement_id );
		update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, $attachment_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture for this plugin's own table.
		$wpdb->insert(
			Plugin::instance()->container()->get( Creative_Assignment_Repository::class )->table_name(),
			array(
				'line_item_id'  => $this->line_item_id,
				'campaign_id'   => $campaign_id,
				'placement_id'  => $placement_id,
				// A distinct asset per candidate, so deduplication is not what
				// excludes anything here.
				'asset_id'      => 500 + ( $index * 10 ) + $rank,
				'revision_id'   => $creative_id,
				'status'        => Assignment_Rules::LIVE,
				'weight'        => 1 === $rank ? 300 : 100,
				'click_url'     => 'https://example.com/paid',
				'attachment_id' => $attachment_id,
				'alt_text'      => 'Paid',
				'width'         => 728,
				'height'        => 90,
				'revision'      => 1,
			)
		);
	}

	/** Writes page-coordination settings onto the line item. */
	private function set_delivery_settings( array $settings ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture for this plugin's own table.
		$wpdb->update(
			Plugin::instance()->container()->get( Line_Item_Repository::class )->table_name(),
			array( 'delivery_settings' => (string) wp_json_encode( $settings ) ),
			array( 'id' => $this->line_item_id )
		);
	}

	/**
	 * How many of the requested slots came back filled.
	 *
	 * @return int
	 */
	private function filled_slots(): int {
		$payloads = Plugin::instance()->container()->get( Fill_Service::class )->for_slots( $this->slugs );

		$filled = 0;

		foreach ( $payloads as $payload ) {
			if ( isset( $payload['creative'] ) && is_array( $payload['creative'] ) ) {
				++$filled;
			}
		}

		return $filled;
	}

	/**
	 * Both slots fill before any coordination rule is configured.
	 *
	 * Asserted first, so an exclusion below is the rule doing it rather than a
	 * fixture that never filled anything.
	 */
	public function test_both_slots_fill_without_coordination_settings(): void {
		$this->assertSame( 2, $this->filled_slots(), 'The fixture did not fill both slots.' );
	}

	/**
	 * Category exclusivity reaches the page coordinator.
	 *
	 * Configured only inside `delivery_settings`, which the candidate query does
	 * not return — so before that column was carried onto the row, this rule
	 * could never fire however it was configured.
	 */
	public function test_exclusive_category_limits_a_page_to_one_slot(): void {
		$this->set_delivery_settings(
			array(
				'category'           => 'automotive',
				'exclusive_category' => true,
			)
		);

		$this->assertSame(
			1,
			$this->filled_slots(),
			'An exclusive category did not reach the page coordinator.'
		);
	}

	/**
	 * A category that is not exclusive changes nothing.
	 *
	 * The negative half: a rule that excluded on the category alone would pass
	 * the test above while breaking every ordinary page.
	 */
	public function test_a_non_exclusive_category_fills_both_slots(): void {
		$this->set_delivery_settings( array( 'category' => 'automotive' ) );

		$this->assertSame( 2, $this->filled_slots() );
	}
}
