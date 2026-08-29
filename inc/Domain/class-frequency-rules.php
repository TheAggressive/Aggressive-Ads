<?php
/**
 * Frequency capping rules.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

use Throwable;

/**
 * Pure domain logic evaluating candidate frequency limits against visitor counts.
 */
final class Frequency_Rules {

	public const LEVEL_CAMPAIGN  = 'campaign';
	public const LEVEL_LINE_ITEM = 'line_item';
	public const LEVEL_CREATIVE  = 'creative';

	public const WINDOW_SESSION = 'session';
	public const WINDOW_HOUR    = 'hour';
	public const WINDOW_DAY     = 'day';
	public const WINDOW_CUSTOM  = 'custom';

	public const SECONDS_HOUR = 3600;
	public const SECONDS_DAY  = 86400;

	/**
	 * Evaluates candidate frequency caps against the frequency store.
	 *
	 * @param array<string, mixed> $row     Candidate row.
	 * @param Decision_Context     $context Decision context containing request facts.
	 * @param Frequency_Store      $store   Frequency count store.
	 * @return string|null Exclusion reason or null.
	 */
	public static function evaluate_candidate( array $row, Decision_Context $context, Frequency_Store $store ): ?string {
		$config = self::extract_frequency_config( $row );

		if ( null === $config || empty( $config['enabled'] ) ) {
			return null;
		}

		$max_impressions = (int) ( $config['max_impressions'] ?? 0 );
		if ( $max_impressions <= 0 ) {
			return null;
		}

		$visitor_id = self::extract_visitor_id( $context );
		if ( '' === $visitor_id ) {
			// No visitor identifier available -> cannot cap frequency safely, allow delivery.
			return null;
		}

		try {
			$level     = (string) ( $config['level'] ?? self::LEVEL_LINE_ITEM );
			$entity_id = self::resolve_entity_id( $level, $row );
			$window    = (string) ( $config['window'] ?? self::WINDOW_DAY );

			$key   = self::build_key( $level, $entity_id, $visitor_id, $window, $context->now, $config );
			$count = $store->get_count( $key );

			if ( $count >= $max_impressions ) {
				return Exclusion_Reason::FREQUENCY_CAPPED;
			}

			return null;
		} catch ( Throwable ) {
			return Exclusion_Reason::FREQUENCY_STAGE_ERROR;
		}
	}

	/**
	 * Whether a frequency policy is one this engine can enforce.
	 *
	 * Refused at the write boundary rather than ignored at serve time. A policy
	 * naming a window or level the evaluator does not know would read as "no
	 * cap" — indistinguishable from having configured nothing, which is the
	 * shape of bug that let this whole stage sit inert.
	 *
	 * @param mixed $config Decoded frequency policy.
	 * @return array<int, string> Human-readable problems; empty when valid.
	 */
	public static function validate( mixed $config ): array {
		if ( ! is_array( $config ) ) {
			return array( 'Frequency policy must be an object.' );
		}

		// No policy is the default and valid; capping is opt-in.
		if ( array() === $config ) {
			return array();
		}

		$errors = array();
		$window = (string) ( $config['window'] ?? self::WINDOW_DAY );
		$level  = (string) ( $config['level'] ?? self::LEVEL_LINE_ITEM );

		if ( ! in_array( $window, array( self::WINDOW_SESSION, self::WINDOW_HOUR, self::WINDOW_DAY, self::WINDOW_CUSTOM ), true ) ) {
			$errors[] = sprintf( '"%s" is not a frequency window.', $window );
		}

		if ( ! in_array( $level, array( self::LEVEL_CAMPAIGN, self::LEVEL_LINE_ITEM, self::LEVEL_CREATIVE ), true ) ) {
			$errors[] = sprintf( '"%s" is not a capping level.', $level );
		}

		$max = $config['max_impressions'] ?? null;

		if ( ! empty( $config['enabled'] ) && ( ! is_int( $max ) || $max <= 0 ) ) {
			$errors[] = 'An enabled frequency policy needs a positive whole "max_impressions".';
		}

		if ( self::WINDOW_CUSTOM === $window ) {
			$seconds = $config['window_seconds'] ?? null;

			if ( ! is_int( $seconds ) || $seconds <= 0 ) {
				$errors[] = 'A custom window needs a positive whole "window_seconds".';
			}
		}

		return $errors;
	}

	/**
	 * Counts one delivery against every cap that applies to it.
	 *
	 * The half that was missing: `evaluate_candidate()` read a counter nothing
	 * ever wrote, so no candidate was capped however the policy was configured.
	 * Called when an ad is actually served, not when it is decided, so a staff
	 * trace does not spend a visitor's impressions.
	 *
	 * @param array<string, mixed> $row     Winning candidate row.
	 * @param Decision_Context     $context Decision context.
	 * @param Frequency_Store      $store   Frequency count store.
	 * @param int                  $now     Evaluation time in UTC seconds.
	 * @return bool Whether a cap was counted.
	 */
	public static function record_delivery( array $row, Decision_Context $context, Frequency_Store $store, int $now ): bool {
		$config = self::extract_frequency_config( $row );

		if ( null === $config || empty( $config['enabled'] ) ) {
			return false;
		}

		if ( (int) ( $config['max_impressions'] ?? 0 ) <= 0 ) {
			return false;
		}

		$visitor_id = self::extract_visitor_id( $context );

		if ( '' === $visitor_id ) {
			return false;
		}

		try {
			$level     = (string) ( $config['level'] ?? self::LEVEL_LINE_ITEM );
			$window    = (string) ( $config['window'] ?? self::WINDOW_DAY );
			$entity_id = self::resolve_entity_id( $level, $row );
			$key       = self::build_key( $level, $entity_id, $visitor_id, $window, $now, $config );

			$store->increment( $key, self::resolve_window_ttl( $config ) );

			return true;
		} catch ( Throwable ) {
			// Storage failures fail open: losing a count serves one ad too many,
			// while throwing here would lose the ad entirely.
			return false;
		}
	}

