<?php
/**
 * Native delivery publisher.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Integration\Native;

use Aggressive\Ads\Domain\Publication_Result;
use Aggressive\Ads\Domain\Transition_Table;
use Aggressive\Ads\Integration\Ad_Provider_Interface;
use Aggressive\Ads\Workflow\Fill_Cache;

/**
 * There is no downstream ad CPT. The live set is campaign status. These
 * operations bust fill cache so a pause cannot ride out a TTL.
 */
final class Publisher implements Ad_Provider_Interface {

	/**
	 * Constructor.
	 *
	 * @param Fill_Cache $cache Native fill cache.
	 */
	public function __construct(
		private readonly Fill_Cache $cache
	) {
	}

	/**
	 * Marks the campaign eligible for fill by dropping stale identity.
	 *
	 * @param int $campaign_id Campaign post id.
	 */
	public function publish_campaign( int $campaign_id ): Publication_Result {
		$this->cache->bust_campaign( $campaign_id );

		return new Publication_Result();
	}

	/**
	 * Drops fill so a cancelled campaign is not served from cache.
	 *
	 * @param int $campaign_id Campaign post id.
	 */
	public function unpublish_campaign( int $campaign_id ): true {
		$this->cache->bust_campaign( $campaign_id );

		return true;
	}

	/**
	 * Drops fill so a paused campaign is not served from cache.
	 *
	 * @param int $campaign_id Campaign post id.
	 */
	public function suppress_campaign( int $campaign_id ): true {
		$this->cache->bust_campaign( $campaign_id );

		return true;
	}

	/**
	 * Drops fill so resume can pick the current window on the next GET.
	 *
	 * @param int $campaign_id Campaign post id.
	 */
	public function resume_campaign( int $campaign_id ): true {
		$this->cache->bust_campaign( $campaign_id );

		return true;
	}

	/**
	 * Creative bytes already swapped in our records. Bust fill only.
	 *
	 * @param int $campaign_id    Campaign id.
	 * @param int $current_id     Current creative id.
	 * @param int $replacement_id Reviewed replacement id.
	 */
	public function replace_creative( int $campaign_id, int $current_id, int $replacement_id ): true {
		unset( $current_id, $replacement_id );
		$this->cache->bust_campaign( $campaign_id );

		return true;
	}

	/**
	 * Restore is a no-op: native fill reads the current creative record.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $creative_id Current creative id.
	 */
	public function restore_creative( int $campaign_id, int $creative_id ): true {
		unset( $creative_id );
		$this->cache->bust_campaign( $campaign_id );

		return true;
	}

	/**
	 * Effect handlers keyed by the transition table's provider effect names.
	 *
	 * @return array<string, callable>
	 */
	public function transition_effects(): array {
		return array(
			Transition_Table::EFFECT_PUBLISH   => function ( int $campaign_id ): true {
				$this->publish_campaign( $campaign_id );

				return true;
			},
			Transition_Table::EFFECT_UNPUBLISH => fn ( int $id ): true => $this->unpublish_campaign( $id ),
			Transition_Table::EFFECT_SUPPRESS  => fn ( int $id ): true => $this->suppress_campaign( $id ),
			Transition_Table::EFFECT_RESUME    => fn ( int $id ): true => $this->resume_campaign( $id ),
		);
	}
}
