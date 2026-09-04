<?php
/**
 * The Media Library copy of a creative's artwork.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Repository\Creative_Attachment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use WP_UnitTestCase;

/**
 * Written when the cluster was split out of `Creative_Repository`, because
 * mutating it proved five of its nine methods were not defended by anything.
 *
 * Each of the five was reachable only through a caller that asserted the
 * caller's own outcome, so deleting the method's body left the suite green.
 * Three of them are in this plugin's "dangerous" category and are the reason
 * this file exists rather than a note in `open-work.md`: a migration that runs
 * once against real data, a query that feeds a deletion sweep, and the guard
 * that decides whether a creative may be published.
 */
final class CreativeAttachmentRepositoryTest extends WP_UnitTestCase {

	/**
	 * The class under test.
	 *
	 * @var Creative_Attachment_Repository
	 */
	private Creative_Attachment_Repository $attachments;

	/**
	 * Sets up a repository per test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->attachments = new Creative_Attachment_Repository();
	}

	/**
	 * **A recorded id is not an attachment.**
	 *
	 * `has_attachment()` checks the post type rather than trusting the meta,
	 * because deleting an attachment from the Media Library leaves the meta
	 * row behind. A creative pointing at a hole must be promoted again rather
	 * than published with nothing behind it — and every existing test reached
	 * this through a caller that only ever saw the happy path, so removing the
	 * check changed nothing anywhere.
	 *
	 * @return void
	 */
	public function test_a_creative_pointing_at_a_deleted_attachment_has_none(): void {
		$creative_id   = $this->creative();
		$attachment_id = $this->attachment();

		update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, $attachment_id );

		$this->assertTrue( $this->attachments->has_attachment( $creative_id ) );

		wp_delete_attachment( $attachment_id, true );

		$this->assertFalse(
			$this->attachments->has_attachment( $creative_id ),
			'A creative whose attachment was deleted still claims to have one.'
		);

