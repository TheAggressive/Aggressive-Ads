<?php
/**
 * Uploading a creative.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\REST;

use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Security\Capabilities;
use LAAO_Advertiser_Portal\Workflow\Creative_Manager;
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
	 * @param Creative_Manager $manager Shared creative workflow.
	 */
	public function __construct( private readonly Creative_Manager $manager ) {
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
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'alt_text'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			Creative_File_Controller::NAMESPACE,
			'/creatives/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete' ),
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

		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : array();

		$result = $this->manager->upload(
			$campaign_id,
			$placement_id,
			$file,
			(string) ( $request->get_param( 'click_url' ) ?? '' ),
			(string) ( $request->get_param( 'alt_text' ) ?? '' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['file_url'] = rest_url(
			sprintf( '%s/creatives/%d/file', Creative_File_Controller::NAMESPACE, (int) $result['id'] )
		);

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Deletes an editable creative through the shared workflow.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function delete( WP_REST_Request $request ) {
		$result = $this->manager->remove( (int) $request->get_param( 'id' ) );

		return is_wp_error( $result ) ? $result : new WP_REST_Response( null, 204 );
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
