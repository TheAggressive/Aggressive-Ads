<?php
/**
 * Frequency capping storage contract.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Interface for reading and recording ephemeral visitor frequency counts.
 */
interface Frequency_Store {

	/**
	 * Gets the current impression count for a given entity key.
	 *
	 * @param string $key Unique storage key.
	 * @return int Impression count.
	 */
	public function get_count( string $key ): int;

	/**
	 * Increments the impression count for a given entity key with TTL.
	 *
	 * @param string $key         Unique storage key.
	 * @param int    $ttl_seconds Lifetime of the frequency record in seconds.
	 * @return int New impression count.
	 */
	public function increment( string $key, int $ttl_seconds ): int;

	/**
	 * Resets or clears a frequency key.
	 *
	 * @param string $key Unique storage key.
	 */
	public function reset( string $key ): void;
}
