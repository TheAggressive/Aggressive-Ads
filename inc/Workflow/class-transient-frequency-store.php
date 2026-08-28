<?php
/**
 * Transient-backed frequency capping store.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Frequency_Store;

/**
 * Frequency store backed by the object cache, falling back to transients.
 *
 * `wp_cache_incr()` is atomic where a persistent object cache is installed,
 * which matters here: a read-then-write increment loses counts under exactly
 * the concurrent traffic that makes a cap worth having, and an undercount
 * serves more ads than the advertiser agreed to.
 *
 * Without a persistent cache there is nothing atomic to use, so the transient
 * path stays read-then-write and is best-effort by design. Frequency data is
 * ephemeral and the phase contract fails open, so a lost count costs one extra
 * impression rather than an error.
 */
final class Transient_Frequency_Store implements Frequency_Store {

	private const PREFIX = 'aggr_freq_';

	/** Own cache group, so a flush of one group cannot reset every visitor's caps. */
	private const GROUP = 'aggr_frequency';

	/**
	 * Gets the current impression count.
	 *
	 * @param string $key Unique storage key.
	 */
	public function get_count( string $key ): int {
		$transient_key = self::PREFIX . md5( $key );

		if ( wp_using_ext_object_cache() ) {
			$cached = wp_cache_get( $transient_key, self::GROUP );

			if ( is_numeric( $cached ) ) {
				return (int) $cached;
			}
		}

		$value = get_transient( $transient_key );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Increments the impression count.
	 *
	 * @param string $key         Unique storage key.
	 * @param int    $ttl_seconds Lifetime in seconds.
	 */
	public function increment( string $key, int $ttl_seconds ): int {
		$transient_key = self::PREFIX . md5( $key );
		$ttl           = max( 1, $ttl_seconds );

		if ( wp_using_ext_object_cache() ) {
			$incremented = wp_cache_incr( $transient_key, 1, self::GROUP );

			if ( false !== $incremented ) {
				return (int) $incremented;
			}

			// Absent key: seed it. `add` loses the race deliberately, so a
			// second caller falls through to incr rather than resetting to 1.
			// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- The lifetime is the frequency window itself, floored at one second; a fixed expiry here would be the sliding-window bug in a different place.
			if ( wp_cache_add( $transient_key, 1, self::GROUP, $ttl ) ) {
				return 1;
			}

			$raced = wp_cache_incr( $transient_key, 1, self::GROUP );

			if ( false !== $raced ) {
				return (int) $raced;
			}
		}

		$new_count = $this->get_count( $key ) + 1;

		set_transient( $transient_key, $new_count, $ttl );

		return $new_count;
	}

	/**
	 * Resets or clears a frequency key.
	 *
	 * @param string $key Unique storage key.
	 */
	public function reset( string $key ): void {
		$transient_key = self::PREFIX . md5( $key );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $transient_key, self::GROUP );
		}

		delete_transient( $transient_key );
	}
}
