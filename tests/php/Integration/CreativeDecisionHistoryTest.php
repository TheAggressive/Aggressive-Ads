<?php
/**
 * What was decided about a revision, and how long it survives.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Review_Data;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Creative_View_Data;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Creative_Decision_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Creative_Approval;
use Aggressive\Ads\Workflow\Creative_Manager;
use WP_UnitTestCase;

/**
 * The review history against real decisions, not the audit log.
 *
 * P17 asks for a record a reviewer and an advertiser can read without one —
 * and for a rejection to be as durable as an approval, because deleting why
 * something was refused is how the same creative gets resubmitted for ever.
 * The durability is the assertion that matters here: the reason used to live
 * on the revision's own state and was read only while that state still said
 * "rejected", so a campaign moving on took the explanation with it.
 */
final class CreativeDecisionHistoryTest extends WP_UnitTestCase {

	use CreativeFixtures;

	/**
	 * Owning advertiser user id.
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * A reviewer, who is the only one who may decide.
	 *
	 * @var int
	 */
	private int $reviewer;

	/**
	 * Running campaign id.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * The placement its ads run on.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * A second placement, because a running campaign takes an ad only for a
	 * size that has none — so two decisions need two sizes.
	 *
	 * @var int
	 */
	private int $second_placement;

	/**
	 * Decisions as stored.
	 *
	 * @var Creative_Decision_Repository
	 */
	private Creative_Decision_Repository $decisions;

	/**
	 * Review decisions.
	 *
	 * @var Creative_Approval
	 */
	private Creative_Approval $approvals;

	/**
	 * Uploads.
	 *
	 * @var Creative_Manager
	 */
	private Creative_Manager $manager;

	/**
	 * A live campaign, so an upload joins the review queue.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->owner            = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->reviewer         = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );
		$this->placement_id     = $this->placement( 'Header', '728x90' );
		$this->second_placement = $this->placement( 'Break', '728x90' );
		$this->campaign_id      = $this->campaign(
			$this->owner,
			$this->org( $this->owner ),
			array( $this->placement_id, $this->second_placement )
		);

		wp_update_post(
			array(
				'ID'          => $this->campaign_id,
				'post_status' => Post_Statuses::LIVE,
			)
		);

		$container       = Plugin::instance()->container();
		$this->decisions = $container->get( Creative_Decision_Repository::class );
		$this->approvals = $container->get( Creative_Approval::class );
		$this->manager   = $container->get( Creative_Manager::class );

		$this->decisions->install_table();
		$container->get( Ownership::class )->flush_cache();
	}

	/**
	 * Removes stored and temporary files.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_creative_files( $this->campaign_id );

		parent::tear_down();
	}

	/**
	 * A rejection keeps its reason after the campaign has moved on.
	 *
	 * @return void
	 */
	public function test_a_rejection_keeps_its_reason_after_the_campaign_moves_on(): void {
		$creative_id = $this->ad();

		wp_set_current_user( $this->reviewer );
		$this->assertSame( $this->campaign_id, $this->approvals->reject( $creative_id, 'The logo is stretched.' ) );

		// The campaign moves on: paused, then complete, then its ad replaced
		// in every way a later state can change what the revision says.
		wp_update_post(
			array(
				'ID'          => $this->campaign_id,
				'post_status' => Post_Statuses::COMPLETE,
			)
		);

		$history = $this->decisions->for_revision( $creative_id );

		$this->assertCount( 1, $history );
		$this->assertSame( Creative_Decision_Repository::REJECTED, $history[0]['decision'] );
		$this->assertSame( 'The logo is stretched.', $history[0]['reason'] );
		$this->assertGreaterThan( 0, (int) $history[0]['decided_at_ts'] );
	}

	/**
	 * Every decision is kept, in the order they were taken.
	 *
	 * A later decision corrects an earlier one by being later, never by
	 * rewriting it: an approval that followed a rejection is two facts about
	 * two moments, and a reviewer reading the history needs both.
	 *
	 * @return void
	 */
	public function test_decisions_accumulate_rather_than_replace_each_other(): void {
		$first  = $this->ad();
		$second = $this->ad( $this->second_placement );

		wp_set_current_user( $this->reviewer );

		$this->assertSame( $this->campaign_id, $this->approvals->reject( $first, 'Too dark to read.' ) );
		$this->assertSame( $this->campaign_id, $this->approvals->approve( $second ) );

		$this->assertSame(
			array( Creative_Decision_Repository::REJECTED ),
			array_column( $this->decisions->for_revision( $first ), 'decision' )
		);
		$this->assertSame(
			array( Creative_Decision_Repository::APPROVED ),
			array_column( $this->decisions->for_revision( $second ), 'decision' ),
			'A decision landed on the wrong revision, or an approval was not recorded.'
		);

		// And the campaign's own view has both, newest first.
		$this->assertSame(
			array( Creative_Decision_Repository::APPROVED, Creative_Decision_Repository::REJECTED ),
			array_column( $this->decisions->for_campaign( $this->campaign_id ), 'decision' )
		);
	}

