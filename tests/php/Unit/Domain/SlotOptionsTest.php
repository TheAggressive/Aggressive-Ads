<?php
/**
 * Reading a slot's settings out of whatever an author wrote.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Refresh_Policy;
use Aggressive\Ads\Domain\Slot_Options;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every input here is reachable by typing.
 *
 * A block comment is `post_content` and a shortcode is prose, so all three
 * settings arrive from a text field somebody can put anything in. The cases
 * that matter are the ones where a wrong reading is invisible: a slot that
 * quietly stops collapsing holds an empty box open on every page it is on, and
 * nothing errors, nothing logs, and the publisher finds out from a reader.
 */
final class SlotOptionsTest extends TestCase {

	/** A slot that asked for nothing gets the shipped behaviour. */
	public function test_the_defaults_are_one_ad_per_load_and_gone_if_unsold(): void {
		$options = Slot_Options::defaults();

		$this->assertFalse( $options->rotate );
		$this->assertSame( Slot_Options::DEFAULT_ROTATE_SECONDS, $options->rotate_seconds );
		$this->assertTrue( $options->collapse_when_empty );
	}

	/**
	 * Content written before an attribute existed keeps behaving as it did.
	 *
	 * This is the case that makes the whole change safe to ship: every slot
	 * already in somebody's `post_content` was saved without
	 * `collapseWhenEmpty`, and each one has to keep collapsing.
	 */
	public function test_attributes_from_before_a_setting_existed_get_its_default(): void {
		$options = Slot_Options::from_block_attributes( array( 'slot' => 'leaderboard' ) );

		$this->assertEquals( Slot_Options::defaults(), $options );
	}

	/** An empty attribute array is the same question with none of the answers. */
	public function test_an_empty_attribute_array_is_the_defaults(): void {
		$this->assertEquals( Slot_Options::defaults(), Slot_Options::from_block_attributes( array() ) );
		$this->assertEquals( Slot_Options::defaults(), Slot_Options::from_atts( array() ) );
	}

	/** The block editor's booleans and numbers are read as written. */
	public function test_block_attributes_are_read_as_written(): void {
		$options = Slot_Options::from_block_attributes(
			array(
				'rotate'            => true,
				'rotateSeconds'     => 45,
				'collapseWhenEmpty' => false,
			)
		);

		$this->assertTrue( $options->rotate );
		$this->assertSame( 45, $options->rotate_seconds );
		$this->assertFalse( $options->collapse_when_empty );
	}

	/**
	 * The shortcode's strings mean what their author meant.
	 *
	 * `collapse_when_empty="false"` is a non-empty string, so plain PHP
	 * truthiness reads it as *true* — the exact opposite of what was typed, and
	 * the reason this class reads booleans rather than casting them.
	 */
	public function test_shortcode_strings_mean_what_was_typed(): void {
		$options = Slot_Options::from_atts(
			array(
				'rotate'              => '1',
				'rotate_seconds'      => '20',
				'collapse_when_empty' => 'false',
			)
		);

		$this->assertTrue( $options->rotate );
		$this->assertSame( 20, $options->rotate_seconds );
		$this->assertFalse( $options->collapse_when_empty );
	}

	/**
	 * Only an explicit false keeps a slot's space.
	 *
	 * Collapsing is the shipped behaviour and the recoverable one: a slot that
	 * vanishes costs a publisher a gap they did not ask for, while one that
	 * stays costs every reader an empty box on every page. So anything that is
	 * not a decision has to collapse.
	 *
	 * @param mixed $written What the attribute carried.
	 */
	#[DataProvider( 'non_decisions' )]
	public function test_anything_short_of_an_explicit_false_still_collapses( mixed $written ): void {
		$this->assertTrue(
			Slot_Options::from_block_attributes( array( 'collapseWhenEmpty' => $written ) )->collapse_when_empty,
			'A slot stopped collapsing without being asked to.'
		);
	}

	/**
	 * Values that are not an answer to the question.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function non_decisions(): array {
		return array(
			'absent as null'    => array( null ),
			'an empty string'   => array( '' ),
			'an explicit true'  => array( true ),
			'the string true'   => array( 'true' ),
			'the string yes'    => array( 'yes' ),
			'the string one'    => array( '1' ),
			'the number one'    => array( 1 ),
			'an array'          => array( array( 'nonsense' ) ),
			'a whitespace word' => array( ' false' ),
		);
	}

	/**
	 * Every spelling of no that reaches a slot.
	 *
	 * `"FALSE"` is in here because a shortcode is typed by hand and nothing
	 * lower-cases it on the way in.
	 *
	 * @param mixed $written What the attribute carried.
	 */
	#[DataProvider( 'refusals' )]
	public function test_every_spelling_of_no_keeps_the_space( mixed $written ): void {
		$this->assertFalse(
			Slot_Options::from_atts( array( 'collapse_when_empty' => $written ) )->collapse_when_empty
		);
	}