	/**
	 * Builds an opaque frequency storage key.
	 *
	 * @param string               $level      Scope level.
	 * @param int                  $entity_id  Entity ID.
	 * @param string               $visitor_id Ephemeral visitor token.
	 * @param string               $window     Time window.
	 * @param int                  $now        Evaluation time in UTC seconds.
	 * @param array<string, mixed> $config     Frequency configuration.
	 * @return string
	 */
	public static function build_key(
		string $level,
		int $entity_id,
		string $visitor_id,
		string $window,
		int $now = 0,
		array $config = array()
	): string {
		return sprintf(
			'%s:%d:%s:%s:%s',
			$level,
			$entity_id,
			$window,
			self::window_bucket( $window, $now, $config ),
			hash( 'sha256', $visitor_id )
		);
	}

	/**
	 * The fixed window this instant falls in.
	 *
	 * Without it the key is stable and the window is enforced only by the
	 * store's TTL, which every write refreshes — so an "hourly" cap never
	 * expires for a visitor who sees an ad at least once an hour, and behaves
	 * as a lifetime cap. Bucketing makes the boundary absolute: the key changes
	 * on the hour whatever the traffic looks like.
	 *
	 * A session is already scoped by its own identifier and has no clock.
	 *
	 * @param string               $window Window name.
	 * @param int                  $now    Evaluation time in UTC seconds.
	 * @param array<string, mixed> $config Frequency configuration.
	 * @return string
	 */
	public static function window_bucket( string $window, int $now, array $config = array() ): string {
		if ( self::WINDOW_SESSION === $window ) {
			return 'session';
		}

		$ttl = self::resolve_window_ttl( array( 'window' => $window ) + $config );

		return (string) intdiv( max( 0, $now ), max( 1, $ttl ) );
	}

	/**
	 * Resolves TTL in seconds for a window.
	 *
	 * @param array<string, mixed> $config Frequency configuration.
	 */
	public static function resolve_window_ttl( array $config ): int {
		$window = (string) ( $config['window'] ?? self::WINDOW_DAY );

		return match ( $window ) {
			self::WINDOW_HOUR => self::SECONDS_HOUR,
			self::WINDOW_DAY => self::SECONDS_DAY,
			self::WINDOW_CUSTOM => max( 1, (int) ( $config['window_seconds'] ?? self::SECONDS_DAY ) ),
			default => self::SECONDS_DAY,
		};
	}

	/**
	 * Resolves the relevant entity ID based on capping level.
	 *
	 * @param string               $level Level name.
	 * @param array<string, mixed> $row   Candidate row.
	 */
	public static function resolve_entity_id( string $level, array $row ): int {
		return match ( $level ) {
			self::LEVEL_CAMPAIGN => (int) ( $row['campaign_id'] ?? 0 ),
			self::LEVEL_CREATIVE => (int) ( $row['revision_id'] ?? $row['asset_id'] ?? 0 ),
			default => (int) ( $row['line_item_id'] ?? $row['id'] ?? 0 ),
		};
	}

	/**
	 * Extracts visitor ID from context facts.
	 *
	 * @param Decision_Context $context Decision context.
	 */
	public static function extract_visitor_id( Decision_Context $context ): string {
		$facts = $context->facts;

		if ( isset( $facts['visitor_id'] ) && is_string( $facts['visitor_id'] ) && '' !== trim( $facts['visitor_id'] ) ) {
			return trim( $facts['visitor_id'] );
		}

		if ( isset( $facts['session_id'] ) && is_string( $facts['session_id'] ) && '' !== trim( $facts['session_id'] ) ) {
			return trim( $facts['session_id'] );
		}

		return '';
	}

	/**
	 * Extracts frequency capping configuration array from candidate row.
	 *
	 * @param array<string, mixed> $row Candidate row.
	 * @return array<string, mixed>|null
	 */
	public static function extract_frequency_config( array $row ): ?array {
		if ( isset( $row['frequency_rules'] ) ) {
			if ( is_array( $row['frequency_rules'] ) ) {
				return $row['frequency_rules'];
			}
			if ( is_string( $row['frequency_rules'] ) ) {
				$decoded = json_decode( $row['frequency_rules'], true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		if ( isset( $row['frequency_capping'] ) ) {
			if ( is_array( $row['frequency_capping'] ) ) {
				return $row['frequency_capping'];
			}
			if ( is_string( $row['frequency_capping'] ) ) {
				$decoded = json_decode( $row['frequency_capping'], true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		if ( isset( $row['delivery_settings'] ) ) {
			$settings = is_array( $row['delivery_settings'] )
				? $row['delivery_settings']
				: json_decode( (string) $row['delivery_settings'], true );

			if ( is_array( $settings ) && isset( $settings['frequency_capping'] ) && is_array( $settings['frequency_capping'] ) ) {
				return $settings['frequency_capping'];
			}
		}

		return null;
	}
}
