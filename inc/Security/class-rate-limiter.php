<?php
/**
 * Bounding the cost of abuse.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Security;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Rate_Limit_Repository;
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

	public const ACTION_UPLOAD         = 'upload';
	public const ACTION_TRANSITION     = 'transition';
	public const ACTION_AUTOSAVE       = 'autosave';
	public const ACTION_COPY           = 'copy';
	public const ACTION_LOGIN          = 'login';
	public const ACTION_SIGNUP         = 'signup';
	public const ACTION_PASSWORD_RESET = 'password_reset';
	public const ACTION_ORG_INVITE     = 'org_invite';
	public const ACTION_EMAIL_CHANGE   = 'email_change';
	public const ACTION_BEACON         = 'beacon';
	public const ACTION_CLICK          = 'click';

	/**
	 * Limits per action, as attempts per window.
	 *
	 * @var array<string, array{limit: int, window: int}>
	 */
	private const LIMITS = array(
		self::ACTION_UPLOAD         => array(
			'limit'  => 30,
			'window' => HOUR_IN_SECONDS,
		),
		self::ACTION_TRANSITION     => array(
			'limit'  => 20,
			'window' => HOUR_IN_SECONDS,
		),
		self::ACTION_AUTOSAVE       => array(
			'limit'  => 120,
			'window' => HOUR_IN_SECONDS,
		),
		self::ACTION_COPY           => array(
			'limit'  => 20,
			'window' => HOUR_IN_SECONDS,
		),

		/*
		 * Counted per client rather than per user, because a login attempt has
		 * no user until it succeeds. Generous enough that a person mistyping a
		 * password on a shared office connection is never locked out, tight
		 * enough that credential stuffing is not worth the round trips.
		 */
		self::ACTION_LOGIN          => array(
			'limit'  => 20,
			'window' => 15 * MINUTE_IN_SECONDS,
		),
		self::ACTION_SIGNUP         => array(
			'limit'  => 5,
			'window' => HOUR_IN_SECONDS,
		),
		self::ACTION_PASSWORD_RESET => array(
			'limit'  => 5,
			'window' => HOUR_IN_SECONDS,
		),
		self::ACTION_ORG_INVITE     => array(
			'limit'  => 20,
			'window' => DAY_IN_SECONDS,
		),
		self::ACTION_EMAIL_CHANGE   => array(
			'limit'  => 5,
			'window' => HOUR_IN_SECONDS,
		),

		/*
		 * Counted per client. Fill GET is cached and high-volume, so it is
		 * not limited. Beacon and click writes are.
		 */
		self::ACTION_BEACON         => array(
			'limit'  => 300,
			'window' => HOUR_IN_SECONDS,
		),
		self::ACTION_CLICK          => array(
			'limit'  => 120,
			'window' => HOUR_IN_SECONDS,
		),
	);

	/**
	 * Constructor.
	 *
	 * @param Audit_Repository      $audit    Audit persistence.
	 * @param Rate_Limit_Repository $counters Atomic counter persistence.
	 */
	public function __construct(
		private readonly Audit_Repository $audit,
		private readonly Rate_Limit_Repository $counters
	) {
	}

	/**
	 * Records an attempt, and refuses once the window's allowance is spent.
	 *
	 * @param string $action  One of the ACTION_* constants.
	 * @param int    $user_id Acting user.
	 * @return true|WP_Error
	 */
	public function attempt( string $action, int $user_id ): bool|WP_Error {
		if ( $user_id <= 0 ) {
			return true;
		}

		return $this->attempt_for( $action, (string) $user_id, $user_id );
	}

	/**
	 * Counts an attempt against an arbitrary subject.
	 *
	 * Exists for actions that happen before there is a user to count against:
	 * signing in, signing up and requesting password recovery. The subject is a hashed client
	 * identifier, never a raw address — a rate-limit transient is not a place
	 * to accumulate a log of who visited from where.
	 *
	 * @param string $action  One of the ACTION_* constants.
	 * @param string $subject Opaque subject identifier.
	 * @param int    $user_id Acting user, or 0 when there is none yet.
	 * @return true|WP_Error
	 */
	public function attempt_for( string $action, string $subject, int $user_id = 0 ): bool|WP_Error {
		if ( ! isset( self::LIMITS[ $action ] ) || '' === $subject ) {
			return true;
		}

		$limit  = self::LIMITS[ $action ]['limit'];
		$window = self::LIMITS[ $action ]['window'];
		$key    = $this->key( $action, $subject );
		$now    = time();

		$claimed = $this->counters->claim( $key, $limit, $window, $now );

		if ( null === $claimed ) {
			return new WP_Error(
				'aggr_rate_limit_unavailable',
				__( 'This request could not be safely accepted. Please try again.', 'aggressive-ads' ),
				array( 'status' => 503 )
			);
		}

		if ( ! $claimed['allowed'] ) {
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
				'aggr_rate_limited',
				__( 'That is more requests than we can accept right now. Please wait a moment and try again.', 'aggressive-ads' ),
				array(
					'status'      => 429,
					'retry_after' => max( 1, $claimed['reset'] - $now ),
				)
			);
		}

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
		return $this->remaining_for( $action, (string) $user_id );
	}

	/**
	 * How many attempts remain for an arbitrary subject.
	 *
	 * @param string $action  One of the ACTION_* constants.
	 * @param string $subject Opaque subject identifier.
	 * @return int
	 */
	public function remaining_for( string $action, string $subject ): int {
		if ( ! isset( self::LIMITS[ $action ] ) ) {
			return PHP_INT_MAX;
		}

		$state = get_transient( $this->key( $action, $subject ) );

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
	 * The transient key for one subject's counter.
	 *
	 * @param string $action  Action name.
	 * @param string $subject Subject identifier.
	 * @return string
	 */
	private function key( string $action, string $subject ): string {
		$blog_id = get_current_blog_id();
		$blog_id = $blog_id > 0 ? $blog_id : 1;

		return 'aggr_rl_' . $blog_id . '_' . $action . '_' . $subject;
	}

	/**
	 * An opaque, stable identifier for the current client.
	 *
	 * Hashed with wp_hash() so the stored value cannot be reversed into an
	 * address: this is a counter, not a visitor log, and a plugin that quietly
	 * accumulates IP addresses in the options table is a data-protection
	 * problem nobody asked for.
	 *
	 * REMOTE_ADDR only. The forwarded-for headers are attacker-controlled
	 * unless a known proxy is in front, and trusting them by default would let
	 * anyone reset their own counter by inventing a header.
	 *
	 * @return string
	 */
	public static function client_subject(): string {
		/*
		 * Both sniffs on this line are answered by the lines below it, and
		 * neither can see that from where it stands.
		 *
		 * The cache sniff guards against varying cached page output by address;
		 * this value never reaches a response, only a counter key, and the
		 * requests it counts are POSTs that are uncacheable by definition. The
		 * user-controlled-header sniff asks for validation, which is exactly
		 * what filter_var() below does — it fires on any read of REMOTE_ADDR
		 * regardless, because it cannot follow the value.
		 *
		 * There is no pattern to fix here: identifying an anonymous client is
		 * the one thing rate limiting a login form requires.
		 */
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders
		$raw = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/*
		 * Validated as an address, not merely sanitized.
		 *
		 * Anything that is not one falls into a single shared bucket rather
		 * than minting its own key. That is the fail-safe direction: a client
		 * the server cannot identify gets a stricter allowance, never an
		 * unlimited one, and no amount of junk in the header can manufacture
		 * fresh counters.
		 */
		$address = filter_var( $raw, FILTER_VALIDATE_IP );

		if ( ! is_string( $address ) ) {
			return 'ip_unknown';
		}

		return 'ip_' . substr( wp_hash( $address ), 0, 32 );
	}
}
