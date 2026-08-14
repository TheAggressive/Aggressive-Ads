<?php
/**
 * Serialized rate-limit counter updates.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory locks are the serialization primitive; they have no cacheable result.

/**
 * Owns the database lock that makes transient counter updates atomic.
 */
final class Rate_Limit_Repository {

	private const CACHE_GROUP = 'aggr_rate_limits';

	/**
	 * Claims one attempt inside a database advisory lock.
	 *
	 * @param string $key    Transient key.
	 * @param int    $limit  Attempts allowed in the window.
	 * @param int    $window Window length in seconds.
	 * @param int    $now    Current Unix timestamp.
	 * @return array{allowed: bool, count: int, reset: int}|null Null when the lock cannot be acquired.
	 */
	public function claim( string $key, int $limit, int $window, int $now ): ?array {
		if ( '' === $key || $limit < 1 || $window < 1 || $now < 0 ) {
			return null;
		}

		if ( wp_using_ext_object_cache() ) {
			return $this->claim_from_object_cache( $key, $limit, $window, $now );
		}

		return $this->claim_with_database_lock( $key, $limit, $window, $now );
	}

	/**
	 * Remaining attempts for the active persistence backend.
	 *
	 * @param string $key    Counter identity.
	 * @param int    $limit  Attempts allowed.
	 * @param int    $window Window seconds.
	 * @param int    $now    Current Unix timestamp.
	 */
	public function remaining( string $key, int $limit, int $window, int $now ): int {
		if ( '' === $key || $limit < 1 || $window < 1 || $now < 0 ) {
			return 0;
		}

		if ( wp_using_ext_object_cache() ) {
			$count = wp_cache_get( $this->bucket_key( $key, $window, $now ), self::CACHE_GROUP );

			return is_numeric( $count ) ? max( 0, $limit - (int) $count ) : $limit;
		}

		$state = get_transient( $key );

		if ( ! is_array( $state ) || ! isset( $state['count'], $state['reset'] ) || $now >= (int) $state['reset'] ) {
			return $limit;
		}

		return max( 0, $limit - (int) $state['count'] );
	}

	/**
	 * Atomic counter on Redis/Memcached-compatible persistent object caches.
	 *
	 * `add` initializes once and `incr` is the cross-process atomic operation.
	 * Returning null on a backend without atomic increment fails closed instead
	 * of silently splitting one allowance across two persistence mechanisms.
	 *
	 * @param string $key    Counter identity.
	 * @param int    $limit  Attempts allowed.
	 * @param int    $window Window seconds.
	 * @param int    $now    Current Unix timestamp.
	 * @return array{allowed: bool, count: int, reset: int}|null
	 */
	private function claim_from_object_cache( string $key, int $limit, int $window, int $now ): ?array {
		$reset      = ( intdiv( $now, $window ) + 1 ) * $window;
		$bucket_key = $this->bucket_key( $key, $window, $now );

		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- The fixed-window counter must expire at its calculated reset boundary, not at an unrelated cache floor.
		wp_cache_add( $bucket_key, 0, self::CACHE_GROUP, max( 1, $reset - $now + 1 ) );
		$count = wp_cache_incr( $bucket_key, 1, self::CACHE_GROUP );

		if ( ! is_int( $count ) ) {
			return null;
		}

		return array(
			'allowed' => $count <= $limit,
			'count'   => $count,
			'reset'   => $reset,
		);
	}

	/**
	 * Serialized transient fallback for installations without persistent cache.
	 *
	 * @param string $key    Transient key.
	 * @param int    $limit  Attempts allowed in the window.
	 * @param int    $window Window length in seconds.
	 * @param int    $now    Current Unix timestamp.
	 * @return array{allowed: bool, count: int, reset: int}|null
	 */
	private function claim_with_database_lock( string $key, int $limit, int $window, int $now ): ?array {
		global $wpdb;

		$lock_name = 'aggr_rl_' . substr( hash( 'sha256', $key ), 0, 48 );
		$acquired  = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 2 ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Advisory lock name and timeout are prepared.
		);

		if ( 1 !== $acquired ) {
			return null;
		}

		try {
			$state = get_transient( $key );

			if ( ! is_array( $state ) || ! isset( $state['count'], $state['reset'] ) || $now >= (int) $state['reset'] ) {
				$state = array(
					'count' => 0,
					'reset' => $now + $window,
				);
			}

			$count = (int) $state['count'];
			$reset = (int) $state['reset'];

			if ( $count >= $limit ) {
				return array(
					'allowed' => false,
					'count'   => $count,
					'reset'   => $reset,
				);
			}

			++$count;
			set_transient(
				$key,
				array(
					'count' => $count,
					'reset' => $reset,
				),
				max( 1, $reset - $now )
			);

			return array(
				'allowed' => true,
				'count'   => $count,
				'reset'   => $reset,
			);
		} finally {
			$wpdb->get_var(
				$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Releases the exact prepared advisory lock.
			);
		}
	}

	/**
	 * Epoch-aligned bucket key so expiry never needs a non-atomic reset.
	 *
	 * @param string $key    Counter identity.
	 * @param int    $window Window seconds.
	 * @param int    $now    Current Unix timestamp.
	 */
	private function bucket_key( string $key, int $window, int $now ): string {
		return $key . '_' . intdiv( $now, $window );
	}
}
