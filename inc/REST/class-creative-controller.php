<?php
/**
 * Uploading a creative.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\REST;

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Domain\Campaign_Rules;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Repository\Creative_Repository;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;
use LAAO_Advertiser_Portal\Security\Capabilities;
use LAAO_Advertiser_Portal\Security\Rate_Limiter;
use LAAO_Advertiser_Portal\Workflow\Creative_Uploader;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Accepts a creative file for a campaign.
 *
 * The write counterpart to the file stream. Where reads answer 404 to avoid an
 * object-id oracle, writes may answer 403: the caller already knows the object
 * exists, because they are trying to modify it.
 *
 * `org_id` is never a parameter here, or anywhere. It is derived from the
 * campaign, which is itself authorized against the caller. See
 * docs/rest-api.md.
 */
final class Creative_Controller implements Service {

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository  $campaigns  Campaign persistence.
	 * @param Creative_Repository  $creatives  Creative persistence.
	 * @param Placement_Repository $placements Placement persistence.
	 * @param Creative_Uploader    $uploader   Upload validation and storage.
	 * @param Rate_Limiter         $limiter    Abuse bounding.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Placement_Repository $placements,
		private readonly Creative_Uploader $uploader,
		private readonly Rate_Limiter $limiter
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
		register_rest_route(
			Creative_File_Controller::NAMESPACE,
			'/campaigns/(?P<id>\d+)/creatives',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'id'           => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),
					'placement_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),
					'click_url'    => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'alt_text'     => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Whether the caller may upload at all.
	 *
	 * @return bool
	 */
	public function permission(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::UPLOAD_CREATIVE );
	}

	/**
	 * Handles the upload.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function handle( WP_REST_Request $request ) {
		$campaign_id  = (int) $request->get_param( 'id' );
		$placement_id = (int) $request->get_param( 'placement_id' );

		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_UPLOAD, get_current_user_id() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$authorized = $this->authorize( $campaign_id, $placement_id );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$files = $request->get_file_params();

		if ( ! isset( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new WP_Error(
				'laao_ads_no_file',
				__( 'No file was received. Please choose a file and try again.', 'laao-advertiser-portal' ),
				array( 'status' => 400 )
			);
		}

		$accepted = $this->uploader->accept( $files['file'] );

		if ( is_wp_error( $accepted ) ) {
			$accepted->add_data( array( 'status' => 422 ), $accepted->get_error_code() );

			return $accepted;
		}

		$creative_id = $this->create( $campaign_id, $placement_id, $accepted, $request );

		if ( is_wp_error( $creative_id ) ) {
			return $creative_id;
		}

		return new WP_REST_Response( $this->represent( $creative_id, $placement_id, $accepted ), 201 );
	}

	/**
	 * Whether this caller may add a creative to this campaign.
	 *
	 * @param int $campaign_id  Campaign post id.
	 * @param int $placement_id Placement post id.
	 * @return true|WP_Error
	 */
	private function authorize( int $campaign_id, int $placement_id ): bool|WP_Error {
		// A write, so 403 rather than 404 is correct: the caller already knows
		// the object exists, because they are trying to change it.
		if ( ! current_user_can( 'edit_laao_ads_campaign', $campaign_id ) ) {
			return new WP_Error(
				'laao_ads_forbidden',
				__( 'You do not have permission to do that.', 'laao-advertiser-portal' ),
				array( 'status' => 403 )
			);
		}

		$status = $this->campaigns->status( $campaign_id );

		if ( ! in_array( $status, Post_Statuses::advertiser_editable(), true ) ) {
			return new WP_Error(
				'laao_ads_campaign_not_editable',
				__( 'This campaign cannot be changed right now.', 'laao-advertiser-portal' ),
				array( 'status' => 409 )
			);
		}

		// Validated against the repository under the caller's own scope, not
		// trusted from the request: a placement id is a client-supplied object
		// reference like any other.
		if ( ! $this->placements->is_active( $placement_id ) ) {
			return new WP_Error(
				'laao_ads_placement_unavailable',
				__( 'That placement is not available.', 'laao-advertiser-portal' ),
				array( 'status' => 422 )
			);
		}

		if ( ! in_array( $placement_id, $this->campaigns->placement_ids( $campaign_id ), true ) ) {
			return new WP_Error(
				'laao_ads_placement_not_selected',
				__( 'That placement is not part of this campaign.', 'laao-advertiser-portal' ),
				array( 'status' => 422 )
			);
		}

		return true;
	}

	/**
	 * Creates the creative post and records the upload against it.
	 *
	 * @param int                  $campaign_id  Campaign post id.
	 * @param int                  $placement_id Placement post id.
	 * @param array<string, mixed> $accepted     Accepted upload.
	 * @param WP_REST_Request      $request      The request.
	 * @return int|WP_Error
	 *
	 * @phpstan-param array{path: string, token: string, sha256: string, bytes: int, mime: string, width: int, height: int, name: string} $accepted
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private function create( int $campaign_id, int $placement_id, array $accepted, WP_REST_Request $request ): int|WP_Error {
		$creative_id = $this->creatives->create(
			$campaign_id,
			$this->campaigns->org_id( $campaign_id ),
			$placement_id,
			array(
				'kind'      => Campaign_Rules::ADVERTISER_CREATIVE_KIND,
				'click_url' => (string) ( $request->get_param( 'click_url' ) ?? '' ),
				'alt_text'  => (string) ( $request->get_param( 'alt_text' ) ?? '' ),
				'size'      => $this->placements->size( $placement_id ),
			)
		);

		if ( 0 === $creative_id ) {
			return new WP_Error(
				'laao_ads_creative_not_created',
				__( 'The creative could not be saved. Please try again.', 'laao-advertiser-portal' ),
				array( 'status' => 500 )
			);
		}

		$this->creatives->record_upload( $creative_id, $accepted );

		return $creative_id;
	}

	/**
	 * The advertiser-facing shape of a creative.
	 *
	 * Built explicitly, field by field. Serializing whatever meta happens to
	 * exist is how an internal field leaks the moment somebody adds one — the
	 * private path and its token are on this object.
	 *
	 * @param int                  $creative_id  Creative post id.
	 * @param int                  $placement_id Placement the creative fills.
	 * @param array<string, mixed> $accepted     Accepted upload.
	 * @return array<string, mixed>
	 */
	private function represent( int $creative_id, int $placement_id, array $accepted ): array {
		return array(
			'id'           => $creative_id,
			'placement_id' => $placement_id,
			'width'        => (int) $accepted['width'],
			'height'       => (int) $accepted['height'],
			'mime'         => (string) $accepted['mime'],
			'bytes'        => (int) $accepted['bytes'],
			'name'         => (string) $accepted['name'],
			'file_url'     => rest_url(
				sprintf( '%s/creatives/%d/file', Creative_File_Controller::NAMESPACE, $creative_id )
			),
		);
	}

	/**
	 * The post type this controller creates, for tests.
	 *
	 * @return string
	 */
	public static function post_type(): string {
		return Post_Types::CREATIVE;
	}
}
