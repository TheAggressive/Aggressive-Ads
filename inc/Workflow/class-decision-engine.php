<?php
/**
 * Assignment decision engine.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Decision_Context;
use Aggressive\Ads\Domain\Decision_Pipeline;
use Aggressive\Ads\Domain\Decision_Request;
use Aggressive\Ads\Domain\Decision_Result;
use Aggressive\Ads\Domain\Decision_Trace;
use Aggressive\Ads\Domain\Exclusion_Reason;
use Aggressive\Ads\Domain\Frequency_Rules;
use Aggressive\Ads\Domain\Frequency_Store;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;

/**
 * Loads assignment candidates, runs the pipeline, and exposes traces for staff.
 */
final class Decision_Engine {

	private const CANDIDATE_LIMIT = 500;

	/** Bounded wait for the request currently rebuilding a placement. */
	private const REBUILD_WAIT_ATTEMPTS     = 10;
	private const REBUILD_WAIT_MICROSECONDS = 20_000;

	/**
	 * Builds the engine.
	 *
	 * @param Creative_Assignment_Repository $assignments Candidate reads.
	 * @param Creative_Assignment_Migrator   $migrator    Backfill completion.
	 * @param Decision_Metrics               $metrics     Exclusion counters.
	 * @param Decision_Pipeline              $pipeline    Pure stages.
	 * @param Fill_Cache                     $cache       Short-TTL candidate cache.
	 * @param Frequency_Store                $frequency   Visitor frequency counts.
	 * @param Line_Item_Repository           $line_items  Delivery policy and counters.
	 */
	public function __construct(
		private readonly Creative_Assignment_Repository $assignments,
		private readonly Creative_Assignment_Migrator $migrator,
		private readonly Decision_Metrics $metrics,
		private readonly Decision_Pipeline $pipeline,
		private readonly Fill_Cache $cache,
		private readonly Frequency_Store $frequency,
		private readonly Line_Item_Repository $line_items
	) {
	}

	/**
	 * Attaches the delivery policy and counters the stages read.
	 *
	 * `candidates_for_placement()` returns the assignment's own columns and
	 * nothing else — no priority, pacing, caps, targeting or frequency policy,
	 * because all of those belong to the line item. Every stage therefore fell
	 * back to its default, and a configured policy changed nothing at serve
	 * time. Five phases were `[x]` in that state.
	 *
	 * One bounded query for the whole candidate set, never one per candidate, and
	 * only on a cache miss: the enriched rows are what gets cached. Policy and
	 * counters are read together because the budget is counted in queries, and
	 * a second statement was enough to put a cold thousand-candidate fill over
	 * it.
	 *
	 * Counters are keyed by line item, which is what carries a cap. Keying them
	 * by campaign was correct only while every campaign had exactly one line
	 * item; a second would have counted its sibling's impressions against its
	 * own cap.
	 *
	 * Pacing counters are consequently as fresh as the fill cache, which is the
	 * right trade for a cap that is a budget rather than a hard limit — an exact
	 * count would mean reading the ledger on every fill.
	 *
	 * @param list<array<string, mixed>> $rows Candidate rows.
	 * @param int                        $now  Evaluation time in UTC seconds.
	 * @return list<array<string, mixed>>
	 */
	private function enrich( array $rows, int $now ): array {
		if ( array() === $rows ) {
			return $rows;
		}

		$line_item_ids = array();

		foreach ( $rows as $row ) {
			$line_item_ids[] = (int) ( $row['line_item_id'] ?? 0 );
		}

		$policies = $this->line_items->delivery_policies_for( $line_item_ids, gmdate( 'Y-m-d', $now ) );

		foreach ( $rows as $index => $row ) {
			$line_item_id = (int) ( $row['line_item_id'] ?? 0 );
			$policy       = $policies[ $line_item_id ] ?? array();

			/*
			 * The assignment wins on any key it already owns. Its window is
			 * narrower than the line item's by construction, and overwriting it
			 * here would silently widen what an advertiser was refused.
			 */
			$rows[ $index ] = array_merge( $policy, $row );
		}

		return $rows;
	}

