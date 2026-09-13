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
use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Attachment_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Storage\Private_Storage;
use Throwable;
use WP_Error;

/**
 * One policy for REST and progressively enhanced creative forms.
 */
final class Creative_Manager {

	public const MAX_ALT_TEXT_LENGTH = 500;

	/**
	 * How many creatives one placement may hold on a campaign.
	 *
	 * A backstop, not a product constraint. P2 exists to allow several
	 * creatives per placement — a rotation, a seasonal set — and ten is high
	 * enough that no honest use meets it.
	 *
	 * There is a limit at all because the cost of a runaway lands on the wrong
	 * person. Rate limiting bounds how fast an advertiser can upload and
	 * nothing bounds the total, so fifty creatives on one placement is fifty
	 * things a publisher has to review and, once P3 arrives, fifty candidates
	 * on a fill. Both fail gradually and neither points at its cause.
	 *
	 * Deliberately a constant rather than a setting. A setting whose default
	 * nobody changes is a constant with more moving parts, and shipping this
	 * first tells us whether anyone ever reaches it — which is what would say
	 * what range a setting should offer.
	 */
	public const MAX_CREATIVES_PER_PLACEMENT = 10;

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository            $campaigns  Campaign persistence.
	 * @param Creative_Repository            $creatives  Creative persistence.
	 * @param Creative_Attachment_Repository $attachments Media Library copy of the artwork.
	 * @param Placement_Repository           $placements Placement persistence.
	 * @param Creative_Uploader              $uploader   Hostile-file validation.
	 * @param Private_Storage                $storage    Private file storage.
	 * @param Rate_Limiter                   $limiter    Upload abuse bounding.
	 * @param Audit_Repository               $audit      Audit persistence.
	 * @param Edit_Window                    $window     When editing is permitted.
	 * @param Creative_Approval              $approvals  Queue counter for creatives awaiting publication.
	 * @param Creative_Assignment_Repository $assignments Delivery assignments, which carry each variant's weight.
	 * @param Revision_Policy                $revisions   Whether a creative may still be edited in place.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Creative_Attachment_Repository $attachments,
		private readonly Placement_Repository $placements,
		private readonly Creative_Uploader $uploader,
		private readonly Private_Storage $storage,
		private readonly Rate_Limiter $limiter,
		private readonly Audit_Repository $audit,
		private readonly Edit_Window $window,
		private readonly Creative_Approval $approvals,
		private readonly Creative_Assignment_Repository $assignments,
		private readonly Revision_Policy $revisions
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
			return $this->error( 'aggr_upload_forbidden', __( 'You do not have permission to upload creative.', 'aggressive-ads' ), 403 );
		}

		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_UPLOAD, get_current_user_id() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$authorized = $this->authorize_campaign_placement( $campaign_id, $placement_id );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		/*
		 * Several creatives per placement, up to a backstop.
		 *
		 * This used to refuse the second one outright, which is the P1
		 * limitation P2 exists to remove. Coverage was switched to
		 * `Coverage_Service` first precisely so this could be a small change:
		 * a placement counts as covered once, however many creatives cover it,
		 * so lifting the cap changes what may be uploaded and nothing about
		 * what may be submitted.
		 */
		$on_this_placement = 0;

		foreach ( $this->creatives->for_campaign( $campaign_id ) as $creative ) {
			if ( $placement_id === $creative['placement_id'] ) {
				++$on_this_placement;
			}
		}

		if ( $on_this_placement >= self::MAX_CREATIVES_PER_PLACEMENT ) {
			return $this->error(
				'aggr_creative_limit_reached',
				sprintf(
					/* translators: %d: maximum creatives allowed on one placement. */
					__( 'This placement already has the maximum of %d creatives. Remove one before uploading another.', 'aggressive-ads' ),
					self::MAX_CREATIVES_PER_PLACEMENT
				),
				409,
				'file'
			);
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
			return $this->error( 'aggr_alt_text_too_long', __( 'Use 500 characters or fewer for the ad creative description.', 'aggressive-ads' ), 422, 'alt_text' );
		}

		$accepted = $this->uploader->accept( $file, $this->placements->max_bytes( $placement_id ) );

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
					__( 'Uploaded: %1$s. Required: %2$s. Resize the ad creative and try again.', 'aggressive-ads' ),
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

			/*
			 * **A five hundred that recorded nothing could not be diagnosed.**
			 *
			 * `rest-api.md` already says diagnostics belong in the audit log
			 * rather than the response body, and this path did neither: the
			 * reader got "please try again" and nobody could find out what
			 * happened. The two ways this upload can fail late are
			 * indistinguishable from each other in a bug report, so each now
			 * says which one it was.
			 */
			$this->audit->insert(
				new Audit_Event(
					event: 'creative.upload_failed',
					object_type: 'campaign',
					object_id: $campaign_id,
					org_id: $this->campaigns->org_id( $campaign_id ),
					message: 'Creative record could not be inserted.',
					context: array(
						'reason'       => 'insert_returned_zero',
						'placement_id' => $placement_id,
						'width'        => $accepted['width'],
						'height'       => $accepted['height'],
					),
					outcome: Audit_Event::OUTCOME_FAILED
				)
			);

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
			/*
			 * Which of the four checks disagreed, recorded before the evidence
			 * is deleted. "The record did not read back" is not a diagnosis; a
			 * path that differs by a prefix and a campaign id that differs by
			 * one are entirely different bugs.
			 */
			$this->audit->insert(
				new Audit_Event(
					event: 'creative.upload_failed',
					object_type: 'campaign',
					object_id: $campaign_id,
					org_id: $this->campaigns->org_id( $campaign_id ),
					message: 'Creative record did not read back as written.',
					context: array(
						'reason'            => 'read_back_mismatch',
						'creative_id'       => $creative_id,
						'storage_recorded'  => null !== $recorded,
						'details_recorded'  => null !== $details,
						'path_matches'      => null !== $recorded && $accepted['path'] === $recorded['path'],
						'campaign_matches'  => null !== $details && $campaign_id === $details['campaign_id'],
						'placement_matches' => null !== $details && $placement_id === $details['placement_id'],
					),
					outcome: Audit_Event::OUTCOME_FAILED
				)
			);

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

		/*
		 * A creative uploaded to a campaign that is already running has missed
		 * the transition that publishes artwork, so it needs a reviewer. This
		 * is what puts it on the queue — before it existed such a creative was
		 * invisible: no counter, no tab, no route, and therefore no way to ever
		 * approve it or serve it.
		 */
		$this->approvals->refresh_count( $campaign_id );
		$this->notify_awaiting( $campaign_id, $creative_id );

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
	 * Tells staff about a creative that has just joined the review queue.
	 *
	 * Only when it actually joined one. `refresh_count()` runs after every
	 * upload, including uploads to a campaign that has never been published,
	 * where the creative is published by the campaign's own approval and no
	 * reviewer is waiting on anything. Asking the queue is what distinguishes
	 * the two, and it is the same question the retry re-asks later.
	 *
	 * Failures are swallowed for the reason `Campaign_Change_Manager::notify_request()`
	 * swallows them: the creative is already saved, and returning an error now
	 * would tell the advertiser their upload failed when it did not.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $creative_id Creative post id.
	 * @return void
	 */
	private function notify_awaiting( int $campaign_id, int $creative_id ): void {
		if ( ! in_array( $creative_id, $this->approvals->awaiting( $campaign_id ), true ) ) {
			return;
		}

		try {
			// Spelled out rather than referenced through Creative_Mailer, as
			// Campaign_Change_Manager spells out its own notify hook: a hook
			// name that only exists as a constant is a hook nobody can grep for.
			do_action( 'aggr_notify_creative_awaiting', $campaign_id, $creative_id, get_current_user_id() );
		} catch ( Throwable $exception ) {
			$this->audit->insert(
				new Audit_Event(
					event: 'campaign.notification_failed',
					outcome: Audit_Event::OUTCOME_FAILED,
					object_type: 'campaign',
					object_id: $campaign_id,
					org_id: $this->campaigns->org_id( $campaign_id ),
					message: $exception->getMessage(),
					context: array( 'creative_id' => $creative_id )
				)
			);
		}
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
	 * Swaps the artwork on a creative that no reviewer has accepted yet.
	 *
	 * Changing a banner used to mean removing the creative and uploading
	 * again. On a placement that is already covered that drops the coverage in
	 * between, throws away the destination and the share, and — once other
	 * creatives are on the placement — silently reorders the rotation. The
	 * record stays; only its bytes change.
	 *
	 * **Only while the creative is still editable**, on the same authority
	 * `set_destination()` uses. Replacing approved artwork in place is exactly
	 * what P2's immutability rule forbids, and the route from there is
	 * `Creative_Change_Manager::request()`, which stages the new file for
	 * review and leaves the live ad serving until staff accept it.
	 *
	 * The new bytes are recorded before the old ones are deleted. The other
	 * order leaves a record pointing at a file that is already gone if the
	 * write fails, which is unreadable rather than merely stale.
	 *
	 * @param int                  $creative_id Creative post id.
	 * @param array<string, mixed> $file        One $_FILES entry.
	 * @return array<string, mixed>|WP_Error
	 */
	public function replace_artwork( int $creative_id, array $file ): array|WP_Error {
		if ( ! current_user_can( Capabilities::UPLOAD_CREATIVE ) || ! current_user_can( 'edit_aggr_creative', $creative_id ) ) {
			return $this->error( 'aggr_artwork_forbidden', __( 'You do not have permission to change that creative.', 'aggressive-ads' ), 403 );
		}

		$creative = $this->creatives->details( $creative_id );

		if ( null === $creative ) {
			return $this->error( 'aggr_artwork_forbidden', __( 'You do not have permission to change that creative.', 'aggressive-ads' ), 403 );
		}

		$campaign_id  = (int) $creative['campaign_id'];
		$placement_id = (int) $creative['placement_id'];

		if ( ! current_user_can( 'edit_aggr_campaign', $campaign_id ) || ! $this->window->allows( $campaign_id ) ) {
			return $this->error( 'aggr_campaign_not_editable', __( 'This campaign cannot be changed right now.', 'aggressive-ads' ), 409 );
		}

		if ( $this->revisions->is_frozen( $creative_id ) ) {
			return $this->error(
				'aggr_artwork_frozen',
				__( 'This ad has already been approved, so new artwork is submitted as an update for review rather than swapped in here.', 'aggressive-ads' ),
				409,
				'file'
			);
		}

		// Rate limited as an upload, because it is one. Leaving this off would
		// make replacement the cheap way around the limit on `upload()`.
		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_UPLOAD, get_current_user_id() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$accepted = $this->uploader->accept( $file, $this->placements->max_bytes( $placement_id ) );

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
					__( 'Uploaded: %1$s. Required: %2$s. Resize the ad creative and try again.', 'aggressive-ads' ),
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

		$previous = $this->creatives->storage_details( $creative_id );

		$this->creatives->record_upload( $creative_id, $accepted );

		$recorded = $this->creatives->storage_details( $creative_id );

		if ( null === $recorded || $accepted['path'] !== $recorded['path'] ) {
			$this->storage->delete( $accepted['path'] );

			$this->audit->insert(
				new Audit_Event(
					event: 'creative.artwork_replace_failed',
					object_type: 'campaign',
					object_id: $campaign_id,
					org_id: $this->campaigns->org_id( $campaign_id ),
					message: 'Replacement artwork did not read back.',
					context: array(
						'creative_id' => $creative_id,
						'reason'      => 'read_back_mismatch',
					),
					outcome: Audit_Event::OUTCOME_FAILED
				)
			);

			return $this->error( 'aggr_artwork_not_saved', __( 'The new artwork could not be saved. Please try again.', 'aggressive-ads' ), 500 );
		}

		if ( null !== $previous && $previous['path'] !== $accepted['path'] ) {
			$this->storage->delete( $previous['path'] );
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'creative.artwork_replaced',
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: 'Creative artwork replaced before review.',
				context: array(
					'creative_id' => $creative_id,
					'width'       => $accepted['width'],
					'height'      => $accepted['height'],
					'bytes'       => $accepted['bytes'],
					'mime'        => $accepted['mime'],
				),
				actor_user_id: get_current_user_id()
			)
		);

		$this->approvals->refresh_count( $campaign_id );

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
	 * Repoints a creative that no reviewer has accepted yet.
	 *
	 * A destination typed with a typo used to be unfixable: the only route was
	 * remove and re-upload, which on a placement that is already covered means
	 * losing the coverage in between and re-doing the file.
	 *
	 * **Only while the creative is still editable.** `Revision_Policy` is the
	 * single authority on that and nothing here re-derives it. Once artwork has
	 * been approved, repointing it in place is the exact mutation P2's
	 * immutability rule exists to stop — a publisher accepted a destination as
	 * well as an image, and the advertiser's route from there is
	 * `Creative_Change_Manager::request_text_change()`, which stages the same
	 * edit for review and leaves the live ad serving meanwhile.
	 *
	 * Gated exactly as `set_weight()` is, and validated exactly as `upload()`
	 * is: the same URL reaching the site by a different door must not be
	 * judged by a different rule.
	 *
	 * @param int    $creative_id Creative post id.
	 * @param string $click_url   New destination.
	 * @return true|WP_Error
	 */
	public function set_destination( int $creative_id, string $click_url ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::UPLOAD_CREATIVE ) || ! current_user_can( 'edit_aggr_creative', $creative_id ) ) {
			return $this->error( 'aggr_destination_forbidden', __( 'You do not have permission to change that creative.', 'aggressive-ads' ), 403 );
		}

		$creative = $this->creatives->details( $creative_id );

		if ( null === $creative ) {
			return $this->error( 'aggr_destination_forbidden', __( 'You do not have permission to change that creative.', 'aggressive-ads' ), 403 );
		}

		$campaign_id = (int) $creative['campaign_id'];

		if ( ! current_user_can( 'edit_aggr_campaign', $campaign_id ) || ! $this->window->allows( $campaign_id ) ) {
			return $this->error( 'aggr_campaign_not_editable', __( 'This campaign cannot be changed right now.', 'aggressive-ads' ), 409 );
		}

		if ( $this->revisions->is_frozen( $creative_id ) ) {
			return $this->error(
				'aggr_destination_frozen',
				__( 'This ad has already been approved, so its destination is changed by requesting an update rather than edited here.', 'aggressive-ads' ),
				409,
				'click_url'
			);
		}

		$click_url = trim( $click_url );

		if ( '' === $click_url ) {
			return $this->error( 'aggr_click_url_required', __( 'Enter the destination URL for this creative.', 'aggressive-ads' ), 422, 'click_url' );
		}

		if ( ! Campaign_Rules::is_valid_click_url( $click_url ) || false === wp_http_validate_url( $click_url ) ) {
			return $this->error( 'aggr_click_url_invalid', __( 'Enter a valid http or https destination URL without embedded credentials.', 'aggressive-ads' ), 422, 'click_url' );
		}

		/*
		 * A save that changes nothing is a success, not an error. Somebody who
		 * opened the field, thought better of it and saved anyway has done
		 * nothing wrong, and there is no audit event worth writing for it.
		 */
		if ( $click_url === (string) $creative['click_url'] ) {
			return true;
		}

		$this->creatives->set_click_url( $creative_id, $click_url );

		/*
		 * The destination is recorded, the old one is not. What it used to be
		 * is the question an abuse report actually asks, and the revision
		 * chain does not hold it for a creative that was never frozen.
		 */
		$this->audit->insert(
			new Audit_Event(
				event: 'creative.destination_changed',
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: 'Creative destination changed before review.',
				context: array(
					'creative_id' => $creative_id,
					'from'        => (string) $creative['click_url'],
					'to'          => $click_url,
				)
			)
		);

		return true;
	}

	/**
	 * Sets how often one creative is chosen against the others on its placement.
	 *
	 * The weight already decides delivery — `Weighted_Selection` reads it, and
	 * two creatives at 3 and 1 have always rotated three to one — but nothing
	 * outside the REST route could set it, so the behaviour existed and was
	 * unreachable.
	 *
	 * Gated exactly as removal is, because it is the same kind of act: it
	 * changes what this campaign delivers. Capability, then ownership of the
	 * creative, then ownership of the campaign, then the edit window. Ownership
	 * before the window, so a caller who may not touch the campaign at all is
	 * refused for that reason rather than told it is the wrong moment.
	 *
	 * The floor is `Assignment_Rules::MIN_WEIGHT`, which is 1, not 0. There is
	 * deliberately no "weight it to nothing": a variant that should stop serving
	 * is retired, which removes it from the candidate set and says so, rather
	 * than left in the set at a weight that can never win. The two look the same
	 * on a chart and mean different things to whoever reads it next.
	 *
	 * @param int $creative_id Creative post id.
	 * @param int $weight      Relative share, clamped to the assignment rules.
	 * @return true|WP_Error
	 */
	public function set_weight( int $creative_id, int $weight ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::UPLOAD_CREATIVE ) || ! current_user_can( 'edit_aggr_creative', $creative_id ) ) {
			return $this->error( 'aggr_weight_forbidden', __( 'You do not have permission to change that creative.', 'aggressive-ads' ), 403 );
		}

		$creative = $this->creatives->details( $creative_id );

		if ( null === $creative ) {
			return $this->error( 'aggr_weight_forbidden', __( 'You do not have permission to change that creative.', 'aggressive-ads' ), 403 );
		}

		$campaign_id = (int) $creative['campaign_id'];

		if ( ! current_user_can( 'edit_aggr_campaign', $campaign_id ) || ! $this->window->allows( $campaign_id ) ) {
			return $this->error( 'aggr_campaign_not_editable', __( 'This campaign cannot be changed right now.', 'aggressive-ads' ), 409 );
		}

		if ( $weight < Assignment_Rules::MIN_WEIGHT || $weight > Assignment_Rules::MAX_WEIGHT ) {
			return $this->error(
				'aggr_weight_out_of_range',
				sprintf(
					/* translators: 1: lowest permitted weight, 2: highest permitted weight. */
					__( 'A share must be between %1$d and %2$d.', 'aggressive-ads' ),
					Assignment_Rules::MIN_WEIGHT,
					Assignment_Rules::MAX_WEIGHT
				),
				422
			);
		}

		$assignment = null;

		foreach ( $this->assignments->for_campaign( $campaign_id ) as $row ) {
			if ( (int) $row['revision_id'] === $creative_id ) {
				$assignment = $row;

				break;
			}
		}

		if ( null === $assignment ) {
			return $this->error( 'aggr_weight_no_assignment', __( 'That creative is not delivering yet, so it has no share to set.', 'aggressive-ads' ), 409 );
		}

		/*
		 * `update()` carries the revision it expects, so two people editing one
		 * campaign's shares cannot silently overwrite each other: the second
		 * write matches no row and reports it rather than winning.
		 */
		$written = $this->assignments->update(
			(int) $assignment['id'],
			$campaign_id,
			array( 'weight' => $weight ),
			(int) $assignment['revision']
		);

		if ( false === $written || $written < 1 ) {
			return $this->error( 'aggr_weight_not_saved', __( 'That share could not be saved. Reload the page and try again.', 'aggressive-ads' ), 409 );
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'creative.weight_changed',
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: 'Creative delivery share changed.',
				context: array(
					'creative_id' => $creative_id,
					'weight'      => $weight,
					'was'         => (int) $assignment['weight'],
				)
			)
		);

		return true;
	}

	/**
	 * Removes an unapproved creative and its private file.
	 *
	 * @param int $creative_id Creative post id.
	 * @return true|WP_Error
	 */
	public function remove( int $creative_id ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::UPLOAD_CREATIVE ) || ! current_user_can( 'delete_aggr_creative', $creative_id ) ) {
			return $this->error( 'aggr_remove_forbidden', __( 'You do not have permission to remove that creative.', 'aggressive-ads' ), 403 );
		}

		$creative = $this->creatives->details( $creative_id );

		if ( null === $creative ) {
			return $this->error( 'aggr_remove_forbidden', __( 'You do not have permission to remove that creative.', 'aggressive-ads' ), 403 );
		}

		$campaign_id = $creative['campaign_id'];

		if ( ! current_user_can( 'edit_aggr_campaign', $campaign_id ) || ! $this->window->allows( $campaign_id ) ) {
			return $this->error( 'aggr_campaign_not_editable', __( 'This campaign cannot be changed right now.', 'aggressive-ads' ), 409 );
		}

		if ( $this->attachments->has_attachment( $creative_id ) || $this->creatives->provider_ad_id( $creative_id ) > 0 ) {
			return $this->error( 'aggr_creative_published', __( 'A published creative cannot be removed from this draft workflow.', 'aggressive-ads' ), 409 );
		}

		$stored      = $this->creatives->storage_details( $creative_id );
		$quarantined = '';

		if ( null !== $stored && '' !== $stored['path'] && null !== $this->storage->resolve( $stored['path'] ) ) {
			$quarantined = (string) $this->storage->quarantine( $stored['path'] );

			if ( '' === $quarantined ) {
				return $this->error( 'aggr_creative_not_deleted', __( 'The creative could not be removed. Please try again.', 'aggressive-ads' ), 500 );
			}
		}

		if ( ! $this->creatives->delete( $creative_id ) ) {
			if ( '' !== $quarantined && null !== $stored && ! $this->storage->restore( $quarantined, $stored['path'] ) ) {
				return $this->error( 'aggr_creative_restore_failed', __( 'The creative record and file could not be reconciled. Please contact an administrator.', 'aggressive-ads' ), 500 );
			}

			return $this->error( 'aggr_creative_not_deleted', __( 'The creative could not be removed. Please try again.', 'aggressive-ads' ), 500 );
		}

		if ( '' !== $quarantined ) {
			$this->storage->delete( $quarantined );
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
			/*
			 * **Keeps `aggr_forbidden`.** The two checks above got their own
			 * codes because nothing asserted theirs; this one is the ownership
			 * refusal that forty-four tests and `campaign-workflow.md` already
			 * name, and renaming an established code to improve one sentence is
			 * a trade in the wrong direction. Which refusal this was belongs in
			 * the audit log, not in a code a tenant-isolation test depends on.
			 */
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to do that.', 'aggressive-ads' ), 403 );
		}

		if ( ! $this->window->allows( $campaign_id ) ) {
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
