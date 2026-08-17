<?php
/**
 * The admin notice that tells a reviewer there is work waiting.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Action_Notice;
use Aggressive\Ads\Admin\Review_Screen;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * The notice is the only thing that tells staff a queue has filled up without
 * them opening it, so these assert who sees it and what it links to.
 */
final class ActionNoticeTest extends WP_UnitTestCase {

	/**
	 * Subject.
	 *
	 * @var Action_Notice
	 */
	private Action_Notice $notice;

	/**
	 * Installs roles and resolves the service.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->notice = Plugin::instance()->container()->get( Action_Notice::class );

		Action_Notice::forget();
	}

	/**
	 * The cached counts must not leak into later tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		Action_Notice::forget();

		parent::tear_down();
	}

	/**
	 * Captures whatever the notice prints.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		$this->notice->render();

		return (string) ob_get_clean();
	}

	/**
	 * A campaign sitting in the submitted queue.
	 *
	 * @return int
	 */
	private function submit_a_campaign(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::SUBMITTED,
			)
		);
	}

	/**
	 * Somebody who can review, looking at an unrelated admin screen.
	 *
	 * @return void
	 */
	private function become_a_reviewer(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$this->assertTrue(
			current_user_can( Capabilities::REVIEW_CAMPAIGNS ),
			'Fixture is wrong: this user cannot review, so an empty notice would prove nothing.'
		);
	}

	/**
	 * Work waiting produces a notice linking to the queue that clears it.
	 *
	 * @return void
	 */
	public function test_a_reviewer_is_told_about_a_submitted_campaign(): void {
		$this->become_a_reviewer();
		$this->submit_a_campaign();

		$output = $this->render();

		$this->assertStringContainsString( 'notice', $output );
		$this->assertStringContainsString(
			esc_url( Review_Screen::queue_url( 'pending' ) ),
			$output,
			'The notice must link to the queue that clears the work it reports.'
		);
	}

	/**
	 * An empty queue says nothing at all.
	 *
	 * @return void
	 */
	public function test_nothing_is_printed_when_no_work_is_waiting(): void {
		$this->become_a_reviewer();

		$this->assertSame( '', $this->render() );
	}

	/**
	 * Somebody without the review capability is never told.
	 *
	 * Asserted with work genuinely waiting, so an empty string means the
	 * capability gate refused rather than that there was nothing to report.
	 *
	 * @return void
	 */
	public function test_a_user_without_the_review_capability_sees_nothing(): void {
		$this->submit_a_campaign();

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$this->assertFalse( current_user_can( Capabilities::REVIEW_CAMPAIGNS ) );
		$this->assertSame( '', $this->render() );
	}

	/**
	 * A logged-out request is never told either.
	 *
	 * @return void
	 */
	public function test_a_logged_out_visitor_sees_nothing(): void {
		$this->submit_a_campaign();
		wp_set_current_user( 0 );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * Clearing the work clears the notice.
	 *
	 * The counts are cached, so this also proves the cache is dropped rather
	 * than telling a reviewer to clear a queue they have already cleared.
	 *
	 * @return void
	 */
	public function test_the_notice_goes_away_once_the_work_is_done(): void {
		$this->become_a_reviewer();
		$campaign = $this->submit_a_campaign();

		$this->assertNotSame( '', $this->render(), 'Fixture is wrong: nothing was reported to begin with.' );

		wp_update_post(
			array(
				'ID'          => $campaign,
				'post_status' => Post_Statuses::APPROVED,
			)
		);

		// The hook the state machine fires on every transition.
		do_action( 'aggr_campaign_transitioned', $campaign, Post_Statuses::SUBMITTED, Post_Statuses::APPROVED, array() );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * A stale cache is what makes the count wrong, so prove it is really cached.
	 *
	 * Without this, `test_the_notice_goes_away_once_the_work_is_done` would
	 * pass with the cache-clearing hooks deleted, because nothing would have
	 * been cached in the first place.
	 *
	 * @return void
	 */
	public function test_counts_are_cached_until_something_clears_them(): void {
		$this->become_a_reviewer();
		$campaign = $this->submit_a_campaign();

		$this->assertNotSame( '', $this->render() );

		// Change the world without firing a hook. The notice should still be
		// reporting the cached count.
		wp_update_post(
			array(
				'ID'          => $campaign,
				'post_status' => Post_Statuses::APPROVED,
			)
		);

		$this->assertNotSame(
			'',
			$this->render(),
			'The counts are not cached, so the cache-clearing hooks prove nothing.'
		);
	}
}
