<?php
/**
 * In-memory frequency capping store.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Pure in-memory frequency store for tests and short-lived evaluations.
 */
final class Array_Frequency_Store implements Frequency_Store {

	/**
	 * In-memory store.
	 *
	 * @var array<string, int>
	 */
	private array $counts = array();

	/**
	 * Constructor with optional initial counts.
	 *
	 * @param array<string, int> $initial_counts Seed counts.
	 */
	public function __construct( array $initial_counts = array() ) {
		$this->counts = $initial_counts;
	}

	/**
	 * Gets the current impression count.
	 *
	 * @param string $key Unique storage key.
	 */
	public function get_count( string $key ): int {
		return $this->counts[ $key ] ?? 0;
	}

	/**
	 * Increments the impression count.
	 *
	 * @param string $key         Unique storage key.
	 * @param int    $ttl_seconds Lifetime in seconds.
	 */
	public function increment( string $key, int $ttl_seconds ): int {
		$current              = $this->counts[ $key ] ?? 0;
		$this->counts[ $key ] = $current + 1;

		return $this->counts[ $key ];
	}

	/**
	 * Resets or clears a frequency key.
	 *
	 * @param string $key Unique storage key.
	 */
	public function reset( string $key ): void {
		unset( $this->counts[ $key ] );
	}
}
