<?php
/**
 * The credentialed conversion report.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Conversion_Credential;
use Aggressive\Ads\Domain\Conversion_Definition;
use Aggressive\Ads\Domain\Conversion_Rules;
use Aggressive\Ads\Repository\Conversion_Definition_Repository;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Workflow\Conversion_Credential_Manager;
use Aggressive\Ads\Workflow\Conversion_Recorder;
use Aggressive\Ads\Workflow\Fill_Service;
use Aggressive\Ads\Workflow\Fill_Token;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /aggr/v1/conversions/server — reported by the advertiser's own server.
 *
 * **A second route rather than a mode of the browser one**, and the separation
 * is the security property rather than tidiness. This route accepts a value and
 * a currency; the browser route has no such parameter at all, so "an anonymous
 * browser may never state what its outcome was worth" is a fact about the URL
 * space instead of a conditional somebody can widen later.
 *
 * Everything else it shares with the browser route it shares deliberately: the
 * same signed token, the same idempotency key, the same single refusal for
 * every reason. What differs is that the caller is authenticated, so its rate
 * limit is per credential and its ceiling is a shop's checkout volume rather
 * than one shopper's.
 */
final class Server_Conversions_Controller implements Service {

	/**
	 * Constructor.
	 *
	 * @param Fill_Service                     $fill        Module gate.
	 * @param Fill_Token                       $tokens      Token parser.
	 * @param Rate_Limiter                     $limiter     Per-credential bound.
	 * @param Conversion_Recorder              $recorder    Attribution and durable write.
	 * @param Conversion_Credential_Manager    $credentials Bearer verification.
	 * @param Conversion_Definition_Repository $definitions Currency bounds.
	 */
	public function __construct(
		private readonly Fill_Service $fill,
		private readonly Fill_Token $tokens,
		private readonly Rate_Limiter $limiter,
		private readonly Conversion_Recorder $recorder,
		private readonly Conversion_Credential_Manager $credentials,
		private readonly Conversion_Definition_Repository $definitions
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
			'/conversions/server',
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
					'occurred_at'     => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( mixed $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),

					/*
					 * The two parameters that exist here and nowhere else.
					 *
					 * Optional together: a reporter that states neither gets the
					 * definition's default, which is what an integration
					 * reporting signups rather than orders wants. Stating one
					 * without the other is refused below rather than defaulted,
					 * because a value with an assumed currency is how a shop
					 * denominated in one currency silently reports totals in
					 * another.
					 */
					'value_micros'    => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( mixed $value ): bool => is_numeric( $value )
							&& Conversion_Rules::is_valid_value_micros( (int) $value ),
					),
					'currency'        => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn ( mixed $value ): bool => is_string( $value )
							&& Conversion_Rules::is_valid_currency( $value ),
					),
				),
			)
		);
	}

	/**
	 * Public when native delivery is on, then authenticated by the bearer.
	 *
	 * The credential is verified in the callback rather than here, deliberately.
	 * A `permission_callback` answers before the workflow does, so a denial here
	 * would never reach `Conversion_Credential_Manager` and never write the
	 * audit row that says a revoked secret is still being presented — which is
	 * the one signal an operator wants after revoking one.
	 *
	 * @return true|WP_Error
	 */
	public function permission(): true|WP_Error {
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
	 * Records one credentialed conversion.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function record( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$presented = Conversion_Credential::token_from_header(
			(string) $request->get_header( 'authorization' )
		);

		if ( '' === $presented ) {
			return $this->unauthorized();
		}

		$credential = $this->credentials->authenticate( $presented );

		if ( null === $credential ) {
			return $this->unauthorized();
		}

		/*
		 * Counted per credential and only after it verified. Limiting before
		 * authentication would let anyone with the URL spend a real
		 * integration's budget, and limiting by address would put a whole shop's
		 * checkout volume through a bucket sized for one shopper.
		 */
		$limited = $this->limiter->attempt_for(
			Rate_Limiter::ACTION_CONVERSION_S2S,
			'credential:' . $credential['id']
		);

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$parsed = $this->tokens->parse( (string) $request->get_param( 'token' ), true );

		if ( null === $parsed ) {
			return $this->refused();
		}

		$occurred = $request->get_param( 'occurred_at' );
		$occurred = is_numeric( $occurred ) ? (int) $occurred : time();

		if ( abs( time() - $occurred ) > Conversions_Controller::MAX_CLOCK_SKEW ) {
			return $this->refused();
		}

		$reported = $this->stated_value( $request, (string) $request->get_param( 'definition' ) );

		if ( is_wp_error( $reported ) ) {
			return $reported;
		}

		$result = $this->recorder->record_from_server(
			$parsed,
			$this->tokens->hash( (string) $request->get_param( 'token' ) ),
			(string) $request->get_param( 'definition' ),
			(string) $request->get_param( 'idempotency_key' ),
			$occurred,
			$credential['org_id'],
			$reported
		);

		return match ( $result['outcome'] ) {
			Conversion_Recorder::RECORDED,
			Conversion_Recorder::RECORDED_PENDING => $this->accepted( 201 ),
			Conversion_Recorder::DUPLICATE        => $this->accepted( 200 ),
			default                               => $this->refused(),
		};
	}

	/**
	 * The value and currency this report states, if it states them.
	 *
	 * Both or neither. A value without a currency would be stored against the
	 * definition's, which is the case where a shop denominated in euros silently
	 * reports dollars — and nothing downstream could ever detect it, because the
	 * row would look exactly like a correct one.
	 *
	 * A currency that disagrees with the definition is refused rather than
	 * converted. This plugin holds no exchange rate, and storing two currencies
	 * under one definition makes every total it produces a meaningless sum.
	 *
	 * @param WP_REST_Request $request    The request.
	 * @param string          $public_key Definition the reporter named.
	 * @return array{value_micros: int, currency: string}|null|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private function stated_value( WP_REST_Request $request, string $public_key ): array|null|WP_Error {
		$value    = $request->get_param( 'value_micros' );
		$currency = $request->get_param( 'currency' );

		if ( null === $value && null === $currency ) {
			return null;
		}

		if ( null === $value || null === $currency ) {
			return new WP_Error(
				'aggr_conversion_value_incomplete',
				__( 'State value_micros and currency together, or neither.', 'aggressive-ads' ),
				array( 'status' => 422 )
			);
		}

		$definition = $this->definitions->find_by_public_key( $public_key );

		/*
		 * A missing definition is answered by the ordinary refusal rather than
		 * by a currency complaint. Saying "that currency does not match" about a
		 * definition that does not exist would confirm which public keys are
		 * real, which is what the single refusal exists to prevent.
		 */
		if ( null === $definition ) {
			return $this->refused();
		}

		if ( (string) $currency !== (string) $definition['currency'] ) {
			return $this->refused();
		}

		return array(
			'value_micros' => (int) $value,
			'currency'     => (string) $currency,
		);
	}

	/**
	 * One accepted report.
	 *
	 * The body says nothing about what was credited, exactly as the browser
	 * route's does not. A credential is scoped to an organization, not to a
	 * campaign, so a response describing the attribution would tell one
	 * integration which of its advertiser's campaigns a given token came from.
	 *
	 * @param int $status 201 for a new outcome, 200 for one already counted.
	 */
	private function accepted( int $status ): WP_REST_Response {
		$response = new WP_REST_Response( array( 'ok' => true ), $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * No usable credential, whatever the reason.
	 *
	 * A malformed header, an unknown secret and a revoked one are one answer.
	 * Distinguishing the revoked case would tell somebody holding a leaked
	 * credential that it was leaked and cut off, which is information for the
	 * operator rather than for them — it goes to the audit log instead.
	 */
	private function unauthorized(): WP_Error {
		return new WP_Error(
			'aggr_credential_invalid',
			__( 'That credential is not usable.', 'aggressive-ads' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * One refusal, identical whatever the reason.
	 *
	 * The same answer the browser route gives, and it now covers two more
	 * reasons: a definition that does not permit server reports, and one
	 * belonging to another organization. Both must be indistinguishable from an
	 * unknown definition, or a credential for one advertiser becomes a way to
	 * discover another advertiser's definitions.
	 */
	private function refused(): WP_Error {
		return new WP_Error(
			'aggr_conversion_refused',
			__( 'That conversion could not be recorded.', 'aggressive-ads' ),
			array( 'status' => 400 )
		);
	}
}
