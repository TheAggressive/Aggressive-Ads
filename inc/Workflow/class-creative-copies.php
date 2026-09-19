<?php
/**
 * One file on several placements of the same size.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use WP_Error;

/**
 * Copies an ad onto another placement, and removes the copies with it.
 *
 * A package can sell two placements of one size — a 728×90 header and a
 * 728×90 break — and the advertiser has one file for both. Each operation
 * here is a composition of `Creative_Manager`'s own `upload()` and `remove()`,
 * so every rule those enforce applies unchanged; this class adds only the
 * question of which creatives share a file.
 */
final class Creative_Copies {

	/**
	 * Constructor.
	 *
	 * @param Creative_Manager    $manager   The upload and removal every copy goes through.
	 * @param Creative_Repository $creatives Creative persistence.
	 * @param Creative_Source     $sources   Where an existing creative's bytes are read from.
	 * @param Campaign_Repository $campaigns Campaign persistence.
	 * @param Audit_Repository    $audit     Audit persistence.
	 */
	public function __construct(
		private readonly Creative_Manager $manager,
		private readonly Creative_Repository $creatives,
		private readonly Creative_Source $sources,
		private readonly Campaign_Repository $campaigns,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Puts another placement's file on this placement, as a creative of its own.
	 *
	 * **A copy, not a shared reference.** A revision belongs to one placement
	 * throughout the domain — coverage, review, replacement and delivery all
	 * read `placement_id` off it — and a revision serving two placements would
	 * make replacing one silently replace the other. As two creatives, each is
	 * reviewed, replaced and removed on its own, and coverage counts both
	 * placements because there are two rows covering them.
	 *
	 * **Through `Creative_Manager::upload()`, not beside it.** The bytes are handed to the same
	 * method a browser's file goes through, so the copy is re-inspected,
	 * re-checked against this placement's size and file limit, rate limited,
	 * capped and queued for review exactly like an upload. A second path that
	 * trusted the source because it had been accepted once would be a way to
	 * put a file on a placement whose limits it was never checked against.
	 *
	 * @param int $source_id    The creative whose file to reuse.
	 * @param int $placement_id Placement post id to put it on.
	 * @param int $campaign_id  The campaign the request was made for; zero skips the check.
	 * @return array<string, mixed>|WP_Error
	 */
	public function copy_to_placement( int $source_id, int $placement_id, int $campaign_id = 0 ): array|WP_Error {
		$source = $this->creatives->details( $source_id );

		/*
		 * One refusal for "no such creative" and "not yours", so the answer
		 * says nothing about which creative ids exist in other organizations.
		 * The same code the placement check below gives a foreign campaign.
		 */
		if (
			null === $source
			|| ( $campaign_id > 0 && $campaign_id !== $source['campaign_id'] )
			|| ! current_user_can( 'edit_aggr_campaign', $source['campaign_id'] )
			|| ! $this->creatives->is_active( $source_id )
		) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to do that.', 'aggressive-ads' ), 403 );
		}

		$file = $this->sources->resolve( $source_id );

		if ( null === $file ) {
			return $this->error( 'aggr_copy_source_unavailable', __( 'That ad\'s file could not be read. Upload it to this size instead.', 'aggressive-ads' ), 422, 'file' );
		}

		try {
			$result = $this->manager->upload(
				$source['campaign_id'],
				$placement_id,
				array(
					'tmp_name' => $file['path'],
					'name'     => '' !== $file['name'] ? $file['name'] : 'creative.' . $file['extension'],
					'error'    => UPLOAD_ERR_OK,
				),
				$source['click_url'],
				$source['alt_text']
			);
		} finally {
			// Decrypted artwork outside private storage: gone whatever happened.
			if ( $file['temporary'] ) {
				wp_delete_file( $file['path'] );
			}
		}

		if ( ! is_wp_error( $result ) ) {
			$this->audit->insert(
				new Audit_Event(
					event: 'creative.copied',
					object_type: 'campaign',
					object_id: $source['campaign_id'],
					org_id: $this->campaigns->org_id( $source['campaign_id'] ),
					message: 'Creative copied to another placement.',
					context: array(
						'creative_id'      => (int) $result['id'],
						'placement_id'     => $placement_id,
						'source_id'        => $source_id,
						'source_placement' => $source['placement_id'],
					),
					actor_user_id: get_current_user_id()
				)
			);
		}

		return $result;
	}

	/**
	 * Removes a creative and, as asked, the same file on other placements.
	 *
	 * The ids asked for are narrowed to the ones this creative's file is
	 * actually on, worked out before anything is removed. The form lists
	 * them, but the form is the browser's: an id it sends that is not a copy
	 * of this file is not removed, whoever owns it.
	 *
	 * @param int             $creative_id Creative post id.
	 * @param array<int, int> $also        Creative ids to remove with it.
	 * @return true|WP_Error
	 */
	public function remove_with_copies( int $creative_id, array $also ): bool|WP_Error {
		$copies = array_values( array_intersect( $this->creatives->same_file_elsewhere( $creative_id ), $also ) );
		$result = $this->manager->remove( $creative_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $copies as $copy_id ) {
			$removed = $this->manager->remove( $copy_id );

			if ( is_wp_error( $removed ) ) {
				return $removed;
			}
		}

		return true;
	}

	/**
	 * Builds a workflow error in `Creative_Manager`'s shape, so the portal's
	 * message map and the lane that checks it read both classes alike.
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
