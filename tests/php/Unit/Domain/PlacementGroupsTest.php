<?php
/**
 * Group slugs that mean the same thing must be the same string.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Placement_Groups;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A group slug is compared, not just stored: the repository verifies a write by
 * reading it back and comparing, an admin filter matches on it, and slice 4
 * will total by it. Every one of those breaks quietly if the same label can
 * normalise two ways, so idempotence and determinism are the properties under
 * test rather than any particular slug's spelling.
 *
 * The deliberate non-use of `sanitize_title()` is what makes this testable with
 * no bootstrap at all, and is also what makes the result independent of which
 * filters a site has installed.
 */
final class PlacementGroupsTest extends TestCase {

	/**
	 * Labels and the slug each must reduce to.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function labels(): array {
		return array(
			'already a slug'        => array( 'sidebar', 'sidebar' ),
			'ascii uppercase'       => array( 'SIDEBAR', 'sidebar' ),
			'mixed case'            => array( 'Above The Fold', 'above-the-fold' ),
			'spaces collapse'       => array( 'above    fold', 'above-fold' ),
			'punctuation separates' => array( 'home/page', 'home-page' ),
			'run of punctuation'    => array( 'home -- page', 'home-page' ),
			'leading separator'     => array( '  sidebar', 'sidebar' ),
			'trailing separator'    => array( 'sidebar--', 'sidebar' ),
			'digits survive'        => array( 'tier 2', 'tier-2' ),
			'underscore separates'  => array( 'ad_group', 'ad-group' ),
			'emoji separates'       => array( 'top 🔥 slot', 'top-slot' ),
			'only punctuation'      => array( '!!!', '' ),
			'empty'                 => array( '', '' ),
			'whitespace only'       => array( "  \t ", '' ),
		);
	}

	#[DataProvider( 'labels' )]
	public function test_slug_reduces_a_label( string $label, string $expected ): void {
		$this->assertSame( $expected, Placement_Groups::slug( $label ) );
	}

	/**
	 * Non-ASCII letters survive rather than becoming separators.
	 *
	 * The regex is Unicode-aware on purpose. A byte-wise class would turn
	 * every accented letter into a hyphen and reduce a group named in another
	 * language to punctuation.
	 */
	public function test_slug_preserves_non_ascii_letters(): void {
		$this->assertSame( 'café-crème', Placement_Groups::slug( 'café crème' ) );
		$this->assertSame( 'обзор', Placement_Groups::slug( 'обзор' ) );
		$this->assertSame( '日本語', Placement_Groups::slug( '日本語' ) );
	}

	/**
	 * Non-ASCII case is left alone, and that is the documented behaviour.
	 *
	 * Case-folding the rest of Unicode needs mbstring, which is not a
	 * requirement, so folding it here would make the slug depend on the
	 * server. Pinned so a later "improvement" to `mb_strtolower` has to
	 * confront the portability question rather than quietly introduce it.
	 */
	public function test_slug_does_not_case_fold_non_ascii(): void {
		$this->assertSame( 'École', Placement_Groups::slug( 'École' ) );
	}

	public function test_normalise_sorts_and_deduplicates(): void {
		$this->assertSame(
			array( 'footer', 'header', 'sidebar' ),
			Placement_Groups::normalise( array( 'sidebar', 'Header', 'footer', 'SIDEBAR' ) )
		);
	}

	public function test_normalise_drops_labels_that_reduce_to_nothing(): void {
		$this->assertSame(
			array( 'kept' ),
			Placement_Groups::normalise( array( '!!!', 'kept', '   ', '---' ) )
		);
	}

	public function test_normalise_rejects_a_non_array(): void {
		$this->assertSame( array(), Placement_Groups::normalise( 'sidebar' ) );
		$this->assertSame( array(), Placement_Groups::normalise( null ) );
		$this->assertSame( array(), Placement_Groups::normalise( 42 ) );
	}

	/**
	 * A nested array or an object is skipped, not stringified.
	 *
	 * `(string) array()` is a notice and the string "Array", which would file
	 * a placement under a group named after a PHP diagnostic.
	 */
	public function test_normalise_skips_values_that_are_not_scalar_labels(): void {
		$this->assertSame(
			array( 'sidebar' ),
			Placement_Groups::normalise( array( 'sidebar', array( 'nested' ), null, true, 1.5 ) )
		);
	}

