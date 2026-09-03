<?php
/**
 * Publishing a creative added to a campaign that is already running.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Attachment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Creative_Approval;
use WP_UnitTestCase;

/**
 * The decision that had nowhere to be made.
 *
 * `Publisher::publish_campaign()` promotes every creative on the transition
 * *into* a published state. A creative added afterwards misses it: the campaign
 * has no publish transition left, `EFFECT_RESUME` only busts the fill cache,
 * and the only per-creative approval that existed was for replacements. So such
 * a creative stayed unpublished for ever, had no attachment, and the decision
 * engine refused it with `eligibility_missing_attachment` — correctly, and with
 * nothing in wp-admin able to change it.
 */
final class CreativeApprovalTest extends WP_UnitTestCase {

	/**
	 * Workflow under test.
	 *
	 * @var Creative_Approval
	 */
	private Creative_Approval $approvals;

	/**
	 * Campaign persistence.
	 *
	 * @var Campaign_Repository
	 */
	private Campaign_Repository $campaigns;

	/**
	 * Creative persistence.
	 *
	 * @var Creative_Repository
	 */
	private Creative_Repository $creatives;

	/**
	 * Media Library copy of the artwork.
	 *
	 * @var Creative_Attachment_Repository
	 */
	private Creative_Attachment_Repository $attachments;

	/**
	 * Reviewer with publishing rights.
	 *
	 * @var int
	 */
	private int $reviewer;

	/**
	 * Owning organization.
	 *
	 * @var int
	 */
	private int $org_id;

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$container = Plugin::instance()->container();

		$this->approvals   = $container->get( Creative_Approval::class );
		$this->campaigns   = $container->get( Campaign_Repository::class );
		$this->creatives   = $container->get( Creative_Repository::class );
		$this->attachments = $container->get( Creative_Attachment_Repository::class );

		$container->get( Audit_Repository::class )->install_table();

