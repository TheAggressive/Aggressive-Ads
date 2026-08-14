<?php
/**
 * Durable impression/click recording.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

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
	 * @param Event_Repository  $events  Durable event ledger.
	 * @param Rollup_Repository $rollups Repairable reporting projection.
	 */
	public function __construct(
		private readonly Event_Repository $events,
		private readonly Rollup_Repository $rollups
	) {
	}

	/**
	 * Records one event and attempts the low-latency reporting projection.
	 *
	 * A failed projection does not discard or replay the accepted event. The
	 * closed-day reconciler rebuilds exact counters from the ledger.
	 *
	 * @param string $type         impression|click.
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

		$column = Event_Repository::TYPE_IMPRESSION === $type ? 'impressions' : 'clicks';

		return $this->rollups->increment( $column, $placement_id, $campaign_id )
			? self::RECORDED
			: self::RECORDED_PENDING;
	}
}
