<?php
/**
 * Staff routes for conversion definitions.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Conversion_Definition;
use Aggressive\Ads\Repository\Conversion_Definition_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Conversion_Definition_Manager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Reading and writing what counts as a conversion. Staff only, on every verb.
 */
final class Conversion_Definitions_Controller implements Service {

	/**
	 * Constructor.
	 *
	 * @param Conversion_Definition_Repository $definitions Definition persistence.
	 * @param Conversion_Definition_Manager    $manager     Validation, capability and audit.
	 */
	public function __construct(
		private readonly Conversion_Definition_Repository $definitions,
		private readonly Conversion_Definition_Manager $manager
	) {
	}

	/**
	 * Attaches the routes.
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		Creative_File_Controller::register_route(
			'/conversion-definitions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(),
			)
		);

		Creative_File_Controller::register_route(
			'/conversion-definitions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(),
			)
		);

		Creative_File_Controller::register_route(
			'/conversion-definitions/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( mixed $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),
				),
			)
		);
	}

	/**
	 * Whether the caller may see or change definitions.
	 *
	 * One capability for both, unlike packages. A definition is not a catalogue
	 * an advertiser browses — it carries the public key a page reports against,
	 * so reading it is as sensitive as writing it.
	 */
	public function permission(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::MANAGE_SETTINGS );
	}

	/**
	 * Every definition.
	 *
	 * @return WP_REST_Response
	 */
	public function index(): WP_REST_Response {
		$rows = array_map(
			static fn ( array $row ): array => self::view( $row ),
			$this->definitions->all()
		);

		$response = new WP_REST_Response( array( 'definitions' => $rows ), 200 );

		/*
		 * These carry the public keys pages report against. A shared cache
		 * holding a staff response and serving it to somebody else would hand
		 * over every one of them.
		 */
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Creates one definition.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->manager->create( self::fields( $request ) );

		if ( is_wp_error( $result ) ) {
			return self::as_response_error( $result );
		}

		$created = $this->definitions->find( $result );

		$response = new WP_REST_Response(
			array( 'definition' => null === $created ? array() : self::view( $created ) ),
			201
		);

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Updates one definition.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id       = (int) $request->get_param( 'id' );
		$revision = $request->get_param( 'revision' );

		$result = $this->manager->update(
			$id,
			self::fields( $request ),
			is_numeric( $revision ) ? (int) $revision : 0
		);

		if ( is_wp_error( $result ) ) {
			return self::as_response_error( $result );
		}

		$updated = $this->definitions->find( $id );

		$response = new WP_REST_Response(
			array( 'definition' => null === $updated ? array() : self::view( $updated ) ),
			200
		);

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The fields a client may set, and only those.
	 *
	 * An allowlist rather than the whole body: `public_key`, `revision`,
	 * `created_at_ts` and `id` are all columns, and none of them is a client's
	 * to choose. A caller that could set `public_key` could reuse one it had
	 * already learned.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array<string, mixed>
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private static function fields( WP_REST_Request $request ): array {
		return array(
			'name'                 => $request->get_param( 'name' ),
			'org_id'               => $request->get_param( 'org_id' ),
			'window_seconds'       => $request->get_param( 'window_seconds' ),
			'default_value_micros' => $request->get_param( 'default_value_micros' ),
			'currency'             => $request->get_param( 'currency' ),
			'allow_s2s'            => $request->get_param( 'allow_s2s' ),
			'status'               => $request->get_param( 'status' ),
		);
	}

	/**
	 * One definition as the staff screen sees it.
	 *
	 * @param array<string, mixed> $row Shaped row.
	 * @return array<string, mixed>
	 */
	private static function view( array $row ): array {
		return array(
			'id'                   => $row['id'],
			'org_id'               => $row['org_id'],
			'public_key'           => $row['public_key'],
			'name'                 => $row['name'],
			'window_seconds'       => $row['window_seconds'],
			'default_value_micros' => $row['default_value_micros'],
			'currency'             => $row['currency'],
			'allow_s2s'            => $row['allow_s2s'],
			'status'               => $row['status'],
			'accepts_reports'      => Conversion_Definition::accepts_reports( (string) $row['status'] ),
			'revision'             => $row['revision'],
			'updated_at_ts'        => $row['updated_at_ts'],
		);
	}

	/**
	 * Gives a workflow error an HTTP status without rewording it.
	 *
	 * @param WP_Error $error Workflow error.
	 * @return WP_Error
	 */
	private static function as_response_error( WP_Error $error ): WP_Error {
		$status = match ( $error->get_error_code() ) {
			'aggr_forbidden'                       => 403,
			'aggr_conversion_definition_not_found' => 404,
			'aggr_conversion_definition_stale'     => 409,
			default                                => 400,
		};

		$data = $error->get_error_data();

		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			array_merge( is_array( $data ) ? $data : array(), array( 'status' => $status ) )
		);
	}
}