		$this->reviewer = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->org_id   = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * A campaign in one status with one creative that has never been published.
	 *
	 * @param string $status Campaign post status.
	 * @return array{campaign: int, creative: int, placement: int, assignment: int}
	 */
	private function fixture( string $status ): array {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => $status,
			)
		);

		update_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, $this->org_id );

		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);

		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $creative_id, Creative_Repository::META_CAMPAIGN_ID, $campaign_id );
		update_post_meta( $creative_id, Creative_Repository::META_ORG_ID, $this->org_id );
		update_post_meta( $creative_id, Creative_Repository::META_PLACEMENT_ID, $placement_id );
		update_post_meta( $creative_id, Creative_Repository::META_SIZE, '728x90' );
		update_post_meta( $creative_id, Creative_Repository::META_KIND, 'image' );

		/*
		 * A live assignment, because that is what delivery actually reads. The
		 * first version of this fixture created none, so `retire_assignments()`
		 * was never exercised — removing it entirely changed no test, which is
		 * the definition of untested code.
		 */
		global $wpdb;

		$assignments = Plugin::instance()->container()->get( Creative_Assignment_Repository::class );
		$assignments->install_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture for this plugin's own table.
		$wpdb->insert(
			$assignments->table_name(),
			array(
				'line_item_id'  => 1,
				'campaign_id'   => $campaign_id,
				'placement_id'  => $placement_id,
				'revision_id'   => $creative_id,
				'status'        => Assignment_Rules::LIVE,
				'weight'        => 100,
				'click_url'     => 'https://example.com/landing',
				'attachment_id' => 4242,
				'width'         => 728,
				'height'        => 90,
				'revision'      => 1,
			)
		);

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		return array(
			'campaign'   => $campaign_id,
			'creative'   => $creative_id,
			'placement'  => $placement_id,
			'assignment' => (int) $wpdb->insert_id,
		);
	}

	/**
	 * **A creative on a running campaign is offered for a decision.**
	 *
	 * Before this it was offered nowhere at all.
	 */
	public function test_a_creative_on_a_running_campaign_awaits_a_decision(): void {
		$fixture = $this->fixture( Post_Statuses::LIVE );

		$this->assertFalse(
			$this->attachments->has_attachment( $fixture['creative'] ),
			'The fixture must be unpublished, or this proves nothing.'
		);

		$this->assertSame( array( $fixture['creative'] ), $this->approvals->awaiting( $fixture['campaign'] ) );
	}

	/**
	 * A campaign still awaiting its first approval offers none.
	 *
	 * Its creatives are decided by approving the campaign. A second control for
	 * the same thing would let a reviewer publish one creative of a campaign
	 * nobody has approved.
	 *
	 * @return array<string, array{string}>
	 */
	public static function unpublished_statuses(): array {
		return array(
			'a draft'         => array( Post_Statuses::DRAFT ),
			'submitted'       => array( Post_Statuses::SUBMITTED ),
			'under review'    => array( Post_Statuses::REVIEW ),
			'approved'        => array( Post_Statuses::APPROVED ),
			'asked to change' => array( Post_Statuses::CHANGES ),
		);
	}

	/**
	 * Asserts one pre-publication status offers nothing.
	 *
	 * @dataProvider unpublished_statuses
	 *
	 * @param string $status Campaign post status.
	 */
	public function test_a_campaign_before_publication_offers_no_creative_decision( string $status ): void {
		$fixture = $this->fixture( $status );

		$this->assertSame( array(), $this->approvals->awaiting( $fixture['campaign'] ) );
	}

	/**
	 * **A reviewer without publishing rights cannot publish.**
	 *
	 * Reviewing and publishing are separate capabilities, and this promotes
	 * bytes onto a public page.
	 */
	public function test_publishing_requires_the_publish_capability(): void {
		$fixture = $this->fixture( Post_Statuses::LIVE );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		$result = $this->approvals->approve( $fixture['creative'] );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_forbidden', $result->get_error_code() );
	}

	/**
	 * A creative that is not awaiting anything cannot be published by id.
	 *
	 * The route takes a creative id and nothing else, so this is what stops a
	 * caller publishing a creative on a campaign that has not been approved by
	 * naming it directly.
	 */
	public function test_a_creative_on_an_unapproved_campaign_is_refused(): void {
		$fixture = $this->fixture( Post_Statuses::SUBMITTED );

		wp_set_current_user( $this->reviewer );

		$result = $this->approvals->approve( $fixture['creative'] );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_creative_not_awaiting', $result->get_error_code() );
	}

	/**
	 * A creative that does not exist is refused without a fatal.
	 */
	public function test_an_unknown_creative_is_refused(): void {
		wp_set_current_user( $this->reviewer );

		$result = $this->approvals->approve( 987654 );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_creative_not_found', $result->get_error_code() );
	}

	/**
	 * **Turning one down requires a reason.**
	 *
	 * An advertiser told only "no" learns nothing, and silence is the behaviour
	 * this whole decision exists to replace. The same rule the replacement
	 * rejection already applies.
	 */
	public function test_rejecting_requires_a_reason(): void {
		$fixture = $this->fixture( Post_Statuses::LIVE );

		wp_set_current_user( $this->reviewer );

		foreach ( array( '', '   ', "\n\t " ) as $empty ) {
			$result = $this->approvals->reject( $fixture['creative'], $empty );

			$this->assertWPError( $result );
			$this->assertSame( 'aggr_creative_notes_required', $result->get_error_code() );
		}

		$this->assertSame(
			array( $fixture['creative'] ),
			$this->approvals->awaiting( $fixture['campaign'] ),
			'A refused rejection must leave the creative waiting.'
		);
	}

	/**
	 * A reason longer than the field allows is refused rather than truncated.
	 */
	public function test_an_over_long_reason_is_refused(): void {
		$fixture = $this->fixture( Post_Statuses::LIVE );

		wp_set_current_user( $this->reviewer );

		$result = $this->approvals->reject(
			$fixture['creative'],
			str_repeat( 'x', Creative_Approval::MAX_NOTES_LENGTH + 1 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_creative_notes_too_long', $result->get_error_code() );
	}

	/**
	 * **A turned-down creative stops waiting and stops being a candidate.**
	 *
	 * Both halves matter. Rejecting only the creative would leave it on the
	 * queue for ever — rejecting cannot give it the attachment whose absence
	 * put it there — and leave its assignment `live`, so the decision engine
	 * would go on considering a candidate it must always refuse.
	 */
	public function test_a_rejected_creative_leaves_the_queue_and_the_candidate_set(): void {
		$fixture = $this->fixture( Post_Statuses::LIVE );

		wp_set_current_user( $this->reviewer );

		$this->assertSame(
			$fixture['campaign'],
			$this->approvals->reject( $fixture['creative'], 'The artwork is the wrong size.' )
		);

		$this->assertSame( array(), $this->approvals->awaiting( $fixture['campaign'] ) );
		$this->assertSame( 0, $this->campaigns->pending_creative_count( $fixture['campaign'] ) );
		$this->assertTrue( $this->creatives->is_rejected( $fixture['creative'] ) );

		/*
		 * The candidate set, which is the half the queue cannot show. Leaving
		 * the assignment `live` would have the decision engine go on
		 * considering a candidate it must always refuse — and the trace would
		 * report a rejected creative as merely ineligible.
		 */
		$assignments = Plugin::instance()->container()->get( Creative_Assignment_Repository::class );

		$this->assertSame(
			array(),
			$assignments->candidates_for_placement( $fixture['placement'], time() ),
			'A turned-down creative was still a delivery candidate.'
		);
	}

	/**
	 * The reason is stored, because the advertiser is going to read it.
	 */
	public function test_the_reason_is_stored(): void {
		$fixture = $this->fixture( Post_Statuses::LIVE );

		wp_set_current_user( $this->reviewer );

		$this->approvals->reject( $fixture['creative'], '  The logo is unreadable at this size.  ' );

		$this->assertSame(
			'The logo is unreadable at this size.',
			(string) get_post_meta( $fixture['creative'], Creative_Repository::META_CHANGE_NOTES, true ),
			'The reason must be stored trimmed and intact.'
		);
	}

	/**
	 * A rejected creative cannot then be published.
	 *
	 * The decision is one or the other, and `awaiting()` is what enforces it —
	 * so this also proves the two paths agree about what is still open.
	 */
	public function test_a_rejected_creative_cannot_be_published_afterwards(): void {
		$fixture = $this->fixture( Post_Statuses::LIVE );

		wp_set_current_user( $this->reviewer );

		$this->approvals->reject( $fixture['creative'], 'Wrong artwork.' );

		$result = $this->approvals->approve( $fixture['creative'] );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_creative_not_awaiting', $result->get_error_code() );
	}

	/**
	 * Rejecting needs only the reviewing capability.
	 *
	 * Publishing needs two because it puts bytes on a public page. Refusing
	 * publishes nothing, so demanding the publishing capability would stop a
	 * reviewer doing the half of their job that is purely a refusal.
	 */
	public function test_rejecting_needs_only_the_review_capability(): void {
		$fixture = $this->fixture( Post_Statuses::LIVE );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		$refused = $this->approvals->reject( $fixture['creative'], 'Not allowed.' );

		$this->assertWPError( $refused );
		$this->assertSame( 'aggr_forbidden', $refused->get_error_code() );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$this->assertIsInt(
			$this->approvals->reject( $fixture['creative'], 'The artwork is the wrong size.' ),
			'A reviewer must be able to turn a creative down.'
		);
	}

	/**
	 * **The queue counter is what makes it findable.**
	 *
	 * Without it the campaign appears on no tab and a reviewer has to already
	 * know to open it — which is exactly how this went unnoticed.
	 */
	public function test_the_queue_counter_follows_the_waiting_creative(): void {
		$fixture = $this->fixture( Post_Statuses::LIVE );

		$this->approvals->refresh_count( $fixture['campaign'] );

		$this->assertSame( 1, $this->campaigns->pending_creative_count( $fixture['campaign'] ) );
		$this->assertGreaterThan( 0, $this->campaigns->campaigns_with_pending_updates() );
	}

	/**
	 * A campaign that has not been published counts nothing.
	 */
	public function test_a_campaign_before_publication_counts_nothing(): void {
		$fixture = $this->fixture( Post_Statuses::DRAFT );

		$this->approvals->refresh_count( $fixture['campaign'] );

		$this->assertSame( 0, $this->campaigns->pending_creative_count( $fixture['campaign'] ) );
	}
	/**
	 * **A reason belongs to the decision that produced it.**
	 *
	 * `META_CHANGE_NOTES` carries two decisions: a refused *replacement*, written
	 * by `reject_replacement()`, and a turned-down creative, written by
	 * `reject_creative()`. Only the second is the advertiser's answer to "why is
	 * this ad not running". Today the raw key happens to be safe to read on a
	 * campaign screen, because replacement revisions are filtered out before it
	 * is reached — which is a property of a different method and would go on
	 * being relied on silently.
	 *
	 * So the pairing is asserted directly: notes present, creative not rejected,
	 * nothing returned.
	 *
	 * @return void
	 */
	public function test_notes_are_not_a_rejection_reason_until_there_is_a_rejection(): void {
		$fixture   = $this->fixture( Post_Statuses::LIVE );
		$creatives = Plugin::instance()->container()->get( Creative_Repository::class );

		update_post_meta( $fixture['creative'], Creative_Repository::META_CHANGE_NOTES, 'Notes from some other decision.' );

		$this->assertSame(
			'Notes from some other decision.',
			$creatives->change_notes( $fixture['creative'] ),
			'The fixture did not put notes on the creative, so the refusal below proves nothing.'
		);
		$this->assertFalse( $creatives->is_rejected( $fixture['creative'] ) );
		$this->assertSame(
			'',
			$this->approvals->rejection_notes( $fixture['creative'] ),
			'Notes written by another decision were offered as the reason this ad is not running.'
		);

		$this->assertTrue( $creatives->reject_creative( $fixture['creative'], 'The logo is stretched.' ) );
		$this->assertSame(
			'The logo is stretched.',
			$this->approvals->rejection_notes( $fixture['creative'] ),
			'A real rejection reason must reach the advertiser.'
		);
	}
}
