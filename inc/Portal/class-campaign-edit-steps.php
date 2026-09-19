<?php
/**
 * The steps of editing a running campaign.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

/**
 * Which steps the edit flow has, what each is called, and which follows.
 *
 * The page head draws the steps and the flow below it draws the step, and
 * each used to work the list out for itself from the site's live-edit
 * settings. Two copies of one decision is how a head offers a step the flow
 * never renders; this is the one copy.
 *
 * The same three steps creation has, with the same names: Package & dates,
 * Ads, Review & submit. An advertiser who made the campaign already knows
 * that shape, and Ads is where a running ad's artwork is replaced — the
 * reason editing needs a step for ads at all, not only for their links. The
 * step keys are the ones `Campaign_Actions::CHANGE_STEPS` accepts, so a
 * posted `next_step` still validates against the same list.
 */
final class Campaign_Edit_Steps {

	public const DETAILS     = 'details';
	public const DESTINATION = 'destination';
	public const REVIEW      = 'review';

	/**
	 * The step that used to hold the dates on its own.
	 *
	 * Still a key `Campaign_Actions` accepts, so an old link or bookmark to it
	 * arrives here and is shown the step that holds the dates now.
	 */
	private const RETIRED_SCHEDULE = 'schedule';

	/**
	 * The steps for what a site lets advertisers change, in order.
	 *
	 * @param array<int, string> $fields         Live-edit fields the site allows.
	 * @param bool               $can_update_ads Whether the campaign's ads can be replaced.
	 * @return array<string, string> Step key → label.
	 */
	public static function for_fields( array $fields, bool $can_update_ads = false ): array {
		$steps = array();

		if ( self::has_details( $fields ) || self::has_dates( $fields ) ) {
			$steps[ self::DETAILS ] = __( 'Package & dates', 'aggressive-ads' );
		}

		if ( self::has_links( $fields ) || $can_update_ads ) {
			$steps[ self::DESTINATION ] = __( 'Ads', 'aggressive-ads' );
		}

		$steps[ self::REVIEW ] = __( 'Review & submit', 'aggressive-ads' );

		return $steps;
	}

	/**
	 * The step to show for the one asked for.
	 *
	 * @param string                $requested A step key from the address.
	 * @param array<string, string> $steps     Output of for_fields().
	 * @return string A key of `$steps`.
	 */
	public static function current( string $requested, array $steps ): string {
		if ( self::RETIRED_SCHEDULE === $requested ) {
			$requested = self::DETAILS;
		}

		return isset( $steps[ $requested ] ) ? $requested : (string) array_key_first( $steps );
	}

	/**
	 * The step after this one, or review.
	 *
	 * @param string                $step  Current step key.
	 * @param array<string, string> $steps Output of for_fields().
	 * @return string
	 */
	public static function next( string $step, array $steps ): string {
		$keys  = array_keys( $steps );
		$index = array_search( $step, $keys, true );

		return false === $index ? self::REVIEW : (string) ( $keys[ $index + 1 ] ?? self::REVIEW );
	}

	/**
	 * The address of a step.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $step        Step key, or empty for the first.
	 * @return string
	 */
	public static function url( int $campaign_id, string $step = '' ): string {
		$url = add_query_arg( 'edit', '1', Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id ) );

		return '' === $step ? $url : add_query_arg( 'step', $step, $url );
	}

	/**
	 * Whether the package, name, placements or notes may change.
	 *
	 * @param array<int, string> $fields Live-edit fields the site allows.
	 * @return bool
	 */
	public static function has_details( array $fields ): bool {
		return array() !== array_intersect( array( 'package_id', 'title', 'advertiser_notes', 'placement_ids' ), $fields );
	}

	/**
	 * Whether the ads' links may change as a staged edit.
	 *
	 * @param array<int, string> $fields Live-edit fields the site allows.
	 * @return bool
	 */
	public static function has_links( array $fields ): bool {
		return in_array( 'click_urls', $fields, true );
	}

	/**
	 * Whether the dates may change.
	 *
	 * @param array<int, string> $fields Live-edit fields the site allows.
	 * @return bool
	 */
	public static function has_dates( array $fields ): bool {
		return in_array( 'start_ts', $fields, true );
	}
}