	/**
	 * The advertiser's card carries the decision and the reason, and no reviewer.
	 *
	 * @return void
	 */
	public function test_the_advertisers_card_shows_the_decision_without_naming_the_reviewer(): void {
		$creative_id = $this->ad();

		wp_set_current_user( $this->reviewer );
		$this->approvals->reject( $creative_id, 'Use the approved logo.' );

		wp_set_current_user( $this->owner );

		$rows = Plugin::instance()->container()->get( Creative_View_Data::class )->creative_rows( $this->campaign_id );

		$this->assertCount( 1, $rows );
		$this->assertSame(
			array(
				array(
					'decision' => Creative_Decision_Repository::REJECTED,
					'reason'   => 'Use the approved logo.',
					'at'       => (int) $this->decisions->for_revision( $creative_id )[0]['decided_at_ts'],
				),
			),
			$rows[0]['decisions']
		);

		/*
		 * The shape, not a search for the id: a reviewer id of 7 appears
		 * inside any timestamp, so "the response does not contain 7" passes
		 * whatever the response holds. What the advertiser gets is these three
		 * keys and nothing else.
		 */
		$this->assertSame(
			array( 'decision', 'reason', 'at' ),
			array_keys( $rows[0]['decisions'][0] ),
			'An advertiser-facing decision grew a field; who decided is not theirs to read.'
		);
	}

	/**
	 * Publishing one ad leaves every other ad's bytes where they were.
	 *
	 * The checksum is the revision. Approving a sibling must not rewrite it,
	 * or a later file check would be judging artwork nobody reviewed.
	 *
	 * @return void
	 */
	public function test_approving_one_ad_leaves_the_others_checksum_alone(): void {
		$first  = $this->ad();
		$second = $this->ad( $this->second_placement );
		$hash   = (string) get_post_meta( $first, Creative_Repository::META_SHA256, true );
		$path   = (string) get_post_meta( $first, Creative_Repository::META_PRIVATE_PATH, true );

		$this->assertNotSame( '', $hash );
		$this->assertNotSame( '', $path );

		wp_set_current_user( $this->reviewer );
		$this->assertSame( $this->campaign_id, $this->approvals->approve( $second ) );

		$this->assertSame( $hash, (string) get_post_meta( $first, Creative_Repository::META_SHA256, true ) );
		$this->assertSame( $path, (string) get_post_meta( $first, Creative_Repository::META_PRIVATE_PATH, true ) );
		$this->assertSame( array(), $this->decisions->for_revision( $first ) );
	}

	/**
	 * The review screen shows who decided; the advertiser's card does not.
	 *
	 * @return void
	 */
	public function test_the_review_screen_names_who_decided(): void {
		$creative_id = $this->ad();

		wp_update_user(
			array(
				'ID'           => $this->reviewer,
				'display_name' => 'Reviewer Lane',
			)
		);
		wp_set_current_user( $this->reviewer );
		$this->approvals->reject( $creative_id, 'Use the approved logo.' );

		$campaign = Plugin::instance()->container()->get( Review_Data::class )->campaign( $this->campaign_id );

		$this->assertIsArray( $campaign );

		$match = null;

		foreach ( $campaign['creatives'] as $row ) {
			if ( (int) $row['id'] === $creative_id ) {
				$match = $row;
			}
		}

		$this->assertIsArray( $match );
		$this->assertSame( Creative_Decision_Repository::REJECTED, $match['decisions'][0]['decision'] );
		$this->assertSame( 'Use the approved logo.', $match['decisions'][0]['reason'] );
		$this->assertSame( 'Reviewer Lane', $match['decisions'][0]['actor'] );
		$this->assertArrayNotHasKey( 'actor_user_id', $match['decisions'][0] );
	}

	/**
	 * A decision belongs to the site that made it.
	 *
	 * @return void
	 */
	public function test_a_decision_is_scoped_to_its_own_site(): void {
		$creative_id = $this->ad();

		wp_set_current_user( $this->reviewer );
		$this->approvals->reject( $creative_id, 'Not this one.' );

		$this->assertCount( 1, $this->decisions->for_revision( $creative_id ) );

		// A row written for another site is not this site's history. Written
		// directly, because switching sites in a single-site suite proves
		// nothing about the column that scopes the read.
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Arranging a row this test is about.
		$wpdb->update(
			$this->decisions->table_name(),
			array( 'blog_id' => get_current_blog_id() + 1 ),
			array( 'revision_id' => $creative_id ),
			array( '%d' ),
			array( '%d' )
		);

		$this->assertSame( array(), $this->decisions->for_revision( $creative_id ) );
		$this->assertSame( array(), $this->decisions->for_campaign( $this->campaign_id ) );
	}

	/**
	 * Nothing outside the decisions this table stores can be written into it.
	 *
	 * @return void
	 */
	public function test_an_unknown_decision_is_not_recorded(): void {
		$this->assertSame(
			0,
			$this->decisions->record(
				array(
					'revision_id' => 123,
					'campaign_id' => $this->campaign_id,
					'decision'    => 'maybe',
				)
			)
		);
		$this->assertSame( array(), $this->decisions->for_campaign( $this->campaign_id ) );
	}

	/**
	 * Uploads one ad to one of the running campaign's empty sizes.
	 *
	 * @param int $placement_id Which size; the first by default.
	 * @return int Creative id.
	 */
	private function ad( int $placement_id = 0 ): int {
		wp_set_current_user( $this->owner );

		$result = $this->manager->upload(
			$this->campaign_id,
			$placement_id > 0 ? $placement_id : $this->placement_id,
			$this->image_file( 728, 90, count( $this->temporary ) + 1 ),
			'https://example.com/ad',
			'Ad'
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );

		return (int) $result['id'];
	}
}
