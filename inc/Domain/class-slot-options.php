<?php
/**
 * The per-slot settings an author chooses.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Everything about *this* slot that the author decided, parsed once.
 *
 * Split out of `Placement_Slot` when a third setting arrived and `markup()`
 * would have taken a fifth positional argument — three of them scalars in a
 * row, where `markup( $slug, true, false, 10, false )` says nothing at a call
 * site and swaps silently if two are transposed.
 *
 * It is also the one place the settings are read from untrusted input. A block
 * comment is `post_content`: an author with the unfiltered HTML capability, a
 * paste from another site, or a hand-edited template can put any JSON in it,
 * and every accessor here answers with the shipped default rather than with
 * whatever was written. The class is the boundary, so a new setting cannot be
 * added without passing through it.
 *
 * **In the domain layer, so those defaults are exhaustively testable.** Reading
 * a hostile attribute has more cases than it has lines, and every one of them
 * is a value in and a value out — the sort of thing worth running hundreds of
 * in milliseconds rather than a handful of through a WordPress bootstrap.
 * `Placement_Slot` hands it raw values; nothing here touches WordPress.
 */
final class Slot_Options {

	/**
	 * The rotation interval a slot uses when it asks for none.
	 */
	public const DEFAULT_ROTATE_SECONDS = 10;

	/**
	 * The shortest rotation this plugin will emit.
	 *
	 * One second, matching the client. There is deliberately no ceiling: a
	 * longer interval records fewer impressions, so refusing one would refuse
	 * the safer setting. This floor only stops a zero or a negative — from a
	 * hand-edited block comment — becoming an interval of no length.
	 */
	public const MIN_ROTATE_SECONDS = 1;

	/**
	 * Constructor.
	 *
	 * Private so a slot's settings can only be built through a named reader,
	 * which is what keeps the clamping from being skippable.
	 *
	 * @param bool $rotate              Whether the slot replaces its ad on an interval.
	 * @param int  $rotate_seconds      Interval, already floored.
	 * @param bool $collapse_when_empty Whether an unfilled slot leaves the page.
	 */
	private function __construct(
		public readonly bool $rotate,
		public readonly int $rotate_seconds,
		public readonly bool $collapse_when_empty
	) {
	}

	/**
	 * A slot that asked for nothing: one ad per page load, gone if unsold.
	 */
	public static function defaults(): self {
		return new self( false, self::DEFAULT_ROTATE_SECONDS, true );
	}

	/**
	 * Reads the settings out of block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function from_block_attributes( array $attributes ): self {
		return new self(
			self::truthy( $attributes['rotate'] ?? null ),
			self::interval( $attributes['rotateSeconds'] ?? null ),
			self::collapses( $attributes['collapseWhenEmpty'] ?? null )
		);
	}

	/**
	 * Reads the settings out of attributes as an author writes them.
	 *
	 * Both loose surfaces come through here: a shortcode, whose attributes are
	 * always strings, and the array a theme template hands `aggr_placement()`,
	 * which may hold real booleans. So the same setting arrives as `true`,
	 * `"1"`, `"true"` or `"no"` depending on where it was written, and every
	 * one of them has to mean what its author meant.
	 *
	 * @param array<string, mixed> $atts Author-supplied attributes.
	 */
	public static function from_atts( array $atts ): self {
		return new self(
			self::truthy( $atts['rotate'] ?? null ),
			self::interval( $atts['rotate_seconds'] ?? null ),
			self::collapses( $atts['collapse_when_empty'] ?? null )
		);
	}

	/**
	 * The shortcode attributes this understands, with their defaults.
	 *
	 * Declared here rather than inline in the shortcode callback so the names a
	 * publisher may type and the names this class reads cannot drift apart.
	 * Empty-string defaults rather than real ones: an absent attribute must
	 * reach the readers as "unset" so they apply the same default the block
	 * does, and `shortcode_atts()` fills in whatever is written here.
	 *
	 * @return array<string, string>
	 */
	public static function shortcode_defaults(): array {
		return array(
			'slot'                => '',
			'rotate'              => '',
			'rotate_seconds'      => '',
			'collapse_when_empty' => '',
		);
	}

