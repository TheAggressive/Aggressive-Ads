<?php
/**
 * The named slices of an advertiser's campaign list.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

use Aggressive\Ads\Core\Post_Statuses;

/**
 * One definition of each slice, shared by the count and by the list.
 *
 * The dashboard says "Needs your attention 3" and the campaign list shows the
 * campaigns that need attention. Those are the same question asked twice, and
 * before this they were answered twice: the tiles built their groups inline and
 * the list had no groups at all. The moment a status is added — and eleven
 * exist — a second definition is one edit away from disagreeing with the first,
 * and the symptom is a count that does not match the rows underneath it, which
 * a reader has no way to resolve and no reason to trust afterwards.
 *
 * Deliberately not every status. These are the three slices a reader acts on;
 * the rest are reachable by clearing the filter. A filter for each of eleven
 * statuses would be a schema browser rather than a way to find work.
 */
final class Campaign_Filter {

	public const RUNNING   = 'running';
	public const IN_REVIEW = 'in-review';
	public const ATTENTION = 'attention';

	/**
	 * Every filter slug, in the order the dashboard shows them.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array( self::RUNNING, self::IN_REVIEW, self::ATTENTION );
	}

	/**
	 * The statuses one slug covers, or every status for anything else.
	 *
	 * An unknown slug widens to the whole list rather than narrowing to
	 * nothing. A guessed or stale URL then shows the advertiser their
	 * campaigns, which is the answer they can act on; an empty list would read
	 * as "you have no campaigns" and be believed.
	 *
	 * @param string $filter Filter slug, or '' for no filter.
	 * @return array<int, string>
	 */
	public static function statuses( string $filter ): array {
		return match ( $filter ) {
			self::RUNNING   => Post_Statuses::published(),
			self::IN_REVIEW => array( Post_Statuses::SUBMITTED, Post_Statuses::REVIEW ),
			self::ATTENTION => Post_Statuses::advertiser_editable(),
			default         => Post_Statuses::all(),
		};
	}

	/**
	 * Whether a slug names one of the slices.
	 *
	 * @param string $filter Candidate slug.
	 * @return bool
	 */
	public static function is_valid( string $filter ): bool {
		return in_array( $filter, self::all(), true );
	}
}
