<?php
/**
 * Progressive creative form delivery.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Portal;

use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Domain\Upload_Rules;
use LAAO_Advertiser_Portal\Security\Capabilities;
use LAAO_Advertiser_Portal\Workflow\Creative_Manager;
use WP_Error;

/**
 * Handles creative forms without creating a second workflow policy.
 */
final class Creative_Actions implements Service {

	public const UPLOAD_ACTION = 'laao_ads_upload_creative';
	public const REMOVE_ACTION = 'laao_ads_remove_creative';

	/**
	 * Constructor.
	 *
	 * @param Creative_Manager $manager Shared creative workflow.
	 */
	public function __construct( private readonly Creative_Manager $manager ) {
	}

	/**
	 * Attaches authenticated form handlers.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_post_' . self::UPLOAD_ACTION, array( $this, 'handle_upload' ) );
		add_action( 'admin_post_' . self::REMOVE_ACTION, array( $this, 'handle_remove' ) );
	}

	/**
	 * Uploads one placement creative.
	 *
	 * @return void
	 */
	public function handle_upload(): void {
		$this->assert_portal_access();

		$campaign_id  = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$placement_id = isset( $_POST['placement_id'] ) ? absint( $_POST['placement_id'] ) : 0;

		check_admin_referer( self::upload_nonce_action( $campaign_id, $placement_id ) );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The shared uploader validates the temporary file and ignores the claimed MIME; sanitizing a filesystem path would corrupt it before validation.
		$file      = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : array();
		$click_url = isset( $_POST['click_url'] ) ? sanitize_text_field( wp_unslash( $_POST['click_url'] ) ) : '';
		$alt_text  = isset( $_POST['alt_text'] ) ? sanitize_text_field( wp_unslash( $_POST['alt_text'] ) ) : '';
		$result    = $this->process_upload( $campaign_id, $placement_id, $file, $click_url, $alt_text );

		if ( is_wp_error( $result ) ) {
			$this->redirect( $campaign_id, 'error', $result, $placement_id );
		}

		$this->redirect( $campaign_id, 'creative_uploaded' );
	}

	/**
	 * Removes one creative.
	 *
	 * @return void
	 */
	public function handle_remove(): void {
		$this->assert_portal_access();

		$creative_id = isset( $_POST['creative_id'] ) ? absint( $_POST['creative_id'] ) : 0;
		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		check_admin_referer( self::remove_nonce_action( $creative_id ) );

		$result = $this->process_remove( $creative_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect( $campaign_id, 'error', $result );
		}

		$this->redirect( $campaign_id, 'creative_removed' );
	}

	/**
	 * Testable form upload entry point.
	 *
	 * @param int                  $campaign_id  Campaign post id.
	 * @param int                  $placement_id Placement post id.
	 * @param array<string, mixed> $file         One $_FILES entry.
	 * @param string               $click_url    Destination URL.
	 * @param string               $alt_text     Alternative text.
	 * @return array<string, mixed>|WP_Error
	 */
	public function process_upload( int $campaign_id, int $placement_id, array $file, string $click_url, string $alt_text ): array|WP_Error {
		return $this->manager->upload( $campaign_id, $placement_id, $file, $click_url, $alt_text );
	}

	/**
	 * Testable form removal entry point.
	 *
	 * @param int $creative_id Creative post id.
	 * @return true|WP_Error
	 */
	public function process_remove( int $creative_id ): bool|WP_Error {
		return $this->manager->remove( $creative_id );
	}

	/**
	 * Nonce scoped to one campaign placement.
	 *
	 * @param int $campaign_id  Campaign post id.
	 * @param int $placement_id Placement post id.
	 * @return string
	 */
	public static function upload_nonce_action( int $campaign_id, int $placement_id ): string {
		return self::UPLOAD_ACTION . '_' . max( 0, $campaign_id ) . '_' . max( 0, $placement_id );
	}

	/**
	 * Nonce scoped to one creative.
	 *
	 * @param int $creative_id Creative post id.
	 * @return string
	 */
	public static function remove_nonce_action( int $creative_id ): string {
		return self::REMOVE_ACTION . '_' . max( 0, $creative_id );
	}

	/**
	 * Reads an allowlisted creative notice.
	 *
	 * @return string
	 */
	public static function request_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post/redirect/get display state.
		$value = isset( $_GET['laao_ads_notice'] ) ? sanitize_key( wp_unslash( $_GET['laao_ads_notice'] ) ) : '';

