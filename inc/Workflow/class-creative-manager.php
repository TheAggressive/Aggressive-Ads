<?php
/**
 * Advertiser creative lifecycle.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Storage\Private_Storage;
use WP_Error;

/**
 * One policy for REST and progressively enhanced creative forms.
 */
final class Creative_Manager {

	public const MAX_ALT_TEXT_LENGTH = 500;

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository  $campaigns  Campaign persistence.
	 * @param Creative_Repository  $creatives  Creative persistence.
	 * @param Placement_Repository $placements Placement persistence.
	 * @param Creative_Uploader    $uploader   Hostile-file validation.
	 * @param Private_Storage      $storage    Private file storage.
	 * @param Rate_Limiter         $limiter    Upload abuse bounding.
	 * @param Audit_Repository     $audit      Audit persistence.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Placement_Repository $placements,
		private readonly Creative_Uploader $uploader,
		private readonly Private_Storage $storage,
		private readonly Rate_Limiter $limiter,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Validates, privately stores, and records one placement creative.
	 *
	 * @param int                  $campaign_id  Campaign post id.
	 * @param int                  $placement_id Placement post id.
	 * @param array<string, mixed> $file         One $_FILES entry.
	 * @param string               $click_url    Public destination URL.
	 * @param string               $alt_text     Alternative text.
	 * @return array<string, mixed>|WP_Error
	 */
	public function upload( int $campaign_id, int $placement_id, array $file, string $click_url, string $alt_text ): array|WP_Error {
		if ( ! current_user_can( Capabilities::UPLOAD_CREATIVE ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to upload creative.', 'aggressive-ads' ), 403 );
		}

		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_UPLOAD, get_current_user_id() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$authorized = $this->authorize_campaign_placement( $campaign_id, $placement_id );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		foreach ( $this->creatives->for_campaign( $campaign_id ) as $creative ) {
			if ( $placement_id === $creative['placement_id'] ) {
				return $this->error( 'aggr_creative_already_exists', __( 'That placement already has a creative. Remove it before uploading a replacement.', 'aggressive-ads' ), 409, 'file' );
			}
		}

		$click_url = trim( $click_url );

		if ( '' === $click_url ) {
			return $this->error( 'aggr_click_url_required', __( 'Enter the destination URL for this creative.', 'aggressive-ads' ), 422, 'click_url' );
		}

		if ( ! Campaign_Rules::is_valid_click_url( $click_url ) || false === wp_http_validate_url( $click_url ) ) {
			return $this->error( 'aggr_click_url_invalid', __( 'Enter a valid http or https destination URL without embedded credentials.', 'aggressive-ads' ), 422, 'click_url' );
		}

		$alt_text = trim( sanitize_text_field( $alt_text ) );
		$alt_text = '' === $alt_text ? self::automatic_alt_text( $click_url ) : $alt_text;

		if ( mb_strlen( $alt_text ) > self::MAX_ALT_TEXT_LENGTH ) {
			return $this->error( 'aggr_alt_text_too_long', __( 'Use 500 characters or fewer for the image description.', 'aggressive-ads' ), 422, 'alt_text' );
		}

		$accepted = $this->uploader->accept( $file );

		if ( is_wp_error( $accepted ) ) {
			$accepted->add_data( array( 'status' => 422 ), $accepted->get_error_code() );

			return $accepted;
		}

		$required_size = $this->placements->size( $placement_id );

		if ( ! Campaign_Rules::size_matches( $accepted['width'], $accepted['height'], $required_size ) ) {
			$this->storage->delete( $accepted['path'] );

			return new WP_Error(
				'aggr_creative_size_mismatch',
				sprintf(
					/* translators: 1: uploaded dimensions. 2: required dimensions. */
					__( 'Uploaded: %1$s. Required: %2$s. Resize the image and try again.', 'aggressive-ads' ),
					$accepted['width'] . ' × ' . $accepted['height'],
					$required_size
				),
				array(
					'status'   => 422,
					'field'    => 'file',
					'uploaded' => array( $accepted['width'], $accepted['height'] ),
					'required' => $required_size,
				)
			);
		}

		$creative_id = $this->creatives->create(
			$campaign_id,
			$this->campaigns->org_id( $campaign_id ),
			$placement_id,
			array(
				'kind'      => Campaign_Rules::ADVERTISER_CREATIVE_KIND,
				'click_url' => esc_url_raw( $click_url ),
				'alt_text'  => $alt_text,
				'size'      => $required_size,
			)
		);

		if ( 0 === $creative_id ) {
			$this->storage->delete( $accepted['path'] );

			return $this->error( 'aggr_creative_not_created', __( 'The creative could not be saved. Please try again.', 'aggressive-ads' ), 500 );
		}

		$this->creatives->record_upload( $creative_id, $accepted );

		$recorded = $this->creatives->storage_details( $creative_id );
		$details  = $this->creatives->details( $creative_id );

		if (
			null === $recorded
			|| null === $details
			|| $accepted['path'] !== $recorded['path']
			|| $campaign_id !== $details['campaign_id']
			|| $placement_id !== $details['placement_id']
		) {
			$this->creatives->delete( $creative_id );
			$this->storage->delete( $accepted['path'] );

			return $this->error( 'aggr_creative_not_created', __( 'The creative could not be saved. Please try again.', 'aggressive-ads' ), 500 );
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'creative.uploaded',
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: 'Creative uploaded.',
				context: array(
					'creative_id'  => $creative_id,
					'placement_id' => $placement_id,
					'width'        => $accepted['width'],
					'height'       => $accepted['height'],
					'bytes'        => $accepted['bytes'],
					'mime'         => $accepted['mime'],
				),
				actor_user_id: get_current_user_id()
			)
		);

		return array(
			'id'           => $creative_id,
			'placement_id' => $placement_id,
			'width'        => $accepted['width'],
			'height'       => $accepted['height'],
			'mime'         => $accepted['mime'],
			'bytes'        => $accepted['bytes'],
			'name'         => $accepted['name'],
		);
	}