	/**
	 * The client's view of these settings.
	 *
	 * Interactivity context rather than attributes on the wrapper, because the
	 * store reads it per slot: two ad slots on one page rotate on their own
	 * intervals and settle their own emptiness.
	 *
	 * Every key is always present, including the ones holding a default. The
	 * store treats an absent key as the shipped behaviour, which makes an
	 * omission indistinguishable from a choice — and a slot whose context
	 * failed to encode would then look like a slot that asked for it.
	 *
	 * @return array<string, bool|int>
	 */
	public function to_context(): array {
		return array(
			'rotate'            => $this->rotate,
			'rotateSeconds'     => $this->rotate_seconds,
			'collapseWhenEmpty' => $this->collapse_when_empty,
		);
	}

	/**
	 * A requested interval, floored.
	 *
	 * @param mixed $requested Whatever the attribute carried.
	 */
	private static function interval( mixed $requested ): int {
		if ( ! is_numeric( $requested ) ) {
			return self::DEFAULT_ROTATE_SECONDS;
		}

		return max( self::MIN_ROTATE_SECONDS, (int) $requested );
	}

	/**
	 * Whether an unfilled slot removes itself.
	 *
	 * **Only an explicit false keeps the space**, matching `empty.js`.
	 * Collapsing is the shipped behaviour and the recoverable one — a slot that
	 * vanishes costs a publisher a gap they did not want, while a slot that
	 * stays costs every reader an empty box on every page — so an absent
	 * attribute, a null, or a block comment written before this existed all
	 * collapse, exactly as they did before.
	 *
	 * @param mixed $requested Whatever the attribute carried.
	 */
	private static function collapses( mixed $requested ): bool {
		if ( null === $requested || '' === $requested ) {
			return true;
		}

		return self::truthy( $requested );
	}

	/**
	 * The strings PHP's own truthiness gets wrong.
	 *
	 * One word and its case variants: `"false"` is a non-empty string, so plain
	 * PHP calls it true. Lower-cased at the point of comparison, because a
	 * shortcode is typed by hand and nothing shouts `FALSE` on purpose.
	 *
	 * `rest_sanitize_boolean()` also lists `"0"`, and this deliberately does
	 * not: `(bool) "0"` is already false, so the entry cannot change an answer.
	 * Mirroring it here would add a line no test can fail over — one the next
	 * reader has to prove harmless before they may touch it, which is the cost
	 * a redundant line actually charges.
	 */
	private const FALSE_WORDS = array( 'false' );

	/**
	 * A loosely written boolean, read the way the REST API reads one.
	 *
	 * Shortcode attributes are always strings, so `rotate="false"` arrives as a
	 * non-empty string and plain PHP would call it true. This is the rule
	 * `rest_sanitize_boolean()` applies, written out rather than delegated
	 * because this class is in the domain layer and calls no WordPress
	 * function. `SlotOptionsRestParityTest` asserts the two agree across the
	 * whole vocabulary, so the copy cannot drift in silence.
	 *
	 * @param mixed $value Whatever the attribute carried.
	 */
	private static function truthy( mixed $value ): bool {
		if ( is_string( $value ) && in_array( strtolower( $value ), self::FALSE_WORDS, true ) ) {
			return false;
		}

		/*
		 * Not an else. The word list is the *exception* to PHP's own
		 * truthiness, not a replacement for it, and reading it as a
		 * replacement made the empty string true — so a shortcode that
		 * mentioned none of these settings arrived carrying all three of them
		 * turned on. `shortcode_atts()` fills an unwritten attribute with `''`,
		 * which is how a default became a decision.
		 */
		return (bool) $value;
	}
}
