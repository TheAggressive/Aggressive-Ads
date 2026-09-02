<?php
/**
 * How much staff work is waiting.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Repository\Campaign_Repository;

/**
 * One definition of "waiting for a decision", for every surface that shows it.
 *
 * The count began on the Review submenu and is now also on the Advertising
 * parent, because **the submenu's copy is not actually visible**. WordPress
 * positions a top-level item's submenu off-canvas and slides it in on hover, so
 * the badge on `Review` is off-screen on every admin page — measured at
 * x = -886 on a 1400px viewport — including while you are inside Advertising.
 * A reviewer working anywhere else in wp-admin saw nothing at all.
 *
 * The parent's copy is visible from every screen, which is the same reason core
 * puts its own count on `Comments` rather than on a child of it.
 *
 * Note what this does *not* fix: when the menu is folded, core parks
 * `.wp-menu-name` at `left: -999px` and the badge rides along with it. Core's
 * own Comments bubble disappears the same way, so matching that behaviour is
 * correct rather than a gap to paper over with a bespoke rule.
 *
 * **Extracted from `Review_Data` rather than reused from it.** That class needs
 * ten collaborators to do its job, and the menu shell loads on every admin
 * page; building that graph to render a number would make every request in
 * wp-admin pay for the review screen. This needs one repository.
 *
 * **Not memoized, deliberately.** Two surfaces do ask the same question on one
 * page load, so caching the answer is tempting and saves a query. It was
 * written that way first and the suite caught it: the service container is a
 * singleton, so the memo outlives the request that filled it. In a web request
 * that is invisible; in WP-CLI — where somebody scripts a bulk approval and
 * then reads the count — it serves a number from before their own work.
 *
 * A stale count is worse than a duplicated query, because the query is
 * measurable and the staleness is not. If this ever costs enough to matter the
 * fix is a cache with explicit invalidation on the transitions that change it,
 * not a memo whose lifetime nobody can see.
 */
final class Pending_Work {

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository $campaigns Campaign counts.
	 */
	public function __construct( private readonly Campaign_Repository $campaigns ) {
	}

	/**
	 * How many items are waiting for a staff decision.
	 *
	 * Submitted campaigns plus advertiser requests — the two things a reviewer
	 * is expected to clear. Creative replacements are deliberately excluded:
	 * they already surface on their own tab, and a badge that counts everything
	 * is a badge nobody can act on.
	 */
	public function pending_decision_count(): int {
		$counts = $this->campaigns->count_by_status( array( Post_Statuses::SUBMITTED, Post_Statuses::REVIEW ) );
		$total  = 0;

		foreach ( $counts as $count ) {
			$total += (int) $count;
		}

		return $total + $this->campaigns->campaigns_with_pending_requests();
	}

	/**
	 * The count as the bubble a **top-level parent** wears.
	 *
	 * **`update-plugins`, not `awaiting-mod`, and the difference is not
	 * cosmetic.** Core styles the current-item bubble through two selectors:
	 *
	 *     #adminmenu li.current a .awaiting-mod                    (current top-level item)
	 *     #adminmenu li a.wp-has-current-submenu .update-plugins   (parent of a current submenu)
	 *
	 * The Advertising parent is the second kind — it carries
	 * `wp-has-current-submenu` whenever any of its screens is open. A bubble
	 * marked `awaiting-mod` matches neither rule, so it keeps its resting
	 * colours on the active row while still inverting on hover, which is the
	 * inconsistency this spelling fixes.
	 *
	 * The inner `update-count` and the `count-N` class are core's too; the
	 * latter is what `#adminmenu li span.count-0 { display: none }` keys on.
	 *
	 * Returns empty rather than a zero bubble: a badge showing nothing to do is
	 * noise that teaches people to stop reading badges.
	 */
	public function parent_badge(): string {
		$waiting = $this->pending_decision_count();

		if ( $waiting < 1 ) {
			return '';
		}

		return sprintf(
			'<span class="update-plugins count-%1$s"><span class="update-count">%2$s</span></span>',
			esc_attr( (string) $waiting ),
			esc_html( number_format_i18n( $waiting ) )
		);
	}

	/**
	 * The count as the bubble a **submenu item** wears.
	 *
	 * `awaiting-mod` here, because a submenu row becomes `li.current` and that
	 * is the selector core inverts for it. See `parent_badge()` for why the
	 * two spellings are not interchangeable.
	 */
	public function submenu_badge(): string {
		$waiting = $this->pending_decision_count();

		if ( $waiting < 1 ) {
			return '';
		}

		return sprintf(
			'<span class="awaiting-mod"><span class="pending-count">%s</span></span>',
			esc_html( number_format_i18n( $waiting ) )
		);
	}

	/**
	 * Appends a badge to a menu label, or returns the label unchanged.
	 *
	 * @param string $label Translated menu label.
	 * @param string $badge Badge markup from `parent_badge()` or `submenu_badge()`.
	 */
	public function label_with_badge( string $label, string $badge ): string {
		if ( '' === $badge ) {
			return $label;
		}

		return sprintf(
			/* translators: 1: menu label, 2: number of items awaiting a decision. */
			__( '%1$s %2$s', 'aggressive-ads' ),
			$label,
			$badge
		);
	}
}