	/** An integer label is accepted, because a group named "2024" is legitimate. */
	public function test_normalise_accepts_an_integer_label(): void {
		$this->assertSame( array( '2024' ), Placement_Groups::normalise( array( 2024 ) ) );
	}

	public function test_normalise_bounds_the_number_of_groups(): void {
		$many = array();

		for ( $i = 0; $i < Placement_Groups::MAX_GROUPS + 25; $i++ ) {
			$many[] = sprintf( 'group-%03d', $i );
		}

		$result = Placement_Groups::normalise( $many );

		$this->assertCount( Placement_Groups::MAX_GROUPS, $result );
		$this->assertSame( 'group-000', $result[0] );
	}

	/**
	 * The length cap counts characters, not bytes.
	 *
	 * A byte-counted cut lands inside a multi-byte character and produces a
	 * slug that is not valid UTF-8 — which the database will either mangle or
	 * reject, and which no later read can match.
	 */
	public function test_slug_caps_length_in_characters(): void {
		$long = str_repeat( 'é', Placement_Groups::MAX_SLUG_LENGTH + 50 );
		$slug = Placement_Groups::slug( $long );

		$this->assertSame( Placement_Groups::MAX_SLUG_LENGTH, self::characters( $slug ) );

		// Twice the characters in bytes, which is the whole point: a
		// byte-counted cap would have stopped at half this many characters
		// and split the last one.
		$this->assertSame( Placement_Groups::MAX_SLUG_LENGTH * 2, strlen( $slug ) );
		$this->assertSame( 1, preg_match( '/^é+$/u', $slug ), 'slug must remain valid UTF-8' );
	}

	/**
	 * Counts characters without mbstring.
	 *
	 * The production code avoids mbstring for portability; a test that reached
	 * for it would pass on this machine and error on a server that lacks it.
	 */
	private static function characters( string $value ): int {
		return (int) preg_match_all( '/./u', $value );
	}

	/**
	 * A cut landing immediately after a separator does not leave one trailing.
	 *
	 * The label is one character short of the cap, so the separator itself is
	 * the final character kept and the trim after truncation is the only thing
	 * that removes it. An earlier version of this test used a label exactly at
	 * the cap, where the cut lands *before* the separator — it passed with the
	 * post-truncation trim deleted, which is to say it was testing nothing.
	 */
	public function test_slug_does_not_end_on_a_separator_after_truncation(): void {
		$body  = str_repeat( 'a', Placement_Groups::MAX_SLUG_LENGTH - 1 );
		$label = $body . ' tail';

		$this->assertSame( $body, Placement_Groups::slug( $label ) );
	}

	/**
	 * Normalising twice changes nothing.
	 *
	 * This is the property the repository's read-back verification depends on.
	 * If normalise were not idempotent, `set_groups()` would compare a
	 * once-normalised set against a twice-normalised one and report failure
	 * over a write that had actually succeeded.
	 */
	#[DataProvider( 'sets' )]
	public function test_normalise_is_idempotent( array $input ): void {
		$once  = Placement_Groups::normalise( $input );
		$twice = Placement_Groups::normalise( $once );

		$this->assertSame( $once, $twice );
	}

	/**
	 * Assorted inputs for the idempotence sweep.
	 *
	 * @return array<string, array{array<int, mixed>}>
	 */
	public static function sets(): array {
		return array(
			'empty'        => array( array() ),
			'simple'       => array( array( 'sidebar' ) ),
			'messy'        => array( array( ' Above The Fold ', 'above---the---fold' ) ),
			'unicode'      => array( array( 'café crème', 'ÉCOLE' ) ),
			'droppable'    => array( array( '!!!', 'kept' ) ),
			'over the cap' => array( array_map( static fn ( int $i ): string => "g$i", range( 0, 40 ) ) ),
			'over length'  => array( array( str_repeat( 'x', 400 ) ) ),
			'mixed types'  => array( array( 'sidebar', 7, array( 'x' ), null ) ),
		);
	}
}