	/**
	 * Counts one delivery against the winner's frequency caps.
	 *
	 * Called when an ad is actually served rather than when it is decided, so a
	 * staff trace does not spend a visitor's impressions. Nothing called this
	 * before: the frequency stage read a counter no code ever wrote, so a
	 * configured cap excluded nobody.
	 *
	 * @param array<string, mixed> $winner Winning candidate row.
	 * @param int                  $now    Evaluation time in UTC seconds.
	 * @param array<string, mixed> $facts  Request facts, including the visitor id.
	 * @return void
	 */
	public function record_delivery( array $winner, int $now, array $facts ): void {
		if ( array() === $facts ) {
			return;
		}

		Frequency_Rules::record_delivery(
			$winner,
			new Decision_Context( 0, $now, $facts ),
			$this->frequency,
			$now
		);
	}

	/**
	 * Whether the assignment backfill has finished and fill may read it.
	 */
	public function serving_ready(): bool {
		return $this->migrator->is_complete() && $this->assignments->table_exists();
	}

	/**
	 * Serving status for observability surfaces.
	 */
	public function serving_status(): string {
		return $this->serving_ready() ? 'assignments' : 'backfill_pending';
	}

	/**
	 * Runs the pipeline for one placement and clock.
	 *
	 * @param int                             $placement_id   Placement post id.
	 * @param int                             $now            Evaluation time, UTC seconds.
	 * @param int|null                        $seed           Draw for weighted selection; random when null.
	 * @param list<array<string, mixed>>|null $rows           Preloaded candidates; queried when null.
	 * @param bool                            $record_metrics Whether to record exclusion metrics.
	 * @param array<string, mixed>            $facts          Request and targeting facts.
	 * @return array{result: Decision_Result, trace: Decision_Trace}
	 */
	public function decide( int $placement_id, int $now, ?int $seed = null, ?array $rows = null, bool $record_metrics = true, array $facts = array() ): array {
		if ( null === $rows ) {
			$rows = $this->enrich(
				array_values( $this->assignments->candidates_for_placement( $placement_id, $now, self::CANDIDATE_LIMIT ) ),
				$now
			);
		}

		$rows = array_values( $rows );

		$request = new Decision_Request(
			$placement_id,
			$now,
			$seed ?? random_int( 0, PHP_INT_MAX ),
			$facts
		);

		$decision = $this->pipeline->decide( $rows, $request );

		if ( $record_metrics ) {
			$this->count_outcome( $placement_id, $decision['result'] );
		}

		return array(
			'result' => $decision['result'],
			'trace'  => $decision['trace'],
		);
	}

	/**
	 * Coordinates batch decisions for an array of slots on a page.
	 *
	 * @param array<string, array{placement_id: int, candidates: list<array<string, mixed>>}> $slots_map Keyed by slot slug.
	 * @param int                                                                             $now       Evaluation time.
	 * @param int|null                                                                        $seed      Random seed.
	 * @param array<string, mixed>                                                            $facts     Request facts.
	 * @return array<string, array{result: Decision_Result, trace: Decision_Trace}>
	 */
	public function decide_page( array $slots_map, int $now, ?int $seed = null, array $facts = array() ): array {
		$decisions = \Aggressive\Ads\Domain\Page_Decision_Coordinator::coordinate(
			$slots_map,
			$this->pipeline,
			$now,
			$seed,
			$facts
		);

		/*
		 * Counted here rather than in the coordinator, which is `inc/Domain/`
		 * and may not call a WordPress function at all.
		 *
		 * This path recorded nothing before P13. A page served through the
		 * batch decision produced no counters while the same page served slot
		 * by slot produced them, so "why is this slot empty" had an answer that
		 * depended on which code path the request took — the shape that makes a
		 * metric untrustworthy rather than merely incomplete.
		 */
		foreach ( $slots_map as $slot_slug => $slot_data ) {
			if ( ! isset( $decisions[ $slot_slug ]['result'] ) ) {
				continue;
			}

			$this->count_outcome( (int) $slot_data['placement_id'], $decisions[ $slot_slug ]['result'] );
		}

		return $decisions;
	}

