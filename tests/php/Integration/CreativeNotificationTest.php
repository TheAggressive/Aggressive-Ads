<?php
/**
 * Staff notification for a creative waiting on a running campaign.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Review_Screen;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Notification\Creative_Mailer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Workflow\Creative_Approval;
use Aggressive\Ads\Workflow\Creative_Manager;
use WP_UnitTestCase;

/**
 * Proves the review team is told, once, about artwork that cannot serve.
 *
 * Everything here goes through `Creative_Manager::upload()` rather than firing
 * the hook by hand. A creative that reaches the queue and a mailer that would
 * announce one are two halves that have to meet: the counter and the tab
 * shipped working and told nobody, which is the defect this covers, and a test
 * that fires `aggr_notify_creative_awaiting` itself would have passed over it.
 *
 * The uploader is staff throughout, because only staff can upload here at all —
 * `Edit_Window` opens a running campaign to REVIEW_CAMPAIGNS and nobody else.
 * That is also why the actor is excluded from the fan-out, and why that
 * exclusion is worth a test of its own.
 */
final class CreativeNotificationTest extends WP_UnitTestCase {

	/**
	 * Fixture organization post id.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * Active placement the campaign carries.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * The reviewer performing the uploads.
	 *
	 * @var int
	 */
	private int $uploader;

	/**
	 * Production upload workflow.
	 *
	 * @var Creative_Manager
	 */
	private Creative_Manager $manager;

	/**
	 * Production creative decisions.
	 *
	 * @var Creative_Approval
	 */
	private Creative_Approval $approvals;

	/**
	 * Private file storage, for cleanup.
	 *
	 * @var Private_Storage
	 */
	private Private_Storage $storage;

	/**
	 * Every wp_mail call captured through WordPress's supported short circuit.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mail = array();

	/**
	 * Whether the transport accepts what it is handed.
	 *
	 * @var bool
	 */
	private bool $mail_succeeds = true;

	/**
	 * Temporary source files.
	 *
	 * @var array<int, string>
	 */
	private array $temporary = array();

	/**
	 * Installs roles, an organization, an active placement and a staff uploader.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->uploader = $this->reviewer( 'uploader@example.test' );

		$this->org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Bright Angle Media',
			)
		);

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage Leaderboard',
			)
		);

		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );

		$this->manager   = Plugin::instance()->container()->get( Creative_Manager::class );
		$this->approvals = Plugin::instance()->container()->get( Creative_Approval::class );
		$this->storage   = Plugin::instance()->container()->get( Private_Storage::class );

		wp_set_current_user( $this->uploader );

		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/**
	 * Removes the mail interception and the filesystem fixtures.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );

		foreach ( $this->temporary as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}

		parent::tear_down();
	}

	/**
	 * Captures a message and supplies the configured transport result.
	 *
	 * The result is a property rather than a second filter. A second filter at
	 * an earlier priority does not work: `pre_wp_mail` runs the whole chain, so
	 * this one received the refusal and returned `true` over the top of it, and
	 * the failure test it was written for passed against a message that had in
	 * fact been delivered. `RequestNotificationTest` uses a map for this reason.
	 *
	 * @param null|bool            $short_circuit Value from earlier filters.
	 * @param array<string, mixed> $mail          Normalized wp_mail arguments.
	 * @return bool
	 */
	public function capture_mail( null|bool $short_circuit, array $mail ): bool {
		$this->mail[] = $mail;

		return $this->mail_succeeds;
	}

	/**
	 * **Adding artwork to a running campaign tells the rest of the team.**
	 *
	 * The queue counter and the tab already worked. Nobody was told, so the
	 * creative sat unpublished and unserved until somebody happened to look.
	 */
	public function test_a_creative_on_a_running_campaign_reaches_every_other_reviewer(): void {
		$this->reviewer( 'first@example.test' );
		$this->reviewer( 'second@example.test' );

		$campaign_id = $this->campaign( Post_Statuses::LIVE );
		$creative_id = $this->upload( $campaign_id );

		$this->assertIsInt( $creative_id, 'The upload itself failed, so nothing about the notification is being tested.' );
		$this->assertSame(
			array( $creative_id ),
			$this->approvals->awaiting( $campaign_id ),
			'The creative did not reach the review queue, so there was nothing to announce.'
		);

		$this->assertSame(
			$this->expected_recipients( array( 'first@example.test', 'second@example.test' ) ),
			$this->addressed(),
			'Every reviewer but the uploader should have been told exactly once.'
		);
		$this->assertNotContains(
			'uploader@example.test',
			$this->addressed(),
			'The uploader was told to go and review their own upload.'
		);
	}

