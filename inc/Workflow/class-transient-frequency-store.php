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
 * Frequency store backed by WordPress transients and object caching.
 */
final class Transient_Frequency_Store implements Frequency_Store {

	private const PREFIX = 'aggr_freq_';

	/**
	 * Gets the current impression count.
	 *
	 * @param string $key Unique storage key.
	 */
	public function get_count( string $key ): int {
		$transient_key = self::PREFIX . md5( $key );
		$value         = get_transient( $transient_key );

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
		$current       = $this->get_count( $key );
		$new_count     = $current + 1;

		set_transient( $transient_key, $new_count, max( 1, $ttl_seconds ) );

		return $new_count;
	}

	/**
	 * Resets or clears a frequency key.
	 *
	 * @param string $key Unique storage key.
	 */
	public function reset( string $key ): void {
		$transient_key = self::PREFIX . md5( $key );
		delete_transient( $transient_key );
	}
}
