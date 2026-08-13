<?php
/**
 * First-party click hop.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Security\Rate_Limiter;

/**
 * 302 /ads/c/{token} → destination. Counts the click, then leaves.
 */
final class Click_Hop implements Service {

	public const QUERY_VAR       = 'aggr_click';
	public const REWRITE_VERSION = 1;
	public const OPTION_REWRITE  = 'aggr_delivery_rewrite_version';
	public const REFERRER_POLICY = 'no-referrer';

	/**
	 * Constructor.
	 *
	 * @param Fill_Service         $fill       Module gate and live check.
	 * @param Fill_Token           $tokens     Token parser.
	 * @param Rate_Limiter         $limiter    Anonymous click bound.
	 * @param Event_Repository     $events     Append-only log.
	 * @param Rollup_Repository    $rollups    Day counters.
	 * @param Creative_Repository  $creatives  Paid destinations.
	 * @param Placement_Repository $placements House destinations.
	 */
	public function __construct(
		private readonly Fill_Service $fill,
		private readonly Fill_Token $tokens,
		private readonly Rate_Limiter $limiter,
		private readonly Event_Repository $events,
		private readonly Rollup_Repository $rollups,
		private readonly Creative_Repository $creatives,
		private readonly Placement_Repository $placements
	) {
	}

	/**
	 * Attaches rewrite and the hop.
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_rules' ) );
		add_action( 'init', array( $this, 'maybe_flush' ), 99 );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'hop' ) );
	}

	/**
	 * Public click URL for a minted token.
	 *
	 * @param string $token Full token string.
	 */
	public static function url( string $token ): string {
		return home_url( '/ads/c/' . rawurlencode( $token ) );
	}

	/**
	 * Registers the hop rule.
	 */
	public function register_rules(): void {
		add_rewrite_rule( '^ads/c/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Registers the hop query var.
	 *
	 * @param array<int, string> $vars Query vars.
	 * @return array<int, string>
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Flushes once when the rule version changes.
	 */
	public function maybe_flush(): void {
		$stored = (int) get_option( self::OPTION_REWRITE, 0 );

		if ( self::REWRITE_VERSION === $stored ) {
			return;
		}

		$this->register_rules();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Version-gated, so this runs once per deploy that changes the hop, never per request. Same reason as Portal\Router.
		flush_rewrite_rules( false );
		update_option( self::OPTION_REWRITE, self::REWRITE_VERSION, true );
	}

	/**
	 * Consumes the token and redirects.
	 */
	public function hop(): void {
		$token = get_query_var( self::QUERY_VAR, '' );

		if ( ! is_string( $token ) || '' === $token ) {
			return;
		}

		if ( ! $this->fill->is_enabled() || Delivery_Request::is_prefetch() ) {
			$this->not_found();

			return;
		}

		$parsed = $this->tokens->parse( $token );
		$dest   = $this->destination( $parsed );

		if (
			null === $parsed
			|| ! $this->fill->accepts( $parsed )
			|| ! Campaign_Rules::is_valid_click_url( $dest )
			|| false === wp_http_validate_url( $dest )
		) {
			$this->not_found();

			return;
		}

		$allowed = $this->limiter->attempt_for( Rate_Limiter::ACTION_CLICK, Rate_Limiter::client_subject() );

		if ( ! is_wp_error( $allowed ) ) {
			$this->record_click( $token, $parsed );
		}

		// PHPUnit's bootstrap has already sent output, so header() here is a
		// warning in that suite. On a real request this runs before the body
		// and is what stops the destination from seeing /ads/c/{token} in Referer.
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: ' . self::REFERRER_POLICY );
			nocache_headers();
		}

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- First-party hop to an advertiser destination. wp_safe_redirect would refuse every paid click that leaves this host.
		wp_redirect( $dest, 302 );

		exit;
	}

	/**
	 * Paid or house destination for a parsed token.
	 *
	 * @param array{placement_id: int, campaign_id: int, creative_id: int, exp: int, nonce: string}|null $parsed Token.
	 */
	private function destination( ?array $parsed ): string {
		if ( null === $parsed ) {
			return '';
		}

		if ( $parsed['creative_id'] > 0 ) {
			$details = $this->creatives->details( $parsed['creative_id'] );

			return is_array( $details ) ? $details['click_url'] : '';
		}

		return $this->placements->house_click_url( $parsed['placement_id'] );
	}

	/**
	 * Records one click if this token has not already counted a click.
	 *
	 * @param string                                                                                $token  Full token string.
	 * @param array{placement_id: int, campaign_id: int, creative_id: int, exp: int, nonce: string} $parsed Token.
	 */
	private function record_click( string $token, array $parsed ): void {
		$hash = $this->tokens->hash( $token );
		$ip   = $this->tokens->ip_hash( Delivery_Request::client_ip() );

		if ( ! $this->events->insert( Event_Repository::TYPE_CLICK, $parsed['placement_id'], $parsed['campaign_id'], $parsed['creative_id'], $hash, $ip ) ) {
			return;
		}

		$this->rollups->increment( 'clicks', $parsed['placement_id'], $parsed['campaign_id'] );
	}

	/**
	 * Unknown, expired, paused, or hostile hop.
	 */
	private function not_found(): void {
		status_header( 404 );
		nocache_headers();
	}
}
