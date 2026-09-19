<?php
/**
 * Advertiser-proposed changes to a campaign that is already running.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Live_Edit_Rules;
use Aggressive\Ads\Notification\Request_Mailer;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Campaign_Request_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Rate_Limiter;
use WP_Error;

/**
 * Stage a change, keep serving the approved version, reconcile on approval.
 *
 * The shape is taken from `Creative_Change_Manager`, and for the same reason:
 * a campaign that is scheduled, live or paused was approved by staff as a
 * specific thing, and letting an advertiser rewrite it in place would mean the
 * approval no longer describes what is being served. So nothing an advertiser
 * submits here touches the campaign. It sits beside it until somebody with
 * `aggr_review_campaigns` accepts or refuses it.
 *
 * What may be proposed at all is site policy, held in Settings. This class
 * asks; it does not decide, and it never trusts the request to say what was
 * allowed.
 */
final class Campaign_Change_Manager implements Service {

	public const MAX_REVIEW_NOTES_LENGTH = 2000;

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository         $campaigns  Campaign persistence.
	 * @param Creative_Repository         $creatives  Creative persistence.
	 * @param Revision_Policy             $revisions  Immutability policy for approved creatives.
	 * @param Settings                    $settings   Site policy.
	 * @param Fill_Cache                  $fill       Delivery cache.
	 * @param Rate_Limiter                $limiter    Abuse bounding.
	 * @param Audit_Repository            $audit      Audit persistence.
	 * @param Campaign_Request_Repository $requests   Advertiser requests and proposed changes.
	 * @param Campaign_Change_Summary     $summary    Words a change set for people.
	 * @param Live_Package_Change         $package_change Upgrades and downgrades.
	 * @param Live_Link_Change            $links      The link every ad goes to.
	 * @param Request_Notifier            $notifier   Tells the review team.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Revision_Policy $revisions,
		private readonly Settings $settings,
		private readonly Fill_Cache $fill,
		private readonly Rate_Limiter $limiter,
		private readonly Audit_Repository $audit,
		private readonly Campaign_Request_Repository $requests,
		private readonly Campaign_Change_Summary $summary,
		private readonly Live_Package_Change $package_change,
		private readonly Live_Link_Change $links,
		private readonly Request_Notifier $notifier
	) {
	}

	/**
	 * Listens for status changes so a request cannot outlive its subject.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'aggr_campaign_transitioned', array( $this, 'clear_request_on_transition' ) );
	}

	/**
	 * Drops a request once the campaign has moved.
	 *
	 * Whatever the advertiser asked for, the status it asked about is gone —
	 * leaving the request in place would show staff a pending ask against a
	 * campaign that has already changed, and show the advertiser a request that
	 * will never be answered.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return void
	 */
	public function clear_request_on_transition( int $campaign_id ): void {
		if ( array() !== $this->requests->action_request( $campaign_id ) ) {
			$this->requests->clear_action_request( $campaign_id );
		}

		/*
		 * Staged edits go the same way, and for the same reason.
		 *
		 * This used to clear only the action request, which left a submitted
		 * edit attached to a campaign that had since been cancelled or
		 * completed. Nothing downstream re-checked the status, so a reviewer
		 * working the queue could approve a title and a date window onto a
		 * finished campaign — rewriting it, busting the fill cache and auditing
		 * the change as applied.
		 */
		if ( array() === $this->submitted_edits( $campaign_id ) ) {
			return;
		}

		if ( in_array( $this->campaigns->status( $campaign_id ), self::editable_statuses(), true ) ) {
			return;
		}

		$this->requests->clear_pending_edits( $campaign_id );
		$this->log(
			'campaign.changes_dropped',
			$campaign_id,
			array(),
			'Pending campaign changes dropped: the campaign is no longer running.',
			Audit_Event::OUTCOME_FAILED
		);
	}

	/**
	 * The statuses this workflow applies to.
	 *
	 * Exactly `Post_Statuses::published()` — the campaigns that occupy the live
	 * set. A draft needs no proposal workflow because it can simply be edited,
	 * and anything terminal is finished.
	 *
	 * @return array<int, string>
	 */
	public static function editable_statuses(): array {
		return Post_Statuses::published();
	}

	/**
	 * The fields an advertiser may propose on this site right now.
	 *
	 * @return array<int, string>
	 */
	public function allowed_fields(): array {
		return Live_Edit_Rules::allowed_fields( $this->settings->live_edit_fields() );
	}

	/**
	 * Whether this campaign can currently take a proposal.
	 *
	 * @param int $campaign_id Campaign post id.
	 */
	public function accepts_changes( int $campaign_id ): bool {
		return array() !== $this->settings->live_edit_fields()
			&& in_array( $this->campaigns->status( $campaign_id ), self::editable_statuses(), true );
	}

	/**
	 * Stages a change without touching the running campaign.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $proposed    Advertiser input.
	 * @return array<string, mixed>|WP_Error The stored change set.
	 */
	public function request( int $campaign_id, array $proposed ): array|WP_Error {
		// Retained as the one-shot form: stage the fields, then send them. The
		// stepped portal flow calls the two halves separately.
		$authorized = $this->authorize( $campaign_id, Capabilities::SUBMIT_CAMPAIGN );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$allowed = $this->settings->live_edit_fields();

		if ( array() === $allowed ) {
			return $this->error( 'aggr_live_edits_disabled', __( 'Changes to running campaigns are not accepted on this site.', 'aggressive-ads' ), 404 );
		}

		if ( ! in_array( $this->campaigns->status( $campaign_id ), self::editable_statuses(), true ) ) {
			return $this->error( 'aggr_campaign_not_running', __( 'This campaign is not running, so there is nothing to change.', 'aggressive-ads' ), 409 );
		}

		$limited = $this->limiter->attempt( Rate_Limiter::ACTION_TRANSITION, get_current_user_id() );

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		if ( $this->requests->pending_edits_submitted( $campaign_id ) ) {
			return $this->error( 'aggr_edits_pending', __( 'This campaign already has changes waiting for review. Withdraw them first.', 'aggressive-ads' ), 409 );
		}

		$proposed = $this->package_change->with_derived_end( $campaign_id, $this->links->expand( $campaign_id, $proposed ), array() );
		$diff     = Live_Edit_Rules::diff( $allowed, $this->current( $campaign_id ), $proposed );

		$validation = $this->package_change->validate( $this->current( $campaign_id ), $campaign_id, $diff );

		if ( ! $validation->is_valid() ) {
			return new WP_Error(
				'aggr_live_edit_invalid',
				__( 'These changes cannot be requested yet.', 'aggressive-ads' ),
				array(
					'status'   => 422,
					'problems' => $validation->problems(),
				)
			);
		}

		$buyable = $this->package_change->buyable( $diff );

		if ( is_wp_error( $buyable ) ) {
			return $buyable;
		}

		if ( ! $this->requests->set_pending_edits( $campaign_id, $diff, get_current_user_id() ) ) {
			return $this->error( 'aggr_edits_not_saved', __( 'The requested changes could not be saved. Please try again.', 'aggressive-ads' ), 500 );
		}

		$this->log( 'campaign.changes_requested', $campaign_id, $diff, 'Campaign changes requested.' );

		return $diff;
	}

	/**
	 * Merges one wizard step into the proposal without sending it for review.
	 *
	 * Each step posts only its own fields, so the merge is over the *stored*
	 * proposal rather than a whole-campaign diff: saving the schedule step must
	 * not discard a name change made two steps earlier. Fields absent from this
	 * step are therefore left alone rather than treated as cleared.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $proposed    One step's fields.
	 * @return array<string, mixed>|WP_Error The accumulated proposal.
	 */
	public function stage( int $campaign_id, array $proposed ): array|WP_Error {
		$authorized = $this->authorize( $campaign_id, Capabilities::SUBMIT_CAMPAIGN );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$allowed = $this->settings->live_edit_fields();

		if ( array() === $allowed ) {
			return $this->error( 'aggr_live_edits_disabled', __( 'Changes to running campaigns are not accepted on this site.', 'aggressive-ads' ), 404 );
		}

		if ( ! in_array( $this->campaigns->status( $campaign_id ), self::editable_statuses(), true ) ) {
			return $this->error( 'aggr_campaign_not_running', __( 'This campaign is not running, so there is nothing to change.', 'aggressive-ads' ), 409 );
		}

		if ( $this->requests->pending_edits_submitted( $campaign_id ) ) {
			return $this->error( 'aggr_edits_pending', __( 'These changes are already with the review team. Withdraw them to keep editing.', 'aggressive-ads' ), 409 );
		}

		$current  = $this->current( $campaign_id );
		$merged   = $this->requests->pending_edits( $campaign_id );
		$proposed = $this->package_change->with_derived_end( $campaign_id, $this->links->expand( $campaign_id, $proposed ), $merged );
		$step     = Live_Edit_Rules::diff( $allowed, $current, $proposed );

		// A field the advertiser has put back to its original value is no
		// longer a change, so it leaves the proposal rather than lingering as
		// a no-op row a reviewer has to read and dismiss.
		foreach ( Live_Edit_Rules::allowed_fields( $allowed ) as $field ) {
			if ( ! array_key_exists( $field, $proposed ) ) {
				continue;
			}

			unset( $merged[ $field ] );

			if ( array_key_exists( $field, $step ) ) {
				$merged[ $field ] = $step[ $field ];
			}
		}

		/*
		 * Judged at each step, not only at submission. Telling somebody their
		 * destination URL is malformed four screens after they typed it is a
		 * worse version of not telling them.
		 *
		 * "Nothing changed" is excluded: clearing a field back to its original
		 * value is a legitimate thing to do on a step, and it is only a problem
		 * at submission time.
		 */
		$validation = $this->package_change->validate( $current, $campaign_id, $merged );

		if ( ! $validation->is_valid() && ! $validation->has( Live_Edit_Rules::ERROR_NOTHING_CHANGED ) ) {
			return new WP_Error(
				'aggr_live_edit_invalid',
				__( 'These changes cannot be saved yet.', 'aggressive-ads' ),
				array(
					'status'   => 422,
					'problems' => $validation->problems(),
				)
			);
		}

		// On sale is not enough; creation's own test decides whether it can be bought.
		$buyable = $this->package_change->buyable( $merged );

		if ( is_wp_error( $buyable ) ) {
			return $buyable;
		}

		if ( ! $this->requests->set_pending_edits( $campaign_id, $merged, get_current_user_id() ) ) {
			return $this->error( 'aggr_edits_not_saved', __( 'The changes could not be saved. Please try again.', 'aggressive-ads' ), 500 );
		}

		return $merged;
	}

	/**
	 * Sends the accumulated proposal to the review team.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	public function submit( int $campaign_id ): bool|WP_Error {
		$authorized = $this->authorize( $campaign_id, Capabilities::SUBMIT_CAMPAIGN );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$limited = $this->limiter->attempt( Rate_Limiter::ACTION_TRANSITION, get_current_user_id() );

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		if ( $this->requests->pending_edits_submitted( $campaign_id ) ) {
			return $this->error( 'aggr_edits_pending', __( 'These changes are already with the review team.', 'aggressive-ads' ), 409 );
		}

		$edits      = $this->requests->pending_edits( $campaign_id );
		$validation = $this->package_change->validate( $this->current( $campaign_id ), $campaign_id, $edits );

		if ( ! $validation->is_valid() ) {
			return new WP_Error(
				'aggr_live_edit_invalid',
				__( 'These changes cannot be submitted yet.', 'aggressive-ads' ),
				array(
					'status'   => 422,
					'problems' => $validation->problems(),
				)
			);
		}

		if ( ! $this->requests->set_pending_edits( $campaign_id, $edits, get_current_user_id(), true ) ) {
			return $this->error( 'aggr_edits_not_saved', __( 'The changes could not be submitted. Please try again.', 'aggressive-ads' ), 500 );
		}

		$buyable = $this->package_change->buyable( $edits );

		if ( is_wp_error( $buyable ) ) {
			return $buyable;
		}

		// The prices the advertiser was shown, for billing to settle from (#263).
		$this->log( 'campaign.changes_requested', $campaign_id, $edits, 'Campaign changes submitted for review.', Audit_Event::OUTCOME_OK, $this->package_change->price_context( $campaign_id, $edits ) );
		$this->notifier->send( $campaign_id, Request_Mailer::KIND_EDITS );

		return true;
	}

	/**
	 * Applies a pending change to the running campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	public function approve( int $campaign_id ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) || ! current_user_can( Capabilities::PUBLISH_TO_ADSANITY ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to approve campaign changes.', 'aggressive-ads' ), 403 );
		}

		$edits = $this->submitted_edits( $campaign_id );

		if ( array() === $edits ) {
			return $this->error( 'aggr_no_pending_edits', __( 'This campaign has no changes waiting for review.', 'aggressive-ads' ), 404 );
		}

		/*
		 * The campaign must still be one this workflow applies to.
		 *
		 * The transition hook clears stale edits, but a hook is a promise about
		 * a code path rather than about state: a status written by a migration,
		 * a direct repository call, or a transition that fired before this
		 * service was initialized leaves edits behind with nothing to catch
		 * them. This is the check that makes approving onto a finished campaign
		 * impossible rather than merely unlikely.
		 */
		if ( ! in_array( $this->campaigns->status( $campaign_id ), self::editable_statuses(), true ) ) {
			return $this->error( 'aggr_campaign_not_running', __( 'This campaign is no longer running, so its changes cannot be approved.', 'aggressive-ads' ), 409 );
		}

		// Re-judged at approval, not merely at request. A proposal can sit in
		// the queue past its own end date, and approving it then would write a
		// window that was valid on Tuesday and is nonsense today.
		$validation = $this->package_change->validate( $this->current( $campaign_id ), $campaign_id, $edits );

		if ( ! $validation->is_valid() ) {
			$this->log( 'campaign.changes_stale', $campaign_id, $edits, 'Pending campaign changes were no longer valid at approval.', Audit_Event::OUTCOME_FAILED );

			return new WP_Error(
				'aggr_live_edit_stale',
				__( 'These changes are no longer valid and cannot be approved. Ask the advertiser to request them again.', 'aggressive-ads' ),
				array(
					'status'   => 409,
					'problems' => $validation->problems(),
				)
			);
		}

		// Read before apply(): the old price is the campaign's only until it is overwritten.
		$prices  = $this->package_change->price_context( $campaign_id, $edits );
		$applied = $this->apply( $campaign_id, $edits );

		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$this->requests->clear_pending_edits( $campaign_id );

		// The campaign is in the live set, so anything the fill payload quotes
		// — the destination above all — is stale the instant this returns.
		// Busting here rather than on a schedule is what stops an approved
		// destination change riding out a CDN TTL pointing at the old URL.
		$this->fill->bust_campaign( $campaign_id );

		$this->log( 'campaign.changes_approved', $campaign_id, $edits, 'Campaign changes approved and applied.', Audit_Event::OUTCOME_OK, $prices );

		return true;
	}

	/**
	 * Refuses a pending change, with a reason the advertiser will read.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $notes       Advertiser-facing reason.
	 * @return true|WP_Error
	 */
	public function reject( int $campaign_id, string $notes ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to decide campaign changes.', 'aggressive-ads' ), 403 );
		}

		$notes = trim( $notes );

		if ( '' === $notes ) {
			return $this->error( 'aggr_review_notes_required', __( 'Tell the advertiser why the changes were not accepted.', 'aggressive-ads' ), 422 );
		}

		if ( strlen( $notes ) > self::MAX_REVIEW_NOTES_LENGTH ) {
			return $this->error( 'aggr_review_notes_long', __( 'That explanation is too long.', 'aggressive-ads' ), 422 );
		}

		$edits = $this->submitted_edits( $campaign_id );

		if ( array() === $edits ) {
			return $this->error( 'aggr_no_pending_edits', __( 'This campaign has no changes waiting for review.', 'aggressive-ads' ), 404 );
		}

		$this->campaigns->set_review_notes( $campaign_id, $notes );
		$this->requests->clear_pending_edits( $campaign_id );

		$this->log( 'campaign.changes_rejected', $campaign_id, $edits, 'Campaign changes rejected.' );

		return true;
	}

	/**
	 * Lets the advertiser take back their own proposal.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	public function withdraw( int $campaign_id ): bool|WP_Error {
		$authorized = $this->authorize( $campaign_id, Capabilities::SUBMIT_CAMPAIGN );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$edits = $this->requests->pending_edits( $campaign_id );

		if ( array() === $edits ) {
			return $this->error( 'aggr_no_pending_edits', __( 'There are no requested changes to withdraw.', 'aggressive-ads' ), 404 );
		}

		$this->requests->clear_pending_edits( $campaign_id );
		$this->log( 'campaign.changes_withdrawn', $campaign_id, $edits, 'Campaign changes withdrawn by the advertiser.' );

		return true;
	}

	/**
	 * What a reviewer must know about a submitted change beyond its rows.
	 *
	 * Whether it changes the ad sizes — a package change does, and the review
	 * screen's warning used to look for a placement row only, so a package
	 * change arrived without one — and, for a package change, the price moving
	 * in words.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array{structural: bool, price: string}
	 */
	public function pending_review_facts( int $campaign_id ): array {
		$edits = $this->submitted_edits( $campaign_id );

		return array(
			'structural' => Live_Edit_Rules::is_structural( $edits ),
			'price'      => $this->package_change->price_note( $campaign_id, $edits ),
		);
	}

	/**
	 * A pending change rendered as before/after rows.
	 *
	 * One presenter, used by both the advertiser's screen and the reviewer's,
	 * because a reviewer approving a change described differently from the one
	 * the advertiser requested is the failure this whole workflow exists to
	 * avoid.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, array{field: string, label: string, from: string, to: string}>
	 */
	public function pending_summary( int $campaign_id ): array {
		return $this->summary->rows( $this->package_change->implied( $campaign_id, $this->submitted_edits( $campaign_id ) ), $this->current( $campaign_id ) );
	}

	/**
	 * The change set an advertiser is still assembling, submitted or not.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, array{field: string, label: string, from: string, to: string}>
	 */
	public function draft_summary( int $campaign_id ): array {
		return $this->summary->rows( $this->package_change->implied( $campaign_id, $this->requests->pending_edits( $campaign_id ) ), $this->current( $campaign_id ) );
	}

	/**
	 * A proposal only once the advertiser has finished it.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<string, mixed>
	 */
	private function submitted_edits( int $campaign_id ): array {
		return $this->requests->pending_edits_submitted( $campaign_id )
			? $this->requests->pending_edits( $campaign_id )
			: array();
	}

	/**
	 * The campaign as it stands, in the shape Live_Edit_Rules compares against.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<string, mixed>
	 */
	public function current( int $campaign_id ): array {
		$click_urls = array();

		foreach ( $this->creatives->for_campaign( $campaign_id ) as $creative ) {
			$click_urls[ (int) $creative['id'] ] = (string) $creative['click_url'];
		}

		return array(
			'title'             => $this->campaigns->title( $campaign_id ),
			'advertiser_notes'  => $this->campaigns->advertiser_notes( $campaign_id ),
			'start_ts'          => $this->campaigns->start_ts( $campaign_id ),
			'end_ts'            => $this->campaigns->end_ts( $campaign_id ),
			'placement_ids'     => $this->campaigns->placement_ids( $campaign_id ),
			'click_urls'        => $click_urls,
			'default_click_url' => $this->links->shared( $campaign_id ),
			'package_id'        => $this->campaigns->package_id( $campaign_id ),

			// Not a field: what Live_Edit_Rules holds a placement change to.
			'placement_choices' => $this->package_change->placement_choices( $campaign_id ),

			// Not fields: what a package change may move to, and what was paid.
			'package_choices'   => $this->package_change->on_sale(),
			'budget_cents'      => $this->campaigns->budget_cents( $campaign_id ),
			'currency'          => $this->campaigns->currency( $campaign_id ),
		);
	}

	/**
	 * Writes an approved change set.
	 *
	 * Destinations are written through Creative_Repository because that is
	 * where creative meta lives; everything else goes through the campaign's
	 * own persistence, which already rolls back a partial meta write.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $edits       Validated change set.
	 * @return true|WP_Error
	 */
	private function apply( int $campaign_id, array $edits ) {
		$fields = array();

		// The package first, so a placement or date change in the same edit wins over what it implies.
		if ( array_key_exists( 'package_id', $edits ) ) {
			$package = $this->package_change->fields( $campaign_id, $edits );

			if ( is_wp_error( $package ) ) {
				return $package;
			}

			$fields = $package;
		}

		foreach ( array( 'title', 'advertiser_notes', 'start_ts', 'end_ts', 'placement_ids', 'default_click_url' ) as $field ) {
			if ( array_key_exists( $field, $edits ) ) {
				$fields[ $field ] = $edits[ $field ];
			}
		}

		if ( array() !== $fields ) {
			$written = $this->campaigns->update_draft( $campaign_id, $fields );

			if ( is_wp_error( $written ) ) {
				return $written;
			}
		}

		if ( ! array_key_exists( 'click_urls', $edits ) || ! is_array( $edits['click_urls'] ) ) {
			return true;
		}

		// Scoped to this campaign's own creatives before writing. The ids came
		// from a stored proposal, and a stored proposal is still input.
		$owned = array();

		foreach ( $this->creatives->for_campaign( $campaign_id ) as $creative ) {
			$owned[ (int) $creative['id'] ] = true;
		}

		foreach ( $edits['click_urls'] as $creative_id => $url ) {
			$id = (int) $creative_id;

			if ( isset( $owned[ $id ] ) && is_string( $url ) ) {
				/*
				 * A frozen creative is revised, not rewritten.
				 *
				 * This used to call `set_click_url()`, which repointed the
				 * destination of an ad a publisher had already approved and
				 * that was already serving — the exact mutation P2's
				 * immutability rule exists to stop. The policy decides which it
				 * is; nothing here re-derives that condition.
				 */
				$this->revisions->apply_text_change( $id, $url );
			}
		}

		return true;
	}

	/**
	 * Capability plus object authorization for the advertiser side.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $capability  Required capability.
	 * @return true|WP_Error
	 */
	private function authorize( int $campaign_id, string $capability ) {
		if ( ! current_user_can( $capability ) || ! current_user_can( 'edit_post', $campaign_id ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to change that campaign.', 'aggressive-ads' ), 403 );
		}

		return true;
	}

	/**
	 * Audit one decision, recording what was actually proposed.
	 *
	 * The field names go into the context and the values do not. A rejected
	 * destination URL is exactly the kind of thing worth not copying into a
	 * second table that outlives the proposal.
	 *
	 * @param string               $event       Audit event name.
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $edits       Change set.
	 * @param string               $message     Human-readable summary.
	 * @param string               $outcome     Audit outcome.
	 * @param array<string, mixed> $extra       Further context, such as a package change's prices.
	 * @return void
	 */
	private function log( string $event, int $campaign_id, array $edits, string $message, string $outcome = Audit_Event::OUTCOME_OK, array $extra = array() ): void {
		$this->audit->insert(
			new Audit_Event(
				event: $event,
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: $message,
				outcome: $outcome,
				context: array( 'fields' => array_keys( $edits ) ) + $extra,
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
	 */
	private function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