	/**
	 * **The link goes to the tab that actually lists the campaign.**
	 *
	 * The default queue holds campaigns awaiting a first approval. This campaign
	 * is running, so a reviewer following a link to that tab arrives at a screen
	 * the campaign is not on and concludes the email was wrong.
	 */
	public function test_the_link_opens_the_tab_the_campaign_is_listed_on(): void {
		$this->reviewer( 'first@example.test' );

		$campaign_id = $this->campaign( Post_Statuses::LIVE );

		$this->upload( $campaign_id );

		$body = (string) ( $this->mail[0]['message'] ?? '' );

		$this->assertStringContainsString(
			Review_Screen::campaign_url( $campaign_id, 'updates' ),
			$body,
			'The email must link to the ad-updates tab, which is where a waiting creative is shown.'
		);
		$this->assertStringNotContainsString(
			Review_Screen::campaign_url( $campaign_id, 'pending' ),
			$body,
			'The pending queue does not list a running campaign at all.'
		);
	}

	/**
	 * **A campaign that has never run announces nothing.**
	 *
	 * Artwork on a draft is published by approving the campaign, so there is no
	 * separate decision and no reviewer waiting on one. Announcing it would mail
	 * the whole team on every upload an advertiser makes while building.
	 */
	public function test_a_creative_on_a_campaign_that_has_never_run_tells_nobody(): void {
		$this->reviewer( 'first@example.test' );

		$campaign_id = $this->campaign( Post_Statuses::DRAFT );
		$creative_id = $this->upload( $campaign_id );

		$this->assertIsInt( $creative_id, 'The upload itself failed, so the silence proves nothing.' );
		$this->assertSame(
			array(),
			$this->approvals->awaiting( $campaign_id ),
			'A draft campaign has no separate creative decision to make.'
		);
		$this->assertSame( array(), $this->addressed(), 'Nobody should have been mailed about a draft.' );
	}

	/**
	 * **The uploader is not mailed about their own upload.**
	 *
	 * Only REVIEW_CAMPAIGNS can upload to a running campaign, so the actor is
	 * always inside the recipient set. A queue whose mail is noise stops being
	 * read.
	 */
	public function test_the_uploader_is_not_told_about_their_own_upload(): void {
		$this->only_reviewer_is_the_uploader();

		$campaign_id = $this->campaign( Post_Statuses::LIVE );
		$creative_id = $this->upload( $campaign_id );

		$this->assertIsInt( $creative_id, 'The upload itself failed, so the silence proves nothing.' );
		$this->assertSame(
			array( $creative_id ),
			$this->approvals->awaiting( $campaign_id ),
			'The creative did reach the queue; only the announcement is under test.'
		);
		$this->assertSame(
			array(),
			$this->addressed(),
			'The only reviewer is the person who uploaded it, so there was nobody to tell.'
		);
	}

	/**
	 * **A retry does not send a second copy.**
	 *
	 * The receipt is what stops every cron tick mailing the whole team again.
	 */
	public function test_a_retry_does_not_send_the_same_reviewer_a_second_copy(): void {
		$this->reviewer( 'first@example.test' );

		$campaign_id = $this->campaign( Post_Statuses::LIVE );
		$creative_id = $this->upload( $campaign_id );

		$expected = $this->expected_recipients( array( 'first@example.test' ) );

		$this->assertSame( $expected, $this->addressed() );

		Plugin::instance()->container()->get( Creative_Mailer::class )
			->retry( $campaign_id, (int) $creative_id, $this->uploader, 1 );

		$this->assertSame(
			$expected,
			$this->addressed(),
			'The retry re-sent a message the reviewers already have.'
		);
	}

	/**
	 * **A retry after the decision has been made says nothing.**
	 *
	 * A reviewer who has already turned the artwork down must not be told hours
	 * later to go and look at it.
	 */
	public function test_a_retry_after_the_creative_is_decided_sends_nothing(): void {
		$this->reviewer( 'first@example.test' );

		$campaign_id = $this->campaign( Post_Statuses::LIVE );
		$creative_id = (int) $this->upload( $campaign_id );

		/*
		 * A reviewer who joined after the announcement, and therefore holds no
		 * receipt to be suppressed by. Without one, deleting the still-waiting
		 * check changes nothing: every recipient of the retry was already sent
		 * to, so the receipt alone keeps the test green and the guard it is
		 * supposed to be covering is never reached.
		 */
		$this->reviewer( 'late@example.test' );

		$this->mail = array();

		$this->assertSame(
			$campaign_id,
			$this->approvals->reject( $creative_id, 'The logo is stretched.' ),
			'The fixture could not reach the decided state this test is about.'
		);
		$this->assertSame( array(), $this->approvals->awaiting( $campaign_id ) );

		Plugin::instance()->container()->get( Creative_Mailer::class )
			->retry( $campaign_id, $creative_id, $this->uploader, 1 );

		$this->assertSame(
			array(),
			$this->addressed(),
			'A creative that has already been decided must not produce a late email.'
		);

		/*
		 * And the receipt is not what produced that silence. The control is a
		 * retry that must deliver: a creative still waiting, and a reviewer with
		 * no receipt for it — who has to be created after the upload, because
		 * the upload announces itself and would leave them one.
		 */
		$second = (int) $this->upload( $campaign_id );

		$this->reviewer( 'later@example.test' );
		$this->mail = array();

		Plugin::instance()->container()->get( Creative_Mailer::class )
			->retry( $campaign_id, $second, $this->uploader, 1 );

		$this->assertContains(
			'later@example.test',
			$this->addressed(),
			'The retry reaches an untold reviewer, so the silence above was the decision and not the receipt.'
		);
	}

