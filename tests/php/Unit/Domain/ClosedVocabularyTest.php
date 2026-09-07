<?php
/**
 * Every closed vocabulary lists every code it declares.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A guard against one specific defect, which has now happened twice.
 *
 * `No_Fill_Reason::SIZE_UNAVAILABLE` was mapped and missing from
 * `No_Fill_Reason::all()`, so `Decision_Metrics::buffer()` silently dropped
 * every counter carrying it. `Exclusion_Reason::ELIGIBILITY_SIZE_MISMATCH` was
 * declared, emitted by `Eligibility_Stage`, mapped by `No_Fill_Reason`, and
 * missing from `Exclusion_Reason::all()` — latent only because nothing read
 * that list yet.
 *
 * Both are the same mistake: a constant added to a class and not to the
 * hand-kept list beside it. Nothing could catch it, because the list *is* the
 * definition of the vocabulary — so this asks the class itself what it
 * declares and compares.
 *
 * **The scope selects itself, rather than naming classes.** Any `inc/Domain/`
 * class with a static `all()` returning strings is a code vocabulary and is
 * checked; `Transition_Table::all()` returns `Campaign_Transition` objects and
 * is skipped by that rule rather than by an exception list. A vocabulary added
 * tomorrow is covered without anybody remembering to register it, which is the
 * property an opt-in list cannot have — and an exception list is how a guard
 * turns into a blind spot.
 */
final class ClosedVocabularyTest extends TestCase {

	/**
	 * Domain classes whose `all()` returns a list of string codes.
	 *
	 * @return array<string, array<int, string>> Class name to the codes it lists.
	 */
	private function vocabularies(): array {
		$found = array();

		foreach ( (array) glob( __DIR__ . '/../../../../inc/Domain/*.php' ) as $file ) {
			$source = (string) file_get_contents( (string) $file );

			if ( ! preg_match( '/final class ([A-Za-z_]+)/', $source, $name ) ) {
				continue;
			}

			$class = 'Aggressive\\Ads\\Domain\\' . $name[1];

			if ( ! class_exists( $class ) || ! method_exists( $class, 'all' ) ) {
				continue;
			}

			$listed = $class::all();

			if ( ! is_array( $listed ) || array() === $listed ) {
				continue;
			}

			foreach ( $listed as $entry ) {
				if ( ! is_string( $entry ) ) {
					// An `all()` of objects is a table, not a vocabulary.
					continue 2;
				}
			}

			$found[ $class ] = $listed;
		}

		return $found;
	}

	public function test_the_scan_finds_the_vocabularies_it_is_meant_to_guard(): void {
		$found = $this->vocabularies();

		/*
		 * A scan that quietly matched nothing would make every assertion below
		 * vacuous, and would look exactly like a codebase with no defects.
		 */
		$this->assertGreaterThanOrEqual(
			5,
			count( $found ),
			'The vocabulary scan found almost nothing, so it is protecting nothing.'
		);

		foreach ( array( 'Decision_Outcome', 'Exclusion_Reason', 'No_Fill_Reason', 'Opportunity', 'Reservation_Rules' ) as $expected ) {
			$this->assertArrayHasKey(
				'Aggressive\\Ads\\Domain\\' . $expected,
				$found,
				"{$expected} is a closed vocabulary and the scan did not reach it."
			);
		}
	}

	public function test_a_table_of_objects_is_not_treated_as_a_vocabulary(): void {
		$this->assertArrayNotHasKey(
			'Aggressive\\Ads\\Domain\\Transition_Table',
			$this->vocabularies(),
			'Its all() returns transitions, and comparing those against ACTOR_ and GUARD_ constants would fail on correct code.'
		);
	}

	public function test_every_declared_code_is_listed(): void {
		$checked = 0;

		foreach ( $this->vocabularies() as $class => $listed ) {
			foreach ( ( new ReflectionClass( $class ) )->getConstants() as $name => $value ) {
				if ( ! is_string( $value ) ) {
					// A length or a limit is not a member of the vocabulary.
					continue;
				}

				/*
				 * **A deliberate non-member says so in its name.**
				 * `Measurement_Event_Type::LEGACY_IMPRESSION` is an inbound
				 * alias that `normalize()` folds into `served`; listing it in
				 * `all()` would let `impression` be stored as though it were a
				 * different event. So the rule is not "every constant is a
				 * member" — it is "every constant is a member unless it is
				 * named as legacy", which puts the exception at the
				 * declaration where a reader meets it, rather than in a list
				 * inside this test where nobody would.
				 */
				if ( str_starts_with( $name, 'LEGACY_' ) ) {
					continue;
				}

				++$checked;

				$this->assertContains(
					$value,
					$listed,
					"{$class}::{$name} is declared, can be produced, and is absent from all(). That is how SIZE_UNAVAILABLE silently dropped every counter carrying it. If it is a deliberate non-member, name it LEGACY_*."
				);
			}
		}

		$this->assertGreaterThan( 40, $checked, 'Too few constants examined for this to be the guard it claims to be.' );
	}

	/**
	 * **A legacy name cannot be used to hide a genuine omission.**
	 *
	 * The rule above lets a constant sit outside `all()` when it is named
	 * `LEGACY_*`, which would be a loophole if nothing checked what the class
	 * then does with it. Every such code has to normalise into a canonical
	 * member, so the prefix means "this folds into a real one" rather than
	 * "ignore me".
	 *
	 * @return void
	 */
	public function test_a_legacy_code_folds_into_a_canonical_one(): void {
		$checked = 0;

		foreach ( $this->vocabularies() as $class => $listed ) {
			if ( ! method_exists( $class, 'normalize' ) ) {
				continue;
			}

			foreach ( ( new ReflectionClass( $class ) )->getConstants() as $name => $value ) {
				if ( ! is_string( $value ) || ! str_starts_with( $name, 'LEGACY_' ) ) {
					continue;
				}

				++$checked;

				$this->assertContains(
					$class::normalize( $value ),
					$listed,
					"{$class}::{$name} is excused from all() as legacy and normalises to nothing in it, so the name is hiding an omission."
				);
			}
		}

		$this->assertGreaterThan( 0, $checked, 'No legacy codes were examined, so the loophole above is unguarded.' );
	}

	public function test_nothing_is_listed_that_is_not_declared(): void {
		foreach ( $this->vocabularies() as $class => $listed ) {
			$declared = array_filter(
				( new ReflectionClass( $class ) )->getConstants(),
				static fn ( $value ): bool => is_string( $value )
			);

			foreach ( $listed as $code ) {
				/*
				 * `Decision_Outcome::all()` deliberately merges
				 * `No_Fill_Reason::all()`, so a code it lists may be declared
				 * elsewhere. Checking the whole domain rather than one class
				 * keeps that legitimate and still refuses a literal nothing
				 * declares.
				 */
				$this->assertTrue(
					in_array( $code, $declared, true ) || $this->declared_anywhere( $code ),
					"{$class}::all() lists '{$code}', which no domain constant declares."
				);
			}
		}
	}

	/**
	 * Whether any domain vocabulary declares this code.
	 *
	 * @param string $code Candidate code.
	 */
	private function declared_anywhere( string $code ): bool {
		foreach ( array_keys( $this->vocabularies() ) as $class ) {
			foreach ( ( new ReflectionClass( $class ) )->getConstants() as $value ) {
				if ( is_string( $value ) && $value === $code ) {
					return true;
				}
			}
		}

		return false;
	}
}
