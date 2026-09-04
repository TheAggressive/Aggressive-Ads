<?php
/**
 * Copy a campaign into a new draft.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Attachment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Storage\Private_Storage;
use WP_Error;

/**
 * Renew and duplicate are this copy, not a transition backwards.
 */
final class Campaign_Copier {

	/**
	 * Constructor.
	 *
	 * @param Campaign_Editor                $editor    Draft allocation.
	 * @param Campaign_Repository            $campaigns Campaign persistence.
	 * @param Creative_Repository            $creatives Creative persistence.
	 * @param Creative_Attachment_Repository $attachments Media Library copy of the artwork.
	 * @param Private_Storage                $storage   Private file storage.
	 * @param Audit_Repository               $audit     Audit persistence.
	 * @param Line_Item_Repository|null      $line_items Line-item compatibility persistence.
	 */
	public function __construct(
		private readonly Campaign_Editor $editor,
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Creative_Attachment_Repository $attachments,
		private readonly Private_Storage $storage,
		private readonly Audit_Repository $audit,
		private readonly ?Line_Item_Repository $line_items = null
	) {
	}

	/**
	 * Copies a readable campaign into a new draft owned by the same
	 * organization as the campaign it copies.
	 *
	 * The target org is the source campaign's, never the caller's. For an
	 * advertiser these are the same thing — they can only read their own
	 * organization's campaigns. For staff they are not: reading a client's
	 * campaign is allowed, so deriving the target from the caller would file
	 * the copy, its snapshot and its private creative bytes under whichever
	 * organization the staff member happened to belong to.
	 *
	 * @param int $source_id Source campaign post id.
	 * @return int|WP_Error
	 */
	public function copy( int $source_id ): int|WP_Error {
		if ( ! current_user_can( Capabilities::SUBMIT_CAMPAIGN ) || ! current_user_can( 'create_aggr_campaigns' ) ) {
			return $this->denied( $source_id, __( 'You do not have permission to create a campaign.', 'aggressive-ads' ) );
		}

		if ( ! $this->campaigns->exists( $source_id ) || ! current_user_can( 'read_aggr_campaign', $source_id ) ) {
			return $this->denied( $source_id, __( 'You do not have permission to copy that campaign.', 'aggressive-ads' ) );
		}

		$title       = $this->copied_title( $source_id );
		$source_org  = $this->campaigns->org_id( $source_id );
		$campaign_id = current_user_can( Capabilities::REVIEW_CAMPAIGNS )
			? $this->editor->create_for_org( $source_org, $title )
			: $this->editor->create( $title );

		if ( is_wp_error( $campaign_id ) ) {
			return $campaign_id;
		}

		$copied = $this->copy_snapshot( $source_id, $campaign_id );

		if ( is_wp_error( $copied ) ) {
			$this->abandon( $campaign_id );

			return $copied;
		}

		$creatives = $this->copy_creatives( $source_id, $campaign_id );

		if ( is_wp_error( $creatives ) ) {
			$this->abandon( $campaign_id );

			return $creatives;
		}

		$wizard = $this->resume_step( $campaign_id, $creatives );
		$step   = $this->campaigns->update_draft( $campaign_id, array( 'wizard_step' => $wizard ) );

		if ( is_wp_error( $step ) ) {
			$this->abandon( $campaign_id );

			return $this->failed( $campaign_id );
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'campaign.copied',
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				to_state: Post_Statuses::DRAFT,
				message: 'Campaign copied into a new draft.',
				context: array(
					'source_id'        => $source_id,
					'copied_creatives' => $creatives,
					'renewal'          => Post_Statuses::COMPLETE === $this->campaigns->status( $source_id ),
				),
				actor_user_id: get_current_user_id()
			)
		);

		if ( null !== $this->line_items ) {
			$this->line_items->sync_default_from_campaign( $campaign_id );
		}

