<?php
/**
 * First-party click hop.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Security\Rate_Limiter;

/**
 * 302 /ads/c/{token} → destination. Counts the click, then leaves.
 */
final class Click_Hop implements Service {

	public const QUERY_VAR       = 'aggr_click';
	public const REWRITE_VERSION = 2;
	public const OPTION_REWRITE  = 'aggr_delivery_rewrite_version';
	public const REFERRER_POLICY = 'no-referrer';

	/**
	 * The public path prefix, named once.
	 *
	 * Three places need to agree on it — the rule, the URL builder, and the
	 * Site Health check that asserts the rule is installed. A health check
	 * carrying its own copy of the path is a health check that passes after
	 * the path changes.
	 */
	public const PATH = 'ads/c';

	/**
	 * The query parameter that carries the click onto the advertiser's page.
	 *
	 * Attribution needs the landing page to know which click brought the
	 * visitor, and this hop is the only place that can tell it. The redirect
	 * sets `Referrer-Policy: no-referrer` on purpose, so the destination learns
	 * nothing otherwise — which is correct, and is exactly why the carrier has
	 * to be explicit rather than incidental.
	 *
	 * The value is the same signed fill token the beacon uses. It is worth
	 * nothing except attribution: it names a placement, campaign and creative,
	 * carries no visitor identity, and can only be spent against a definition
	 * the campaign's organization owns.
	 */
	public const TOKEN_PARAM = 'aggr_ct';

	/**
	 * Constructor.
	 *
	 * @param Fill_Service   $fill       Module gate and live check.
	 * @param Fill_Token     $tokens     Token parser.
	 * @param Rate_Limiter   $limiter    Anonymous click bound.
	 * @param Event_Recorder $recorder Durable event and projection write.
	 */
	public function __construct(
		private readonly Fill_Service $fill,
		private readonly Fill_Token $tokens,
		private readonly Rate_Limiter $limiter,
		private readonly Event_Recorder $recorder
	) {
	}

	/**
	 * Attaches rewrite and the hop.
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'hop' ) );
	}

	/**
	 * Public click URL for a minted token.
	 *
	 * @param string $token Full token string.
	 */
	public static function url( string $token ): string {
		return home_url( '/' . self::PATH . '/' . rawurlencode( $token ) );
	}

	/**
	 * The rules the hop declares, as data.
	 *
	 * Pure and static for the same reason as `Router::rules()`: the installer,
	 * the Site Health assertion and the CI version guard all read this one
	 * definition rather than each keeping a copy that can quietly disagree.
	 *
	 * @return array<int, array{regex: string, query: string, position: 'bottom'|'top'}>
	 */
	public static function rules(): array {
		return array(
			array(
				'regex'    => '^' . self::PATH . '/([^/]+)/?$',
				'query'    => 'index.php?' . self::QUERY_VAR . '=$matches[1]',
				'position' => 'top',
			),
		);
	}

	/**
	 * Installs the declared rules.
	 */
	public function register_rules(): void {
		foreach ( self::rules() as $rule ) {
			add_rewrite_rule( $rule['regex'], $rule['query'], $rule['position'] );
		}
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
		$dest   = is_array( $parsed ) ? $this->fill->destination( $parsed ) : null;

		if ( null === $parsed || null === $dest ) {
			$this->not_found();

			return;
		}

		$allowed = $this->limiter->attempt_for( Rate_Limiter::ACTION_CLICK, Rate_Limiter::client_subject() );

		if ( ! is_wp_error( $allowed ) && ! Delivery_Request::is_obvious_bot() ) {
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
		wp_redirect( self::with_token( $dest, $token ), 302 );

		exit;
	}

	/**
	 * Puts the click token on the destination URL.
	 *
	 * `add_query_arg` replaces an existing parameter of the same name rather
	 * than appending a second, which is the behaviour that matters: a
	 * destination already carrying `aggr_ct` — a URL copied from a previous
	 * click, or an advertiser who pasted one into their own campaign settings —
	 * must end up with this click's token and no other. Two values for one
	 * parameter would leave the landing page choosing which click to credit.
	 *
	 * It also preserves an existing query string and fragment, so a destination
	 * with either survives intact. Both are asserted rather than assumed.
	 *
	 * @param string $destination Advertiser destination, already validated.
	 * @param string $token       Full signed token.
	 */
	public static function with_token( string $destination, string $token ): string {
		if ( '' === $token ) {
			return $destination;
		}

		return add_query_arg( self::TOKEN_PARAM, rawurlencode( $token ), $destination );
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

		$this->recorder->record( Event_Repository::TYPE_CLICK, $parsed['placement_id'], $parsed['campaign_id'], $parsed['creative_id'], $hash, $ip );
	}

	/**
	 * Unknown, expired, paused, or hostile hop.
	 */
	private function not_found(): void {
		status_header( 404 );
		nocache_headers();
	}
}
