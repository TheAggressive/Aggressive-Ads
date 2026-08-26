<?php
/**
 * An advertiser corrects text without re-uploading, and the ad keeps serving.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Creative_Revision_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Creative_Change_Manager;
use WP_UnitTestCase;

/**
 * The behaviour chosen deliberately: the live ad keeps running while this waits.
 *
 * An advertiser fixing a typo must not be able to take their own paid placement
 * off the site, and a publisher must not lose inventory because somebody
 * corrected a word. So a pending text revision changes nothing a visitor can
 * see until a reviewer decides.
 *
 * The other half is the one that makes the one-click review lane safe: the
 * revision carries the predecessor's bytes, so `is_text_only_revision()` is
 * true because the two checksums genuinely match — never because a caller said
 * so. A client-settable flag would be a way to swap artwork and claim the fast
 * lane.
 */
final class TextOnlyChangeTest extends WP_UnitTestCase {

	/**
	 * Change workflow under test.
	 *
	 * @var Creative_Change_Manager
	 */
	private Creative_Change_Manager $changes;

	/**
	 * Revision chain persistence.
	 *
	 * @var Creative_Revision_Repository
	 */
	private Creative_Revision_Repository $revisions;

	/**
	 * Creative persistence.
	 *
	 * @var Creative_Repository
	 */
	private Creative_Repository $creatives;

	/**
	 * Owning advertiser.
	 *
	 * @var int
	 */
	private int $owner = 0;

	/**
	 * Owning organization.
	 *
	 * @var int
	 */
	private int $org_id = 0;

