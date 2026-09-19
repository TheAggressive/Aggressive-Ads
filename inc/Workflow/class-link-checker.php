<?php
/**
 * Asking a campaign's destination whether it answers.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Click_Macros;
use Aggressive\Ads\Domain\Link_Check_Rules;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Campaign_Request_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Rate_Limiter;
use WP_Error;

/**
 * The only place this plugin fetches a URL an advertiser supplied.
 *
 * **It checks the saved link, never one that arrives in the request.** The
 * caller names a campaign; the URL comes from that campaign's stored
 * destination, which already passed `Campaign_Rules::is_valid_click_url()` on
 * its way in — or, for a running campaign being edited, from the link staged
 * in its proposal, which passed `Live_Edit_Rules` on the way into storage. There is no parameter to point this at an address, so reaching a
 * new one means first saving it to a campaign you own — which leaves an
 * audited write behind it and still meets every rule below.
 *
 * **Three layers, because the first two can each be wrong.**
 *
 * 1. `Link_Check_Rules::refuse()` judges the URL's own text: scheme, port,
 *    credentials, and hosts that are addresses or names only a local network
 *    resolves.
 * 2. Every address the host resolves to must be public. A name is ordinary
 *    and its DNS answer is not: `metadata.example.com` resolving to
 *    169.254.169.254 is the attack the text check cannot see.
 * 3. `wp_safe_remote_*()` with `reject_unsafe_urls` re-validates the URL and
 *    every redirect it follows, inside WordPress, after this code has run.
 *
 * Between (2) and (3) a name can change its answer — DNS rebinding — which is
 * why (3) is not left to this code's own resolution. What survives is a short,
 * unauthenticated GET of a page the advertiser chose, with the body discarded.
 *
 * **What comes back says little.** The advertiser learns works, missing,
 * private, broken or unreachable, and the status code for their own link. No
 * body, no headers, no redirect chain.
 */
final class Link_Checker {

	/**
	 * How long to wait, in seconds. Short: a slow site is the advertiser's to
	 * fix, and a long wait here is a request holding a PHP worker open.
	 */
	private const TIMEOUT = 5;

	/**
	 * Redirects followed. Enough for the http → https → www hops a real site
	 * makes, few enough that a redirect loop ends quickly.
	 */
	private const REDIRECTS = 3;

