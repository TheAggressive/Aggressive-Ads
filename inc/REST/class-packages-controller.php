<?php
/**
 * Reading the package catalogue.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Admin\Package_Data;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Campaign_Editor;
use Aggressive\Ads\Workflow\Package_Manager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Exposes only packages that the shared selection workflow would accept.
 */
final class Packages_Controller implements Service {

	/**
	 * Constructor.
	 *
	 * @param Package_Repository   $packages   Package persistence.
	 * @param Placement_Repository $placements Placement labels.
	 * @param Campaign_Editor      $editor     Shared package validation.
	 * @param Package_Manager      $manager    Staff catalogue writes.
	 * @param Package_Data         $data       Catalogue view for the staff screen.
	 */
	public function __construct(
		private readonly Package_Repository $packages,
		private readonly Placement_Repository $placements,
		private readonly Campaign_Editor $editor,
		private readonly Package_Manager $manager,
		private readonly Package_Data $data
	) {
	}

	/**
	 * Attaches the route.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the catalogue route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		Creative_File_Controller::register_route(
			'/packages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(),
			)
		);

		Creative_File_Controller::register_route(
			'/packages/catalogue',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( $this, 'write_permission' ),
				'args'                => array(),
			)
		);

		Creative_File_Controller::register_route(
			'/packages/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update' ),
				'permission_callback' => array( $this, 'write_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),
				),
			)
		);
	}

	/**
	 * Whether the caller may change the catalogue.
	 *
	 * A different capability from reading it. Any advertiser may see what is on
	 * sale; only staff may decide what is.
	 *
	 * @return bool
	 */
	public function write_permission(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::MANAGE_PACKAGES );
	}

	/**
	 * Creates one package.
	 *
	 * Thin over Package_Manager, which owns validation, the capability check it
	 * repeats for its own audit trail, and the stable error codes. There is no
	 * second rule set here and there must not be one: the manager is what the
	 * integration tests exercise, so anything decided here would be untested by
	 * construction.
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

		return new WP_REST_Response(
			array(
				'id'   => $result,
				'view' => $this->data->view(),
			),
			201
		);
	}

	/**
	 * Updates one package.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->manager->update( (int) $request->get_param( 'id' ), self::fields( $request ) );

		if ( is_wp_error( $result ) ) {
			return self::as_response_error( $result );
		}

		return new WP_REST_Response( array( 'view' => $this->data->view() ), 200 );
	}

	/**
	 * Gives a workflow error an HTTP status without rewording it.
	 *
	 * The manager's messages are already translated and already written for the
	 * person reading the screen, so they travel to the client unchanged. Only
	 * the status is decided here, because the workflow has no opinion about HTTP.
	 *
	 * @param WP_Error $error Workflow error.
	 * @return WP_Error
	 */
	private static function as_response_error( WP_Error $error ): WP_Error {
		$status = match ( $error->get_error_code() ) {
			'aggr_forbidden'         => 403,
			'aggr_package_not_found' => 404,
			default                  => 400,
		};

		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			array( 'status' => $status )
		);
	}

	/**
	 * The catalogue fields, allowlisted by name.
	 *
	 * Reading a fixed list rather than whatever arrived is what stops an extra
	 * key in the body from reaching the workflow. Values are shaped only to
	 * their type here; whether they are allowed is the manager's decision.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array<string, mixed>
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private static function fields( WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();

		$placements = array();

		if ( isset( $body['placement_ids'] ) && is_array( $body['placement_ids'] ) ) {
			foreach ( $body['placement_ids'] as $raw ) {
				$placements[] = absint( $raw );
			}
		}

		return array(
			'name'            => isset( $body['name'] ) && is_string( $body['name'] ) ? $body['name'] : '',
			'placement_ids'   => $placements,
			'duration_days'   => isset( $body['duration_days'] ) ? absint( $body['duration_days'] ) : 0,
			'custom_duration' => ! empty( $body['custom_duration'] ),
			'price_cents'     => isset( $body['price_cents'] ) && is_numeric( $body['price_cents'] ) ? (int) $body['price_cents'] : -1,
			'currency'        => isset( $body['currency'] ) && is_string( $body['currency'] ) ? $body['currency'] : '',
			'is_active'       => ! empty( $body['is_active'] ),
			'is_default'      => ! empty( $body['is_default'] ),
		);
	}

	/**
	 * Whether the caller may read shared campaign configuration.
	 *
	 * @return bool
	 */
	public function permission(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::ACCESS_PORTAL );
	}

	/**
	 * Every active and internally complete package.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$rows       = array();
		$default_id = $this->packages->default_id();

		foreach ( $this->packages->active_ids() as $package_id ) {
			$snapshot = $this->editor->package_snapshot( $package_id );

			if ( is_wp_error( $snapshot ) ) {
				continue;
			}

			$placements = array();

			foreach ( $snapshot['placement_ids'] as $placement_id ) {
				$placements[] = array(
					'id'   => $placement_id,
					'name' => $this->placements->name( $placement_id ),
					'size' => $this->placements->size( $placement_id ),
				);
			}

			$rows[] = array(
				'id'              => $package_id,
				'name'            => $this->packages->name( $package_id ),
				'duration_days'   => $this->packages->duration_days( $package_id ),
				'custom_duration' => $this->packages->has_custom_duration( $package_id ),
				'is_default'      => $package_id === $default_id,
				'price_cents'     => $snapshot['budget_cents'],
				'currency'        => $snapshot['currency'],
				'placements'      => $placements,
			);
		}

		return new WP_REST_Response( array( 'packages' => $rows ), 200 );
	}
}