		return in_array( $value, array( 'creative_uploaded', 'creative_removed', 'error' ), true ) ? $value : '';
	}

	/**
	 * Reads the safe error code passed through the redirect.
	 *
	 * @return string
	 */
	public static function request_error_code(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only allowlisted display state.
		return isset( $_GET['laao_ads_error'] ) ? sanitize_key( wp_unslash( $_GET['laao_ads_error'] ) ) : '';
	}

	/**
	 * Placement related to the upload error, or zero.
	 *
	 * @return int
	 */
	public static function request_error_placement(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only in-page link target.
		return isset( $_GET['laao_ads_placement'] ) ? absint( $_GET['laao_ads_placement'] ) : 0;
	}

	/**
	 * Stable message for a redirect error code.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function error_message( string $code ): string {
		return match ( $code ) {
			'laao_ads_click_url_required'       => __( 'Enter the destination URL for this creative.', 'laao-advertiser-portal' ),
			'laao_ads_click_url_invalid'        => __( 'Enter a valid http or https destination URL without embedded credentials.', 'laao-advertiser-portal' ),
			'laao_ads_alt_text_required'        => __( 'Describe the image for people who cannot see it.', 'laao-advertiser-portal' ),
			'laao_ads_alt_text_too_long'        => __( 'Use 500 characters or fewer for the image description.', 'laao-advertiser-portal' ),
			'laao_ads_creative_size_mismatch'   => __( 'The uploaded dimensions do not match this placement. Resize the image to the required dimensions and try again.', 'laao-advertiser-portal' ),
			'laao_ads_creative_already_exists'  => __( 'That placement already has a creative. Remove it before uploading a replacement.', 'laao-advertiser-portal' ),
			'laao_ads_upload_no_file'           => __( 'No file was received. Choose an image and try again.', 'laao-advertiser-portal' ),
			'laao_ads_upload_too_large'         => sprintf(
				/* translators: %s: maximum file size. */
				__( 'That file is larger than %s. Save it at a smaller size and try again.', 'laao-advertiser-portal' ),
				size_format( Upload_Rules::MAX_BYTES )
			),
			'laao_ads_upload_too_many_pixels'   => __( 'That image has too many pixels to process. Resize it to the placement dimensions and try again.', 'laao-advertiser-portal' ),
			'laao_ads_upload_not_an_image'      => __( 'That file is not a readable image. JPEG, PNG, GIF, and WebP are supported.', 'laao-advertiser-portal' ),
			'laao_ads_upload_type_mismatch'     => __( 'The file contents do not match its filename, so it was not accepted.', 'laao-advertiser-portal' ),
			'laao_ads_upload_type_not_allowed'  => __( 'That file type is not supported. Use JPEG, PNG, GIF, or WebP.', 'laao-advertiser-portal' ),
			'laao_ads_upload_failed'            => __( 'The upload did not complete. Try again.', 'laao-advertiser-portal' ),
			'laao_ads_placement_unavailable',
			'laao_ads_placement_not_selected'   => __( 'That placement is not available for this campaign.', 'laao-advertiser-portal' ),
			'laao_ads_campaign_not_editable'    => __( 'This campaign cannot be changed right now.', 'laao-advertiser-portal' ),
			'laao_ads_rate_limited'             => __( 'There have been too many uploads. Wait a moment and try again.', 'laao-advertiser-portal' ),
			default                             => __( 'The creative could not be saved. Please try again.', 'laao-advertiser-portal' ),
		};
	}

	/**
	 * In-page field target for a creative error.
	 *
	 * @param string $code         Error code.
	 * @param int    $placement_id Related placement.
	 * @return string
	 */
	public static function error_target( string $code, int $placement_id ): string {
		if ( $placement_id <= 0 ) {
			return '';
		}

		$prefix = match ( $code ) {
			'laao_ads_click_url_required',
			'laao_ads_click_url_invalid' => 'laao-ads-click-',
			'laao_ads_alt_text_required',
			'laao_ads_alt_text_too_long' => 'laao-ads-alt-',
			default                      => 'laao-ads-file-',
		};

		return $prefix . $placement_id;
	}

	/**
	 * Refuses direct handler calls without portal access.
	 *
	 * @return void
	 */
	private function assert_portal_access(): void {
		if ( is_user_logged_in() && current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return;
		}

		wp_die( esc_html__( 'You do not have permission to do that.', 'laao-advertiser-portal' ), '', array( 'response' => 403 ) );
	}

	/**
	 * Redirects after one write without carrying user-provided messages.
	 *
	 * @param int           $campaign_id  Campaign post id.
	 * @param string        $notice       Notice key.
	 * @param WP_Error|null $error        Optional workflow error.
	 * @param int           $placement_id Optional placement for field focus.
	 * @return never
	 */
	private function redirect( int $campaign_id, string $notice, ?WP_Error $error = null, int $placement_id = 0 ): never {
		$args = array(
			'step'            => 'creative',
			'laao_ads_notice' => $notice,
		);

		if ( null !== $error ) {
			$args['laao_ads_error'] = sanitize_key( (string) $error->get_error_code() );
		}

		if ( $placement_id > 0 ) {
			$args['laao_ads_placement'] = $placement_id;
		}

		wp_safe_redirect( add_query_arg( $args, Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id ) ) );
		exit;
	}
}
