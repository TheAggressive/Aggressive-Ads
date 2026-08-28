<?php
/**
 * Short-TTL fill cache, busted on campaign transitions.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Repository\Campaign_Repository;

/**
 * Object-cache wrapper. A miss rebuilds the assignment candidate set; the
 * winner is still chosen per request.
 */
final class Fill_Cache implements Service {

	public const GROUP = 'aggr_fill';

	/** Rebuild locks expire even when the building request dies. */
	private const LOCK_TTL = 10;

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository $campaigns Placement membership.
	 * @param Settings            $settings  Fill TTL.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Settings $settings
	) {
	}

	/**
	 * Listens for transitions that must not ride out a CDN TTL.
	 */
	public function init(): void {
		add_action( 'aggr_campaign_transitioned', array( $this, 'bust_campaign' ), 10, 1 );
		add_action( 'aggr_creative_replaced', array( $this, 'bust_campaign' ), 10, 1 );
	}

	/**
	 * Stores the assignment candidate set for one placement.
	 *
	 * @param int                  $placement_id Placement post id.
	 * @param array<string, mixed> $payload      Cached assignment rows.
	 */
	public function put( int $placement_id, array $payload ): bool {
		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- Fill TTL is 5–300s by design; a 300s floor is the paused-campaign-still-showing bug.
		return wp_cache_set( $this->key( $placement_id ), $payload, self::GROUP, $this->settings->fill_ttl() );
	}

	/**
	 * Cached identity, or null on a miss.
	 *
	 * @param int $placement_id Placement post id.
	 * @return array<string, mixed>|null
	 */
	public function get( int $placement_id ): ?array {
		$cached = wp_cache_get( $this->key( $placement_id ), self::GROUP );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Claims a short cross-request rebuild lock when a persistent cache exists.
	 *
	 * @param int $placement_id Placement post id.
	 * @return string Empty when another request owns the lock.
	 */
	public function claim_rebuild( int $placement_id ): string {
		$owner = wp_generate_uuid4();

		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- A rebuild mutex must fail open quickly after a dead request; it is not cached data.
		return wp_cache_add( $this->lock_key( $placement_id ), $owner, self::GROUP, self::LOCK_TTL ) ? $owner : '';
	}

	/**
	 * Releases only the caller's rebuild lock.
	 *
	 * @param int    $placement_id Placement post id.
	 * @param string $owner        Claim token.
	 */
	public function release_rebuild( int $placement_id, string $owner ): void {
		if ( '' === $owner || wp_cache_get( $this->lock_key( $placement_id ), self::GROUP ) !== $owner ) {
			return;
		}

		wp_cache_delete( $this->lock_key( $placement_id ), self::GROUP );
	}

	/**
	 * Drops one placement's fill.
	 *
	 * @param int $placement_id Placement post id.
	 */
	public function delete( int $placement_id ): void {
		wp_cache_delete( $this->key( $placement_id ), self::GROUP );
	}

	/**
	 * Drops every placement a campaign occupies.
	 *
	 * @param int $campaign_id Campaign post id.
	 */
	public function bust_campaign( int $campaign_id ): void {
		foreach ( $this->campaigns->placement_ids( $campaign_id ) as $placement_id ) {
			$this->delete( $placement_id );
		}
	}

	/**
	 * Object-cache key for one placement on the current site.
	 *
	 * The blog id is in the key, not only implied by the cache group, because some
	 * object-cache drop-ins treat groups as global. Post ids restart on every
	 * site; mixing candidate sets would show the wrong publisher's ads.
	 *
	 * @param int $placement_id Placement post id.
	 */
	private function key( int $placement_id ): string {
		$blog_id = get_current_blog_id();
		$blog_id = $blog_id > 0 ? $blog_id : 1;

		return 'aggr_fill_' . $blog_id . '_' . $placement_id;
	}

	/**
	 * One placement rebuild lock on the current site.
	 *
	 * @param int $placement_id Placement post id.
	 */
	private function lock_key( int $placement_id ): string {
		return $this->key( $placement_id ) . '_lock';
	}
}
