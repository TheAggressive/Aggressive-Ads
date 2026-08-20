<?php
/**
 * Reading campaigns.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Transition_Table;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Workflow\Edit_Window;
use Aggressive\Ads\Workflow\Campaign_Copier;
use Aggressive\Ads\Workflow\Campaign_Editor;
use Aggressive\Ads\Workflow\Reporting_Read;
use Aggressive\Ads\Workflow\Review_Readiness;
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
	 * @param Campaign_Editor      $editor     Draft creation and editing.
	 * @param Campaign_Copier      $copier     Campaign copy into a new draft.
	 * @param Review_Readiness     $readiness  Safe canonical review readiness.
	 * @param Rate_Limiter         $limiter    Autosave abuse bounding.
	 * @param Reporting_Read       $reporting  Native rollup reads.
	 * @param Edit_Window          $window     When editing is permitted.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Placement_Repository $placements,
		private readonly Org_Repository $orgs,
		private readonly Campaign_Editor $editor,
		private readonly Campaign_Copier $copier,
		private readonly Review_Readiness $readiness,
		private readonly Rate_Limiter $limiter,
		private readonly Reporting_Read $reporting,
		private readonly Edit_Window $window
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
		Creative_File_Controller::register_route(
			'/campaigns',
			array(
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
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'write_permission' ),
					'args'                => array(
						'title' => $this->string_arg( false ),
					),
				),
			)
		);

		/*
		 * Separate from POST /campaigns on purpose. That route derives the
		 * tenant from the caller and must keep doing so — an org_id parameter
		 * there would be ignored on update and authoritative on create, which
		 * is the kind of asymmetry nobody remembers at the call site. Here
		 * naming an advertiser is the entire point of the route, and the
		 * permission callback says so.
		 */
		Creative_File_Controller::register_route(
			'/campaigns/for-advertiser',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_for_advertiser' ),
					'permission_callback' => array( $this, 'staff_write_permission' ),
					'args'                => array(
						'title'  => $this->string_arg( false ),
						'org_id' => $this->positive_int_arg( true ),
					),
				),
			)
		);

		Creative_File_Controller::register_route(
			'/campaigns/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'id' => $this->positive_int_arg( true ),
					),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'write_permission' ),
					'args'                => array(
						'id'               => $this->positive_int_arg( true ),
						'title'            => $this->string_arg( false ),
						'package_id'       => $this->nonnegative_int_arg( false ),
						'placement_ids'    => array(
							'type'              => 'array',
							'required'          => false,
							'items'             => array( 'type' => 'integer' ),
							'sanitize_callback' => array( self::class, 'sanitize_ids' ),
							'validate_callback' => array( self::class, 'validate_ids' ),
						),
						'start_ts'         => $this->nonnegative_int_arg( false ),
						'end_ts'           => $this->nonnegative_int_arg( false ),
						'advertiser_notes' => $this->textarea_arg( false ),
						'wizard_step'      => $this->string_arg( false ),
						'autosave_rev'     => $this->nonnegative_int_arg( true ),
					),
				),
			)
		);

		Creative_File_Controller::register_route(
			'/campaigns/(?P<id>\d+)/copy',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'copy' ),
				'permission_callback' => array( $this, 'write_permission' ),
				'args'                => array(
					'id' => $this->positive_int_arg( true ),
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
	 * Whether the caller may reach a campaign write workflow.
	 *
	 * Object-level ownership remains in Campaign_Editor, where a failed write
	 * is audited consistently regardless of delivery layer.
	 *
	 * @return bool
	 */
	public function write_permission(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::SUBMIT_CAMPAIGN );
	}

	/**
	 * Creates an organization-scoped draft.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function create( WP_REST_Request $request ) {
		$campaign_id = $this->editor->create( (string) ( $request->get_param( 'title' ) ?? '' ) );

		if ( is_wp_error( $campaign_id ) ) {
			return $campaign_id;
		}

		return new WP_REST_Response( $this->advertised_summary( $campaign_id ), 201 );
	}

	/**
	 * Creates a draft for an advertiser the caller names.
	 *
	 * The permission callback gates the route, and `create_for_org()` checks
	 * the capability again. That is not redundant: the admin screen reaches
	 * the editor through the same method, and a rule enforced only at one
	 * door is a rule that holds only while nobody adds another.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function create_for_advertiser( WP_REST_Request $request ) {
		$campaign_id = $this->editor->create_for_org(
			(int) $request->get_param( 'org_id' ),
			(string) ( $request->get_param( 'title' ) ?? '' )
		);

		if ( is_wp_error( $campaign_id ) ) {
			return $campaign_id;
		}

		return new WP_REST_Response( $this->advertised_summary( $campaign_id ), 201 );
	}

	/**
	 * Whether the caller may write on an advertiser's behalf.
	 *
	 * @return bool
	 */
	public function staff_write_permission(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::REVIEW_CAMPAIGNS );
	}

	/**
	 * Autosaves the public campaign field allowlist.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update( WP_REST_Request $request ) {
		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_AUTOSAVE, get_current_user_id() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$fields = array();

		foreach ( array( 'title', 'package_id', 'placement_ids', 'start_ts', 'end_ts', 'advertiser_notes', 'wizard_step' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$fields[ $field ] = $request->get_param( $field );
			}
		}

		$campaign_id = (int) $request->get_param( 'id' );
		$revision    = $this->editor->save( $campaign_id, $fields, (int) $request->get_param( 'autosave_rev' ) );

		if ( is_wp_error( $revision ) ) {
			return $revision;
		}

		return new WP_REST_Response( $this->advertised_summary( $campaign_id ), 200 );
	}

	/**
	 * Copies a campaign into a new organization-scoped draft.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function copy( WP_REST_Request $request ) {
		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_COPY, get_current_user_id() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$campaign_id = $this->copier->copy( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $campaign_id ) ) {
			return $campaign_id;
		}

		return new WP_REST_Response( $this->advertised_summary( $campaign_id ), 201 );
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

		return $this->paged( $this->reporting->attach( $campaigns ), $rows['total'], $rows['pages'], $page );
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
		if ( ! current_user_can( 'read_aggr_campaign', $campaign_id ) ) {
			return new WP_Error(
				'aggr_not_found',
				__( 'Not found.', 'aggressive-ads' ),
				array( 'status' => 404 )
			);
		}

		$detail              = $this->advertised_summary( $campaign_id );
		$detail['creatives'] = $this->creatives_for( $campaign_id );
		$detail['readiness'] = $this->readiness->for_campaign( $campaign_id );

		return new WP_REST_Response( $detail, 200 );
	}

	/**
	 * One campaign summary with metrics attached when the surface is on.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<string, mixed>
	 */
	private function advertised_summary( int $campaign_id ): array {
		return $this->reporting->attach_one( $this->summary( $campaign_id ) );
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
			'id'               => $campaign_id,
			'title'            => $this->campaigns->title( $campaign_id ),
			'status'           => $status,
			'status_label'     => $this->status_label( $status ),
			'start_ts'         => $this->campaigns->start_ts( $campaign_id ),
			'end_ts'           => $this->campaigns->end_ts( $campaign_id ),
			'submitted_at'     => $this->campaigns->submitted_at( $campaign_id ),
			'revision'         => $this->campaigns->revision( $campaign_id ),
			'autosave_rev'     => $this->campaigns->autosave_revision( $campaign_id ),
			'wizard_step'      => $this->campaigns->wizard_step( $campaign_id ),
			'package_id'       => $this->campaigns->package_id( $campaign_id ),
			'budget_cents'     => $this->campaigns->budget_cents( $campaign_id ),
			'currency'         => $this->campaigns->currency( $campaign_id ),

			// Advertiser-visible feedback, which is the whole point of the
			// field. Internal notes are a different key and never appear here.
			'review_notes'     => $this->campaigns->review_notes( $campaign_id ),
			'advertiser_notes' => $this->campaigns->advertiser_notes( $campaign_id ),

			'editable'         => $this->window->allows( $campaign_id ),
			'can_copy'         => current_user_can( Capabilities::SUBMIT_CAMPAIGN ),
			'placement_ids'    => $this->campaigns->placement_ids( $campaign_id ),

			// What this advertiser could do next, so a UI does not have to
			// reimplement the transition table to decide which buttons to draw.
			'actions'          => $this->actions( $campaign_id, $status ),
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

	/**
	 * Schema for a positive integer route argument.
	 *
	 * @param bool $required Whether the value is required.
	 * @return array<string, mixed>
	 */
	private function positive_int_arg( bool $required ): array {
		return array(
			'type'              => 'integer',
			'required'          => $required,
			'sanitize_callback' => 'absint',
			'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) && (int) $value > 0,
		);
	}

	/**
	 * Schema for a non-negative integer body argument.
	 *
	 * @param bool $required Whether the value is required.
	 * @return array<string, mixed>
	 */
	private function nonnegative_int_arg( bool $required ): array {
		return array(
			'type'              => 'integer',
			'required'          => $required,
			'sanitize_callback' => 'absint',
			'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) && (int) $value >= 0,
		);
	}

	/**
	 * Schema for a sanitized string body argument.
	 *
	 * @param bool $required Whether the value is required.
	 * @return array<string, mixed>
	 */
	private function string_arg( bool $required ): array {
		return array(
			'type'              => 'string',
			'required'          => $required,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => static fn ( $value ): bool => is_string( $value ),
		);
	}

	/**
	 * Schema for a sanitized multiline string body argument.
	 *
	 * @param bool $required Whether the value is required.
	 * @return array<string, mixed>
	 */
	private function textarea_arg( bool $required ): array {
		return array(
			'type'              => 'string',
			'required'          => $required,
			'sanitize_callback' => 'sanitize_textarea_field',
			'validate_callback' => static fn ( $value ): bool => is_string( $value ),
		);
	}

	/**
	 * Sanitizes an array of object ids without accepting another shape.
	 *
	 * @param mixed $value Candidate value.
	 * @return array<int, int>
	 */
	public static function sanitize_ids( $value ): array {
		return is_array( $value ) ? array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) ) : array();
	}

	/**
	 * Validates that every submitted placement reference is a positive integer.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public static function validate_ids( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		foreach ( $value as $id ) {
			if ( ! is_numeric( $id ) || (int) $id <= 0 ) {
				return false;
			}
		}

		return true;
	}
}
