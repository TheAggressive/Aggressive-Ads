<?php
/**
 * A draft may be edited. An approved creative may only be revised.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Creative_Revision_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Workflow\Revision_Policy;
use WP_UnitTestCase;

/**
 * The rule this protects, and the mutation it replaced.
 *
 * `Campaign_Change_Manager` used to call `set_click_url()` when staff approved
 * a destination change — repointing an ad the publisher had already approved
 * and that was already serving. Nothing recorded what it used to point at. That
 * is the bait-and-switch shape the whole ownership decision exists to prevent,
 * arriving through a door marked "approved by staff".
 *
 * Freezing begins at approval rather than at creation, so both halves need
 * asserting: a draft must still be editable in place, or every autosave becomes
 * an immutable row nobody will read.
 */
final class RevisionPolicyTest extends WP_UnitTestCase {

	/**
	 * Policy under test.
	 *
	 * @var Revision_Policy
	 */
	private Revision_Policy $policy;

	/**
	 * Creative persistence.
	 *
	 * @var Creative_Repository
	 */
	private Creative_Repository $creatives;

	/**
	 * Revision chain persistence.
	 *
	 * @var Creative_Revision_Repository
	 */
	private Creative_Revision_Repository $revisions;

	/**
	 * Assignment persistence.
	 *
	 * @var Creative_Assignment_Repository
	 */
	private Creative_Assignment_Repository $assignments;

	/**
	 * Line-item persistence.
	 *
	 * @var Line_Item_Repository
	 */
	private Line_Item_Repository $line_items;

	public function set_up(): void {
		parent::set_up();

		$container = Plugin::instance()->container();

		$this->policy      = $container->get( Revision_Policy::class );
		$this->creatives   = $container->get( Creative_Repository::class );
		$this->revisions   = $container->get( Creative_Revision_Repository::class );
		$this->assignments = $container->get( Creative_Assignment_Repository::class );
		$this->line_items  = $container->get( Line_Item_Repository::class );

		$this->assignments->install_table();
		$this->line_items->install_table();

		delete_option( Creative_Assignment_Migrator::OPTION_CURSOR );
		delete_option( Creative_Assignment_Migrator::OPTION_DONE );
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( Creative_Assignment_Migrator::HOOK );

		parent::tear_down();
	}

	/**
	 * A campaign with one creative.
	 *
	 * @param bool $approved Whether the creative is promoted, and so frozen.
	 * @return array{campaign: int, creative: int, placement: int}
	 */
	private function fixture( bool $approved ): array {
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

		update_post_meta( $campaign, Campaign_Repository::META_ORG_ID, 5 );
		add_post_meta( $campaign, Campaign_Repository::META_PLACEMENT_ID, $placement );

		$creative = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $creative, Creative_Repository::META_CAMPAIGN_ID, $campaign );
		update_post_meta( $creative, Creative_Repository::META_ORG_ID, 5 );
		update_post_meta( $creative, Creative_Repository::META_PLACEMENT_ID, $placement );
		update_post_meta( $creative, Creative_Repository::META_CLICK_URL, 'https://example.com/old' );
		update_post_meta( $creative, Creative_Repository::META_ALT_TEXT, 'Old copy' );
		update_post_meta( $creative, Creative_Repository::META_KIND, 'image' );
		update_post_meta( $creative, Creative_Repository::META_SHA256, str_repeat( 'a', 64 ) );

		if ( $approved ) {
			// Promotion to the Media Library is the observable form of
			// "approved": the private original is deleted at that moment.
			$attachment = (int) self::factory()->attachment->create_upload_object(
				DIR_TESTDATA . '/images/canola.jpg'
			);

			update_post_meta( $creative, Creative_Repository::META_ATTACHMENT_ID, $attachment );
		}

