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
use Aggressive\Ads\Repository\Creative_Repository;
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
	 * @param Campaign_Editor     $editor    Draft allocation.
	 * @param Campaign_Repository $campaigns Campaign persistence.
	 * @param Creative_Repository $creatives Creative persistence.
	 * @param Private_Storage     $storage   Private file storage.
	 * @param Audit_Repository    $audit     Audit persistence.
	 */
	public function __construct(
		private readonly Campaign_Editor $editor,
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Private_Storage $storage,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Copies a readable campaign into a new organization-scoped draft.
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
		$campaign_id = $this->editor->create( $title );

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

			$stored = $this->storage->store( $file['path'], $file['extension'] );

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
	 * @param int $creative_id Source creative.
	 * @return array{path: string, extension: string, mime: string, sha256: string, name: string}|null
	 */
	private function source_file( int $creative_id ): ?array {
		$details = $this->creatives->storage_details( $creative_id );

		if ( null === $details ) {
			return null;
		}

		$path = '' !== $details['path'] ? $this->storage->resolve( $details['path'] ) : null;

		if ( null === $path ) {
			$attachment = $this->creatives->attachment_file( $creative_id );
			$path       = '' !== $attachment && is_readable( $attachment ) ? $attachment : null;
		}

		if ( null === $path ) {
			return null;
		}

		$mime = $details['mime'];
		$ext  = Upload_Rules::extension_for_mime( $mime );

		if ( '' === $ext ) {
			$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		}

		if ( ! Upload_Rules::is_allowed_extension( $ext ) || ! Upload_Rules::is_allowed_mime( $mime ) ) {
			return null;
		}

		return array(
			'path'      => $path,
			'extension' => $ext,
			'mime'      => $mime,
			'sha256'    => $details['sha256'],
			'name'      => $details['name'],
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
