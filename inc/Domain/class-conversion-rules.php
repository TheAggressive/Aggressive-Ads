<?php
/**
 * What counts as a conversion, and when it still counts.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Pure attribution and idempotency rules.
 *
 * Every one of these is a boundary the storage layer cannot enforce for itself.
 * The unique key refuses a duplicate, but only after MySQL has decided what the
 * key *is* — and a column that silently truncates its input turns two different
 * outcomes into one duplicate. So the shapes are checked here, before a write,
 * and the column widths below are the ones this class is guarding.
 */
final class Conversion_Rules {

	/** Reported by a browser on the advertiser's page. */
	public const SOURCE_BROWSER = 'browser';

	/** Reported by the advertiser's server under a scoped credential. */
	public const SOURCE_SERVER = 's2s';

	/**
	 * The idempotency key column is `varchar(64)`.
	 *
	 * The ceiling is not a style preference. MySQL outside strict mode
	 * truncates an over-long value on write rather than refusing it, so a
	 * sixty-fifth character would be dropped and two genuinely different
	 * outcomes would collapse onto one key — the second silently refused as a
	 * duplicate, which is an undercount nobody can see. Refusing the key is the
	 * honest failure; truncating it is not.
	 */
	public const MAX_IDEMPOTENCY_KEY_LENGTH = 64;

	/**
	 * And a floor, for the mirror-image reason.
	 *
	 * A key of one or two characters collides by accident across unrelated
	 * outcomes within the same definition, and the collision is spent the same
	 * way a replay is: refused, uncounted, unreported. Eight characters is
	 * shorter than any order id or UUID a real integration sends and long
	 * enough that an accidental collision is a choice rather than a surprise.
	 */
	public const MIN_IDEMPOTENCY_KEY_LENGTH = 8;

	/**
	 * Thirty days, the ordinary click-through window.
	 *
	 * Written in seconds rather than as `30 * DAY_IN_SECONDS`, because this
	 * namespace has no WordPress: the unit suite runs without a bootstrap, and
	 * a core constant here would be an undefined one there.
	 */
	public const DEFAULT_WINDOW_SECONDS = 2592000;

	/**
	 * An hour is the shortest window that measures anything; ninety days is
	 * the ceiling, and it exists so a typo cannot configure a window that
	 * outlives the event retention it attributes against.
	 */
	public const MIN_WINDOW_SECONDS = 3600;
	public const MAX_WINDOW_SECONDS = 7776000;

	/**
	 * Value is stored in millionths of a currency unit.
	 *
	 * Integers, because money in a float loses cents on the way in and nobody
	 * notices until a total is off. Micros rather than cents because a CPC
	 * value is routinely smaller than a cent, and rounding it to one at ingest
	 * would make every small conversion worth either nothing or too much.
	 */
	public const MICROS_PER_UNIT = 1000000;

	/**
	 * Roughly nine trillion currency units. Far past any real conversion, and
	 * short of the `bigint(20) unsigned` ceiling, so a hostile value is refused
	 * rather than wrapping.
	 */
	public const MAX_VALUE_MICROS = 9000000000000000000;

	/**
	 * Whether an idempotency key is usable as one.
	 *
	 * The charset is what real reporters send — order ids, UUIDs, WooCommerce
	 * order keys — and nothing else. It is deliberately not "any string": the
	 * key is half of a database unique index, and an unconstrained one is an
	 * unconstrained index.
	 *
	 * @param string $key Client-supplied key.
	 */
	public static function is_valid_idempotency_key( string $key ): bool {
		$length = strlen( $key );

		if ( $length < self::MIN_IDEMPOTENCY_KEY_LENGTH || $length > self::MAX_IDEMPOTENCY_KEY_LENGTH ) {
			return false;
		}

		/*
		 * `\z`, not `$`. PCRE's `$` matches before a trailing newline, so
		 * `/^[A-Za-z0-9._:-]+$/` accepts "order1099\n" — which then reaches a
		 * `varchar(64)` unique index carrying whitespace no reporter meant to
		 * send, and compares unequal to the same key sent without it. Found by
		 * this class's own test on its first run.
		 */
		return 1 === preg_match( '/^[A-Za-z0-9._:-]+\z/', $key );
	}