		return $campaign_id;
	}

	/**
	 * Copies the stored commercial snapshot. Does not re-resolve the catalogue.
	 *
	 * @param int $source_id   Source campaign.
	 * @param int $campaign_id New draft.
	 * @return true|WP_Error
	 */
	private function copy_snapshot( int $source_id, int $campaign_id ): bool|WP_Error {
		$saved = $this->campaigns->update_draft(
			$campaign_id,
			array(
				'package_id'       => $this->campaigns->package_id( $source_id ),
				'placement_ids'    => $this->campaigns->placement_ids( $source_id ),
				'budget_cents'     => $this->campaigns->budget_cents( $source_id ),
				'currency'         => $this->campaigns->currency( $source_id ),
				'advertiser_notes' => $this->campaigns->advertiser_notes( $source_id ),
			)
		);

		return true === $saved ? true : $this->failed( $campaign_id );
	}

	/**
	 * Copies active creatives. Skips a creative whose bytes cannot be resolved.
	 *
	 * @param int $source_id   Source campaign.
	 * @param int $campaign_id New draft.
	 * @return int|WP_Error Copied creative count.
	 */
	private function copy_creatives( int $source_id, int $campaign_id ): int|WP_Error {
		$copied = 0;
		$org_id = $this->campaigns->org_id( $campaign_id );

		foreach ( $this->creatives->for_campaign( $source_id ) as $creative ) {
			$file = $this->source_file( $creative['id'] );

			if ( null === $file ) {
				continue;
			}

			try {
				$stored = $this->storage->store( $file['path'], $file['extension'] );
			} finally {
				// A decrypted copy of unapproved artwork, outside private
				// storage. It exists for one store() call and is removed
				// whether that call succeeded, failed or threw.
				if ( $file['temporary'] ) {
					wp_delete_file( $file['path'] );
				}
			}

			if ( is_wp_error( $stored ) ) {
				return $this->failed( $campaign_id );
			}

			if ( '' !== $file['sha256'] && ! hash_equals( $file['sha256'], $stored['sha256'] ) ) {
				$this->storage->delete( $stored['path'] );

				return $this->failed( $campaign_id );
			}

			$creative_id = $this->creatives->create(
				$campaign_id,
				$org_id,
				$creative['placement_id'],
				array(
					'kind'      => $creative['kind'],
					'click_url' => $creative['click_url'],
					'alt_text'  => $creative['alt_text'],
					'size'      => $creative['size'],
				)
			);

			if ( $creative_id <= 0 ) {
				$this->storage->delete( $stored['path'] );

				return $this->failed( $campaign_id );
			}

			$this->creatives->record_upload(
				$creative_id,
				array(
					'path'   => $stored['path'],
					'token'  => $stored['token'],
					'sha256' => $stored['sha256'],
					'bytes'  => $stored['bytes'],
					'mime'   => $file['mime'],
					'width'  => $creative['width'],
					'height' => $creative['height'],
					'name'   => $file['name'],
				)
			);

			++$copied;
		}

		return $copied;
	}

	/**
	 * Resolves bytes to copy: private file first, then the promoted attachment.
	 *
	 * The private file is encrypted, so it is decrypted into a temporary file
	 * the caller must delete; the promoted attachment is ordinary Media Library
	 * bytes and is read where it lies. `temporary` says which of the two this
	 * is, because deleting the wrong one destroys a published creative.
	 *
	 * @param int $creative_id Source creative.
	 * @return array{path: string, extension: string, mime: string, sha256: string, name: string, temporary: bool}|null
	 */
	private function source_file( int $creative_id ): ?array {
		$details = $this->creatives->storage_details( $creative_id );

		if ( null === $details ) {
			return null;
		}

		$temporary = false;
		$path      = '' !== $details['path'] ? $this->storage->export( $details['path'] ) : null;

		if ( null !== $path ) {
			$temporary = true;
		} else {
			$attachment = $this->attachments->attachment_file( $creative_id );
			$path       = '' !== $attachment && is_readable( $attachment ) ? $attachment : null;
		}

		if ( null === $path ) {
			return null;
		}

		$mime = $details['mime'];
		$ext  = Upload_Rules::extension_for_mime( $mime );

		if ( '' === $ext ) {
			// From the stored name when the bytes are a decrypted temporary
			// file, because that file is named .tmp and always would be.
			$ext = strtolower(
				(string) pathinfo( $temporary ? $details['path'] : $path, PATHINFO_EXTENSION )
			);
		}

		if ( ! Upload_Rules::is_allowed_extension( $ext ) || ! Upload_Rules::is_allowed_mime( $mime ) ) {
			if ( $temporary ) {
				wp_delete_file( $path );
			}

			return null;
		}

		return array(
			'path'      => $path,
			'extension' => $ext,
			'mime'      => $mime,
			'sha256'    => $details['sha256'],
			'name'      => $details['name'],
			'temporary' => $temporary,
		);
	}

	/**
	 * Wizard resume point for the copy: new dates are always required.
	 *
	 * @param int $campaign_id New draft.
	 * @param int $copied      Creatives successfully copied.
	 */
	private function resume_step( int $campaign_id, int $copied ): string {
		$placements = $this->campaigns->placement_ids( $campaign_id );

		if ( $copied > 0 && array() !== $placements && $copied >= count( $placements ) ) {
			return 'destination';
		}

		if ( $this->campaigns->package_id( $campaign_id ) > 0 || array() !== $placements ) {
			return 'creative';
		}

		return 'details';
	}

	/**
	 * Title with a bounded suffix. Complete campaigns are labelled as a renewal.
	 *
	 * @param int $source_id Source campaign.
	 */
	private function copied_title( int $source_id ): string {
		$suffix = Post_Statuses::COMPLETE === $this->campaigns->status( $source_id )
			? ' (renewal)'
			: ' (copy)';
		$base   = $this->campaigns->title( $source_id );
		$base   = '' === $base ? __( 'Untitled campaign', 'aggressive-ads' ) : $base;
		$budget = Campaign_Editor::MAX_TITLE_LENGTH - mb_strlen( $suffix );

		if ( $budget < 1 ) {
			return mb_substr( $suffix, 0, Campaign_Editor::MAX_TITLE_LENGTH );
		}

		return mb_substr( $base, 0, $budget ) . $suffix;
	}

	/**
	 * Deletes a failed copy so a retry does not leave an orphan draft.
	 *
	 * @param int $campaign_id New draft.
	 */
	private function abandon( int $campaign_id ): void {
		foreach ( $this->creatives->for_campaign( $campaign_id ) as $creative ) {
			$stored = $this->creatives->storage_details( $creative['id'] );

			if ( null !== $stored && '' !== $stored['path'] ) {
				$this->storage->delete( $stored['path'] );
			}

			$this->creatives->delete( $creative['id'] );
		}

		$this->campaigns->delete( $campaign_id );
	}

	/**
	 * Authorization denial. Audited, never enumerated.
	 *
	 * @param int    $source_id Source campaign.
	 * @param string $message   User-facing message.
	 */
	private function denied( int $source_id, string $message ): WP_Error {
		$this->audit->insert(
			new Audit_Event(
				event: 'campaign.copy_denied',
				outcome: Audit_Event::OUTCOME_DENIED,
				object_type: 'campaign',
				object_id: max( 0, $source_id ),
				message: 'Campaign copy denied.',
				actor_user_id: get_current_user_id()
			)
		);

		return new WP_Error( 'aggr_forbidden', $message, array( 'status' => 403 ) );
	}

	/**
	 * Persistence failure after a draft was allocated.
	 *
	 * @param int $campaign_id New draft.
	 */
	private function failed( int $campaign_id ): WP_Error {
		$this->audit->insert(
			new Audit_Event(
				event: 'campaign.copy_failed',
				outcome: Audit_Event::OUTCOME_FAILED,
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: 'Campaign copy failed.',
				actor_user_id: get_current_user_id()
			)
		);

		return new WP_Error(
			'aggr_campaign_not_copied',
			__( 'The campaign could not be copied. Please try again.', 'aggressive-ads' ),
			array( 'status' => 500 )
		);
	}
}
