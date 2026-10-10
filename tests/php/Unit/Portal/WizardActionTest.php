<?php
/**
 * The wizard's one action.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Portal;

use Aggressive\Ads\Portal\Wizard_Action;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers Wizard_Action::resolve().
 *
 * The order summary and the phone's pinned bar both draw this decision, so a
 * wrong answer here is wrong on every screen at once. The dangerous half is
 * "open when it should be locked" — that is a Submit button that a card above
 * it said was not ready — so the locked states are asserted as carefully as
 * the open ones, and each reason is asserted to name the right number.
 */
final class WizardActionTest extends TestCase {

	/**
	 * The first step is never locked and always submits the plan form.
	 *
	 * Whatever else is true of the campaign, "ready" flags belong to later
	 * steps. A details step that read them would lock the first step of a
	 * campaign that has, by definition, no ads yet.
	 *
	 * @return void
	 */
	public function test_details_is_never_locked(): void {
		foreach ( array( true, false ) as $creative_ready ) {
			foreach ( array( true, false ) as $review_ready ) {
				$action = Wizard_Action::resolve( 'details', $creative_ready, $review_ready, array( '300×250' ), array( 'no_creatives' ) );

				self::assertFalse( $action->locked );
				self::assertSame( 'aggr-plan-form', $action->form );
				self::assertSame( Wizard_Action::LABEL_PLAN, $action->label );
				self::assertSame( Wizard_Action::REASON_EDITABLE, $action->reason );
			}
		}
	}

	/**
	 * A ready ads step submits the completion form, with nothing to explain.
	 *
	 * @return void
	 */
	public function test_creative_open_when_every_size_has_a_file(): void {
		$action = Wizard_Action::resolve( 'creative', true, false, array(), array() );

		self::assertFalse( $action->locked );
		self::assertSame( 'aggr-creative-complete', $action->form );
		self::assertSame( Wizard_Action::LABEL_REVIEW, $action->label );
		self::assertSame( Wizard_Action::REASON_NONE, $action->reason );
	}

	/**
	 * A locked ads step submits nothing and counts the sizes it is waiting on.
	 *
	 * The count is the missing sizes, not the number of readiness problems: the
	 * latter also includes a missing link, which this step does not list.
	 *
	 * @return void
	 */
	public function test_creative_locked_counts_missing_sizes(): void {
		$action = Wizard_Action::resolve( 'creative', false, false, array( '300×250', '728×90', '160×600' ), array( 'no_creatives', 'click_url_missing' ) );

		self::assertTrue( $action->locked );
		self::assertSame( '', $action->form, 'A locked button must not carry a form to submit.' );
		self::assertSame( Wizard_Action::REASON_SIZES_NEED_FILE, $action->reason );
		self::assertSame( 3, $action->count );
	}

	/**
	 * A ready review step submits the campaign, and says the checks re-run.
	 *
	 * @return void
	 */
	public function test_review_open_submits_the_campaign(): void {
		$action = Wizard_Action::resolve( 'review', true, true, array(), array() );

		self::assertFalse( $action->locked );
		self::assertSame( 'aggr-submit-form', $action->form );
		self::assertSame( Wizard_Action::LABEL_SUBMIT, $action->label );
		self::assertSame( Wizard_Action::REASON_RECHECKED, $action->reason );
	}

	/**
	 * Any step that is not details or creative is the review step.
	 *
	 * A resume point or query value the wizard does not know must land on the
	 * strictest screen, not fall through to an open one.
	 *
	 * @return void
	 */
	public function test_an_unknown_step_is_the_review_step(): void {
		$locked = Wizard_Action::resolve( 'submit', true, false, array(), array( 'package_missing' ) );
		$open   = Wizard_Action::resolve( '', true, true, array(), array() );

		self::assertTrue( $locked->locked );
		self::assertSame( Wizard_Action::LABEL_SUBMIT, $locked->label );
		self::assertFalse( $open->locked );
		self::assertSame( 'aggr-submit-form', $open->form );
	}

	/**
	 * Exactly one missing file, and nothing else wrong, names the size.
	 *
	 * @return void
	 */
	public function test_one_missing_file_names_its_size(): void {
		$action = Wizard_Action::resolve( 'review', false, false, array( '720×300' ), array( 'no_creatives' ) );

		self::assertTrue( $action->locked );
		self::assertSame( '', $action->form );
		self::assertSame( Wizard_Action::REASON_ADD_ONE_FILE, $action->reason );
		self::assertSame( '720×300', $action->size );
		self::assertSame( 1, $action->count );
	}

	/**
	 * Naming the file is only honest when the file is the only problem.
	 *
	 * "Add the 720×300 file to submit" over a campaign that also has no link
	 * sends someone to upload a file and fail again, so any other problem code
	 * turns the sentence into a count — even with exactly one file missing.
	 *
	 * @return array<string, array{0: array<int, string>, 1: array<int, string>, 2: int}>
	 */
	public static function not_just_a_file_provider(): array {
		return array(
			'a missing link as well'          => array( array( '720×300' ), array( 'placement_uncovered', 'click_url_missing' ), 2 ),
			'only a non-file problem'         => array( array( '720×300' ), array( 'package_missing' ), 1 ),
			'two files missing'               => array( array( '720×300', '300×250' ), array( 'no_creatives' ), 1 ),
			'no file missing but not ready'   => array( array(), array( 'title_missing', 'start_missing', 'end_missing' ), 3 ),
			'one file and two other problems' => array( array( '300×250' ), array( 'placement_uncovered', 'title_missing', 'start_missing' ), 3 ),
		);
	}

	/**
	 * Anything beyond a lone missing file counts the problems instead.
	 *
	 * @param array<int, string> $missing  Missing sizes.
	 * @param array<int, string> $codes    Problem codes.
	 * @param int                $expected The count the sentence should carry.
	 * @return void
	 */
	#[DataProvider( 'not_just_a_file_provider' )]
	public function test_other_problems_are_counted_not_named( array $missing, array $codes, int $expected ): void {
		$action = Wizard_Action::resolve( 'review', false, false, $missing, $codes );

		self::assertTrue( $action->locked );
		self::assertSame( Wizard_Action::REASON_THINGS_LEFT, $action->reason );
		self::assertSame( $expected, $action->count );
		self::assertSame( '', $action->size, 'A count must not carry a size.' );
	}

	/**
	 * Open and locked are never confused, whatever the arguments.
	 *
	 * Asserted over every step and flag combination, counting the cases: a
	 * guard that quietly stops matching reports success over nothing, so the
	 * count is part of the assertion. The invariant is that a locked action has
	 * no form and an open one always has one.
	 *
	 * @return void
	 */
	public function test_locked_means_no_form_and_open_means_a_form(): void {
		$checked = 0;

		foreach ( array( 'details', 'creative', 'review', 'other' ) as $step ) {
			foreach ( array( true, false ) as $creative_ready ) {
				foreach ( array( true, false ) as $review_ready ) {
					foreach ( array( array(), array( '300×250' ), array( '300×250', '728×90' ) ) as $missing ) {
						foreach ( array( array(), array( 'no_creatives' ), array( 'no_creatives', 'title_missing' ) ) as $codes ) {
							$action = Wizard_Action::resolve( $step, $creative_ready, $review_ready, $missing, $codes );

							self::assertSame( $action->locked, '' === $action->form, "Step {$step}: form and lock disagree." );
							++$checked;
						}
					}
				}
			}
		}

		self::assertSame( 4 * 2 * 2 * 3 * 3, $checked );
	}
}