	/**
	 * Values that do say no.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function refusals(): array {
		return array(
			'a real false'        => array( false ),
			'the string false'    => array( 'false' ),
			'the shouted string'  => array( 'FALSE' ),
			'a mixed-case string' => array( 'False' ),
			'the string zero'     => array( '0' ),
			'the number zero'     => array( 0 ),
		);
	}

	/**
	 * An unwritten shortcode attribute is not a request to rotate.
	 *
	 * `shortcode_atts()` fills every attribute nobody typed with `''`, and an
	 * empty string is a string — so a boolean rule written as "every string
	 * except these two is true" turns every setting on for every shortcode
	 * that configured none of them. That is what this asserts against; it
	 * failed the first time it ran.
	 */
	public function test_an_unwritten_attribute_is_not_a_choice(): void {
		$options = Slot_Options::from_atts( array( 'rotate' => '' ) );

		$this->assertFalse( $options->rotate );
	}

	/**
	 * An interval below the floor is raised rather than refused.
	 *
	 * A hand-edited `rotateSeconds` of 0 or -1 is an interval of no length,
	 * which in the browser is a request loop. There is deliberately no ceiling:
	 * a longer interval records fewer impressions, so refusing one would refuse
	 * the safer setting.
	 *
	 * @param mixed $written  What the attribute carried.
	 * @param int   $expected The interval that should come out.
	 */
	#[DataProvider( 'intervals' )]
	public function test_an_interval_is_floored_but_never_capped( mixed $written, int $expected ): void {
		$this->assertSame(
			$expected,
			Slot_Options::from_block_attributes( array( 'rotateSeconds' => $written ) )->rotate_seconds
		);
	}

	/**
	 * Requested intervals and what they become.
	 *
	 * @return array<string, array{mixed, int}>
	 */
	public static function intervals(): array {
		return array(
			'zero'              => array( 0, Slot_Options::MIN_ROTATE_SECONDS ),
			'negative'          => array( -30, Slot_Options::MIN_ROTATE_SECONDS ),
			'the floor itself'  => array( 1, 1 ),
			'a normal interval' => array( 45, 45 ),
			'a numeric string'  => array( '20', 20 ),
			'a fractional ask'  => array( 2.9, 2 ),
			'an hour'           => array( 3600, 3600 ),
			'not a number'      => array( 'soon', Slot_Options::DEFAULT_ROTATE_SECONDS ),
			'an empty string'   => array( '', Slot_Options::DEFAULT_ROTATE_SECONDS ),
			'absent as null'    => array( null, Slot_Options::DEFAULT_ROTATE_SECONDS ),
			'an array'          => array( array( 5 ), Slot_Options::DEFAULT_ROTATE_SECONDS ),
		);
	}

	/**
	 * The context always carries every key, including the defaulted ones.
	 *
	 * The store treats an absent key as the shipped behaviour, which makes an
	 * omission indistinguishable from a choice — so a slot whose context lost a
	 * key would look exactly like a slot that asked for the default.
	 */
	public function test_the_context_states_every_setting_rather_than_omitting_defaults(): void {
		/*
		 * Resolved against a placement that permits what the block asks, so this
		 * stays about the context's shape rather than about the refresh policy.
		 * What the policy does to these values is `InventoryGrainTest`'s.
		 */
		$context = Slot_Options::defaults()->resolved_context(
			Refresh_Policy::from_stored( true, Slot_Options::MIN_ROTATE_SECONDS, 6 )
		);

		$this->assertSame(
			array(
				'rotate'            => false,
				'rotateSeconds'     => Slot_Options::DEFAULT_ROTATE_SECONDS,
				'maxRefreshes'      => 6,
				'collapseWhenEmpty' => true,
			),
			$context
		);
	}

	/** The context is the settings, not a re-derivation of them. */
	public function test_the_context_carries_the_settings_that_were_read(): void {
		$context = Slot_Options::from_block_attributes(
			array(
				'rotate'            => true,
				'rotateSeconds'     => 0,
				'collapseWhenEmpty' => false,
			)
		)->resolved_context( Refresh_Policy::from_stored( true, Slot_Options::MIN_ROTATE_SECONDS, 6 ) );

		$this->assertSame(
			array(
				'rotate'            => true,
				'rotateSeconds'     => Slot_Options::MIN_ROTATE_SECONDS,
				'maxRefreshes'      => 6,
				'collapseWhenEmpty' => false,
			),
			$context
		);
	}

	/**
	 * The shortcode's attribute names are the ones the readers read.
	 *
	 * `shortcode_atts()` fills in every key it is given and drops every key it
	 * is not, so a name declared here and read as something else silently makes
	 * the setting unreachable — the shortcode accepts it, and nothing happens.
	 */
	public function test_the_declared_shortcode_attributes_are_the_ones_read(): void {
		$declared = Slot_Options::shortcode_defaults();

		$this->assertSame(
			array( 'slot', 'rotate', 'rotate_seconds', 'collapse_when_empty' ),
			array_keys( $declared )
		);

		// Filled through shortcode_atts() with nothing supplied, the declared
		// defaults have to read back as the shipped behaviour rather than as
		// three deliberate choices.
		$this->assertEquals( Slot_Options::defaults(), Slot_Options::from_atts( $declared ) );
	}
}