	/**
	 * Whether an interaction may be attributed a conversion at all.
	 *
	 * Delegates to the lifecycle rather than restating it. P10 already decided
	 * that a conversion follows a click or a view; a second list here would be
	 * a second answer to one question, and the two would drift.
	 *
	 * @param string $event Canonical event type of the attributed interaction.
	 */
	public static function is_attributable_event( string $event ): bool {
		return Measurement_Rules::is_valid_transition( $event, Measurement_Event_Type::TYPE_CONVERSION );
	}

	/**
	 * Whether a conversion falls inside the window opened by its interaction.
	 *
	 * Measured from the interaction the *server* recorded, never from the fill
	 * token's expiry. `Fill_Token::TTL_SECONDS` is five minutes and bounds when
	 * reporting may start; an attribution window is days and bounds how long it
	 * remains true. Reading one as the other would expire every conversion.
	 *
	 * Inclusive at the boundary: a conversion at exactly the window's last
	 * second counts. An advertiser told "thirty days" is owed thirty days.
	 *
	 * @param int $interaction_ts  Server-recorded click or view, Unix seconds.
	 * @param int $conversion_ts   When the outcome happened, Unix seconds.
	 * @param int $window_seconds  The definition's window.
	 */
	public static function is_within_window( int $interaction_ts, int $conversion_ts, int $window_seconds ): bool {
		if ( $interaction_ts <= 0 || $conversion_ts <= 0 || $window_seconds <= 0 ) {
			return false;
		}

		// A conversion before its own cause is not early, it is wrong. Clock
		// skew on a reporter's server is the usual source, and attributing it
		// would let a backdated report claim a click that had not happened.
		if ( $conversion_ts < $interaction_ts ) {
			return false;
		}

		return ( $conversion_ts - $interaction_ts ) <= $window_seconds;
	}

	/**
	 * Clamps a configured window into the range attribution can honour.
	 *
	 * Clamped rather than refused, matching how every other stored setting
	 * behaves: a value already saved must still produce a working window rather
	 * than switching attribution off.
	 *
	 * @param mixed $value Configured seconds.
	 */
	public static function window_seconds( mixed $value ): int {
		if ( ! is_numeric( $value ) ) {
			return self::DEFAULT_WINDOW_SECONDS;
		}

		return max( self::MIN_WINDOW_SECONDS, min( self::MAX_WINDOW_SECONDS, (int) $value ) );
	}

	/**
	 * ISO 4217, uppercase, three letters. Stored in `char(3)`.
	 *
	 * @param string $code Currency code.
	 */
	public static function is_valid_currency( string $code ): bool {
		// `\z` for the reason on is_valid_idempotency_key(): `$` would accept
		// "USD\n" into a char(3) column that cannot hold it.
		return 1 === preg_match( '/^[A-Z]{3}\z/', $code );
	}

	/**
	 * Whether a value in micros is storable.
	 *
	 * Zero is valid and common: a signup conversion has no money attached, and
	 * refusing it would make "no value" indistinguishable from "not reported".
	 *
	 * @param int $micros Value in millionths of a currency unit.
	 */
	public static function is_valid_value_micros( int $micros ): bool {
		return $micros >= 0 && $micros <= self::MAX_VALUE_MICROS;
	}

	/**
	 * Whether an ingestion source is one this plugin accepts.
	 *
	 * @param string $source Reported source.
	 */
	public static function is_valid_source( string $source ): bool {
		return in_array( $source, array( self::SOURCE_BROWSER, self::SOURCE_SERVER ), true );
	}
}