		return array(
			'campaign'  => $campaign,
			'creative'  => $creative,
			'placement' => $placement,
		);
	}

	public function test_a_draft_creative_is_not_frozen_and_edits_in_place(): void {
		$made = $this->fixture( false );

		$this->assertFalse( $this->policy->is_frozen( $made['creative'] ) );

		$result = $this->policy->apply_text_change( $made['creative'], 'https://example.com/new' );

		$this->assertSame(
			$made['creative'],
			$result,
			'A draft edit should stay on the same creative rather than creating a revision.'
		);
		$this->assertSame(
			'https://example.com/new',
			(string) get_post_meta( $made['creative'], Creative_Repository::META_CLICK_URL, true )
		);

		// And no revision was created: a draft has never been approved, so
		// there is nothing to preserve and nothing to fill a history with.
		$this->assertCount( 1, $this->creative_ids_for( $made['campaign'] ) );
	}

	/**
	 * An approved creative is revised, and what it used to say survives.
	 *
	 * The preservation half is the point. Mutating in place left no record of
	 * the destination a publisher actually approved.
	 */
	public function test_an_approved_creative_is_revised_and_the_original_survives(): void {
		$made = $this->fixture( true );

		$this->assertTrue(
			$this->policy->is_frozen( $made['creative'] ),
			'The fixture was not promoted, so freezing proves nothing.'
		);

		$revision = $this->policy->apply_text_change( $made['creative'], 'https://example.com/new' );

		$this->assertGreaterThan( 0, $revision );
		$this->assertNotSame( $made['creative'], $revision, 'The approved creative was mutated in place.' );

		// The new revision carries the new destination.
		$this->assertSame(
			'https://example.com/new',
			(string) get_post_meta( $revision, Creative_Repository::META_CLICK_URL, true )
		);

		// And the old one still says what the publisher approved.
		$this->assertSame(
			'https://example.com/old',
			(string) get_post_meta( $made['creative'], Creative_Repository::META_CLICK_URL, true ),
			'The superseded revision was rewritten, so the approved destination is lost.'
		);
	}

	/**
	 * The chain is readable, and the new revision is the live one.
	 *
	 * Asserted through the *forward* link, because that is the only one that
	 * survives. `META_REPLACES_ID` means "pending replacement" and
	 * `activate_replacement()` deletes it at approval, so leaving it on a live
	 * revision would make `is_active()` treat the campaign as having no current
	 * creative at all — which is how the first draft of this test failed.
	 */
	public function test_the_revision_supersedes_its_predecessor_and_goes_live(): void {
		$made     = $this->fixture( true );
		$revision = $this->policy->apply_text_change( $made['creative'], 'https://example.com/new' );

		$this->assertSame(
			$revision,
			(int) get_post_meta( $made['creative'], Creative_Repository::META_REPLACED_BY, true )
		);
		$this->assertSame(
			$made['creative'],
			$this->revisions->predecessor_of( $revision ),
			'The chain is unreadable once the revision is live.'
		);

		// The superseded revision drops out of the active set, so the screens
		// show the new one rather than both.
		$this->assertFalse( $this->creatives->is_active( $made['creative'] ) );
		$this->assertTrue( $this->creatives->is_active( $revision ) );
	}

	/**
	 * An approved chain still resolves to one asset.
	 *
	 * The defect this covers shipped in the backfill and was found by this
	 * file. `chain_root()` walked `META_REPLACES_ID` backward, which
	 * `activate_replacement()` deletes the moment a replacement goes live — so
	 * on real data every approved revision looked like its own root and would
	 * have been given its own asset. Two revisions of one artwork with two
	 * asset ids makes "the same artwork, reused" mean nothing.
	 */
	public function test_an_approved_chain_still_resolves_to_one_root(): void {
		$made     = $this->fixture( true );
		$revision = $this->policy->apply_text_change( $made['creative'], 'https://example.com/new' );

		$this->assertSame(
			$made['creative'],
			$this->revisions->chain_root( $revision ),
			'A live revision reports itself as the root, so it would get its own asset.'
		);
		$this->assertSame( $made['creative'], $this->revisions->chain_root( $made['creative'] ) );
	}

	/**
	 * `text_only` is derived from the checksums, not asserted.
	 *
	 * This is the security property behind the one-click review lane: a
	 * client-settable flag would let somebody swap the artwork and claim it.
	 * The two checksums match because the bytes are the same file.
	 */
	public function test_a_text_change_is_classified_text_only_from_the_checksums(): void {
		$made     = $this->fixture( true );
		$revision = $this->policy->apply_text_change( $made['creative'], 'https://example.com/new' );

		$this->assertSame(
			(string) get_post_meta( $made['creative'], Creative_Repository::META_SHA256, true ),
			(string) get_post_meta( $revision, Creative_Repository::META_SHA256, true ),
			'A text revision must carry the predecessor bytes by reference.'
		);
		$this->assertTrue( $this->revisions->is_text_only_revision( $revision ) );
	}

	/**
	 * A revision whose bytes differ is not text-only.
	 *
	 * The negative half, and the one that matters: without it the
	 * classification would pass for any revision at all, including one that
	 * replaced the artwork.
	 */
	public function test_a_revision_with_different_bytes_is_not_text_only(): void {
		$made     = $this->fixture( true );
		$revision = $this->policy->apply_text_change( $made['creative'], 'https://example.com/new' );

		// Somebody swapped the artwork on the revision.
		update_post_meta( $revision, Creative_Repository::META_SHA256, str_repeat( 'b', 64 ) );

		$this->assertFalse(
			$this->revisions->is_text_only_revision( $revision ),
			'A revision with different bytes was offered the one-click review lane.'
		);
	}

	/**
	 * A change that changes nothing is refused.
	 *
	 * Otherwise the history fills with revisions that say nothing, which the
	 * contract asks for explicitly.
	 */
	public function test_a_no_op_change_creates_nothing(): void {
		$made = $this->fixture( true );

		$this->assertSame(
			0,
			$this->policy->apply_text_change( $made['creative'], 'https://example.com/old' ),
			'A change to the same value created a revision.'
		);
		$this->assertCount( 1, $this->creative_ids_for( $made['campaign'] ) );
	}

	/**
	 * The assignment follows the new revision, and so does its snapshot.
	 *
	 * A row that names one revision and describes another is worse than not
	 * denormalizing at all, because nothing says which half is right.
	 */
	public function test_the_assignment_moves_onto_the_new_revision(): void {
		$made = $this->fixture( true );

		Plugin::instance()->container()
			->get( Creative_Assignment_Migrator::class )
			->migrate_one( $made['creative'] );

		$line_item = $this->line_items->default_for_campaign( $made['campaign'] );
		$before    = $this->assignments->compatibility_row( (int) $line_item['id'], $made['placement'] );

		$this->assertSame( $made['creative'], (int) $before['revision_id'] );

		$revision = $this->policy->apply_text_change( $made['creative'], 'https://example.com/new' );

		$after = $this->assignments->compatibility_row( (int) $line_item['id'], $made['placement'] );

		$this->assertSame( $revision, (int) $after['revision_id'], 'The assignment still names the superseded revision.' );
		$this->assertSame( 'https://example.com/new', (string) $after['click_url'], 'The snapshot describes the old revision.' );
		$this->assertGreaterThan( (int) $before['revision'], (int) $after['revision'], 'The optimistic-concurrency revision did not move.' );
	}

	/**
	 * Every creative post on a campaign, superseded ones included.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<int, int>
	 */
	private function creative_ids_for( int $campaign_id ): array {
		global $wpdb;

		/*
		 * A direct read rather than get_posts().
		 *
		 * The fixture needs *every* revision including superseded ones, and the
		 * assertion is a count — "no revision was created" only means anything
		 * as a number. Bounded, because an unbounded query in a test is the
		 * same mistake as one anywhere else.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test introspection over a fixture.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = %s AND m.meta_key = %s AND m.meta_value = %d LIMIT 50",
				Post_Types::CREATIVE,
				Creative_Repository::META_CAMPAIGN_ID,
				$campaign_id
			)
		);

		return array_map( 'intval', (array) $ids );
	}
}
