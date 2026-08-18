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
	 * How many items are named before the notice stops listing them.
	 *
	 * Enough that a reviewer usually sees the whole of a quiet day's work and
	 * can go straight to it, few enough that a busy queue does not push the
	 * screen they actually opened off the bottom of the page.
	 */
	private const NAMED = 4;

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
			esc_html__( 'Advertising is waiting on you', 'aggressive-ads' ),
			implode( '', $items ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each item is escaped as it is built in items().
		);
	}

	/**
	 * One list item per named piece of work, plus a remainder line.
	 *
	 * @return array<int, string>
	 */
	private function items(): array {
		$snapshot = $this->snapshot();
		$items    = array();

		foreach ( $snapshot['named'] as $entry ) {
			$items[] = sprintf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( Review_Screen::campaign_url( (int) $entry['id'], (string) $entry['filter'] ) ),
				esc_html( $this->sentence_for( (string) $entry['filter'], (string) $entry['org'], (string) $entry['title'] ) )
			);
		}

		$remaining = (int) $snapshot['total'] - count( $snapshot['named'] );

		if ( $remaining > 0 ) {
			$items[] = sprintf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( Review_Screen::queue_url() ),
				esc_html(
					sprintf(
						/* translators: %s: number of further items waiting. */
						_n( 'and %s more waiting', 'and %s more waiting', $remaining, 'aggressive-ads' ),
						number_format_i18n( $remaining )
					)
				)
			);
		}

		return $items;
	}

	/**
	 * What happened, in the words somebody would use to describe it.
	 *
	 * "Acme submitted Spring Sale for review" rather than "3 pending", because
	 * a count tells a reviewer that work exists and a sentence tells them what
	 * it is. It is also the link text, so it is what a screen reader announces
	 * out of context — "Review 3 submitted campaigns" is a description of a
	 * page, not of the thing being opened.
	 *
	 * @param string $filter Queue filter key.
	 * @param string $org    Advertiser name.
	 * @param string $title  Campaign title.
	 * @return string
	 */
	private function sentence_for( string $filter, string $org, string $title ): string {
		// An organization can be deleted while its campaigns are still queued,
		// and a campaign can be saved without a title. Neither should produce
		// "  submitted   for review".
		$org   = '' !== trim( $org ) ? $org : __( 'An advertiser', 'aggressive-ads' );
		$title = '' !== trim( $title ) ? $title : __( 'an untitled campaign', 'aggressive-ads' );

		if ( 'updates' === $filter ) {
			/* translators: 1: advertiser name, 2: campaign title. */
			return sprintf( __( '%1$s uploaded replacement creative for %2$s', 'aggressive-ads' ), $org, $title );
		}

		if ( 'requests' === $filter ) {
			/* translators: 1: advertiser name, 2: campaign title. */
			return sprintf( __( '%1$s asked for a change to %2$s', 'aggressive-ads' ), $org, $title );
		}

		/* translators: 1: advertiser name, 2: campaign title. */
		return sprintf( __( '%1$s submitted %2$s for review', 'aggressive-ads' ), $org, $title );
	}

	/**
	 * The named work and the total waiting, cached.
	 *
	 * Data rather than rendered strings, because a rendered string carries the
	 * locale of whoever happened to warm the cache — and two administrators
	 * reading wp-admin in different languages is exactly the case that makes
	 * that bug invisible to the person who introduced it.
	 *
	 * @return array{named: array<int, array<string, mixed>>, total: int}
	 */
	private function snapshot(): array {
		$cached = get_transient( self::TRANSIENT );

		if ( is_array( $cached ) && isset( $cached['named'], $cached['total'] ) && is_array( $cached['named'] ) ) {
			return array(
				'named' => $cached['named'],
				'total' => (int) $cached['total'],
			);
		}

		$named = array();
		$total = 0;

		foreach ( self::ACTIONABLE as $filter ) {
			$page   = $this->data->queue( $filter, 1 );
			$total += (int) $page['total'];

			foreach ( $page['rows'] as $row ) {
				if ( count( $named ) >= self::NAMED ) {
					break;
				}

				$named[] = array(
					'id'     => (int) ( $row['id'] ?? 0 ),
					'org'    => (string) ( $row['org_name'] ?? '' ),
					'title'  => (string) ( $row['title'] ?? '' ),
					'filter' => $filter,
				);
			}
		}

		$snapshot = array(
			'named' => $named,
			'total' => $total,
		);

		set_transient( self::TRANSIENT, $snapshot, self::TTL );

		return $snapshot;
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
