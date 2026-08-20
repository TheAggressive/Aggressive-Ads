<?php
/**
 * Approving a campaign whose start date has already passed.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Domain\Transition_Table;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Workflow\Campaign_Validator;
use Aggressive\Ads\Workflow\Transition_Guards;
use WP_UnitTestCase;

/**
 * A start date is in the future when the advertiser picks it, and review takes
 * time. Refusing approval afterwards punishes the reviewer for the queue, and
 * the advertiser cannot fix it — a campaign in review is no longer theirs to
 * edit, so the only way out was to reject a campaign that was never wrong.
 */
final class ApprovalAfterStartTest extends WP_UnitTestCase {

	/**
	 * Subject.
	 *
	 * @var Campaign_Validator
	 */
	private Campaign_Validator $validator;

	/**
	 * Resolves the services.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->validator = Plugin::instance()->container()->get( Campaign_Validator::class );
	}

	/**
	 * A campaign whose window opened yesterday.
	 *
	 * @return int
	 */
	private function started_yesterday(): int {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::REVIEW,
			)
		);

		update_post_meta( $campaign_id, Campaign_Repository::META_START_TS, time() - DAY_IN_SECONDS );
		update_post_meta( $campaign_id, Campaign_Repository::META_END_TS, time() + DAY_IN_SECONDS );

		return $campaign_id;
	}

	/**
	 * Submission still refuses a start date that has passed.
	 *
	 * The advertiser owns a draft and can move the date, so the rule stays
	 * where somebody can act on it.
	 *
	 * @return void
	 */
	public function test_submission_still_refuses_a_start_in_the_past(): void {
		$result = $this->validator->validate( $this->started_yesterday() );

		$this->assertTrue(
			$result->has( Campaign_Rules::ERROR_START_IN_PAST ),
			'A draft starting yesterday must still fail submission validation.'
		);
	}

	/**
	 * Approval does not.
	 *
	 * @return void
	 */
	public function test_approval_forgives_a_start_in_the_past(): void {
		$result = $this->validator->validate_for_approval( $this->started_yesterday() );

		$this->assertFalse(
			$result->has( Campaign_Rules::ERROR_START_IN_PAST ),
			'A reviewer must be able to approve a campaign whose start date has passed.'
		);
	}

	/**
	 * Only that one rule is forgiven.
	 *
	 * The approval path filters the submission result rather than running its
	 * own checks, so a rule added later is enforced at approval too. This is
	 * what stops the leniency widening by accident.
	 *
	 * @return void
	 */
	public function test_approval_still_refuses_every_other_defect(): void {
		$campaign_id = $this->started_yesterday();

		$submission = $this->validator->validate( $campaign_id );
		$approval   = $this->validator->validate_for_approval( $campaign_id );

		$remaining = array_values(
			array_diff( $submission->codes(), array( Campaign_Rules::ERROR_START_IN_PAST ) )
		);

		$this->assertNotSame( array(), $remaining, 'Fixture is wrong: nothing else was failing.' );
		$this->assertSame(
			$remaining,
			$approval->codes(),
			'Approval must forgive the past start and nothing else.'
		);
	}

	/**
	 * The guard is registered.
	 *
	 * Transition_Guards fails closed on a guard nobody implemented, so a
	 * declared-but-unwired guard would make approval impossible rather than
	 * permissive — the failure would look like a broken review queue, not like
	 * a missing registration.
	 *
	 * @return void
	 */
	public function test_the_approvable_guard_is_wired(): void {
		$guards = Plugin::instance()->container()->get( Transition_Guards::class );
		$result = $guards->check(
			array( Transition_Table::GUARD_APPROVABLE ),
			$this->started_yesterday(),
			array()
		);

		if ( is_wp_error( $result ) ) {
			$this->assertNotSame(
				'aggr_guard_unavailable',
				$result->get_error_code(),
				'The approvable guard is declared but not registered.'
			);
		} else {
			$this->assertTrue( $result );
		}
	}
}
