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

		$this->approvals = $container->get( Creative_Approval::class );
		$this->campaigns = $container->get( Campaign_Repository::class );
		$this->creatives = $container->get( Creative_Repository::class );

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
	 * @return array{campaign: int, creative: int}
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

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		return array(
			'campaign' => $campaign_id,
			'creative' => $creative_id,
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
			$this->creatives->has_attachment( $fixture['creative'] ),
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
}
