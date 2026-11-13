<?php
/**
 * Atomic rate-limit counter claims.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Repository\Rate_Limit_Repository;
use WP_UnitTestCase;

/**
 * Counter decisions are made inside one serialized repository operation.
 */
final class RateLimitRepositoryTest extends WP_UnitTestCase {

	/**
	 * One claim increments exactly once and refuses beyond the limit.
	 *
	 * @return void
	 */
	public function test_claim_serializes_increment_and_limit_decision(): void {
		$key        = 'aggr_test_atomic_rate_limit';
		$repository = new Rate_Limit_Repository();
		$now        = time();

		delete_transient( $key );

		$first  = $repository->claim( $key, 2, HOUR_IN_SECONDS, $now );
		$second = $repository->claim( $key, 2, HOUR_IN_SECONDS, $now );
		$denied = $repository->claim( $key, 2, HOUR_IN_SECONDS, $now );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertIsArray( $denied );
		$this->assertTrue( $first['allowed'] );
		$this->assertSame( 1, $first['count'] );
		$this->assertTrue( $second['allowed'] );
		$this->assertSame( 2, $second['count'] );
		$this->assertFalse( $denied['allowed'] );
		$this->assertSame( 2, $denied['count'] );

		delete_transient( $key );
	}

	/**
	 * Failed counter reads and writes cannot grant an unrecorded attempt.
	 *
	 * @dataProvider storage_failure_cases
	 * @param string $operation Query operation to fail.
	 * @param int    $existing Previously consumed attempts.
	 */
	public function test_database_failure_refuses_and_recovers( string $operation, int $existing ): void {
		global $wpdb;
		$key        = 'aggr_test_storage_' . wp_generate_uuid4();
		$repository = new Rate_Limit_Repository();
		$now        = time();
		$external   = wp_using_ext_object_cache();
		$failures   = 0;
		wp_using_ext_object_cache( false );
		if ( $existing > 0 ) {
			$this->assertTrue(
				set_transient(
					$key,
					array(
						'count' => $existing,
						'reset' => $now + HOUR_IN_SECONDS,
					),
					HOUR_IN_SECONDS
				)
			);
		}
		$this->evict_counter_options( $key );
		$listener   = static function ( string $query ) use ( $wpdb, $key, $operation, &$failures ): string {
			if ( str_contains( $query, "'_transient_" . $key . "'" ) && 1 === preg_match( '/\A' . $operation . '\b/i', $query ) ) {
				++$failures;
				return $wpdb->prepare( 'SELECT %i FROM %i', 'aggr_missing_counter_column', $wpdb->options );
			}
			return $query;
		};
		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $listener );
		try {
			$failed = $repository->claim( $key, 2, HOUR_IN_SECONDS, $now );
		} finally {
			remove_filter( 'query', $listener );
			$wpdb->suppress_errors( $suppressed );
			$this->evict_counter_options( $key );
		}
		try {
			$this->assertGreaterThan( 0, $failures, 'The production SQL must reach the injected failure.' );
			$this->assertNull( $failed, 'An unavailable counter cannot grant permission.' );
			$this->assertSame( $existing, (int) ( get_transient( $key )['count'] ?? 0 ), 'Failure must not overwrite the durable allowance.' );
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', 'aggr_rl_' . substr( hash( 'sha256', $key ), 0, 48 ) ) ), 'The advisory lock must be released on failure.' );
			$recovered = $repository->claim( $key, 2, HOUR_IN_SECONDS, $now );
			$this->assertIsArray( $recovered );
			$this->assertTrue( $recovered['allowed'] );
			$this->assertSame( $existing + 1, $recovered['count'] );
			$this->evict_counter_options( $key );
			$this->assertSame( $existing + 1, get_transient( $key )['count'], 'Recovery must be persisted, not just returned.' );
		} finally {
			delete_transient( $key );
			wp_using_ext_object_cache( $external );
		}
	}

	/**
	 * Failure paths for a new and an existing counter.
	 *
	 * @return array<string, array{string, int}>
	 */
	public static function storage_failure_cases(): array {
		return array(
			'initial insert' => array( 'INSERT', 0 ),
			'counter update' => array( 'UPDATE', 1 ),
			'initial read'   => array( 'SELECT', 0 ),
			'existing read'  => array( 'SELECT', 1 ),
		);
	}

	/** Emulate the next request reading durable options rather than this request's cache. */
	private function evict_counter_options( string $key ): void {
		wp_cache_delete( '_transient_' . $key, 'options' );
		wp_cache_delete( '_transient_timeout_' . $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/** Persistent cache uses atomic increment without database advisory locks. */
	public function test_persistent_cache_claim_is_atomic_and_avoids_database_locks(): void {
		$key        = 'aggr_test_cached_rate_limit_' . wp_generate_uuid4();
		$repository = new Rate_Limit_Repository();
		$now        = time();
		$bucket     = $key . '_' . intdiv( $now, HOUR_IN_SECONDS );
		$queries    = array();
		$external   = wp_using_ext_object_cache();

		$listener = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;

			return $query;
		};

		wp_using_ext_object_cache( true );
		wp_cache_delete( $bucket, 'aggr_rate_limits' );
		add_filter( 'query', $listener );

		try {
			$first  = $repository->claim( $key, 2, HOUR_IN_SECONDS, $now );
			$second = $repository->claim( $key, 2, HOUR_IN_SECONDS, $now );
			$denied = $repository->claim( $key, 2, HOUR_IN_SECONDS, $now );
		} finally {
			remove_filter( 'query', $listener );
			wp_cache_delete( $bucket, 'aggr_rate_limits' );
			wp_using_ext_object_cache( $external );
		}

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertIsArray( $denied );
		$this->assertSame( 1, $first['count'] );
		$this->assertSame( 2, $second['count'] );
		$this->assertFalse( $denied['allowed'] );
		$this->assertSame( 3, $denied['count'] );
		$this->assertSame( 0, count( preg_grep( '/(?:GET|RELEASE)_LOCK/', $queries ) ) );
	}
}
