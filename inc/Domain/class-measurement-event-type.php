<?php
/**
 * Canonical measurement event types.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Closed vocabulary of lifecycle events for ad delivery and interaction.
 * Pure domain model without WordPress dependencies.
 */
final class Measurement_Event_Type {

	public const TYPE_REQUEST    = 'request';
	public const TYPE_FILL       = 'fill';
	public const TYPE_NO_FILL    = 'no_fill';
	public const TYPE_SERVED     = 'served';
	public const TYPE_VIEWABLE   = 'viewable';
	public const TYPE_CLICK      = 'click';
	public const TYPE_CONVERSION = 'conversion';

	/**
	 * Legacy alias mapping to preserve backward compatibility.
	 */
	public const LEGACY_IMPRESSION = 'impression';

	/**
	 * All canonical event types.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::TYPE_REQUEST,
			self::TYPE_FILL,
			self::TYPE_NO_FILL,
			self::TYPE_SERVED,
			self::TYPE_VIEWABLE,
			self::TYPE_CLICK,
			self::TYPE_CONVERSION,
		);
	}

	/**
	 * Normalizes incoming event strings, mapping legacy aliases to canonical types.
	 *
	 * @param string $event Incoming event name.
	 * @return string|null Canonical event type, or null if unrecognized.
	 */
	public static function normalize( string $event ): ?string {
		if ( self::LEGACY_IMPRESSION === $event ) {
			return self::TYPE_SERVED;
		}

		if ( in_array( $event, self::all(), true ) ) {
			return $event;
		}

		return null;
	}

	/**
	 * Whether an event is valid (either canonical or supported legacy alias).
	 *
	 * @param string $event Event string to test.
	 * @return bool
	 */
	public static function is_valid( string $event ): bool {
		return null !== self::normalize( $event );
	}
}
