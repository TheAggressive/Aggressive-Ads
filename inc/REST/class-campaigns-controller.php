<?php
/**
 * Reading campaigns.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\REST;

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Domain\Transition_Table;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Repository\Creative_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;
use LAAO_Advertiser_Portal\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The advertiser's own campaigns, and one campaign in detail.
 *
 * **`org_id` is never a parameter.** It is derived from the authenticated user
 * on every request, which is the single rule that collapses most of the
 * object-reference attack surface. A caller cannot ask for somebody else's
 * campaigns because there is nowhere to say whose.
 *
 * Responses are shaped field by field. Serializing whatever meta exists is how
 * internal notes, reviewer identities and provider ids leak the first time
 * somebody adds one — every one of those is on the campaign object.
 */
final class Campaigns_Controller implements Service {

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository  $campaigns  Campaign persistence.
	 * @param Creative_Repository  $creatives  Creative persistence.
	 * @param Placement_Repository $placements Placement persistence.
	 * @param Org_Repository       $orgs       Organization lookups.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Placement_Repository $placements,
		private readonly Org_Repository $orgs
	) {
	}

	/**
	 * Attaches the routes.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			Creative_File_Controller::NAMESPACE,
			'/campaigns',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'page' => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),
				),
			)
		);

		register_rest_route(
			Creative_File_Controller::NAMESPACE,
			'/campaigns/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'permission' ),
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
	 * Feature-level on purpose. An object-level denial here would answer 403 on
	 * a real id and 404 on an imaginary one, which is an object-id oracle; the
	 * object check happens in the handler, where both answers are 404.
	 *
	 * @return bool
	 */
	public function permission(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::ACCESS_PORTAL );
	}

	/**
	 * One page of the caller's own campaigns.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$org_ids = $this->orgs->org_ids_for_user( get_current_user_id() );

		// No organization means no campaigns, not everybody's campaigns.
		if ( array() === $org_ids ) {
			return $this->paged( array(), 0, 0, 1 );
		}

		$page = max( 1, (int) $request->get_param( 'page' ) );
		$rows = $this->campaigns->for_org( $org_ids[0], $page );

		$campaigns = array();

		foreach ( $rows['ids'] as $campaign_id ) {
			$campaigns[] = $this->summary( $campaign_id );
		}

		return $this->paged( $campaigns, $rows['total'], $rows['pages'], $page );
	}

	/**
	 * One campaign in detail.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function show( WP_REST_Request $request ) {
		$campaign_id = (int) $request->get_param( 'id' );

		// One answer for "not yours" and "does not exist". Anything that
		// distinguishes them is an oracle for enumerating the id space.
		if ( ! current_user_can( 'read_laao_ads_campaign', $campaign_id ) ) {
			return new WP_Error(
				'laao_ads_not_found',
				__( 'Not found.', 'laao-advertiser-portal' ),
				array( 'status' => 404 )
			);
		}

		$detail              = $this->summary( $campaign_id );
		$detail['creatives'] = $this->creatives_for( $campaign_id );

		return new WP_REST_Response( $detail, 200 );
	}

	/**
	 * The advertiser-facing shape of a campaign.
	 *
	 * Built explicitly. Absent by design: internal notes, the reviewer's
	 * identity, provider ad ids, and anything about ad groups — none of which
	 * an advertiser has any business seeing, and all of which live on this
	 * object.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<string, mixed>
	 */
	private function summary( int $campaign_id ): array {
		$status = $this->campaigns->status( $campaign_id );

		return array(
			'id'            => $campaign_id,
			'title'         => $this->campaigns->title( $campaign_id ),
			'status'        => $status,
			'status_label'  => $this->status_label( $status ),
			'start_ts'      => $this->campaigns->start_ts( $campaign_id ),
			'end_ts'        => $this->campaigns->end_ts( $campaign_id ),
			'submitted_at'  => $this->campaigns->submitted_at( $campaign_id ),
			'revision'      => $this->campaigns->revision( $campaign_id ),

			// Advertiser-visible feedback, which is the whole point of the
			// field. Internal notes are a different key and never appear here.
			'review_notes'  => $this->campaigns->review_notes( $campaign_id ),

			'editable'      => in_array( $status, Post_Statuses::advertiser_editable(), true ),
			'placement_ids' => $this->campaigns->placement_ids( $campaign_id ),

			// What this advertiser could do next, so a UI does not have to
			// reimplement the transition table to decide which buttons to draw.
			'actions'       => $this->actions( $campaign_id, $status ),
		);
	}

	/**
	 * The creatives on a campaign, in advertiser-facing shape.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, array<string, mixed>>
	 */
	private function creatives_for( int $campaign_id ): array {
		$creatives = array();

		foreach ( $this->creatives->for_campaign( $campaign_id ) as $creative ) {
			$creatives[] = array(
				'id'             => $creative['id'],
				'placement_id'   => $creative['placement_id'],
				'placement_name' => $this->placements->name( $creative['placement_id'] ),
				'width'          => $creative['width'],
				'height'         => $creative['height'],
				'click_url'      => $creative['click_url'],
				'alt_text'       => $creative['alt_text'],

				// The authorized stream, never a path into private storage.
				'file_url'       => rest_url(
					sprintf( '%s/creatives/%d/file', Creative_File_Controller::NAMESPACE, $creative['id'] )
				),
			);
		}

		return $creatives;
	}

	/**
	 * The transitions this caller may currently make.
	 *
	 * Advisory only — the state machine authorizes the edge again when it is
	 * actually attempted. This exists so a UI can hide a button it would be
	 * refused for, not so it can decide anything.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $status      Current status.
	 * @return array<int, string>
	 */
	private function actions( int $campaign_id, string $status ): array {
		$available = array();

		foreach ( Transition_Table::available_to( $status, Transition_Table::ACTOR_ADVERTISER ) as $transition ) {
			$permitted = true;

			foreach ( $transition->capabilities as $capability ) {
				if ( ! current_user_can( $capability, $campaign_id ) ) {
					$permitted = false;

					break;
				}
			}

			if ( $permitted ) {
				$available[] = $transition->to;
			}
		}

		return $available;
	}

	/**
	 * The human label for a status.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	private function status_label( string $status ): string {
		$object = get_post_status_object( $status );

		return null === $object ? $status : (string) $object->label;
	}

	/**
	 * A paged collection response.
	 *
	 * Totals travel in headers as well as the body, because that is where a
	 * client looks for them and where core's own collections put them.
	 *
	 * @param array<int, array<string, mixed>> $items Items for this page.
	 * @param int                              $total Total across all pages.
	 * @param int                              $pages Number of pages.
	 * @param int                              $page  Current page.
	 * @return WP_REST_Response
	 */
	private function paged( array $items, int $total, int $pages, int $page ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'campaigns' => $items,
				'page'      => $page,
				'pages'     => $pages,
				'total'     => $total,
			),
			200
		);

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $pages );

		return $response;
	}
}
