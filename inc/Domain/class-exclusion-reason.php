<?php
/**
 * Closed vocabulary for decision exclusions.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Stable machine codes. Human labels belong at the edge, never in storage.
 */
final class Exclusion_Reason {

	public const ELIGIBILITY_INVALID_CLICK_URL  = 'eligibility_invalid_click_url';
	public const ELIGIBILITY_MISSING_ATTACHMENT = 'eligibility_missing_attachment';
	public const ELIGIBILITY_INVALID_WEIGHT     = 'eligibility_invalid_weight';
	public const ELIGIBILITY_STAGE_ERROR        = 'eligibility_stage_error';

	public const TARGETING_EXCLUDED = 'targeting_excluded';
	public const FREQUENCY_CAPPED   = 'frequency_capped';
	public const PACING_UNAVAILABLE = 'pacing_unavailable';
	public const PRIORITY_LOWER     = 'priority_lower';

	public const COMPETITION_LOST = 'competition_lost';

	public const NO_FILL = 'no_fill';

	/**
	 * Every reason a stage may emit.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::ELIGIBILITY_INVALID_CLICK_URL,
			self::ELIGIBILITY_MISSING_ATTACHMENT,
			self::ELIGIBILITY_INVALID_WEIGHT,
			self::ELIGIBILITY_STAGE_ERROR,
			self::TARGETING_EXCLUDED,
			self::FREQUENCY_CAPPED,
			self::PACING_UNAVAILABLE,
			self::PRIORITY_LOWER,
			self::COMPETITION_LOST,
			self::NO_FILL,
		);
	}

	/**
	 * Whether a string is one of the closed codes.
	 *
	 * @param string $reason Candidate code.
	 */
	public static function is_reason( string $reason ): bool {
		return in_array( $reason, self::all(), true );
	}
}
