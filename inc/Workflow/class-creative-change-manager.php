<?php
/**
 * Reviewed replacement of published creative.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Integration\Ad_Provider_Interface;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Storage\Private_Storage;
use WP_Error;

/**
 * Keeps live ads serving while advertiser revisions pass through review.
 */
final class Creative_Change_Manager {

	public const MAX_REVIEW_NOTES_LENGTH = 2000;

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository   $campaigns Campaign persistence.
	 * @param Creative_Repository   $creatives Creative persistence.
	 * @param Placement_Repository  $placements Placement persistence.
	 * @param Creative_Uploader     $uploader   Hostile-file validation.
	 * @param Private_Storage       $storage    Private file storage.
	 * @param Rate_Limiter          $limiter    Upload abuse bounding.
	 * @param Ad_Provider_Interface $provider   Public delivery projection.
	 * @param Audit_Repository      $audit      Audit persistence.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Placement_Repository $placements,
		private readonly Creative_Uploader $uploader,
		private readonly Private_Storage $storage,
		private readonly Rate_Limiter $limiter,
		private readonly Ad_Provider_Interface $provider,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Stages a replacement without touching the currently serving ad.
	 *
	 * @param int                  $creative_id Current creative id.
	 * @param array<string, mixed> $file        One uploaded file.
	 * @param string               $click_url   Public destination.
	 * @param string               $alt_text    Alternative text.
	 * @return array<string, mixed>|WP_Error
	 */
	public function request( int $creative_id, array $file, string $click_url, string $alt_text ): array|WP_Error {
		if ( ! current_user_can( Capabilities::UPLOAD_CREATIVE ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to request an ad update.', 'aggressive-ads' ), 403 );
		}

		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_UPLOAD, get_current_user_id() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$current = $this->authorize_current( $creative_id );

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$lock = $this->creatives->claim_change_lock( $creative_id );

		if ( '' === $lock ) {
			return $this->error( 'aggr_replacement_busy', __( 'Another update is already being saved for this ad. Try again.', 'aggressive-ads' ), 409 );
		}

		try {
			if ( $this->creatives->pending_replacement_id( $creative_id ) > 0 ) {
				return $this->error( 'aggr_replacement_pending', __( 'This ad already has an update waiting for review.', 'aggressive-ads' ), 409 );
			}

			$accepted = $this->accept( $current['placement_id'], $file, $click_url, $alt_text );

			if ( is_wp_error( $accepted ) ) {
				return $accepted;
			}

			$replacement_id = $this->creatives->create_replacement(
				$creative_id,
				array(
					'kind'      => Campaign_Rules::ADVERTISER_CREATIVE_KIND,
					'click_url' => $accepted['click_url'],
					'alt_text'  => $accepted['alt_text'],
					'size'      => $accepted['size'],
				)
			);

			if ( 0 === $replacement_id ) {
				$this->storage->delete( $accepted['path'] );

				return $this->error( 'aggr_replacement_not_created', __( 'The ad update could not be saved. Please try again.', 'aggressive-ads' ), 500 );
			}

			$this->creatives->record_upload( $replacement_id, $this->upload_record( $accepted ) );

			if ( ! $this->replacement_was_recorded( $replacement_id, $creative_id, $accepted['path'] ) ) {
				$this->creatives->delete( $replacement_id );
				$this->storage->delete( $accepted['path'] );

				return $this->error( 'aggr_replacement_not_created', __( 'The ad update could not be verified. Please try again.', 'aggressive-ads' ), 500 );
			}

			$campaign_id = $current['campaign_id'];
			$this->sync_pending_count( $campaign_id );
			$this->audit( 'creative.replacement_requested', $campaign_id, $replacement_id, $creative_id, 'Creative replacement requested.' );

			return array(
				'id'           => $replacement_id,
				'creative_id'  => $creative_id,
				'placement_id' => $current['placement_id'],
				'width'        => $accepted['width'],
				'height'       => $accepted['height'],
				'mime'         => $accepted['mime'],
				'bytes'        => $accepted['bytes'],
			);
		} finally {
			$this->creatives->release_change_lock( $creative_id, $lock );
		}
	}

	/**
	 * Approves and reconciles one pending revision onto the existing ad.
	 *
	 * @param int $replacement_id Replacement id.
	 * @return true|WP_Error
	 */
	public function approve( int $replacement_id ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) || ! current_user_can( Capabilities::PUBLISH_TO_ADSANITY ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to approve ad updates.', 'aggressive-ads' ), 403 );
		}

		$context = $this->replacement_context( $replacement_id );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( ! in_array( $this->campaigns->status( $context['campaign_id'] ), array( Post_Statuses::SCHEDULED, Post_Statuses::LIVE ), true ) ) {
			return $this->error( 'aggr_replacement_campaign_inactive', __( 'Only a scheduled or live campaign can apply an ad update.', 'aggressive-ads' ), 409 );
		}

		$lock = $this->creatives->claim_change_lock( $context['current_id'] );

		if ( '' === $lock ) {
			return $this->error( 'aggr_replacement_busy', __( 'Another review action is already updating this ad. Try again.', 'aggressive-ads' ), 409 );
		}

		try {
			$published = $this->provider->replace_creative( $context['campaign_id'], $context['current_id'], $replacement_id );

			if ( is_wp_error( $published ) ) {
				$this->audit( 'creative.replacement_failed', $context['campaign_id'], $replacement_id, $context['current_id'], 'Creative replacement failed.', Audit_Event::OUTCOME_FAILED );

				return $published;
			}

			$ad_id = $this->creatives->provider_ad_id( $context['current_id'] );

			if ( ! $this->creatives->activate_replacement( $context['current_id'], $replacement_id, $ad_id ) ) {
				$restored = $this->provider->restore_creative( $context['campaign_id'], $context['current_id'] );

				return $this->error(
					is_wp_error( $restored ) ? 'aggr_replacement_rollback_failed' : 'aggr_replacement_activation_failed',
					is_wp_error( $restored )
						? __( 'The update could not be recorded or rolled back. Pause the campaign and inspect the provider ad.', 'aggressive-ads' )
						: __( 'The update could not be recorded. The current ad was restored.', 'aggressive-ads' ),
					500
				);
			}

			$this->sync_pending_count( $context['campaign_id'] );
			$this->audit( 'creative.replacement_approved', $context['campaign_id'], $replacement_id, $context['current_id'], 'Creative replacement approved and published.' );
			do_action( 'aggr_creative_replaced', $context['campaign_id'] );

			return true;
		} finally {
			$this->creatives->release_change_lock( $context['current_id'], $lock );
		}
	}

