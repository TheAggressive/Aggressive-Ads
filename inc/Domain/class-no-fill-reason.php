<?php
/**
 * Structured taxonomy for unfilled decision opportunities.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Structured reason taxonomy for no_fill measurement events.
 * Pure domain model without WordPress dependencies.
 */
final class No_Fill_Reason {

	public const NO_CANDIDATES       = 'no_candidates';
	public const ALL_INELIGIBLE      = 'all_ineligible';
	public const SCHEDULE_EXCLUDED   = 'schedule_excluded';
	public const TARGETING_MISMATCH  = 'targeting_mismatch';
	public const FREQUENCY_CAPPED    = 'frequency_capped';
	public const PACING_THROTTLED    = 'pacing_throttled';
	public const COMPETITIVE_EXCLUDE = 'competitive_exclude';
	public const PIPELINE_ERROR      = 'pipeline_error';
	public const UNKNOWN             = 'unknown';

	/**
	 * All structured no-fill reasons.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::NO_CANDIDATES,
			self::ALL_INELIGIBLE,
			self::SCHEDULE_EXCLUDED,
			self::TARGETING_MISMATCH,
			self::FREQUENCY_CAPPED,
			self::PACING_THROTTLED,
			self::COMPETITIVE_EXCLUDE,
			self::PIPELINE_ERROR,
			self::UNKNOWN,
		);
	}

	/**
	 * Maps an internal Exclusion_Reason code to a structured No_Fill_Reason.
	 *
	 * @param string $exclusion_reason Internal exclusion code.
	 * @return string Structured reason.
	 */
	public static function from_exclusion_reason( string $exclusion_reason ): string {
		return match ( $exclusion_reason ) {
			Exclusion_Reason::NO_FILL,
			''                                     => self::NO_CANDIDATES,

			Exclusion_Reason::ELIGIBILITY_INVALID_CLICK_URL,
			Exclusion_Reason::ELIGIBILITY_MISSING_ATTACHMENT,
			Exclusion_Reason::ELIGIBILITY_INVALID_WEIGHT => self::ALL_INELIGIBLE,

			Exclusion_Reason::SCHEDULE_NOT_STARTED,
			Exclusion_Reason::SCHEDULE_EXPIRED,
			Exclusion_Reason::SCHEDULE_DAYPART_EXCLUDED,
			Exclusion_Reason::SCHEDULE_INVALID_TIMEZONE => self::SCHEDULE_EXCLUDED,

			Exclusion_Reason::TARGETING_EXCLUDED => self::TARGETING_MISMATCH,

			Exclusion_Reason::FREQUENCY_CAPPED => self::FREQUENCY_CAPPED,

			Exclusion_Reason::PACING_DAILY_CAP_REACHED,
			Exclusion_Reason::PACING_LIFETIME_CAP_REACHED,
			Exclusion_Reason::PACING_BEHIND_PACE,
			Exclusion_Reason::PACING_THROTTLED,
			Exclusion_Reason::PACING_UNAVAILABLE => self::PACING_THROTTLED,

			Exclusion_Reason::PAGE_COMPETITIVE_SEPARATION,
			Exclusion_Reason::PAGE_ROADBLOCK_INCOMPLETE,
			Exclusion_Reason::PAGE_DUPLICATE_ASSET => self::COMPETITIVE_EXCLUDE,

			Exclusion_Reason::ELIGIBILITY_STAGE_ERROR,
			Exclusion_Reason::SCHEDULE_STAGE_ERROR,
			Exclusion_Reason::TARGETING_STAGE_ERROR,
			Exclusion_Reason::FREQUENCY_STAGE_ERROR,
			Exclusion_Reason::PACING_STAGE_ERROR,
			Exclusion_Reason::PRIORITY_STAGE_ERROR => self::PIPELINE_ERROR,

			default => self::UNKNOWN,
		};
	}
}
