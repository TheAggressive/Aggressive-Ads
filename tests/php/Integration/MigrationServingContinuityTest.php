<?php
/**
 * Ads keep serving while the P1 migration is still running.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Line_Item_Migrator;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Workflow\Fill_Cache;
use Aggressive\Ads\Workflow\Fill_Service;
use WP_UnitTestCase;

/**
 * The central P1 migration promise, asserted rather than inferred.
 *
 * Upgrading creates one default line item per existing Campaign, a batch at a
 * time in the background. On a large catalogue that takes many cron ticks, so
 * for most of the migration the site is in a mixed state: some campaigns have a
 * compatibility row and some do not.
 *
 * A publisher does not care about any of that. Their ads must keep serving
 * throughout, and the closure contract is explicit that this "cannot remain an
 * inference from the fact that serving currently reads Campaigns" — because
 * that fact is only true until somebody changes it, and nothing would say so.
 * A regression here is silent and expensive: blank ad slots on a live site,
 * during an upgrade, with every test still green.
 *
 * So each test leaves a live Campaign in a state the migration has not settled
 * and asks the real fill service for an ad.
 */
final class MigrationServingContinuityTest extends WP_UnitTestCase {

	private const SLOT = 'continuity-leaderboard';

	/**
	 * Placement post id.
	 *
	 * @var int
	 */
	private int $placement = 0;

	/**
	 * Line-item persistence.
	 *
	 * @var Line_Item_Repository
	 */
	private Line_Item_Repository $line_items;

	public function set_up(): void {
		parent::set_up();

		$this->line_items = Plugin::instance()->container()->get( Line_Item_Repository::class );
		$this->line_items->install_table();

		$this->placement = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => self::SLOT,
			)
		);

		update_post_meta( $this->placement, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $this->placement, Placement_Repository::META_SIZE, '728x90' );

		// The creative's image is an attachment the fixture does not create.
		add_filter(
			'wp_get_attachment_image_src',
			static fn (): array => array(
				'https://example.org/creative.png',
				728,
				90,
				false,
			)
		);

		delete_option( Line_Item_Migrator::OPTION_CURSOR );
		delete_option( Line_Item_Migrator::OPTION_DONE );
	}

	public function tear_down(): void {
		delete_option( Line_Item_Migrator::OPTION_CURSOR );
		delete_option( Line_Item_Migrator::OPTION_DONE );

		parent::tear_down();
	}

	/**
	 * One live campaign with one servable creative on the placement.
	 *
	 * @return int Campaign post id.
	 */
	private function live_campaign(): int {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::LIVE,
			)
		);

		add_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID, $this->placement );

		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $creative_id, Creative_Repository::META_CAMPAIGN_ID, $campaign_id );
		update_post_meta( $creative_id, Creative_Repository::META_PLACEMENT_ID, $this->placement );
		update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, 1 );
		update_post_meta( $creative_id, Creative_Repository::META_CLICK_URL, 'https://example.com/ad' );
		update_post_meta( $creative_id, Creative_Repository::META_ALT_TEXT, 'Advertisement' );
		update_post_meta( $creative_id, Creative_Repository::META_WIDTH, 728 );
		update_post_meta( $creative_id, Creative_Repository::META_HEIGHT, 90 );

		return $campaign_id;
	}

	/**
	 * Asks the real fill service for this placement's ad.
	 *
	 * The cache is dropped first: a payload cached before the state under test
	 * was arranged would answer from the wrong world entirely.
	 *
	 * @return array<string, mixed>|null
	 */
	private function fill(): ?array {
		$container = Plugin::instance()->container();
		$container->get( Fill_Cache::class )->delete( $this->placement );

		return $container->get( Fill_Service::class )->for_slug( self::SLOT );
	}

	/**
	 * Asserts a real paid creative came back.
	 *
	 * @param array<string, mixed>|null $payload Fill payload.
	 */
	private function assertServed( ?array $payload ): void {
		$this->assertIsArray( $payload, 'The slot returned no fill at all.' );
		$this->assertIsArray(
			$payload['creative'] ?? null,
			'The slot filled, but with no paid creative.'
		);
	}

	/**
	 * A campaign the migration has not reached yet still serves.
	 *
	 * The ordinary mid-migration state, and the one a publisher spends most of
	 * an upgrade in: the cursor sits below this campaign's id, so its default
	 * line item does not exist yet.
	 */
	public function test_a_campaign_ahead_of_the_migration_cursor_still_serves(): void {
		$campaign_id = $this->live_campaign();

		// The cursor has not reached it, and nothing has created its row.
		update_option( Line_Item_Migrator::OPTION_CURSOR, $campaign_id - 1, false );

		// Assert the fixture is the state under test before asserting on it.
		$this->assertNull(
			$this->line_items->default_for_campaign( $campaign_id ),
			'The fixture already had a compatibility row, so it proves nothing.'
		);

		$this->assertServed( $this->fill() );
	}

	/**
	 * A missing compatibility row does not stop serving.
	 *
	 * Distinct from the case above: here the migration believes it is finished,
	 * and the row is absent anyway — a campaign created by an older version, or
	 * a row deleted by hand. Serving must not depend on a row it never reads.
	 */
	public function test_a_missing_compatibility_row_still_serves(): void {
		$campaign_id = $this->live_campaign();

		update_option( Line_Item_Migrator::OPTION_DONE, 1, false );

		$this->assertNull( $this->line_items->default_for_campaign( $campaign_id ) );

		$this->assertServed( $this->fill() );
	}

	/**
	 * Serving survives a migration batch that failed partway.
	 *
	 * The injected failure is a dropped table, which is the bluntest form the
	 * real thing takes: the batch cannot write, and whatever it had planned to
	 * create does not exist. Serving reads Campaigns and must not notice.
	 */
	public function test_serving_survives_an_injected_migration_failure(): void {
		$this->live_campaign();

		$this->line_items->drop_table();

		$this->assertFalse(
			$this->line_items->table_exists(),
			'The failure was not injected, so the assertion below is vacuous.'
		);

		$this->assertServed( $this->fill() );

		// DDL is not rolled back by the suite's transaction, so put it back.
		$this->line_items->install_table();
	}

	/**
	 * And a fully migrated campaign still serves.
	 *
	 * The negative half. Every assertion above would pass on a site where
	 * serving had broken for migrated campaigns instead, which is the same
	 * promise failing from the other direction.
	 */
	public function test_a_migrated_campaign_still_serves(): void {
		$campaign_id = $this->live_campaign();

		$this->assertIsArray( $this->line_items->ensure_default( $campaign_id ) );
		$this->assertIsArray( $this->line_items->default_for_campaign( $campaign_id ) );

		$this->assertServed( $this->fill() );
	}
}
