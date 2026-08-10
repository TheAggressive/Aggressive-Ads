<?php
/**
 * Reading placements.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\REST;

use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Domain\Campaign_Rules;
use LAAO_Advertiser_Portal\Domain\Upload_Rules;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;
use LAAO_Advertiser_Portal\Security\Capabilities;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The placements an advertiser can buy.
 *
 * Shared configuration rather than anybody's data, so there is no ownership
 * question here — but there is a disclosure one. **The ad-group term id never
 * appears in the response.** It is the mapping between our placements and
 * AdSanity's delivery, it is meaningless to an advertiser, and advertisers
 * never see AdSanity terminology at all.
 */
final class Placements_Controller implements Service {

	/**
	 * Constructor.
	 *
	 * @param Placement_Repository $placements Placement persistence.
	 */
	public function __construct( private readonly Placement_Repository $placements ) {
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
		register_rest_route(
			Creative_File_Controller::NAMESPACE,
			'/placements',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * Whether the caller may use the portal at all.
	 *
	 * @return bool
	 */
	public function permission(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::ACCESS_PORTAL );
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
