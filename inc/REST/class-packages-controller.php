<?php
/**
 * Reading the package catalogue.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\REST;

use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Repository\Package_Repository;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;
use LAAO_Advertiser_Portal\Security\Capabilities;
use LAAO_Advertiser_Portal\Workflow\Campaign_Editor;
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
	 */
	public function __construct(
		private readonly Package_Repository $packages,
		private readonly Placement_Repository $placements,
		private readonly Campaign_Editor $editor
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
		register_rest_route(
			Creative_File_Controller::NAMESPACE,
			'/packages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(),
			)
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
		$rows = array();

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
				'id'            => $package_id,
				'name'          => $this->packages->name( $package_id ),
				'duration_days' => $this->packages->duration_days( $package_id ),
				'price_cents'   => $snapshot['budget_cents'],
				'currency'      => $snapshot['currency'],
				'placements'    => $placements,
			);
		}

		return new WP_REST_Response( array( 'packages' => $rows ), 200 );
	}
}
