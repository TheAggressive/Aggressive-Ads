<?php
/**
 * Changing the link every ad of a running campaign goes to.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;

/**
 * Creation's Destination card, as a proposal.
 *
 * Creation sets one link for every ad, and each size may still have its own.
 * The edit flow shows the same card, so changing it has to mean the same
 * thing: every ad that goes to the shared link moves with it, and an ad with a
 * link of its own keeps it. "Used by 2 of 3 ads" on the card is that count.
 *
 * On a draft the shared link is only where the next upload starts; nothing is
 * serving. On a running campaign an advertiser who changes "the link" means
 * the page their clicks land on, so the change is expanded here into each
 * following ad's own destination — the field that is reviewed, approved and
 * served — rather than left as a default no click ever reads.
 */
final class Live_Link_Change {

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository $campaigns Campaign persistence.
	 * @param Creative_Repository $creatives Creative persistence.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives
	) {
	}

	/**
	 * The campaign's shared link: the one set for it, or else its first ad's.
	 *
	 * The same fallback the draft screen uses, for campaigns made before the
	 * shared link existed or put together by staff one ad at a time.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public function shared( int $campaign_id ): string {
		$link = trim( $this->campaigns->default_click_url( $campaign_id ) );

		if ( '' !== $link ) {
			return $link;
		}

		foreach ( $this->creatives->for_campaign( $campaign_id ) as $creative ) {
			$own = trim( (string) $creative['click_url'] );

			if ( '' !== $own ) {
				return $own;
			}
		}

		return '';
	}

	/**
	 * How many of the campaign's ads go to the shared link.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array{0: int, 1: int} Ads following it, and ads in all.
	 */
	public function usage( int $campaign_id ): array {
		$shared    = $this->shared( $campaign_id );
		$creatives = $this->creatives->for_campaign( $campaign_id );
		$following = 0;

		foreach ( $creatives as $creative ) {
			if ( '' !== $shared && trim( (string) $creative['click_url'] ) === $shared ) {
				++$following;
			}
		}

		return array( $following, count( $creatives ) );
	}

	/**
	 * A proposal with a new shared link carried onto the ads that follow it.
	 *
	 * An ad's own destination posted in the same proposal wins: it was typed
	 * for that ad. Only ads whose link actually changes are added, so the
	 * reviewer sees the ads that move and no others.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $proposed    One step's fields.
	 * @return array<string, mixed>
	 */
	public function expand( int $campaign_id, array $proposed ): array {
		if ( ! isset( $proposed['default_click_url'] ) || ! is_string( $proposed['default_click_url'] ) ) {
			return $proposed;
		}

		$next   = trim( $proposed['default_click_url'] );
		$shared = $this->shared( $campaign_id );
		$urls   = isset( $proposed['click_urls'] ) && is_array( $proposed['click_urls'] ) ? $proposed['click_urls'] : array();

		// Re-saving the card as it stands moves nothing, and must not look as though it did.
		if ( '' === $shared || $next === $shared ) {
			return $proposed;
		}

		foreach ( $this->creatives->for_campaign( $campaign_id ) as $creative ) {
			$id  = (int) $creative['id'];
			$own = trim( (string) $creative['click_url'] );

			if ( $own === $shared && ! array_key_exists( $id, $urls ) ) {
				$urls[ $id ] = $next;
			}
		}

		if ( array() !== $urls ) {
			$proposed['click_urls'] = $urls;
		}

		return $proposed;
	}
}