	/**
	 * Counts one decision opportunity: what was asked, and what came back.
	 *
	 * **One request and exactly one outcome**, so `requests` equals `fills`
	 * plus every no-fill reason and a reader can reconcile the three.
	 *
	 * Per-candidate exclusions are deliberately not counted here, and that is a
	 * change from what this used to store. Counting every losing candidate's
	 * reason alongside the slot's own outcome meant a slot that filled still
	 * incremented reasons, so the totals summed to nothing meaningful and a
	 * busy placement looked like a broken one. Why an individual candidate lost
	 * is what `REST\Decision_Trace_Controller` is for; this table answers why
	 * the *slot* was empty.
	 *
	 * @param int             $placement_id Placement post id.
	 * @param Decision_Result $result       Pipeline outcome for that placement.
	 */
	private function count_outcome( int $placement_id, Decision_Result $result ): void {
		$this->metrics->record_request( $placement_id );

		if ( $result->has_winner() ) {
			$this->metrics->record_fill( $placement_id );

			return;
		}

		$this->metrics->record_no_fill(
			$placement_id,
			is_string( $result->reason ) ? $result->reason : Exclusion_Reason::NO_FILL
		);
	}

	/**
	 * Cached assignment candidates for one placement.
	 *
	 * @param int $placement_id Placement post id.
	 * @param int $now          Evaluation time.
	 * @return list<array<string, mixed>>
	 */
	public function cached_rows( int $placement_id, int $now ): array {
		$cached = $this->cache->get( $placement_id );

		if ( is_array( $cached ) && isset( $cached['assignment_rows'] ) && is_array( $cached['assignment_rows'] ) ) {
			return array_values( $cached['assignment_rows'] );
		}

		$owner = $this->cache->claim_rebuild( $placement_id );

		if ( '' === $owner ) {
			for ( $attempt = 0; $attempt < self::REBUILD_WAIT_ATTEMPTS; ++$attempt ) {
				usleep( self::REBUILD_WAIT_MICROSECONDS );
				$cached = $this->cache->get( $placement_id );

				if ( is_array( $cached ) && isset( $cached['assignment_rows'] ) && is_array( $cached['assignment_rows'] ) ) {
					return array_values( $cached['assignment_rows'] );
				}
			}

			return array();
		}

		try {
			$rows = $this->enrich(
				array_values(
					$this->assignments->candidates_for_placement( $placement_id, $now, self::CANDIDATE_LIMIT )
				),
				$now
			);
			$this->cache->put(
				$placement_id,
				array( 'assignment_rows' => $rows )
			);

			return $rows;
		} finally {
			$this->cache->release_rebuild( $placement_id, $owner );
		}
	}

	/**
	 * Maps a winning assignment row to a token-free fill payload.
	 *
	 * @param array<string, mixed> $row          Winning assignment row.
	 * @param int                  $placement_id Placement post id.
	 * @return array<string, mixed>|null
	 */
	public function payload_from_row( array $row, int $placement_id ): ?array {
		$attachment_id = (int) ( $row['attachment_id'] ?? 0 );
		$image         = wp_get_attachment_image_url( $attachment_id, 'full' );

		if ( ! is_string( $image ) || '' === $image ) {
			return null;
		}

		return array(
			'image'     => $image,
			'alt'       => (string) ( $row['alt_text'] ?? '' ),
			'width'     => (int) ( $row['width'] ?? 0 ),
			'height'    => (int) ( $row['height'] ?? 0 ),
			'placement' => $placement_id,
			'campaign'  => (int) ( $row['campaign_id'] ?? 0 ),
			'creative'  => (int) ( $row['revision_id'] ?? 0 ),
		);
	}

	/**
	 * No-fill reason when no assignment survives the pipeline.
	 */
	public static function no_fill_reason(): string {
		return Exclusion_Reason::NO_FILL;
	}
}