		// The meta survived the deletion, which is the whole reason the post
		// type is checked rather than the id.
		$this->assertSame(
			$attachment_id,
			(int) get_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, true )
		);
	}

	/**
	 * An id that was never an attachment is refused just as firmly.
	 *
	 * @return void
	 */
	public function test_an_id_that_is_not_an_attachment_is_not_an_attachment(): void {
		$creative_id = $this->creative();
		$page_id     = (int) self::factory()->post->create( array( 'post_type' => 'page' ) );

		update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, $page_id );

		$this->assertFalse( $this->attachments->has_attachment( $creative_id ) );

		// And zero, the state every creative starts in.
		$this->assertFalse( $this->attachments->has_attachment( $this->creative() ) );
	}

	/**
	 * The marker that keeps campaign artwork out of the Media Library.
	 *
	 * `Admin\Media_Library` hides attachments carrying this key, so a site
	 * running hundreds of campaigns still has a library of its own media. The
	 * screen's own test writes the meta by hand, which is why deleting this
	 * writer left everything green: nothing exercised the production path that
	 * puts the marker there.
	 *
	 * @return void
	 */
	public function test_marking_an_attachment_records_the_creative_it_came_from(): void {
		$creative_id   = $this->creative();
		$attachment_id = $this->attachment();

		$this->attachments->mark_attachment_as_creative( $attachment_id, $creative_id );

		$this->assertSame(
			$creative_id,
			(int) get_post_meta( $attachment_id, Creative_Repository::META_IS_CREATIVE, true ),
			'The Media Library filter has nothing to hide this attachment by.'
		);
	}

	/**
	 * **The contradiction is the query.**
	 *
	 * This finds creatives that were promoted — so delivery serves the
	 * attachment — and still hold a private original, which is a duplicate of
	 * already-public bytes inside the directory the deny rule exists to
	 * protect. It feeds a sweep that deletes files, so what it must *not*
	 * return is the more valuable half: a creative with only one of the two
	 * is not a contradiction and its file is not redundant.
	 *
	 * @return void
	 */
	public function test_only_creatives_holding_both_a_promotion_and_a_private_file_are_swept(): void {
		$both = $this->creative();
		update_post_meta( $both, Creative_Repository::META_ATTACHMENT_ID, $this->attachment() );
		update_post_meta( $both, Creative_Repository::META_PRIVATE_PATH, 'private/both.png' );

		$promoted_only = $this->creative();
		update_post_meta( $promoted_only, Creative_Repository::META_ATTACHMENT_ID, $this->attachment() );

		$private_only = $this->creative();
		update_post_meta( $private_only, Creative_Repository::META_PRIVATE_PATH, 'private/waiting.png' );

		$neither = $this->creative();

		$found = $this->attachments->ids_promoted_with_private_file( 50 );

		$this->assertSame( array( $both ), $found, 'The sweep selected something it must not delete.' );
		$this->assertNotContains( $promoted_only, $found, 'A promoted creative with no private file has nothing to sweep.' );
		$this->assertNotContains( $private_only, $found, 'An unpromoted creative still needs its private original.' );
		$this->assertNotContains( $neither, $found );
	}

	/**
	 * The limit is a limit, not a suggestion.
	 *
	 * @return void
	 */
	public function test_the_sweep_returns_no_more_than_it_was_asked_for(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$creative_id = $this->creative();
			update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, $this->attachment() );
			update_post_meta( $creative_id, Creative_Repository::META_PRIVATE_PATH, 'private/' . $i . '.png' );
		}

		$this->assertCount( 2, $this->attachments->ids_promoted_with_private_file( 2 ) );
		$this->assertCount( 3, $this->attachments->ids_promoted_with_private_file( 50 ) );
	}

	/**
	 * **A migration that marks nothing reports success.**
	 *
	 * This runs once, against real data, to mark the attachments of creatives
	 * promoted before the marker existed. Collapsing its body to a no-op left
	 * the whole suite green, which for migration code is the worst available
	 * outcome: it would have run, returned 0, logged nothing, and left every
	 * historical creative's artwork visible in the Media Library.
	 *
	 * @return void
	 */
	public function test_the_backfill_marks_promoted_creatives_and_says_how_many(): void {
		$promoted   = array();
		$attachment = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$creative_id  = $this->creative();
			$attached     = $this->attachment();
			$promoted[]   = $creative_id;
			$attachment[] = $attached;

			update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, $attached );
		}

		// One creative that was never promoted. Nothing to mark, and marking it
		// anyway would put a marker on attachment 0.
		$unpromoted = $this->creative();

		$marked = $this->attachments->backfill_creative_attachment_marks( 2 );

		$this->assertSame( 3, $marked, 'The backfill did not mark what it claims to have marked.' );

		foreach ( $promoted as $index => $creative_id ) {
			$this->assertSame(
				$creative_id,
				(int) get_post_meta( $attachment[ $index ], Creative_Repository::META_IS_CREATIVE, true ),
				'A promoted creative was left unmarked.'
			);
		}

		$this->assertSame(
			'',
			(string) get_post_meta( $unpromoted, Creative_Repository::META_IS_CREATIVE, true )
		);
	}

	/**
	 * Running it twice writes the same answer, because a migration is retried.
	 *
	 * The batch size is deliberately smaller than the fixture, so this also
	 * covers the paging loop: a backfill that read only its first page would
	 * return 2 here rather than 3.
	 *
	 * @return void
	 */
	public function test_the_backfill_is_idempotent(): void {
		$creative_id = $this->creative();
		update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, $this->attachment() );

		$this->assertSame( 1, $this->attachments->backfill_creative_attachment_marks() );
		$this->assertSame( 1, $this->attachments->backfill_creative_attachment_marks() );
	}

	/**
	 * The on-disk path a copy falls back to when the private stage is gone.
	 *
	 * `Campaign_Copier` reads this when it cannot resolve a private original —
	 * approval deletes those — so an empty answer there is a copied campaign
	 * whose creative has no bytes behind it.
	 *
	 * @return void
	 */
	public function test_the_attachment_file_is_the_path_on_disk(): void {
		$creative_id   = $this->creative();
		$attachment_id = $this->attachment();

		$this->assertSame( '', $this->attachments->attachment_file( $creative_id ), 'An unpromoted creative has no file.' );

		update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, $attachment_id );

		$file = $this->attachments->attachment_file( $creative_id );

		$this->assertNotSame( '', $file, 'A promoted creative reported no file on disk.' );
		$this->assertSame( get_attached_file( $attachment_id ), $file );
		$this->assertStringContainsString( 'creative', $file );
	}

	/**
	 * Creates a creative post.
	 *
	 * @return int Creative post id.
	 */
	private function creative(): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Creates a Media Library attachment.
	 *
	 * @return int Attachment post id.
	 */
	private function attachment(): int {
		return (int) self::factory()->attachment->create_object(
			array(
				'file'           => 'creative.png',
				'post_mime_type' => 'image/png',
			)
		);
	}
}
