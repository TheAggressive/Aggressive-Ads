<?php
/**
 * The one action a campaign wizard step offers.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

/**
 * What the wizard's primary button does, and why it might not yet.
 *
 * Decided here, once, and drawn in two places: the order summary's card and the
 * phone's pinned bar (`campaign-order-summary.php`, `campaign-action-bar.php`).
 * It used to be a branch written inside the summary template, which is fine
 * for one reader and wrong for two — a second copy of the branch can disagree,
 * and "locked in the card, open in the bar" is the one disagreement that lets a
 * person submit something the card said was not ready.
 *
 * This class carries the *decision* and its numbers, never the wording. Wording
 * is translated, and a class that called `__()` could not be proven in the unit
 * suite; the template maps a {@see self::$reason} to a sentence. Everything
 * here is a plain function of its arguments, so every state is asserted
 * exhaustively in `WizardActionTest`.
 *
 * No WordPress function is called here, so the rule can be tested in
 * milliseconds without a bootstrap.
 */
final class Wizard_Action {

	/** The first step: package and dates. */
	public const STEP_DETAILS = 'details';

	/** The second step: the ads. */
	public const STEP_CREATIVE = 'creative';

	/** What the first step's button says. */
	public const LABEL_PLAN = 'plan';

	/** What the second step's button says. */
	public const LABEL_REVIEW = 'review';

	/** What the last step's button says. */
	public const LABEL_SUBMIT = 'submit';

	/** Nothing to say beneath an unlocked button that needs no reassurance. */
	public const REASON_NONE = 'none';

	/** The first step: nothing is final until the campaign is submitted. */
	public const REASON_EDITABLE = 'editable';

	/** The ads step is locked: {@see self::$count} sizes still need a file. */
	public const REASON_SIZES_NEED_FILE = 'sizes_need_file';

	/** The last step is open: the checks run again when it is pressed. */
	public const REASON_RECHECKED = 'rechecked';

	/** The last step is locked and only one file is missing: {@see self::$size}. */
	public const REASON_ADD_ONE_FILE = 'add_one_file';

	/** The last step is locked: {@see self::$count} things are left. */
	public const REASON_THINGS_LEFT = 'things_left';

	/**
	 * Problem codes that mean "a file is missing", and nothing else.
	 *
	 * The one-file wording is only honest when these are the *only* problems:
	 * "Add the 300×250 file to submit" over a campaign that also has no link
	 * would send someone to upload a file and then fail again.
	 */
	private const FILE_ONLY_CODES = array( 'no_creatives', 'placement_uncovered' );

	/**
	 * Use {@see self::resolve()}.
	 *
	 * @param string $form   Id of the form the button submits; empty while locked.
	 * @param string $label  One of the `LABEL_*` constants.
	 * @param bool   $locked Whether the step cannot move on yet.
	 * @param string $reason One of the `REASON_*` constants.
	 * @param int    $count  The number a reason speaks of, or zero.
	 * @param string $size   The size a reason names, or an empty string.
	 */
	private function __construct(
		public readonly string $form,
		public readonly string $label,
		public readonly bool $locked,
		public readonly string $reason,
		public readonly int $count,
		public readonly string $size
	) {}

	/**
	 * Decides the action for a step.
	 *
	 * @param string             $step           Display step: `details`, `creative`, or anything else for review.
	 * @param bool               $creative_ready Whether every active placement has a creative.
	 * @param bool               $review_ready   Whether every submission check passes.
	 * @param array<int, string> $missing_sizes  Sizes still without a file, e.g. `720×300`.
	 * @param array<int, string> $problem_codes  Codes of the readiness problems, one per problem.
	 * @return self
	 */
	public static function resolve(
		string $step,
		bool $creative_ready,
		bool $review_ready,
		array $missing_sizes,
		array $problem_codes
	): self {
		if ( self::STEP_DETAILS === $step ) {
			return new self( 'aggr-plan-form', self::LABEL_PLAN, false, self::REASON_EDITABLE, 0, '' );
		}

		if ( self::STEP_CREATIVE === $step ) {
			return $creative_ready
				? new self( 'aggr-creative-complete', self::LABEL_REVIEW, false, self::REASON_NONE, 0, '' )
				: new self( '', self::LABEL_REVIEW, true, self::REASON_SIZES_NEED_FILE, count( $missing_sizes ), '' );
		}

		if ( $review_ready ) {
			return new self( 'aggr-submit-form', self::LABEL_SUBMIT, false, self::REASON_RECHECKED, 0, '' );
		}

		$only_files = array() === array_diff( $problem_codes, self::FILE_ONLY_CODES );

		if ( 1 === count( $missing_sizes ) && $only_files ) {
			return new self( '', self::LABEL_SUBMIT, true, self::REASON_ADD_ONE_FILE, 1, $missing_sizes[0] );
		}

		return new self( '', self::LABEL_SUBMIT, true, self::REASON_THINGS_LEFT, count( $problem_codes ), '' );
	}
}
