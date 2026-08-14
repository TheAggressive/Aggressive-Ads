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
}
