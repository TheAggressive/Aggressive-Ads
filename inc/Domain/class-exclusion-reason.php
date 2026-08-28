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

	public const SCHEDULE_NOT_STARTED      = 'schedule_not_started';
	public const SCHEDULE_EXPIRED          = 'schedule_expired';
	public const SCHEDULE_DAYPART_EXCLUDED = 'schedule_daypart_excluded';
	public const SCHEDULE_INVALID_TIMEZONE = 'schedule_invalid_timezone';
	public const SCHEDULE_STAGE_ERROR      = 'schedule_stage_error';

	public const TARGETING_EXCLUDED          = 'targeting_excluded';
	public const TARGETING_STAGE_ERROR       = 'targeting_stage_error';
	public const FREQUENCY_CAPPED            = 'frequency_capped';
	public const FREQUENCY_STAGE_ERROR       = 'frequency_stage_error';
	public const PACING_DAILY_CAP_REACHED    = 'pacing_daily_cap_reached';
	public const PACING_LIFETIME_CAP_REACHED = 'pacing_lifetime_cap_reached';
	public const PACING_BEHIND_PACE          = 'pacing_behind_pace';
	public const PACING_THROTTLED            = 'pacing_throttled';
	public const PACING_STAGE_ERROR          = 'pacing_stage_error';
	public const PACING_UNAVAILABLE          = 'pacing_unavailable';
	public const PRIORITY_LOWER              = 'priority_lower';
	public const PRIORITY_STAGE_ERROR        = 'priority_stage_error';

	public const PAGE_COMPETITIVE_SEPARATION = 'page_competitive_separation';
	public const PAGE_ROADBLOCK_INCOMPLETE   = 'page_roadblock_incomplete';
	public const PAGE_DUPLICATE_ASSET        = 'page_duplicate_asset';

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
			self::SCHEDULE_NOT_STARTED,
			self::SCHEDULE_EXPIRED,
			self::SCHEDULE_DAYPART_EXCLUDED,
			self::SCHEDULE_INVALID_TIMEZONE,
			self::SCHEDULE_STAGE_ERROR,
			self::TARGETING_EXCLUDED,
			self::TARGETING_STAGE_ERROR,
			self::FREQUENCY_CAPPED,
			self::FREQUENCY_STAGE_ERROR,
			self::PACING_DAILY_CAP_REACHED,
			self::PACING_LIFETIME_CAP_REACHED,
			self::PACING_BEHIND_PACE,
			self::PACING_THROTTLED,
			self::PACING_STAGE_ERROR,
			self::PACING_UNAVAILABLE,
			self::PRIORITY_LOWER,
			self::PRIORITY_STAGE_ERROR,
			self::PAGE_COMPETITIVE_SEPARATION,
			self::PAGE_ROADBLOCK_INCOMPLETE,
			self::PAGE_DUPLICATE_ASSET,
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
