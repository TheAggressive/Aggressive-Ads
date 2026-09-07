<?php
/**
 * When an acting-as session is live.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * The lifetime rule for staff acting on an advertiser's behalf.
 *
 * `HOUR_IN_SECONDS` is deliberately not used: it is a WordPress constant, and
 * this layer must load without WordPress.
 */
final class Acting_Session {

	/**
	 * How long a session lasts without being renewed.
	 *
	 * Long enough for a support call, short enough that a forgotten session
	 * does not survive the day.
	 */
	public const LIFETIME = 4 * 60 * 60;

	/**
	 * When a session started now would lapse.
	 *
	 * @param int $now Current unix timestamp.
	 * @return int
	 */
	public static function expires_at( int $now ): int {
		return $now + self::LIFETIME;
	}

	/**
	 * Whether a session with this expiry is still live.
	 *
	 * Exclusive: a stamp equal to the current second has lapsed. The cost of
	 * erring towards ended is one re-entry, against a session outliving its
	 * window.
	 *
	 * @param int $expires_at When the session lapses.
	 * @param int $now        Current unix timestamp.
	 * @return bool
	 */
	public static function is_live( int $expires_at, int $now ): bool {
		return $expires_at > $now;
	}
}
