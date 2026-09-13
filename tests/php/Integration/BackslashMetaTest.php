<?php
/**
 * Text written to post meta keeps its backslashes.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Campaign_Request_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Creative_Revision_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use WP_UnitTestCase;

/**
 * `update_post_meta()` unslashes its value, so text stored without `wp_slash()`
 * loses every backslash.
 *
 * Where a write was followed by a read-back — rejecting a creative, rejecting
 * an ad update, saving a house ad — the stored value no longer matched what was
 * sent and the action reported that it had not been saved. A reviewer's
 * rejection note containing a path like `C:\ads\banner.png` could not be
 * recorded at all. Everywhere else the backslash simply disappeared.
 *
 * Organized by repository rather than by feature, because the defect is in
 * how each one writes, and a new repository method is where it would return.
 */
final class BackslashMetaTest extends WP_UnitTestCase {

	/**
	 * Text that only survives if it is slashed on the way in.
	 */
	private const TEXT = 'See C:\\ads\\banner.png and the \\n in the brief';

	/**
	 * Campaign post id.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * Placement post id.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Stored creative reads and writes.
	 *
	 * @var Creative_Repository
	 */
	private Creative_Repository $creatives;

	/**
	 * A campaign and a placement to hang writes on.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->campaign_id  = (int) self::factory()->post->create( array( 'post_type' => Post_Types::CAMPAIGN ) );
		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);
		$this->creatives    = Plugin::instance()->container()->get( Creative_Repository::class );
	}

	/**
	 * A creative to act on.
	 *
	 * @param string $alt_text  Alt text to store.
	 * @param string $click_url Destination to store.
	 * @return int Creative post id.
	 */
	private function creative( string $alt_text = 'Alt', string $click_url = 'https://example.com/' ): int {
		$creative_id = $this->creatives->create(
			$this->campaign_id,
			1,
			$this->placement_id,
			array(
				'kind'      => 'image',
				'click_url' => $click_url,
				'alt_text'  => $alt_text,
				'size'      => '728x90',
			)
		);

		$this->assertGreaterThan( 0, $creative_id );

		return $creative_id;
	}

	/**
	 * **Rejecting a creative with a backslash in the note is recorded.**
	 *
	 * The write stripped the backslash, the read-back compared the stored note
	 * with the one sent, and the rejection was reported as not saved.
	 *
	 * @return void
	 */
	public function test_rejecting_a_creative_keeps_the_note(): void {
		$creative_id = $this->creative();

		$this->assertTrue( $this->creatives->reject_creative( $creative_id, self::TEXT ) );
		$this->assertSame( self::TEXT, $this->creatives->change_notes( $creative_id ) );
	}

	/**
	 * **Rejecting an ad update with a backslash in the note is recorded.**
	 *
	 * @return void
	 */
	public function test_rejecting_an_ad_update_keeps_the_note(): void {
		$creative_id = $this->creative();
		$revisions   = Plugin::instance()->container()->get( Creative_Revision_Repository::class );

		$this->assertTrue( $revisions->reject_replacement( $creative_id, self::TEXT ) );
		$this->assertSame( self::TEXT, $this->creatives->change_notes( $creative_id ) );
	}

	/**
	 * **A house ad with a backslash in its alt text or destination saves.**
	 *
	 * @return void
	 */
	public function test_a_house_ad_keeps_its_text(): void {
		$placements = Plugin::instance()->container()->get( Placement_Repository::class );
		$click_url  = 'https://example.com/path\\with\\backslashes';

		$this->assertTrue( $placements->set_house( $this->placement_id, 0, $click_url, self::TEXT ) );
		$this->assertSame( $click_url, $placements->house_click_url( $this->placement_id ) );
		$this->assertSame( self::TEXT, $placements->house_alt( $this->placement_id ) );
	}

	/**
	 * A creative's alt text and destination keep their backslashes, on create
	 * and on every later edit. These had no read-back, so the loss was silent.
	 *
	 * @return void
	 */
	public function test_a_creative_keeps_backslashes_in_its_text(): void {
		$url         = 'https://example.com/a\\b';
		$creative_id = $this->creative( self::TEXT, $url );

		$this->assertSame( self::TEXT, get_post_meta( $creative_id, Creative_Repository::META_ALT_TEXT, true ) );
		$this->assertSame( $url, get_post_meta( $creative_id, Creative_Repository::META_CLICK_URL, true ) );

		$edited_url = 'https://example.com/c\\d';
		$edited_alt = self::TEXT . ' \\ edited';

		$this->creatives->set_text( $creative_id, $edited_url, $edited_alt );

		$this->assertSame( $edited_alt, get_post_meta( $creative_id, Creative_Repository::META_ALT_TEXT, true ) );
		$this->assertSame( $edited_url, get_post_meta( $creative_id, Creative_Repository::META_CLICK_URL, true ) );

		$relinked = 'https://example.com/e\\f';

		$this->creatives->set_click_url( $creative_id, $relinked );

		$this->assertSame( $relinked, get_post_meta( $creative_id, Creative_Repository::META_CLICK_URL, true ) );
	}

	/**
	 * A campaign's review and internal notes keep their backslashes.
	 *
	 * @return void
	 */
	public function test_campaign_notes_keep_backslashes(): void {
		$campaigns = Plugin::instance()->container()->get( Campaign_Repository::class );

		$campaigns->set_review_notes( $this->campaign_id, self::TEXT );
		$campaigns->set_internal_notes( $this->campaign_id, self::TEXT . ' (internal)' );

		$this->assertSame( self::TEXT, $campaigns->review_notes( $this->campaign_id ) );
		$this->assertSame( self::TEXT . ' (internal)', $campaigns->internal_notes( $this->campaign_id ) );
	}

	/**
	 * **A proposed change with a backslash in its title is recorded.**
	 *
	 * The proposal is stored as one array and read back whole. Unslashed, the
	 * title inside it lost its backslash, the read-back no longer matched, and
	 * the advertiser's change request was refused.
	 *
	 * @return void
	 */
	public function test_a_proposed_change_keeps_backslashes(): void {
		$requests = Plugin::instance()->container()->get( Campaign_Request_Repository::class );
		$edits    = array(
			'title'            => 'Path \\ renamed',
			'advertiser_notes' => self::TEXT,
		);

		$this->assertTrue( $requests->set_pending_edits( $this->campaign_id, $edits, 1 ) );
		$this->assertSame( $edits, $requests->pending_edits( $this->campaign_id ) );
	}

	/**
	 * An advertiser's reason for a requested action keeps its backslashes.
	 *
	 * @return void
	 */
	public function test_an_action_request_keeps_its_reason(): void {
		$requests = Plugin::instance()->container()->get( Campaign_Request_Repository::class );

		$this->assertTrue( $requests->set_action_request( $this->campaign_id, 'aggr_paused', self::TEXT, 1 ) );
		$this->assertSame( self::TEXT, $requests->action_request( $this->campaign_id )['reason'] );
	}
}
