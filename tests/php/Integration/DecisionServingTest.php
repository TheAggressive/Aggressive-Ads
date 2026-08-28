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
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Decision_Engine;
use Aggressive\Ads\Workflow\Fill_Service;
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

	private function enable_native(): void {
		$document = $this->settings->get();
		$document['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] = true;

		$this->assertTrue( $this->settings->save( $document ) );
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
