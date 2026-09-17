<?php
/**
 * Values a destination link can carry, filled in at click time.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Tracking templates, as every ad server has them.
 *
 * A static `utm_content=sidebar` is a guess written once. What an advertiser
 * actually wants is the ad that was clicked, the placement it ran in and a
 * value that is different every time so a proxy cannot serve a cached copy of
 * the landing page — and none of that is known until somebody clicks. So the
 * stored link carries `{creative_id}` and this fills it in on the hop, the
 * same way Google's ValueTrack and DCM's click macros do.
 *
 * **Substitution only, never parsing.** A macro is replaced by a value this
 * plugin produced — an integer, or a timestamp — and every replacement is URL
 * encoded. No macro can introduce a host, a scheme or a second question mark,
 * so a destination cannot be turned into a different destination by what is
 * written between the braces.
 *
 * **Unknown macros are removed.** A link written for another ad server, or one
 * with a typo, would otherwise send `{mistake}` to the advertiser's analytics
 * and be counted as a real value. An empty value is the honest answer.
 */
final class Click_Macros {

	/**
	 * The macros an advertiser may write, and what each one means.
	 *
	 * Ids rather than names throughout: a name is a database read on a hop
	 * that must stay a redirect, and a name that is edited later would make
	 * yesterday's reports disagree with today's.
	 */
	public const CAMPAIGN_ID  = 'campaign_id';
	public const CREATIVE_ID  = 'creative_id';
	public const PLACEMENT_ID = 'placement_id';
	public const TIMESTAMP    = 'timestamp';
	public const CACHEBUSTER  = 'cachebuster';
	public const CLICK_ID     = 'click_id';

	/**
	 * Every macro, in the order the page offers them.
	 *
	 * @return array<int, string>
	 */
	public static function names(): array {
		return array(
			self::CAMPAIGN_ID,
			self::CREATIVE_ID,
			self::PLACEMENT_ID,
			self::TIMESTAMP,
			self::CACHEBUSTER,
			self::CLICK_ID,
		);
	}

	/**
	 * Whether a link asks for anything to be filled in.
	 *
	 * @param string $url A destination link.
	 * @return bool
	 */
	public static function has_macros( string $url ): bool {
		return 1 === preg_match( '/\{[a-z_]{1,32}\}/i', $url );
	}

	/**
	 * Fills a destination link's macros in.
	 *
	 * @param string                                                                                        $url     The stored destination.
	 * @param array{campaign_id?: int, creative_id?: int, placement_id?: int, click_id?: string, now?: int} $context What this click knows.
	 * @return string The link to send the visitor to.
	 */
	public static function expand( string $url, array $context ): string {
		if ( ! self::has_macros( $url ) ) {
			return $url;
		}

		$now    = isset( $context['now'] ) ? (int) $context['now'] : 0;
		$values = array(
			self::CAMPAIGN_ID  => (string) (int) ( $context['campaign_id'] ?? 0 ),
			self::CREATIVE_ID  => (string) (int) ( $context['creative_id'] ?? 0 ),
			self::PLACEMENT_ID => (string) (int) ( $context['placement_id'] ?? 0 ),
			self::TIMESTAMP    => (string) $now,

			/*
			 * Different on every click, which is the whole job: a value that
			 * repeats lets a proxy answer the second visitor with the first
			 * visitor's page, and the advertiser counts one visit.
			 */
			self::CACHEBUSTER  => (string) $now . '-' . substr( (string) ( $context['click_id'] ?? '' ), 0, 12 ),
			self::CLICK_ID     => (string) ( $context['click_id'] ?? '' ),
		);

		return (string) preg_replace_callback(
			'/\{([a-z_]{1,32})\}/i',
			static function ( array $found ) use ( $values ): string {
				$name = strtolower( $found[1] );

				// Encoded, so a value can never end a query string or start a path.
				return isset( $values[ $name ] ) ? rawurlencode( $values[ $name ] ) : '';
			},
			$url
		);
	}
}
