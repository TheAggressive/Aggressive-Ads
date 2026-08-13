<?php
/**
 * Reading placements.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Capabilities;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The placements an advertiser can buy.
 *
 * Shared catalogue. Advertisers (portal) and editors (block inserter) may
 * read it. The response is width, height, name, and slug — never orphan
 * mapping meta, never a provider id.
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
		Creative_File_Controller::register_route(
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
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return current_user_can( Capabilities::ACCESS_PORTAL )
			|| current_user_can( 'edit_posts' )
			|| current_user_can( 'edit_theme_options' );
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