	/**
	 * Supplies concise accessibility text without asking advertisers for copy.
	 *
	 * A linked image cannot safely have an empty alt attribute. The validated
	 * destination host describes the link's purpose without exposing an
	 * internal campaign title or requiring a visible description field.
	 *
	 * @param string $click_url Validated public destination URL.
	 * @return string
	 */
	public static function automatic_alt_text( string $click_url ): string {
		$host = (string) wp_parse_url( $click_url, PHP_URL_HOST );
		$host = sanitize_text_field( strtolower( $host ) );

		if ( str_starts_with( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return '' === $host
			? __( 'Advertisement', 'aggressive-ads' )
			: sprintf(
				/* translators: %s: destination website hostname. */
				__( 'Advertisement linking to %s', 'aggressive-ads' ),
				$host
			);
	}

	/**
	 * Removes an unapproved creative and its private file.
	 *
	 * @param int $creative_id Creative post id.
	 * @return true|WP_Error
	 */
	public function remove( int $creative_id ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::UPLOAD_CREATIVE ) || ! current_user_can( 'delete_aggr_creative', $creative_id ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to remove that creative.', 'aggressive-ads' ), 403 );
		}

		$creative = $this->creatives->details( $creative_id );

		if ( null === $creative ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to remove that creative.', 'aggressive-ads' ), 403 );
		}

		$campaign_id = $creative['campaign_id'];

		if ( ! current_user_can( 'edit_aggr_campaign', $campaign_id ) || ! in_array( $this->campaigns->status( $campaign_id ), Post_Statuses::advertiser_editable(), true ) ) {
			return $this->error( 'aggr_campaign_not_editable', __( 'This campaign cannot be changed right now.', 'aggressive-ads' ), 409 );
		}

		if ( $this->creatives->has_attachment( $creative_id ) || $this->creatives->provider_ad_id( $creative_id ) > 0 ) {
			return $this->error( 'aggr_creative_published', __( 'A published creative cannot be removed from this draft workflow.', 'aggressive-ads' ), 409 );
		}

		$stored = $this->creatives->storage_details( $creative_id );

		if ( null !== $stored && '' !== $stored['path'] && null !== $this->storage->resolve( $stored['path'] ) && ! $this->storage->delete( $stored['path'] ) ) {
			return $this->error( 'aggr_creative_not_deleted', __( 'The creative could not be removed. Please try again.', 'aggressive-ads' ), 500 );
		}

		if ( ! $this->creatives->delete( $creative_id ) ) {
			return $this->error( 'aggr_creative_not_deleted', __( 'The creative could not be removed. Please try again.', 'aggressive-ads' ), 500 );
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'creative.removed',
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: 'Creative removed.',
				context: array(
					'creative_id'  => $creative_id,
					'placement_id' => $creative['placement_id'],
				),
				actor_user_id: get_current_user_id()
			)
		);

		return true;
	}

	/**
	 * Campaign and placement authorization shared by every delivery layer.
	 *
	 * @param int $campaign_id  Campaign post id.
	 * @param int $placement_id Placement post id.
	 * @return true|WP_Error
	 */
	private function authorize_campaign_placement( int $campaign_id, int $placement_id ): bool|WP_Error {
		if ( ! current_user_can( 'edit_aggr_campaign', $campaign_id ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to do that.', 'aggressive-ads' ), 403 );
		}

		if ( ! in_array( $this->campaigns->status( $campaign_id ), Post_Statuses::advertiser_editable(), true ) ) {
			return $this->error( 'aggr_campaign_not_editable', __( 'This campaign cannot be changed right now.', 'aggressive-ads' ), 409 );
		}

		if ( ! $this->placements->is_active( $placement_id ) ) {
			return $this->error( 'aggr_placement_unavailable', __( 'That placement is not available.', 'aggressive-ads' ), 422, 'placement_id' );
		}

		if ( ! in_array( $placement_id, $this->campaigns->placement_ids( $campaign_id ), true ) ) {
			return $this->error( 'aggr_placement_not_selected', __( 'That placement is not part of this campaign.', 'aggressive-ads' ), 422, 'placement_id' );
		}

		return true;
	}

	/**
	 * Builds a delivery-safe workflow error.
	 *
	 * @param string $code    Stable error code.
	 * @param string $message User-facing message.
	 * @param int    $status  HTTP status.
	 * @param string $field   Related field.
	 * @return WP_Error
	 */
	private function error( string $code, string $message, int $status, string $field = '' ): WP_Error {
		$data = array( 'status' => $status );

		if ( '' !== $field ) {
			$data['field'] = $field;
		}

		return new WP_Error( $code, $message, $data );
	}
}
