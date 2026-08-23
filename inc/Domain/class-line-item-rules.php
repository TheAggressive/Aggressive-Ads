<?php
/**
 * Line-item vocabulary and invariants.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

use Aggressive\Ads\Core\Post_Statuses;

/** Pure rules shared by migration, workflows, REST and presentation. */
final class Line_Item_Rules {
	public const MAX_NAME_LENGTH = 191;

	public const DRAFT     = 'draft';
	public const READY     = 'ready';
	public const SCHEDULED = 'scheduled';
	public const LIVE      = 'live';
	public const PAUSED    = 'paused';
	public const COMPLETED = 'completed';
	public const CANCELLED = 'cancelled';

	public const PRICING_MODELS = array( 'flat', 'cpm', 'cpc', 'cpa', 'share_of_voice' );
	public const GOAL_TYPES     = array( 'none', 'impressions', 'clicks', 'conversions', 'spend', 'share_of_voice' );
	public const PACING_MODES   = array( 'even', 'asap' );

	/**
	 * All line-item statuses.
	 *
	 * @return array<int, string>
	 */
	public static function statuses(): array {
		return array( self::DRAFT, self::READY, self::SCHEDULED, self::LIVE, self::PAUSED, self::COMPLETED, self::CANCELLED );
	}

	/**
	 * Maps the legacy campaign lifecycle onto its compatibility line item.
	 *
	 * @param string $status Campaign status.
	 */
	public static function status_for_campaign( string $status ): string {
		return match ( $status ) {
			Post_Statuses::APPROVED  => self::READY,
			Post_Statuses::SCHEDULED => self::SCHEDULED,
			Post_Statuses::LIVE      => self::LIVE,
			Post_Statuses::PAUSED    => self::PAUSED,
			Post_Statuses::COMPLETE  => self::COMPLETED,
			Post_Statuses::CANCELLED => self::CANCELLED,
			default                  => self::DRAFT,
		};
	}

	/**
	 * Valid independent line-item edges for the multi-line-item phases.
	 *
	 * P1's default line item follows the campaign state machine, but declaring
	 * the complete graph now prevents later callers from inventing transitions.
	 *
	 * @param string $from Current status.
	 * @param string $to   Target status.
	 */
	public static function can_transition( string $from, string $to ): bool {
		$edges = array(
			self::DRAFT     => array( self::READY, self::CANCELLED ),
			self::READY     => array( self::DRAFT, self::SCHEDULED, self::LIVE, self::CANCELLED ),
			self::SCHEDULED => array( self::LIVE, self::PAUSED, self::CANCELLED ),
			self::LIVE      => array( self::PAUSED, self::COMPLETED, self::CANCELLED ),
			self::PAUSED    => array( self::LIVE, self::COMPLETED, self::CANCELLED ),
			self::COMPLETED => array(),
			self::CANCELLED => array(),
		);

		return in_array( $to, $edges[ $from ] ?? array(), true );
	}
}
