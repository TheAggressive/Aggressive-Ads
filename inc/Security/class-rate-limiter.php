<?php
/**
 * Bounding the cost of abuse.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Security;

use LAAO_Advertiser_Portal\Audit\Audit_Event;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use WP_Error;

/**
 * A per-user fixed-window limit on expensive operations.
 *
 * **The goal is to bound the cost of abuse, not to police normal use.** An
 * advertiser correcting a rejected campaign at 11pm must never meet a limit,
 * so the numbers are deliberately generous — an order of magnitude above what
 * the product asks anyone to do.
 *
 * A fixed window rather than a sliding one: the arithmetic is obvious at 2am,
 * and the difference only matters to someone deliberately pacing themselves
 * against the boundary, who is already bounded by the limit either way.
 */
final class Rate_Limiter {

	public const ACTION_UPLOAD     = 'upload';
	public const ACTION_TRANSITION = 'transition';
	public const ACTION_AUTOSAVE   = 'autosave';

	/**
	 * Limits per action, as attempts per window.
	 *
	 * @var array<string, array{limit: int, window: int}>
	 */
	private const LIMITS = array(
		self::ACTION_UPLOAD     => array(
			'limit'  => 30,
			'window' => HOUR_IN_SECONDS,
		),
		self::ACTION_TRANSITION => array(
			'limit'  => 20,
			'window' => HOUR_IN_SECONDS,
		),
		self::ACTION_AUTOSAVE   => array(
			'limit'  => 120,
			'window' => HOUR_IN_SECONDS,
		),
	);

	/**
	 * Constructor.
	 *
	 * @param Audit_Repository $audit Audit persistence.
	 */
	public function __construct( private readonly Audit_Repository $audit ) {
	}

	/**
	 * Records an attempt, and refuses once the window's allowance is spent.
	 *
	 * @param string $action  One of the ACTION_* constants.
	 * @param int    $user_id Acting user.
	 * @return true|WP_Error
	 */
	public function attempt( string $action, int $user_id ): bool|WP_Error {
		if ( ! isset( self::LIMITS[ $action ] ) || $user_id <= 0 ) {
			return true;
		}

		$limit  = self::LIMITS[ $action ]['limit'];
		$window = self::LIMITS[ $action ]['window'];
		$key    = $this->key( $action, $user_id );
		$now    = time();

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
			$this->audit->insert(
				new Audit_Event(
					event: 'rate_limit.exceeded',
					outcome: Audit_Event::OUTCOME_DENIED,
					object_type: 'user',
					object_id: $user_id,
					message: sprintf( 'Rate limit reached for %s.', $action ),
					context: array(
						'action' => $action,
						'limit'  => $limit,
					),
					actor_user_id: $user_id
				)
			);

			return new WP_Error(
				'laao_ads_rate_limited',
				__( 'That is more requests than we can accept right now. Please wait a moment and try again.', 'laao-advertiser-portal' ),
				array(
					'status'      => 429,
					'retry_after' => max( 1, $reset - $now ),
				)
			);
		}

		set_transient(
			$key,
			array(
				'count' => $count + 1,
				'reset' => $reset,
			),
			max( 1, $reset - $now )
		);

		return true;
	}

	/**
	 * How many attempts remain in the current window.
	 *
	 * @param string $action  One of the ACTION_* constants.
	 * @param int    $user_id Acting user.
	 * @return int
	 */
	public function remaining( string $action, int $user_id ): int {
		if ( ! isset( self::LIMITS[ $action ] ) ) {
			return PHP_INT_MAX;
		}

		$state = get_transient( $this->key( $action, $user_id ) );

		if ( ! is_array( $state ) || ! isset( $state['count'], $state['reset'] ) || time() >= (int) $state['reset'] ) {
			return self::LIMITS[ $action ]['limit'];
		}

		return max( 0, self::LIMITS[ $action ]['limit'] - (int) $state['count'] );
	}

	/**
	 * The limit for an action, for tests and for response headers.
	 *
	 * @param string $action One of the ACTION_* constants.
	 * @return int
	 */
	public static function limit_for( string $action ): int {
		return self::LIMITS[ $action ]['limit'] ?? PHP_INT_MAX;
	}

	/**
	 * The transient key for one user's counter.
	 *
	 * @param string $action  Action name.
	 * @param int    $user_id Acting user.
	 * @return string
	 */
	private function key( string $action, int $user_id ): string {
		return 'laao_ads_rl_' . $action . '_' . $user_id;
	}
}
