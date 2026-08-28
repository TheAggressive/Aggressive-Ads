<?php
/**
 * Pure measurement validation and lifecycle rules.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Validates measurement event lineage, transition validity, and cryptographic digests.
 * Pure domain model without WordPress dependencies.
 */
final class Measurement_Rules {

	public const MAX_TIMESTAMP_SKEW_SECONDS = 86400; // 24 hours

	/**
	 * Validates whether a token hash is formatted properly (64-character lowercase hex digest).
	 *
	 * @param string $token_hash Token hash string.
	 * @return bool
	 */
	public static function is_valid_token_hash( string $token_hash ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $token_hash );
	}

	/**
	 * Validates whether an IP hash is formatted properly (64-character lowercase hex digest).
	 *
	 * @param string $ip_hash IP hash string.
	 * @return bool
	 */
	public static function is_valid_ip_hash( string $ip_hash ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $ip_hash );
	}

	/**
	 * Validates whether an event timestamp is within acceptable skew bounds relative to current time.
	 *
	 * @param int $event_ts Timestamp of the event in seconds.
	 * @param int $now      Current reference timestamp in seconds.
	 * @return bool
	 */
	public static function is_valid_timestamp( int $event_ts, int $now ): bool {
		if ( $event_ts <= 0 || $now <= 0 ) {
			return false;
		}

		$diff = abs( $now - $event_ts );

		return $diff <= self::MAX_TIMESTAMP_SKEW_SECONDS;
	}

	/**
	 * Checks if an event transition from parent to child is allowed in the lifecycle model.
	 *
	 * @param string $parent_type Parent event type.
	 * @param string $child_type  Child event type.
	 * @return bool
	 */
	public static function is_valid_transition( string $parent_type, string $child_type ): bool {
		$parent = Measurement_Event_Type::normalize( $parent_type );
		$child  = Measurement_Event_Type::normalize( $child_type );

		if ( null === $parent || null === $child ) {
			return false;
		}

		$allowed_children = match ( $parent ) {
			Measurement_Event_Type::TYPE_REQUEST => array(
				Measurement_Event_Type::TYPE_FILL,
				Measurement_Event_Type::TYPE_NO_FILL,
			),
			Measurement_Event_Type::TYPE_FILL => array(
				Measurement_Event_Type::TYPE_SERVED,
			),
			Measurement_Event_Type::TYPE_SERVED => array(
				Measurement_Event_Type::TYPE_VIEWABLE,
				Measurement_Event_Type::TYPE_CLICK,
			),
			Measurement_Event_Type::TYPE_VIEWABLE,
			Measurement_Event_Type::TYPE_CLICK => array(
				Measurement_Event_Type::TYPE_CONVERSION,
			),
			default => array(),
		};

		return in_array( $child, $allowed_children, true );
	}
}