	/**
	 * Rejects a pending revision while leaving the current ad untouched.
	 *
	 * @param int    $replacement_id Replacement id.
	 * @param string $notes          Advertiser-facing reason.
	 * @return true|WP_Error
	 */
	public function reject( int $replacement_id, string $notes ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to reject ad updates.', 'aggressive-ads' ), 403 );
		}

		$notes = trim( sanitize_textarea_field( $notes ) );

		if ( '' === $notes ) {
			return $this->error( 'aggr_replacement_notes_required', __( 'Explain why this ad update needs changes.', 'aggressive-ads' ), 422 );
		}

		if ( mb_strlen( $notes ) > self::MAX_REVIEW_NOTES_LENGTH ) {
			return $this->error( 'aggr_replacement_notes_too_long', __( 'Use 2,000 characters or fewer for update feedback.', 'aggressive-ads' ), 422 );
		}

		$context = $this->replacement_context( $replacement_id );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$lock = $this->creatives->claim_change_lock( $context['current_id'] );

		if ( '' === $lock ) {
			return $this->error( 'aggr_replacement_busy', __( 'Another review action is already updating this ad. Try again.', 'aggressive-ads' ), 409 );
		}

		try {
			if ( ! $this->creatives->reject_replacement( $replacement_id, $notes ) ) {
				return $this->error( 'aggr_replacement_rejection_failed', __( 'The update decision could not be saved. Please try again.', 'aggressive-ads' ), 500 );
			}

			$this->sync_pending_count( $context['campaign_id'] );
			$this->audit( 'creative.replacement_rejected', $context['campaign_id'], $replacement_id, $context['current_id'], 'Creative replacement rejected.' );

			return true;
		} finally {
			$this->creatives->release_change_lock( $context['current_id'], $lock );
		}
	}

	/**
	 * Withdraws the caller's pending revision.
	 *
	 * @param int $replacement_id Replacement id.
	 * @return true|WP_Error
	 */
	public function withdraw( int $replacement_id ): bool|WP_Error {
		$context = $this->replacement_context( $replacement_id );

		if ( is_wp_error( $context ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to withdraw that ad update.', 'aggressive-ads' ), 403 );
		}

		if ( ! current_user_can( 'delete_aggr_creative', $replacement_id ) || ! current_user_can( 'edit_aggr_campaign', $context['campaign_id'] ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to withdraw that ad update.', 'aggressive-ads' ), 403 );
		}

		$lock = $this->creatives->claim_change_lock( $context['current_id'] );

		if ( '' === $lock ) {
			return $this->error( 'aggr_replacement_busy', __( 'Another action is already updating this ad. Try again.', 'aggressive-ads' ), 409 );
		}

		try {
			$stored      = $this->creatives->storage_details( $replacement_id );
			$quarantined = '';

			if ( null !== $stored && '' !== $stored['path'] && null !== $this->storage->resolve( $stored['path'] ) ) {
				$quarantined = (string) $this->storage->quarantine( $stored['path'] );

				if ( '' === $quarantined ) {
					return $this->error( 'aggr_replacement_not_deleted', __( 'The update file could not be removed. Please try again.', 'aggressive-ads' ), 500 );
				}
			}

			if ( ! $this->creatives->delete( $replacement_id ) ) {
				if ( '' !== $quarantined && null !== $stored && ! $this->storage->restore( $quarantined, $stored['path'] ) ) {
					return $this->error( 'aggr_replacement_restore_failed', __( 'The update record and file could not be reconciled. Please contact an administrator.', 'aggressive-ads' ), 500 );
				}

				return $this->error( 'aggr_replacement_not_deleted', __( 'The update could not be withdrawn. Please try again.', 'aggressive-ads' ), 500 );
			}

			if ( '' !== $quarantined ) {
				$this->storage->delete( $quarantined );
			}

			$this->sync_pending_count( $context['campaign_id'] );
			$this->audit( 'creative.replacement_withdrawn', $context['campaign_id'], $replacement_id, $context['current_id'], 'Creative replacement withdrawn.' );

			return true;
		} finally {
			$this->creatives->release_change_lock( $context['current_id'], $lock );
		}
	}

	/**
	 * Authorizes a currently published creative for an advertiser request.
	 *
	 * @param int $creative_id Creative id.
	 * @return array<string, mixed>|WP_Error
	 */
	private function authorize_current( int $creative_id ): array|WP_Error {
		$current = $this->creatives->details( $creative_id );

		if ( null === $current || ! $this->creatives->is_active( $creative_id ) || ! current_user_can( 'edit_aggr_creative', $creative_id ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to update that ad.', 'aggressive-ads' ), 403 );
		}

		$campaign_id = $current['campaign_id'];

		if ( ! current_user_can( 'edit_aggr_campaign', $campaign_id ) || ! in_array( $this->campaigns->status( $campaign_id ), array( Post_Statuses::SCHEDULED, Post_Statuses::LIVE ), true ) ) {
			return $this->error( 'aggr_replacement_unavailable', __( 'Only an ad in a scheduled or live campaign can be updated.', 'aggressive-ads' ), 409 );
		}

		return $current;
	}

	/**
	 * Resolves and authorizes a pending replacement for staff or its owner.
	 *
	 * @param int $replacement_id Replacement id.
	 * @return array{campaign_id: int, current_id: int}|WP_Error
	 */
	private function replacement_context( int $replacement_id ): array|WP_Error {
		$replacement = $this->creatives->details( $replacement_id );
		$current_id  = $this->creatives->replacement_target_id( $replacement_id );
		$current     = $this->creatives->details( $current_id );

		if (
			null === $replacement
			|| null === $current
			|| Creative_Repository::CHANGE_PENDING !== $this->creatives->change_state( $replacement_id )
			|| $replacement['campaign_id'] !== $current['campaign_id']
			|| $replacement['org_id'] !== $current['org_id']
			|| $replacement['placement_id'] !== $current['placement_id']
			|| ! $this->creatives->is_active( $current_id )
			|| ! current_user_can( 'read_aggr_campaign', $current['campaign_id'] )
		) {
			return $this->error( 'aggr_replacement_invalid', __( 'That ad update is no longer available.', 'aggressive-ads' ), 409 );
		}

		return array(
			'campaign_id' => $current['campaign_id'],
			'current_id'  => $current_id,
		);
	}

	/**
	 * Validates user fields and stores hostile bytes privately.
	 *
	 * @param int                  $placement_id Placement id.
	 * @param array<string, mixed> $file         Upload.
	 * @param string               $click_url    Destination.
	 * @param string               $alt_text     Alternative text.
	 * @return array<string, mixed>|WP_Error
	 */
	private function accept( int $placement_id, array $file, string $click_url, string $alt_text ): array|WP_Error {
		$click_url = trim( $click_url );

		if ( '' === $click_url ) {
			return $this->error( 'aggr_click_url_required', __( 'Enter the destination URL for this creative.', 'aggressive-ads' ), 422, 'click_url' );
		}

		if ( ! Campaign_Rules::is_valid_click_url( $click_url ) || false === wp_http_validate_url( $click_url ) ) {
			return $this->error( 'aggr_click_url_invalid', __( 'Enter a valid http or https destination URL without embedded credentials.', 'aggressive-ads' ), 422, 'click_url' );
		}

		$alt_text = trim( sanitize_text_field( $alt_text ) );
		$alt_text = '' === $alt_text ? Creative_Manager::automatic_alt_text( $click_url ) : $alt_text;

		if ( mb_strlen( $alt_text ) > Creative_Manager::MAX_ALT_TEXT_LENGTH ) {
			return $this->error( 'aggr_alt_text_too_long', __( 'Use 500 characters or fewer for the image description.', 'aggressive-ads' ), 422, 'alt_text' );
		}

		$accepted = $this->uploader->accept( $file );

		if ( is_wp_error( $accepted ) ) {
			$accepted->add_data( array( 'status' => 422 ), $accepted->get_error_code() );

			return $accepted;
		}

		$size = $this->placements->size( $placement_id );

		if ( ! Campaign_Rules::size_matches( $accepted['width'], $accepted['height'], $size ) ) {
			$this->storage->delete( $accepted['path'] );

			return $this->error( 'aggr_creative_size_mismatch', __( 'The uploaded dimensions do not match this placement.', 'aggressive-ads' ), 422, 'file' );
		}

		$accepted['click_url'] = esc_url_raw( $click_url );
		$accepted['alt_text']  = $alt_text;
		$accepted['size']      = $size;

		return $accepted;
	}

	/**
	 * Exact read-back for a newly staged replacement.
	 *
	 * @param int    $replacement_id Replacement id.
	 * @param int    $current_id     Current creative id.
	 * @param string $path           Accepted private path.
	 * @return bool
	 */
	private function replacement_was_recorded( int $replacement_id, int $current_id, string $path ): bool {
		$stored = $this->creatives->storage_details( $replacement_id );

		return null !== $stored
			&& $path === $stored['path']
			&& $current_id === $this->creatives->replacement_target_id( $replacement_id )
			&& Creative_Repository::CHANGE_PENDING === $this->creatives->change_state( $replacement_id );
	}

	/**
	 * Narrows a validated upload to the repository's exact persistence shape.
	 *
	 * @param array<string, mixed> $accepted Accepted upload plus workflow fields.
	 * @return array{path: string, token: string, sha256: string, bytes: int, mime: string, width: int, height: int, name: string}
	 */
	private function upload_record( array $accepted ): array {
		return array(
			'path'   => (string) $accepted['path'],
			'token'  => (string) $accepted['token'],
			'sha256' => (string) $accepted['sha256'],
			'bytes'  => (int) $accepted['bytes'],
			'mime'   => (string) $accepted['mime'],
			'width'  => (int) $accepted['width'],
			'height' => (int) $accepted['height'],
			'name'   => (string) $accepted['name'],
		);
	}

	/**
	 * Rebuilds the queue count from canonical creative rows.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return void
	 */
	private function sync_pending_count( int $campaign_id ): void {
		$count = count( $this->creatives->replacements_for_campaign( $campaign_id, array( Creative_Repository::CHANGE_PENDING ) ) );

		$this->campaigns->set_pending_update_count( $campaign_id, $count );
	}

	/**
	 * Writes one campaign-scoped change event.
	 *
	 * @param string $event          Event name.
	 * @param int    $campaign_id    Campaign id.
	 * @param int    $replacement_id Replacement id.
	 * @param int    $current_id     Current creative id.
	 * @param string $message        Internal message.
	 * @param string $outcome        Audit outcome.
	 * @return void
	 */
	private function audit( string $event, int $campaign_id, int $replacement_id, int $current_id, string $message, string $outcome = Audit_Event::OUTCOME_OK ): void {
		$this->audit->insert(
			new Audit_Event(
				event: $event,
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: $message,
				outcome: $outcome,
				context: array(
					'creative_id'    => $current_id,
					'replacement_id' => $replacement_id,
				),
				actor_user_id: get_current_user_id()
			)
		);
	}

	/**
	 * Consistent REST/form workflow error.
	 *
	 * @param string $code    Stable code.
	 * @param string $message Localized message.
	 * @param int    $status  HTTP status.
	 * @param string $field   Optional field.
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
