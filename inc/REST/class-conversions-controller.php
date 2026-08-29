<?php
/**
 * The public conversion report.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Conversion_Attribution;
use Aggressive\Ads\Domain\Conversion_Definition;
use Aggressive\Ads\Domain\Conversion_Rules;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Workflow\Conversion_Recorder;
use Aggressive\Ads\Workflow\Delivery_Request;
use Aggressive\Ads\Workflow\Fill_Service;
use Aggressive\Ads\Workflow\Fill_Token;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /aggr/v1/conversions — reported by the advertiser's own page.
 *
 * A separate route from the impression beacon rather than another `event`
 * value on it, and for two reasons that both matter. The beacon is same-origin
 * and this is not: a conversion is reported from the advertiser's site, so the
 * cross-origin refusal that protects `/i` would refuse every real conversion.
 * And it needs its own rate-limit bucket — sharing the beacon's would let a
 * visitor browsing a page full of ads exhaust the budget and have their own
 * purchase go unrecorded.
 */
final class Conversions_Controller implements Service {

	/**
	 * The reported outcome time may not be further from now than this.
	 *
	 * The only client-supplied value that reaches attribution. A report is
	 * bounded against the interaction's own timestamp anyway, so this is the
	 * outer guard rather than the real one: it stops a nonsense clock — a
	 * device set to 2038 — from being stored as an occurrence date that no
	 * report will ever line up with.
	 */
	public const MAX_CLOCK_SKEW = 86400;

	/**
	 * Constructor.
	 *
	 * @param Fill_Service        $fill     Module gate.
	 * @param Fill_Token          $tokens   Token parser.
	 * @param Rate_Limiter        $limiter  Anonymous conversion bound.
	 * @param Conversion_Recorder $recorder Attribution and durable write.
	 */
	public function __construct(
		private readonly Fill_Service $fill,
		private readonly Fill_Token $tokens,
		private readonly Rate_Limiter $limiter,
		private readonly Conversion_Recorder $recorder
	) {
	}

	/**
	 * Attaches the route.
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		Creative_File_Controller::register_route(
			'/conversions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'record' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'token'           => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn ( mixed $value ): bool => is_string( $value )
							&& strlen( $value ) <= Fill_Token::MAX_LENGTH
							&& 1 === preg_match( '/^[0-9a-f.]+\z/', $value ),
					),
					'definition'      => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn ( mixed $value ): bool => is_string( $value )
							&& Conversion_Definition::is_valid_public_key( $value ),
					),
					'idempotency_key' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn ( mixed $value ): bool => is_string( $value )
							&& Conversion_Rules::is_valid_idempotency_key( $value ),
					),

					/*
					 * Optional, and absent means now. A page reporting an
					 * outcome as it happens has nothing useful to say here, and
					 * requiring it would make every integration send a clock we
					 * then have to distrust anyway.
					 */
					'occurred_at'     => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( mixed $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),
				),
			)
		);
	}

	/**
	 * Public when native delivery is on.
	 *
	 * **No cross-origin refusal here, unlike the beacon.** A conversion is
	 * reported by the advertiser's own site, so requiring same-origin would
	 * refuse every real report. Nothing is lost by allowing it: the request
	 * carries a signed token it cannot forge, spends one outcome exactly once
	 * against a database unique key, and can only credit a definition the
	 * campaign's organization owns. Origin was never the thing protecting this.
	 *
	 * @return true|WP_Error
	 */
	public function permission(): true|WP_Error {
		// Mirrors the beacon's gate. Unreachable from the Settings screen today,
		// because `Settings_Schema` forces native delivery on — kept so the two
		// public delivery routes cannot disagree if it ever becomes optional
		// again, which is the only reason the beacon still carries it either.
		if ( ! $this->fill->is_enabled() ) {
			return new WP_Error(
				'aggr_fill_disabled',
				__( 'Native delivery is off.', 'aggressive-ads' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/**
	 * Records one reported conversion.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function record( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( Delivery_Request::is_prefetch() || Delivery_Request::is_obvious_bot() ) {
			return $this->refused();
		}

		$limited = $this->limiter->attempt_for( Rate_Limiter::ACTION_CONVERSION, Rate_Limiter::client_subject() );

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$token = (string) $request->get_param( 'token' );

		/*
		 * Expired tokens are accepted, and must be. `Fill_Token::TTL_SECONDS`
		 * is five minutes and bounds when reporting may *start*; an attribution
		 * window is days. Refusing an expired token here would refuse every
		 * conversion that is not immediate, which is nearly all of them.
		 * Authenticity is the HMAC, not the clock.
		 */
		$parsed = $this->tokens->parse( $token, true );

		if ( null === $parsed ) {
			return $this->refused();
		}

		$occurred = $request->get_param( 'occurred_at' );
		$occurred = is_numeric( $occurred ) ? (int) $occurred : time();

		// The one client-supplied number that reaches attribution, bounded so a
		// broken clock cannot store an occurrence date nothing lines up with.
		if ( abs( time() - $occurred ) > self::MAX_CLOCK_SKEW ) {
			return $this->refused();
		}

		$result = $this->recorder->record(
			$parsed,
			$this->tokens->hash( $token ),
			(string) $request->get_param( 'definition' ),
			(string) $request->get_param( 'idempotency_key' ),
			$occurred,
			Conversion_Recorder::browser_source()
		);

		return match ( $result['outcome'] ) {
			Conversion_Recorder::RECORDED,
			Conversion_Recorder::RECORDED_PENDING => $this->accepted( 201 ),

			/*
			 * A duplicate is success, not a conflict. The reporter did its job;
			 * a retried beacon and a reloaded thank-you page are the normal way
			 * this endpoint is used, and answering 409 would make every correct
			 * integration look broken in somebody's console.
			 */
			Conversion_Recorder::DUPLICATE        => $this->accepted( 200 ),
			default                               => $this->refused(),
		};
	}

	/**
	 * One accepted report.
	 *
	 * The body says nothing about what was credited — not the campaign, not the
	 * value, not the definition's name. The caller is an advertiser's public
	 * page, and a response describing the attribution would tell anyone holding
	 * a token what it was worth.
	 *
	 * @param int $status 201 for a new outcome, 200 for one already counted.
	 */
	private function accepted( int $status ): WP_REST_Response {
		$response = new WP_REST_Response( array( 'ok' => true ), $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * One refusal, identical whatever the reason.
	 *
	 * Every rejection returns this: an unknown definition, an archived one, one
	 * belonging to another organization, a token that never clicked, and a
	 * report outside its window. Internally those are five different reasons and
	 * `Conversion_Attribution` keeps them apart for the operator; externally
	 * they must be one answer, or the endpoint becomes an oracle for which
	 * definitions exist and who owns them.
	 */
	private function refused(): WP_Error {
		return new WP_Error(
			'aggr_conversion_refused',
			__( 'That conversion could not be recorded.', 'aggressive-ads' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * The reason vocabulary, so nothing else has to restate it.
	 *
	 * @return list<string>
	 */
	public static function reasons(): array {
		return Conversion_Attribution::reasons();
	}
}
