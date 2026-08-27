<?php
/**
 * Deletion leaves nothing unexplained and destroys nothing shared.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Creative_Revision_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use WP_UnitTestCase;

/**
 * The half of P2 that cannot be undone if it is wrong.
 *
 * A text revision carries its predecessor's attachment id, so two rows can name
 * the same bytes. Anything that deletes those bytes because one revision went
 * away breaks the other, and there is no recovering an attachment that has been
 * removed from the Media Library.
 */
final class CreativeCleanupTest extends WP_UnitTestCase {

	/**
	 * Assignment persistence.
	 *
	 * @var Creative_Assignment_Repository
	 */
	private Creative_Assignment_Repository $assignments;

	/**
	 * Revision chain persistence.
	 *
	 * @var Creative_Revision_Repository
	 */
	private Creative_Revision_Repository $revisions;

	/**
	 * Backfill, for arranging migrated fixtures.
	 *
	 * @var Creative_Assignment_Migrator
	 */
	private Creative_Assignment_Migrator $migrator;

	public function set_up(): void {
		parent::set_up();

		$container = Plugin::instance()->container();

		$this->assignments = $container->get( Creative_Assignment_Repository::class );
		$this->revisions   = $container->get( Creative_Revision_Repository::class );
		$this->migrator    = $container->get( Creative_Assignment_Migrator::class );

		$this->assignments->install_table();
		$container->get( Line_Item_Repository::class )->install_table();
	}

	/**
	 * A campaign with one promoted creative on one placement.
	 *
	 * @return array{campaign: int, creative: int, placement: int, attachment: int}
	 */
	private function fixture(): array {
		$placement = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);

		$campaign = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::LIVE,
			)
		);

		add_post_meta( $campaign, Campaign_Repository::META_PLACEMENT_ID, $placement );

		$creative = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		$attachment = (int) self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);

		update_post_meta( $creative, Creative_Repository::META_CAMPAIGN_ID, $campaign );
		update_post_meta( $creative, Creative_Repository::META_PLACEMENT_ID, $placement );
		update_post_meta( $creative, Creative_Repository::META_KIND, 'image' );
		update_post_meta( $creative, Creative_Repository::META_ATTACHMENT_ID, $attachment );
		update_post_meta( $creative, Creative_Repository::META_SHA256, str_repeat( 'f', 64 ) );
		update_post_meta( $creative, Creative_Repository::META_CLICK_URL, 'https://example.com/a' );

		$this->migrator->migrate_one( $creative );

		return compact( 'campaign', 'creative', 'placement', 'attachment' );
	}

	/**
	 * Two revisions of one artwork share their bytes, and both keep them.
	 *
	 * The case this file exists for. A text revision points at the predecessor's
	 * attachment rather than copying it, so deleting either record must not take
	 * the image with it.
	 */
	public function test_deleting_one_revision_does_not_destroy_shared_bytes(): void {
		$made = $this->fixture();

		$revision = $this->revisions->create_text_revision(
			$made['creative'],
			'https://example.com/new',
			'New copy'
		);

		$this->assertSame(
			$made['attachment'],
			(int) get_post_meta( $revision, Creative_Repository::META_ATTACHMENT_ID, true ),
			'The revision did not share its predecessor bytes, so this proves nothing.'
		);

		wp_delete_post( $revision, true );

		$this->assertInstanceOf(
			\WP_Post::class,
			get_post( $made['attachment'] ),
			'Deleting a revision destroyed artwork another revision still names.'
		);
	}

	/**
	 * Deleting a campaign leaves no assignment behind, and no other campaign's.
	 *
	 * A count on both sides: "the rows are gone" passes whether one campaign was
	 * cleaned or the table was emptied.
	 */
	public function test_deleting_a_campaign_removes_only_its_own_assignments(): void {
		$mine   = $this->fixture();
		$theirs = $this->fixture();

		$this->assertCount( 1, $this->assignments->for_campaign( $mine['campaign'] ) );

		wp_delete_post( $mine['campaign'], true );

		$this->assertCount( 0, $this->assignments->for_campaign( $mine['campaign'] ) );
		$this->assertCount( 1, $this->assignments->for_campaign( $theirs['campaign'] ) );
	}

	/**
	 * A deleted placement stops its assignments delivering.
	 *
	 * Retired rather than removed, so the row still explains what ran there.
	 * Left live, it would stay a delivery candidate for a slot that no longer
	 * exists — harmless today only because nothing renders that slot, which is
	 * not a property worth depending on.
	 */
	public function test_deleting_a_placement_retires_its_assignments(): void {
		$made = $this->fixture();

		wp_delete_post( $made['placement'], true );

		$rows = $this->assignments->for_campaign( $made['campaign'] );

		$this->assertCount( 1, $rows, 'The assignment was deleted rather than retired.' );
		$this->assertSame(
			Assignment_Rules::CANCELLED,
			(string) $rows[0]['status'],
			'An assignment on a deleted placement is still a delivery candidate.'
		);
	}

	/**
	 * Deleting a placement touches no other placement's assignments.
	 *
	 * The negative half, and the one that matters: a cleanup that ignored its
	 * argument would satisfy the assertion above and retire every campaign on
	 * the site.
	 */
	public function test_deleting_a_placement_leaves_other_placements_alone(): void {
		$doomed   = $this->fixture();
		$survivor = $this->fixture();

		wp_delete_post( $doomed['placement'], true );

		$rows = $this->assignments->for_campaign( $survivor['campaign'] );

		$this->assertNotSame(
			Assignment_Rules::CANCELLED,
			(string) $rows[0]['status'],
			'Deleting one placement retired another placement assignments.'
		);
	}

	/**
	 * Retiring twice is safe.
	 *
	 * The contract asks for recovery that can be run more than once. Deleting a
	 * placement whose assignments are already retired must not fail or double
	 * anything.
	 */
	public function test_placement_cleanup_is_safe_to_repeat(): void {
		$made = $this->fixture();

		$lifecycle = Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Line_Item_Lifecycle::class );
		$placement = get_post( $made['placement'] );

		$lifecycle->delete_placement( $made['placement'], $placement );
		$lifecycle->delete_placement( $made['placement'], $placement );

		$rows = $this->assignments->for_campaign( $made['campaign'] );

		$this->assertCount( 1, $rows );
		$this->assertSame( Assignment_Rules::CANCELLED, (string) $rows[0]['status'] );
	}

	/**
	 * A destructive uninstall leaves no P2 cron event scheduled.
	 *
	 * Asserted against the uninstaller's source rather than by running it: it
	 * drops every plugin table, and DDL is not rolled back by the suite's
	 * transaction — the mistake `UninstallOptionsTest` records. What matters is
	 * that the uninstaller *names* the hook; one it never unschedules keeps
	 * firing on a site where the plugin is gone.
	 */
	public function test_the_uninstaller_unschedules_every_migration_hook(): void {
		$source = (string) file_get_contents( AGGR_PLUGIN_DIR . 'inc/Install/class-uninstaller.php' );

		foreach ( array( 'Line_Item_Migrator::unschedule()', 'Creative_Assignment_Migrator::unschedule()' ) as $call ) {
			$this->assertStringContainsString(
				$call,
				$source,
				"Destructive uninstall never calls {$call}, so its cron event outlives the plugin."
			);
		}
	}
}
