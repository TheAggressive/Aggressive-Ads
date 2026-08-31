<?php
/**
 * Refusal counters for conversion reports.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Conversion_Attribution;

/**
 * Aggregate refusal reasons so "nothing is being recorded" has a diagnosable cause.
 *
 * `Conversion_Health` can already tell an operator that clicks happened and no
 * conversion followed. It could not tell them *why*, and the reasons are not
 * variations on one problem: `no_interaction` is abuse or a bug, `out_of_window`
 * is usually a window set too short, and `s2s_not_permitted` is a definition
 * nobody turned server reporting on for. Three different actions behind one
 * silence.
 *
 * **The write was the objection, and it is answered twice over.** This was left
 * unbuilt because counting means a write per refused request on a public
 * unauthenticated endpoint — a cost an attacker chooses rather than the site.
 *
 * First, `Conversions_Controller::record()` rate-limits before anything reaches
 * attribution, so a refusal counted here has already passed a per-client bound
 * and already paid for a token parse and an indexed definition read.
 *
 * Second, and this is what made it safe rather than merely affordable: **the
 * count is buffered in memory and written once, on `shutdown`.** Refusing costs
 * no query at all on the path the client waits on, which is the property
 * `ConversionRecorderTest::test_an_unknown_definition_never_queries_the_event_ledger`
 * asserts — an unknown definition costs exactly one indexed read and nothing
 * else. The first version of this class wrote inline and failed that test, and
 * the test was right: the cheapest refusal is the one an attacker repeats, so
 * it is the one that must stay cheap.
 *
 * The shape is `Decision_Metrics`, deliberately: that class counts exclusion
 * reasons on the *fill* path, which runs far more often than this one. A second
 * answer to "how does this codebase count refusals" would be worse than either
 * answer alone. Two things are different, and both are improvements the older
 * class could take:
 *
 * - **The keys are bounded by the domain.** Only `Conversion_Attribution`
 *   reasons are stored, so no caller can grow this option by inventing a code.
 *   `Decision_Metrics` keys by placement and grows with the site.
 * - **It records when counting started.** A number with no beginning reads as
 *   current no matter how old it is, and the first question anybody asks of
 *   "47 refusals" is "since when".
 *
 * **The counts are approximate, and callers must present them that way.** This
 * is a read-modify-write on one option: two overlapping requests read the same
 * array and one write wins. Under-counting a diagnostic is not a correctness
 * failure, but reporting it as exact would be — nothing here may become a
 * number somebody bills from. The ledger is the exact record; this is the
 * signpost that says which way to look.
 */
final class Conversion_Metrics implements Service {

	public const OPTION_REFUSALS = 'aggr_conversion_refusal_counts';

	/**
	 * Reasons refused during this request, not yet durable.
	 *
	 * @var array<string, int>
	 */
	private array $buffered = array();

	/**
	 * Writes what this request refused, after the response has gone.
	 *
	 * `shutdown` rather than a destructor: the hook is greppable, testable and
	 * skipped entirely on a request that refused nothing, and a destructor
	 * running during PHP's shutdown sequence cannot rely on the database
	 * connection still being open.
	 */
	public function init(): void {
		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/**
	 * Increments one refusal reason.
	 *
	 * Anything that is not a refusal is ignored rather than stored: an unknown
	 * code cannot enter the option, and `ACCEPTED` is not a refusal — counting
	 * it here would put a second, approximate answer beside the ledger's exact
	 * one for how many conversions were recorded.
	 *
	 * @param string $reason One of Domain\Conversion_Attribution's reasons.
	 */
	public function record_refusal( string $reason ): void {
		if ( ! self::is_refusal( $reason ) ) {
			return;
		}

		$this->buffered[ $reason ] = ( $this->buffered[ $reason ] ?? 0 ) + 1;
	}

	/**
	 * Makes this request's refusals durable, in one write.
	 *
	 * Public because `shutdown` calls it, and because a caller that needs the
	 * count readable *now* — a test, a future admin action — must be able to
	 * ask rather than reach into the buffer.
	 *
	 * Returns early on the overwhelmingly common request that refused nothing,
	 * so the hook costs an array check on every other request in wp-admin.
	 */
	public function flush(): void {
		if ( array() === $this->buffered ) {
			return;
		}

		$stored = $this->stored();

		foreach ( $this->buffered as $reason => $count ) {
			$stored['counts'][ $reason ] = ( $stored['counts'][ $reason ] ?? 0 ) + $count;
		}

		/*
		 * Stamped on the first refusal rather than on install, because "since"
		 * has to mean "since counting began", and an install that has never
		 * refused anything has not begun.
		 */
		if ( $stored['since'] <= 0 ) {
			$stored['since'] = time();
		}

		update_option( self::OPTION_REFUSALS, $stored, false );

		$this->buffered = array();
	}

	/**
	 * Refusal counts keyed by reason code, highest first.
	 *
	 * Sorted here rather than by every reader, because every reader wants the
	 * same thing: the reason to look at first.
	 *
	 * **Durable counts only, never the buffer.** A reader sees what survived a
	 * request, so a test cannot pass by reading back something that was never
	 * written — which is how a counter comes to be believed while nothing
	 * persists it.
	 *
	 * @return array<string, int>
	 */
	public function refusal_counts(): array {
		$counts = $this->stored()['counts'];

		arsort( $counts );

		return $counts;
	}

	/**
	 * When the first refusal was counted, or 0 while none has been.
	 */
	public function counting_since(): int {
		return $this->stored()['since'];
	}

	/**
	 * Forgets every count, and forgets that counting started.
	 *
	 * For an operator who has fixed the cause and wants to see whether it
	 * stayed fixed. Without this the old number never goes away, and a total
	 * that cannot return to zero stops being read at all.
	 */
	public function reset(): void {
		$this->buffered = array();

		delete_option( self::OPTION_REFUSALS );
	}

	/**
	 * Whether a reason is one this class stores.
	 *
	 * @param string $reason Candidate reason code.
	 */
	private static function is_refusal( string $reason ): bool {
		return Conversion_Attribution::ACCEPTED !== $reason
			&& in_array( $reason, Conversion_Attribution::reasons(), true );
	}

	/**
	 * The stored shape, normalized so no reader has to defend itself.
	 *
	 * A code that has since left the domain is dropped on read rather than
	 * migrated: it cannot be explained to anybody in the interface, and keeping
	 * it would mean a Site Health panel able to print a string it has no label
	 * for.
	 *
	 * @return array{since: int, counts: array<string, int>}
	 */
	private function stored(): array {
		$raw = get_option( self::OPTION_REFUSALS, array() );

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$counts = array();
		$stored = isset( $raw['counts'] ) && is_array( $raw['counts'] ) ? $raw['counts'] : array();

		foreach ( $stored as $reason => $count ) {
			if ( ! is_string( $reason ) || ! self::is_refusal( $reason ) ) {
				continue;
			}

			$amount = (int) $count;

			if ( $amount > 0 ) {
				$counts[ $reason ] = $amount;
			}
		}

		return array(
			'since'  => max( 0, (int) ( $raw['since'] ?? 0 ) ),
			'counts' => $counts,
		);
	}
}
