<?php
/**
 * Reading placements.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

use Aggressive\Ads\Admin\Placement_Data;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Domain\Refresh_Policy;
use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Placement_Manager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The placements an advertiser can buy, and the catalogue staff maintain.
 *
 * Shared catalogue. Advertisers (portal) and editors (block inserter) may
 * read it. The response is width, height, name, and slug — never orphan
 * mapping meta, never a provider id.
 *
 * Reading and writing are separated by capability rather than by class: any
 * advertiser may see what is on offer, only `aggr_manage_placements` may decide
 * what is. The write half is what the Placements screen posts to.
 */
final class Placements_Controller implements Service {

	/**
	 * Constructor.
	 *
	 * @param Placement_Repository $placements Placement persistence.
	 * @param Placement_Manager    $manager    Staff catalogue writes.
	 * @param Placement_Data       $data       Catalogue view for the staff screen.
	 */
	public function __construct(
		private readonly Placement_Repository $placements,
		private readonly Placement_Manager $manager,
		private readonly Placement_Data $data
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
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		Creative_File_Controller::register_route(
			'/placements',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(),
			)
		);

		/*
		 * Not POST /placements. That path is the advertiser-readable catalogue,
		 * and hanging a staff write off the same route means one permission
		 * callback deciding two different questions the first time somebody
		 * adds a method to it.
		 */
		Creative_File_Controller::register_route(
			'/placements/catalogue',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( $this, 'write_permission' ),
				'args'                => array(),
			)
		);

		Creative_File_Controller::register_route(
			'/placements/(?P<id>\d+)',
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
	 * Whether the caller may use the portal at all.
	 *
	 * @return bool
	 */
	public function permission(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return current_user_can( Capabilities::ACCESS_PORTAL )
			|| current_user_can( 'edit_posts' )
			|| current_user_can( 'edit_theme_options' );
	}

	/**
	 * Whether the caller may change the catalogue.
	 *
	 * A different capability from reading it. Every advertiser sees the
	 * placements on offer; only staff decide what is offered, because a slot
	 * slug is what a published page renders an ad into.
	 *
	 * @return bool
	 */
	public function write_permission(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::MANAGE_PLACEMENTS );
	}

	/**
	 * Creates one placement.
	 *
	 * Thin over Placement_Manager, which owns validation, the capability check
	 * it repeats for its own audit trail, and the stable error codes. There is
	 * no second rule set here and there must not be one: the manager is what
	 * the integration tests exercise, so anything decided here would be
	 * untested by construction.
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
	 * Updates one placement.
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
			'aggr_forbidden'           => 403,
			'aggr_placement_not_found' => 404,
			default                    => 400,
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

		$fields = array(
			'name'                => isset( $body['name'] ) && is_string( $body['name'] ) ? $body['name'] : '',
			'slug'                => isset( $body['slug'] ) && is_string( $body['slug'] ) ? $body['slug'] : '',
			'size_preset'         => isset( $body['size_preset'] ) && is_string( $body['size_preset'] ) ? $body['size_preset'] : '',
			'size_width'          => isset( $body['size_width'] ) ? (int) $body['size_width'] : 0,
			'size_height'         => isset( $body['size_height'] ) ? (int) $body['size_height'] : 0,
			'sort_order'          => isset( $body['sort_order'] ) ? (int) $body['sort_order'] : 0,
			'is_active'           => ! empty( $body['is_active'] ),
			'house_attachment_id' => isset( $body['house_attachment_id'] ) ? absint( $body['house_attachment_id'] ) : 0,
			'house_click_url'     => isset( $body['house_click_url'] ) && is_string( $body['house_click_url'] ) ? $body['house_click_url'] : '',
			'house_alt'           => isset( $body['house_alt'] ) && is_string( $body['house_alt'] ) ? $body['house_alt'] : '',
		);

		/*
		 * Only when the client named them. Forcing the keys on every write
		 * would make an omitted `refresh_seconds` a zero, which the policy
		 * floors to one second — so a rename that did not mention refresh
		 * would silently tighten every placement to the floor.
		 */
		if (
			array_key_exists( 'refresh_enabled', $body )
			|| array_key_exists( 'refresh_seconds', $body )
			|| array_key_exists( 'refresh_max_per_view', $body )
		) {
			$defaults                       = Refresh_Policy::defaults();
			$fields['refresh_enabled']      = ! empty( $body['refresh_enabled'] );
			$fields['refresh_seconds']      = isset( $body['refresh_seconds'] )
				? (int) $body['refresh_seconds']
				: $defaults->interval_seconds;
			$fields['refresh_max_per_view'] = isset( $body['refresh_max_per_view'] )
				? (int) $body['refresh_max_per_view']
				: $defaults->max_per_view;
		}

		/*
		 * Breakpoints follow the same rule as the refresh policy, for the same
		 * reason: an omitted key must mean "unchanged", never "cleared".
		 *
		 * A rename that did not mention sizes would otherwise write an empty
		 * map, and `Size_Map` reads an empty map as "not a map" and falls back
		 * to the single stored size. A publisher's responsive placement would
		 * quietly become fixed, serving its base everywhere, with the screen
		 * still showing the breakpoints they had configured.
		 */
		if ( array_key_exists( 'breakpoints', $body ) ) {
			$fields['breakpoints'] = is_array( $body['breakpoints'] ) ? $body['breakpoints'] : array();
		}

		return $fields;
	}

	/**
	 * Every placement currently on offer.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$placements = array();

		foreach ( $this->placements->active_ids() as $placement_id ) {
			$size   = $this->placements->size( $placement_id );
			$parsed = Campaign_Rules::parse_size( $size );

			$placements[] = array(
				'id'        => $placement_id,
				'name'      => $this->placements->name( $placement_id ),
				'slug'      => $this->placements->slug( $placement_id ),
				'size'      => $size,
				'width'     => null === $parsed ? 0 : $parsed[0],
				'height'    => null === $parsed ? 0 : $parsed[1],

				// Everything an upload form needs to tell somebody what to
				// prepare *before* they try, rather than after a rejection.
				'accepts'   => Upload_Rules::ALLOWED_MIME,
				'max_bytes' => Upload_Rules::MAX_BYTES,
			);
		}

		return new WP_REST_Response( array( 'placements' => $placements ), 200 );
	}
}
