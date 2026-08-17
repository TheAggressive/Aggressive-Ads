<?php
/**
 * The admin notice that says a reviewer has work waiting.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Security\Capabilities;

/**
 * Surfaces queue work on every admin screen, linked to the tab that clears it.
 *
 * The review queue already counts this, but only once somebody opens it. Staff
 * spend their day on posts, media and orders, so a campaign can sit submitted
 * for a week without anyone being told — the queue is only a queue to a person
 * who visits it.
 */
final class Action_Notice implements Service {

	/**
	 * Cached counts.
	 *
	 * Without this the notice runs three queries on every admin page load for
	 * every member of staff, which is a real cost paid on screens that have
	 * nothing to do with advertising.
	 */
	private const TRANSIENT = 'aggr_pending_actions';

	/**
	 * How stale the counts may be.
	 *
	 * Short, because the number is the whole point, and invalidating precisely
	 * would mean hooking every transition, request and replacement — a lot of
	 * surface for a line of text that is advisory either way. Five minutes late
	 * is not a wrong answer to "is there work waiting".
	 */
	private const TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * The filters a reviewer can act on, in the order they are shown.
	 *
	 * `decided`, `running` and `finished` are deliberately absent: they are
	 * places to look, not work to do, and a notice that counts them is a
	 * notice that never goes away.
	 */
	private const ACTIONABLE = array( 'pending', 'updates', 'requests' );

	/**
	 * Constructor.
	 *
	 * @param Review_Data $data Queue counts.
	 */
	public function __construct( private readonly Review_Data $data ) {
	}

	/**
	 * Attaches the notice.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );

		// Every event that can change a count, cleared through the hooks the
		// workflow already fires rather than by calling into this class from
		// three managers. A reviewer who approves the last submitted campaign
		// and lands back on the dashboard should not be told to review it.
		add_action( 'aggr_campaign_transitioned', array( self::class, 'forget' ) );
		add_action( 'aggr_notify_advertiser_request', array( self::class, 'forget' ) );
		add_action( 'aggr_creative_replaced', array( self::class, 'forget' ) );
	}

	/**
	 * Drops the cached counts.
	 *
	 * Called after a decision so the notice reflects the work just done rather
	 * than telling somebody to clear a queue they have already cleared.
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Prints the notice when a reviewer has something to act on.
	 *
	 * Gated on REVIEW_CAMPAIGNS rather than ACCESS_STAFF, because every link
	 * here goes to the review queue. Telling somebody about work they cannot
	 * open is worse than telling them nothing.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			return;
		}

		if ( $this->on_review_screen() ) {
			return;
		}

		$items = $this->items();

		if ( array() === $items ) {
			return;
		}

		// Not dismissible, and not by oversight. The notice disappears the
		// moment the work is done, so a dismiss button could only ever hide
		// outstanding work until somebody happened to open the queue — which
		// is the situation this exists to end.
		printf(
			'<div class="notice notice-info"><p><strong>%1$s</strong></p><ul style="margin:0.5em 0 0.5em 1.5em;list-style:disc;">%2$s</ul></div>',
			esc_html__( 'Aggressive Ads: advertising work is waiting for you.', 'aggressive-ads' ),
			implode( '', $items ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each item is escaped as it is built in items().
		);
	}

	/**
	 * One list item per actionable queue with something in it.
	 *
	 * @return array<int, string>
	 */
	private function items(): array {
		$counts = $this->counts();
		$items  = array();

		foreach ( self::ACTIONABLE as $filter ) {
			$count = $counts[ $filter ] ?? 0;

			if ( $count < 1 ) {
				continue;
			}

			$items[] = sprintf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( Review_Screen::queue_url( $filter ) ),
				esc_html( $this->label_for( $filter, $count ) )
			);
		}

		return $items;
	}

	/**
	 * What each queue is called when it has work in it.
	 *
	 * Written as whole sentences rather than "Pending: 3" so the link text says
	 * what clicking it does, which is what a screen reader announces out of
	 * context.
	 *
	 * @param string $filter Queue filter key.
	 * @param int    $count  How many are waiting.
	 * @return string
	 */
	private function label_for( string $filter, int $count ): string {
		if ( 'updates' === $filter ) {
			/* translators: %s: number of campaigns. */
			return sprintf( _n( 'Review %s replacement creative', 'Review %s replacement creatives', $count, 'aggressive-ads' ), number_format_i18n( $count ) );
		}

		if ( 'requests' === $filter ) {
			/* translators: %s: number of requests. */
			return sprintf( _n( 'Answer %s advertiser request', 'Answer %s advertiser requests', $count, 'aggressive-ads' ), number_format_i18n( $count ) );
		}

		/* translators: %s: number of campaigns. */
		return sprintf( _n( 'Review %s submitted campaign', 'Review %s submitted campaigns', $count, 'aggressive-ads' ), number_format_i18n( $count ) );
	}

	/**
	 * Counts per actionable filter, cached.
	 *
	 * @return array<string, int>
	 */
	private function counts(): array {
		$cached = get_transient( self::TRANSIENT );

		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}

		$counts = array();

		foreach ( $this->data->tabs() as $tab ) {
			if ( in_array( $tab['key'], self::ACTIONABLE, true ) ) {
				$counts[ $tab['key'] ] = (int) $tab['count'];
			}
		}

		set_transient( self::TRANSIENT, $counts, self::TTL );

		return $counts;
	}

	/**
	 * Whether the reviewer is already looking at the queue.
	 *
	 * @return bool
	 */
	private function on_review_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return null !== $screen && str_contains( $screen->id, Review_Screen::MENU_SLUG );
	}
}