	/**
	 * Bytes of the body accepted when a HEAD is refused and a GET is needed.
	 * Nothing reads it; the status code is the answer.
	 */
	private const MAX_BODY = 2048;

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository         $campaigns Campaign persistence.
	 * @param Rate_Limiter                $limiter   Outbound request bounding.
	 * @param Campaign_Request_Repository $requests  Staged changes to running campaigns.
	 * @param Creative_Repository         $creatives An ad's own link, when one is asked about.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Rate_Limiter $limiter,
		private readonly Campaign_Request_Repository $requests,
		private readonly Creative_Repository $creatives
	) {
	}

	/**
	 * Checks one campaign's destination link.
	 *
	 * @param int  $campaign_id Campaign post id.
	 * @param bool $proposed    The link staged in a change to a running campaign, not the one serving.
	 * @param int  $creative_id One ad's own link instead of the campaign's; zero for the campaign's.
	 * @return array{url: string, status: int, outcome: string, checked_at: int}|WP_Error
	 */
	public function check( int $campaign_id, bool $proposed = false, int $creative_id = 0 ): array|WP_Error {
		$authorized = $this->authorize( $campaign_id );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_LINK_CHECK, get_current_user_id() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$url = $this->link( $campaign_id, $proposed, $creative_id );

		/*
		 * A link with macros in it is not a URL until a click fills them in,
		 * and `{creative_id}` in a path would be checked as a literal and
		 * reported missing. Checked as the click will build it, with this
		 * campaign's own id and zeros for what only a click knows.
		 */
		$url = Click_Macros::expand(
			$url,
			array(
				'campaign_id' => $campaign_id,
				'now'         => time(),
			)
		);

		if ( '' === $url ) {
			return new WP_Error(
				'aggr_link_missing',
				__( 'Add a destination link first.', 'aggressive-ads' ),
				array( 'status' => 409 )
			);
		}

		if ( '' !== Link_Check_Rules::refuse( $url ) || ! $this->resolves_publicly( $url ) ) {
			return new WP_Error(
				'aggr_link_not_checkable',
				__( 'This link cannot be checked from here. Open it in a browser to make sure it works.', 'aggressive-ads' ),
				array( 'status' => 422 )
			);
		}

		return $this->record( $campaign_id, $url, $this->status( $url ), $proposed );
	}

	/**
	 * The last result stored for a campaign, or null.
	 *
	 * @param int  $campaign_id Campaign post id.
	 * @param bool $proposed    About the staged link rather than the saved one.
	 * @return array{url: string, status: int, outcome: string, checked_at: int}|null
	 */
	public function last( int $campaign_id, bool $proposed = false ): ?array {
		$stored = $proposed ? $this->requests->proposed_link_check( $campaign_id ) : $this->campaigns->link_check( $campaign_id );

		// A result is about one link. The moment the link changes it is stale.
		$saved = $this->link( $campaign_id, $proposed );

		if ( null === $stored || $saved !== $stored['url'] ) {
			return null;
		}

		return $stored;
	}

	/**
	 * The link a check is about.
	 *
	 * A proposal with no link of its own falls back to the saved one, so the
	 * edit screen's chip is about whatever its field shows.
	 *
	 * @param int  $campaign_id Campaign post id.
	 * @param bool $proposed    Prefer the staged link.
	 * @param int  $creative_id One ad's own link instead of the campaign's; zero for the campaign's.
	 * @return string
	 */
	private function link( int $campaign_id, bool $proposed, int $creative_id = 0 ): string {
		/*
		 * **An ad's own link is read from the ad, and only from one this
		 * campaign owns.** The campaign is already authorized above; the
		 * creative is checked against it here, so an id belonging to another
		 * advertiser falls through to the campaign's link rather than being
		 * fetched. The route still never takes a URL.
		 */
		if ( $creative_id > 0 ) {
			$creative = $this->creatives->details( $creative_id );

			if ( null !== $creative && $campaign_id === (int) $creative['campaign_id'] ) {
				return trim( (string) $creative['click_url'] );
			}
		}

		$staged = $proposed ? ( $this->requests->pending_edits( $campaign_id )['default_click_url'] ?? '' ) : '';

		return trim( is_string( $staged ) && '' !== $staged ? $staged : $this->campaigns->default_click_url( $campaign_id ) );
	}

	/**
	 * Whether the caller may check this campaign's link.
	 *
	 * One answer for "not yours" and "does not exist", so the route cannot be
	 * used to learn which campaign ids are real. The edit window is not
	 * consulted: a live campaign cannot be edited and its link is still worth
	 * checking.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	private function authorize( int $campaign_id ): bool|WP_Error {
		if (
			! current_user_can( Capabilities::SUBMIT_CAMPAIGN )
			|| ! $this->campaigns->exists( $campaign_id )
			|| ! current_user_can( 'read_post', $campaign_id )
		) {
			return new WP_Error(
				'aggr_campaign_not_found',
				__( 'There is no campaign to check.', 'aggressive-ads' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/**
	 * Whether every address the URL's host resolves to is public.
	 *
	 * A host that resolves to nothing is refused here rather than fetched: the
	 * request would fail anyway, and "unreachable" said without opening a
	 * connection is the same answer for less.
	 *
	 * @param string $url An allowed URL.
	 * @return bool
	 */
	private function resolves_publicly( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( '' === $host ) {
			return false;
		}

		$addresses = $this->resolve( $host );

		if ( array() === $addresses ) {
			return false;
		}

		foreach ( $addresses as $address ) {
			if ( ! Link_Check_Rules::is_public_address( $address ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Every address a host name answers with, IPv4 and IPv6.
	 *
	 * Both families, because a name whose A record is public and whose AAAA
	 * record is ::1 would otherwise pass while the request went to the
	 * loopback over IPv6.
	 *
	 * @param string $host Host name.
	 * @return array<int, string>
	 */
	private function resolve( string $host ): array {
		$addresses = array();
		$ipv4      = gethostbynamel( $host );

		if ( is_array( $ipv4 ) ) {
			$addresses = array_map( 'strval', $ipv4 );
		}

		$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A host with no AAAA record warns; its absence is an answer, not a failure.

		foreach ( is_array( $records ) ? $records : array() as $record ) {
			if ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
				$addresses[] = $record['ipv6'];
			}
		}

		return $addresses;
	}

	/**
	 * The status code the destination answers with, or zero.
	 *
	 * HEAD first, because the body is never read. Sites that refuse HEAD —
	 * with 405, or 501, or by failing outright — are common enough that a GET
	 * fallback is the difference between a useful check and one that reports
	 * half the web broken.
	 *
	 * @param string $url An allowed URL.
	 * @return int
	 */
	private function status( string $url ): int {
		$response = wp_safe_remote_head( $url, $this->args() );
		$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		if ( 0 === $status || in_array( $status, array( 400, 403, 405, 406, 501 ), true ) ) {
			$response = wp_safe_remote_get( $url, $this->args() + array( 'limit_response_size' => self::MAX_BODY ) );
			$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		}

		return $status;
	}

	/**
	 * Arguments for both requests.
	 *
	 * `reject_unsafe_urls` is what makes `wp_safe_remote_*` safe: WordPress
	 * re-validates the URL and each redirect against its own private-address
	 * rules, after this class has done its own.
	 *
	 * @return array<string, mixed>
	 */
	private function args(): array {
		return array(
			'timeout'            => self::TIMEOUT,
			'redirection'        => self::REDIRECTS,
			'reject_unsafe_urls' => true,
			'sslverify'          => true,
			'blocking'           => true,
			'user-agent'         => sprintf( 'AggressiveAds/%s link check (+%s)', AGGR_VERSION, home_url( '/' ) ),
			'headers'            => array( 'Accept' => 'text/html,*/*;q=0.8' ),
			// Nothing about the checking site travels with the request.
			'cookies'            => array(),
		);
	}

	/**
	 * Stores and returns a result.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $url         The link checked.
	 * @param int    $status      Status code, or zero.
	 * @param bool   $proposed    Whether it was the link staged in a proposal.
	 * @return array{url: string, status: int, outcome: string, checked_at: int}
	 */
	private function record( int $campaign_id, string $url, int $status, bool $proposed ): array {
		$result = array(
			'url'        => $url,
			'status'     => $status,
			'outcome'    => Link_Check_Rules::outcome( $status ),
			'checked_at' => time(),
		);

		if ( $proposed ) {
			$this->requests->set_proposed_link_check( $campaign_id, $result );
		} else {
			$this->campaigns->set_link_check( $campaign_id, $result );
		}

		return $result;
	}
}
