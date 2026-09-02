<?php
/**
 * Durable impression/click recording.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;

/**
 * Treats the append-only event as the ledger and the rollup as a projection.
 */
final class Event_Recorder {

	public const RECORDED         = 'recorded';
	public const RECORDED_PENDING = 'recorded_pending';
	public const REPLAY           = 'replay';
	public const FAILED           = 'failed';

	/**
	 * Constructor.
	 *
	 * @param Event_Repository               $events      Durable event ledger.
	 * @param Rollup_Repository              $rollups     Repairable reporting projection.
	 * @param Creative_Assignment_Repository $assignments Line-item attribution.
	 * @param Campaign_Repository            $campaigns   Owning organization, frozen onto the projection.
	 */
	public function __construct(
		private readonly Event_Repository $events,
		private readonly Rollup_Repository $rollups,
		private readonly Creative_Assignment_Repository $assignments,
		private readonly Campaign_Repository $campaigns
	) {
	}

	/**
	 * Records one event and attempts the low-latency reporting projection.
	 *
	 * A failed projection does not discard or replay the accepted event. The
	 * closed-day reconciler rebuilds exact counters from the ledger.
	 *
	 * @param string $type         Measurement event type (e.g. served, click, impression).
	 * @param int    $placement_id Placement post id.
	 * @param int    $campaign_id  Campaign post id, or 0 for house.
	 * @param int    $creative_id  Creative post id, or 0 for house.
	 * @param string $token_hash   Replay digest.
	 * @param string $ip_hash      Daily client digest.
	 * @return self::RECORDED|self::RECORDED_PENDING|self::REPLAY|self::FAILED
	 */
	public function record( string $type, int $placement_id, int $campaign_id, int $creative_id, string $token_hash, string $ip_hash ): string {
		if ( ! $this->events->insert( $type, $placement_id, $campaign_id, $creative_id, $token_hash, $ip_hash ) ) {
			return $this->events->exists( $type, $token_hash ) ? self::REPLAY : self::FAILED;
		}

		$is_served = in_array( $type, array( Event_Repository::TYPE_SERVED, Event_Repository::TYPE_IMPRESSION ), true );

		$column = match ( true ) {
			$is_served => 'impressions',
			Event_Repository::TYPE_VIEWABLE === $type => 'viewables',
			default => 'clicks',
		};

		/*
		 * A cap belongs to the line item, so the counter has to as well. The
		 * ledger records the creative, not the line item, so it is resolved
		 * from the assignment that served it — one indexed read beside a write
		 * that was already happening. An unattributable event counts against
		 * line item 0 rather than being dropped: the ledger stays the truth and
		 * the daily reconcile repairs the projection.
		 *
		 * The owning organization is frozen onto the rollup row from the
		 * campaign the *event* names. Reporting used to recover it by joining
		 * that meta at read time, which made tenancy a current fact rather than
		 * a historical one: a campaign moved between organizations took its
		 * past totals along.
		 *
		 * It is read from the campaign rather than folded into the assignment
		 * lookup above, and the distinction is not stylistic. Tenancy belongs
		 * to the campaign; the assignment is only how a line item is recovered.
		 * A house fill carries `campaign_id = 0` while still matching an
		 * assignment owned by somebody, so keying on the assignment credited
		 * house inventory to that advertiser — and an event whose assignment
		 * has been cleaned up resolved no organization at all. Post meta is
		 * cached per request, so the ordinary cost is no query.
		 */
		$line_item_id = $this->assignments->line_item_for( $creative_id, $placement_id );

		$projected = $this->rollups->increment(
			column: $column,
			placement_id: $placement_id,
			campaign_id: $campaign_id,
			day_utc: '',
			line_item_id: $line_item_id,
			org_id: $this->campaigns->org_id( $campaign_id ),
		);

		return $projected ? self::RECORDED : self::RECORDED_PENDING;
	}
}
