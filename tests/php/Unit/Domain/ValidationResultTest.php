<?php
/**
 * The collector that lets a validator report every problem at once.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Validation_Result;
use PHPUnit\Framework\TestCase;

/**
 * Collecting rather than failing fast is the whole point of this class: an
 * advertiser who fixes one problem, resubmits, and is told about the next one
 * has been made to do the work three times. These assert the collecting.
 */
final class ValidationResultTest extends TestCase {

	/**
	 * Nothing recorded is the valid state.
	 *
	 * @return void
	 */
	public function test_a_fresh_result_is_valid(): void {
		$result = new Validation_Result();

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( array(), $result->problems() );
		$this->assertSame( array(), $result->codes() );
	}

	/**
	 * One problem is enough to invalidate.
	 *
	 * @return void
	 */
	public function test_one_problem_invalidates(): void {
		$result = new Validation_Result();
		$result->add( 'missing_title', 'title' );

		$this->assertFalse( $result->is_valid() );
		$this->assertTrue( $result->has( 'missing_title' ) );
		$this->assertFalse( $result->has( 'something_else' ) );
	}

	/**
	 * Field and context are carried through for the form and the message.
	 *
	 * @return void
	 */
	public function test_a_problem_keeps_its_field_and_context(): void {
		$result = new Validation_Result();
		$result->add(
			'wrong_size',
			'creative',
			array(
				'expected' => '300x250',
				'actual'   => '728x90',
			)
		);

		$this->assertSame(
			array(
				array(
					'code'    => 'wrong_size',
					'field'   => 'creative',
					'context' => array(
						'expected' => '300x250',
						'actual'   => '728x90',
					),
				),
			),
			$result->problems()
		);
	}

	/**
	 * Omitted field and context default to empty rather than being absent.
	 *
	 * A caller reading `$problem['field']` must not have to check it exists.
	 *
	 * @return void
	 */
	public function test_field_and_context_default_to_empty(): void {
		$result = new Validation_Result();
		$result->add( 'broken' );

		$this->assertSame(
			array(
				array(
					'code'    => 'broken',
					'field'   => '',
					'context' => array(),
				),
			),
			$result->problems()
		);
	}

	/**
	 * Order is preserved, because it is the order a form should read in.
	 *
	 * @return void
	 */
	public function test_problems_keep_the_order_they_were_found(): void {
		$result = new Validation_Result();
		$result->add( 'first' );
		$result->add( 'second' );
		$result->add( 'third' );

		$this->assertSame( array( 'first', 'second', 'third' ), $result->codes() );
	}

	/**
	 * The same code twice is kept twice.
	 *
	 * Two creatives can fail the same rule, and collapsing them would report one
	 * problem for two things the advertiser has to fix.
	 *
	 * @return void
	 */
	public function test_a_repeated_code_is_recorded_each_time(): void {
		$result = new Validation_Result();
		$result->add( 'wrong_size', 'creative_1' );
		$result->add( 'wrong_size', 'creative_2' );

		$this->assertCount( 2, $result->problems() );
		$this->assertSame( array( 'wrong_size', 'wrong_size' ), $result->codes() );
	}

	/**
	 * Absorbing appends the other result's problems, in order, after this one's.
	 *
	 * @return void
	 */
	public function test_absorbing_appends_the_other_results_problems(): void {
		$result = new Validation_Result();
		$result->add( 'mine' );

		$other = new Validation_Result();
		$other->add( 'theirs_first' );
		$other->add( 'theirs_second' );

		$result->absorb( $other );

		$this->assertSame( array( 'mine', 'theirs_first', 'theirs_second' ), $result->codes() );
	}

	/**
	 * Absorbing a valid result changes nothing.
	 *
	 * @return void
	 */
	public function test_absorbing_a_valid_result_is_a_no_op(): void {
		$result = new Validation_Result();
		$result->add( 'mine' );
		$result->absorb( new Validation_Result() );

		$this->assertSame( array( 'mine' ), $result->codes() );
	}

	/**
	 * A valid result absorbing problems becomes invalid.
	 *
	 * @return void
	 */
	public function test_absorbing_problems_invalidates_a_valid_result(): void {
		$result = new Validation_Result();
		$this->assertTrue( $result->is_valid() );

		$other = new Validation_Result();
		$other->add( 'theirs' );
		$result->absorb( $other );

		$this->assertFalse( $result->is_valid() );
	}

	/**
	 * Absorbing copies, so the absorbed result is not aliased.
	 *
	 * Adding to `$other` afterwards must not appear in `$result` — a validator
	 * that reuses a sub-result across items would otherwise leak one item's
	 * problems into another's report.
	 *
	 * @return void
	 */
	public function test_absorbing_does_not_alias_the_other_result(): void {
		$result = new Validation_Result();
		$other  = new Validation_Result();
		$other->add( 'first' );

		$result->absorb( $other );
		$other->add( 'added_afterwards' );

		$this->assertSame( array( 'first' ), $result->codes() );
	}
}