	public function set_up(): void {
		parent::set_up();

		$container = Plugin::instance()->container();

		$this->changes   = $container->get( Creative_Change_Manager::class );
		$this->revisions = $container->get( Creative_Revision_Repository::class );
		$this->creatives = $container->get( Creative_Repository::class );

		$this->owner = (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		/*
		 * A real organization, because `edit_aggr_creative` is org-scoped and
		 * `map_meta_cap` answers from ownership rather than authorship. A
		 * fixture without one is refused at the door, which is the correct
		 * behaviour and makes every later assertion vacuous.
		 */
		$this->org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Bright Angle Media',
			)
		);

		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->owner );

		$container->get( Org_Repository::class )->flush_cache();
		$container->get( Ownership::class )->flush_cache();

		wp_set_current_user( $this->owner );
	}

	/**
	 * A live campaign with one approved, serving creative.
	 *
	 * @return array{campaign: int, creative: int}
	 */
	private function serving_creative(): array {
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
				'post_author' => $this->owner,
			)
		);

		add_post_meta( $campaign, Campaign_Repository::META_PLACEMENT_ID, $placement );
		update_post_meta( $campaign, Campaign_Repository::META_ORG_ID, $this->org_id );

		$creative = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
				'post_author' => $this->owner,
			)
		);

		update_post_meta( $creative, Creative_Repository::META_CAMPAIGN_ID, $campaign );
		update_post_meta( $creative, Creative_Repository::META_ORG_ID, $this->org_id );
		update_post_meta( $creative, Creative_Repository::META_PLACEMENT_ID, $placement );
		update_post_meta( $creative, Creative_Repository::META_CLICK_URL, 'https://example.com/old' );
		update_post_meta( $creative, Creative_Repository::META_ALT_TEXT, 'Old copy' );
		update_post_meta( $creative, Creative_Repository::META_KIND, 'image' );
		update_post_meta( $creative, Creative_Repository::META_SHA256, str_repeat( 'c', 64 ) );

		$attachment = (int) self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);

		update_post_meta( $creative, Creative_Repository::META_ATTACHMENT_ID, $attachment );

		return array(
			'campaign' => $campaign,
			'creative' => $creative,
		);
	}

	/**
	 * The ad keeps serving while the correction waits.
	 *
	 * The decision this encodes, asserted rather than assumed: the current
	 * revision stays active, so nothing a visitor sees changes until a reviewer
	 * decides. The alternative — pausing on edit — would let an advertiser pull
	 * their own placement down with a typo fix.
	 */
	public function test_a_pending_text_change_leaves_the_live_ad_serving(): void {
		$made = $this->serving_creative();

		$result = $this->changes->request_text_change(
			$made['creative'],
			'https://example.com/new',
			'New copy'
		);

		$this->assertIsArray( $result, 'The text change was refused.' );

		$this->assertTrue(
			$this->creatives->is_active( $made['creative'] ),
			'The serving ad stopped being active while its correction waited.'
		);
		$this->assertFalse(
			$this->creatives->is_active( (int) $result['id'] ),
			'An unreviewed revision was treated as live.'
		);

		// And the live ad still says what was approved.
		$this->assertSame(
			'https://example.com/old',
			(string) get_post_meta( $made['creative'], Creative_Repository::META_CLICK_URL, true )
		);
	}

	/**
	 * The revision is text-only, derived from the checksums.
	 *
	 * The property behind the one-click lane. It is true because the bytes are
	 * the same file, not because the request said it was a text change.
	 */
	public function test_the_pending_revision_is_classified_text_only(): void {
		$made   = $this->serving_creative();
		$result = $this->changes->request_text_change( $made['creative'], 'https://example.com/new', 'New copy' );

		$revision = (int) $result['id'];

		$this->assertSame(
			(string) get_post_meta( $made['creative'], Creative_Repository::META_SHA256, true ),
			(string) get_post_meta( $revision, Creative_Repository::META_SHA256, true )
		);
		$this->assertTrue( $this->revisions->is_text_only_revision( $revision ) );
	}

	/**
	 * A revision whose bytes were swapped loses the fast lane.
	 *
	 * The negative half, and the one that matters: without it the
	 * classification would be true of any pending revision, including one that
	 * replaced the artwork.
	 */
	public function test_swapping_the_bytes_removes_the_text_only_classification(): void {
		$made     = $this->serving_creative();
		$result   = $this->changes->request_text_change( $made['creative'], 'https://example.com/new', 'New copy' );
		$revision = (int) $result['id'];

		update_post_meta( $revision, Creative_Repository::META_SHA256, str_repeat( 'd', 64 ) );

		$this->assertFalse(
			$this->revisions->is_text_only_revision( $revision ),
			'A revision with different bytes was offered the one-click review lane.'
		);
	}

	/**
	 * The review screen is told the artwork is unchanged, and told the truth.
	 *
	 * The badge a reviewer approves at a glance rests entirely on this flag, so
	 * it is asserted from the screen's own data rather than from the repository
	 * that computes it — a correct classification that never reaches the screen
	 * is the same as a wrong one.
	 *
	 * The negative half matters more: a revision whose bytes were swapped must
	 * lose the badge, or the one-click lane becomes the way to smuggle artwork
	 * past a reviewer who was told they did not need to look.
	 */
	public function test_the_review_screen_is_told_whether_the_artwork_changed(): void {
		$made   = $this->serving_creative();
		$result = $this->changes->request_text_change( $made['creative'], 'https://example.com/new', 'New copy' );

		$staff = (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );
		wp_set_current_user( $staff );

		$data = Plugin::instance()->container()
			->get( \Aggressive\Ads\Admin\Review_Data::class )
			->campaign( $made['campaign'] );

		$updates = $data['creative_updates'] ?? array();

		$this->assertCount( 1, $updates, 'The pending change did not reach the review screen.' );
		$this->assertTrue( $updates[0]['text_only'], 'The reviewer was not told the artwork is unchanged.' );

		// Swap the bytes; the badge must disappear.
		update_post_meta( (int) $result['id'], Creative_Repository::META_SHA256, str_repeat( 'e', 64 ) );

		$again = Plugin::instance()->container()
			->get( \Aggressive\Ads\Admin\Review_Data::class )
			->campaign( $made['campaign'] );

		$this->assertFalse(
			$again['creative_updates'][0]['text_only'],
			'A revision with swapped artwork still told the reviewer nothing had changed.'
		);
	}

	/** A change that changes nothing is refused rather than queued. */
	public function test_an_unchanged_request_is_refused(): void {
		$made = $this->serving_creative();

		$result = $this->changes->request_text_change(
			$made['creative'],
			'https://example.com/old',
			'Old copy'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_replacement_unchanged', $result->get_error_code() );
	}

	/** An invalid destination is refused with the same rule a full upload uses. */
	public function test_an_invalid_destination_is_refused(): void {
		$made = $this->serving_creative();

		$result = $this->changes->request_text_change( $made['creative'], 'javascript:alert(1)', 'x' );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_click_url_invalid', $result->get_error_code() );
	}

	/** Two pending changes at once are refused. */
	public function test_a_second_pending_change_is_refused(): void {
		$made = $this->serving_creative();

		$this->assertIsArray(
			$this->changes->request_text_change( $made['creative'], 'https://example.com/new', 'New copy' )
		);

		$second = $this->changes->request_text_change( $made['creative'], 'https://example.com/newer', 'Newer copy' );

		$this->assertWPError( $second );
		$this->assertSame( 'aggr_replacement_pending', $second->get_error_code() );
	}

	/**
	 * Another advertiser cannot request a change on someone else's ad.
	 *
	 * The tenant boundary, asserted on the new entry point rather than assumed
	 * from the old one sharing an authorization helper.
	 */
	public function test_a_stranger_cannot_request_a_change(): void {
		$made = $this->serving_creative();

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		$result = $this->changes->request_text_change( $made['creative'], 'https://example.com/new', 'New copy' );

		$this->assertWPError( $result );
		$this->assertNotSame(
			'aggr_replacement_unchanged',
			$result->get_error_code(),
			'The refusal must not depend on what was submitted.'
		);
	}
}