	/**
	 * **A mail failure does not fail the upload.**
	 *
	 * The creative is stored before anything is sent. Reporting an error now
	 * would tell the uploader their file did not save when it did, and they
	 * would upload it again.
	 */
	public function test_an_upload_survives_having_nobody_to_notify(): void {
		$this->reviewer( 'first@example.test' );

		$campaign_id = $this->campaign( Post_Statuses::LIVE );

		$this->mail_succeeds = false;

		$creative_id = $this->upload( $campaign_id );

		$this->assertNotSame( array(), $this->mail, 'The transport was never reached, so nothing was refused.' );
		$this->assertIsInt( $creative_id, 'A refused email must not lose the advertiser their artwork.' );
		$this->assertSame(
			array( $creative_id ),
			$this->approvals->awaiting( $campaign_id ),
			'The creative must still be queued for a decision when the announcement failed.'
		);
	}

	/**
	 * Recipient addresses, in order, of everything captured.
	 *
	 * @return array<int, string>
	 */
	private function addressed(): array {
		return array_map(
			static function ( array $mail ): string {
				$to = $mail['to'] ?? '';

				return is_string( $to ) ? $to : (string) ( is_array( $to ) ? reset( $to ) : '' );
			},
			$this->mail
		);
	}

	/**
	 * Admin address plus every reviewer, in the order they are created.
	 *
	 * WordPress's own administrator holds REVIEW_CAMPAIGNS, correctly, so it is
	 * a recipient of everything here. Spelled out rather than filtered away, as
	 * `RequestNotificationTest` spells it out: a test that quietly dropped the
	 * administrator would stop noticing if the fan-out did too.
	 *
	 * @param array<int, string> $reviewers Reviewer addresses.
	 * @return array<int, string>
	 */
	private function expected_recipients( array $reviewers ): array {
		return array_merge( array( (string) get_option( 'admin_email' ) ), $reviewers );
	}

	/**
	 * Leaves the uploader as the only user who can review anything.
	 *
	 * The default administrator is otherwise always a second reviewer, which
	 * makes the empty-recipient path unreachable — and that path is the one that
	 * must stay quiet rather than throw and schedule retries.
	 *
	 * @return void
	 */
	private function only_reviewer_is_the_uploader(): void {
		$administrator = get_user_by( 'email', (string) get_option( 'admin_email' ) );

		$this->assertNotFalse( $administrator, 'The suite no longer has the administrator this arrangement removes.' );

		$administrator->set_role( 'subscriber' );

		$this->assertFalse( user_can( $administrator->ID, Capabilities::REVIEW_CAMPAIGNS ) );
	}

	/**
	 * Creates one reviewer with a stable address.
	 *
	 * @param string $email Reviewer address.
	 * @return int
	 */
	private function reviewer( string $email ): int {
		return (int) self::factory()->user->create(
			array(
				'role'       => Roles::REVIEWER,
				'user_email' => $email,
			)
		);
	}

	/**
	 * One campaign in the given status, carrying the fixture placement.
	 *
	 * @param string $status Campaign post status.
	 * @return int
	 */
	private function campaign( string $status ): int {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => $status,
				'post_title'  => 'Autumn arts guide',
			)
		);

		update_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, $this->org_id );
		add_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID, $this->placement_id );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		return $campaign_id;
	}

	/**
	 * Uploads one correctly sized creative through the production workflow.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int|null Creative post id, or null when the upload was refused.
	 */
	private function upload( int $campaign_id ): ?int {
		$result = $this->manager->upload(
			$campaign_id,
			$this->placement_id,
			$this->image_file( 728, 90 ),
			'https://www.example.com/gallery',
			'Autumn arts guide'
		);

		if ( is_wp_error( $result ) ) {
			return null;
		}

		return (int) $result['id'];
	}

	/**
	 * A valid PNG of the given dimensions, as one $_FILES entry.
	 *
	 * @param int $width  Pixel width.
	 * @param int $height Pixel height.
	 * @return array<string, mixed>
	 */
	private function image_file( int $width, int $height ): array {
		$image = imagecreatetruecolor( $width, $height );
		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();
		$path  = wp_tempnam( 'aggr-creative-notification' );
		file_put_contents( $path, $bytes );
		$this->temporary[] = $path;

		return array(
			'name'     => 'creative.png',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $bytes ),
		);
	}
}
