<?php
/**
 * What the review queue renders.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Domain\Transition_Table;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Campaign_Request_Repository;
use Aggressive\Ads\Repository\Creative_Attachment_Repository;
use Aggressive\Ads\Repository\Creative_Decision_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Creative_Revision_Repository;
use Aggressive\Ads\Workflow\Assigned_Creatives;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\REST\Api;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Campaign_Action_Requests;
use Aggressive\Ads\Workflow\Campaign_Change_Manager;

/**
 * Assembles the staff review screens, so templates render and nothing else.
 *
 * The advertiser's Portal\View_Data scopes everything to one organization. This
 * one deliberately does not: a reviewer works across every advertiser, and the
 * authorization for that is a capability checked before any of this runs. The
 * two are kept apart rather than parameterised by an `$org_id` that could be
 * left at zero — a scoping bug that reads as a missing argument is a scoping bug
 * nobody sees in review.
 */
final class Review_Data {

	/**
	 * The queue's filters, in the order they are shown.
	 *
	 * Keyed by the query-string value, so a bookmarked tab keeps working.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const FILTERS = array(
		'pending'  => array( Post_Statuses::SUBMITTED, Post_Statuses::REVIEW ),
		'updates'  => array(
			Post_Statuses::DRAFT,
			Post_Statuses::SUBMITTED,
			Post_Statuses::REVIEW,
			Post_Statuses::CHANGES,
			Post_Statuses::REJECTED,
			Post_Statuses::APPROVED,
			Post_Statuses::SCHEDULED,
			Post_Statuses::LIVE,
			Post_Statuses::PAUSED,
			Post_Statuses::COMPLETE,
			Post_Statuses::CANCELLED,
		),
		'requests' => array(
			Post_Statuses::SCHEDULED,
			Post_Statuses::LIVE,
			Post_Statuses::PAUSED,
		),
		'changes'  => array( Post_Statuses::CHANGES ),
		'decided'  => array( Post_Statuses::APPROVED, Post_Statuses::REJECTED ),
		'running'  => array( Post_Statuses::SCHEDULED, Post_Statuses::LIVE, Post_Statuses::PAUSED ),
		'finished' => array( Post_Statuses::COMPLETE, Post_Statuses::CANCELLED ),
	);

	/**
	 * The filter shown when none is asked for.
	 */
	public const DEFAULT_FILTER = 'pending';

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository                        $campaigns  Campaign persistence.
	 * @param Creative_Repository                        $creatives  Creative persistence.
	 * @param Creative_Attachment_Repository             $attachments Media Library copy of the artwork.
	 * @param Creative_Revision_Repository               $revisions Revision chain persistence.
	 * @param Assigned_Creatives                         $assigned   What is assigned where.
	 * @param Placement_Repository                       $placements Placement persistence.
	 * @param Org_Repository                             $orgs       Organization lookups.
	 * @param Audit_Trail                                $trail      The campaign's audit log, as the timeline reads it.
	 * @param Campaign_Change_Manager                    $changes    Running-campaign change proposals.
	 * @param Line_Item_Repository                       $line_items Campaign delivery strategies.
	 * @param \Aggressive\Ads\Workflow\Creative_Approval $approvals  Creatives awaiting publication.
	 * @param Pending_Work                               $pending    Waiting-work count, shared with the menu.
	 * @param Campaign_Request_Repository                $requests   Advertiser requests and proposed changes.
	 * @param Creative_Decision_Repository               $decisions  What a reviewer decided about each revision.
	 * @param Approval_Readiness                         $readiness  What blocks approval, from the approval guard's own check.
	 * @param \Aggressive\Ads\Workflow\Edit_Window       $window     Whether the campaign's status still allows edits.
	 * @param \Aggressive\Ads\Workflow\Creative_Promoter $promoter   Whether a creative has artwork to publish.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Creative_Attachment_Repository $attachments,
		private readonly Creative_Revision_Repository $revisions,
		private readonly Assigned_Creatives $assigned,
		private readonly Placement_Repository $placements,
		private readonly Org_Repository $orgs,
		private readonly Audit_Trail $trail,
		private readonly Campaign_Change_Manager $changes,
		private readonly Line_Item_Repository $line_items,
		private readonly \Aggressive\Ads\Workflow\Creative_Approval $approvals,
		private readonly Pending_Work $pending,
		private readonly Campaign_Request_Repository $requests,
		private readonly Creative_Decision_Repository $decisions,
		private readonly Approval_Readiness $readiness,
		private readonly \Aggressive\Ads\Workflow\Edit_Window $window,
		private readonly \Aggressive\Ads\Workflow\Creative_Promoter $promoter
	) {
	}

	/**
	 * The statuses a filter covers, falling back to the default.
	 *
	 * @param string $filter Filter key.
	 * @return array<int, string>
	 */
	public static function statuses_for( string $filter ): array {
		return self::FILTERS[ $filter ] ?? self::FILTERS[ self::DEFAULT_FILTER ];
	}

	/**
	 * Whether a filter key is one we offer.
	 *
	 * @param string $filter Filter key.
	 * @return bool
	 */
	public static function is_filter( string $filter ): bool {
		return array_key_exists( $filter, self::FILTERS );
	}

	/**
	 * The tabs across the top of the queue, each with its count.
	 *
	 * @return array<int, array{key: string, label: string, count: int}>
	 */
	public function tabs(): array {
		$counts = $this->campaigns->count_by_status( Post_Statuses::all() );
		$tabs   = array();

		foreach ( self::FILTERS as $key => $statuses ) {
			$total = 0;

			if ( 'updates' === $key ) {
				$total = $this->campaigns->campaigns_with_pending_updates();
			} elseif ( 'requests' === $key ) {
				$total = $this->campaigns->campaigns_with_pending_requests();
			} else {
				foreach ( $statuses as $status ) {
					$total += $counts[ $status ] ?? 0;
				}
			}

			$tabs[] = array(
				'key'   => $key,
				'label' => self::label_for( $key ),
				'count' => $total,
			);
		}

		return $tabs;
	}

	/**
	 * How many items are waiting for a staff decision.
	 *
	 * Delegated to `Pending_Work`, which the Advertising parent menu also uses.
	 * Two definitions of "waiting" would disagree the moment one of them
	 * learned about a new kind of work, and the disagreement would show as two
	 * different numbers on the same screen.
	 *
	 * @return int
	 */
	public function pending_decision_count(): int {
		return $this->pending->pending_decision_count();
	}

	/**
	 * One page of the queue.
	 *
	 * @param string $filter Filter key.
	 * @param int    $page   1-based page number.
	 * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int}
	 */
	public function queue( string $filter, int $page = 1 ): array {
		$result = $this->campaigns->for_review(
			self::statuses_for( $filter ),
			$page,
			'updates' === $filter,
			'requests' === $filter
		);
		$rows   = array();

		/*
		 * **One trip for the posts and their meta, rather than one per row.**
		 *
		 * The query asks for ids, which means WordPress primes no caches at
		 * all: every title, status, organization and placement below was then
		 * its own round trip. Measured at 2.2 queries per row — forty-five for
		 * a single page of twenty.
		 *
		 * Priming here rather than widening the query keeps the page size
		 * decision separate from the cost of rendering a row.
		 */
		if ( array() !== $result['ids'] ) {
			_prime_post_caches( $result['ids'], false, true );
		}

		foreach ( $result['ids'] as $campaign_id ) {
			$rows[] = $this->row( $campaign_id );
		}

		return array(
			'rows'  => $rows,
			'total' => $result['total'],
			'pages' => $result['pages'],
			'page'  => max( 1, $page ),
		);
	}

	/**
	 * Active advertisers, for creating a campaign on one's behalf.
	 *
	 * Only active organizations: an inactive one is refused by the editor, so
	 * offering it would be offering a choice that cannot succeed.
	 *
	 * @return array<int, array{id: int, name: string}>
	 */
	public function advertisers(): array {
		$rows = array();

		foreach ( $this->orgs->all_ids() as $org_id ) {
			if ( ! $this->orgs->is_active( $org_id ) ) {
				continue;
			}

			$rows[] = array(
				'id'   => $org_id,
				'name' => $this->orgs->name( $org_id ),
			);
		}

		return $rows;
	}

	/**
	 * One campaign in full, for the review screen.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<string, mixed>|null Null when the id is not a campaign.
	 */
	public function campaign( int $campaign_id ): ?array {
		if ( $campaign_id <= 0 || ! $this->campaigns->exists( $campaign_id ) ) {
			return null;
		}

		$row = $this->row( $campaign_id );

		$row['creatives']        = $this->creative_rows( $campaign_id );
		$row['creative_updates'] = $this->replacement_rows( $campaign_id );
		$row['pending_edits']    = $this->changes->pending_summary( $campaign_id );
		$facts                   = $this->changes->pending_review_facts( $campaign_id );
		$row['pending_sizes']    = $facts['structural'];
		$row['pending_price']    = $facts['price'];
		$row['action_request']   = self::labelled_request( $this->requests->action_request( $campaign_id ) );
		$row['actions']          = $this->actions_for( $campaign_id, $row['status'] );
		$row['internal_notes']   = $this->campaigns->internal_notes( $campaign_id );
		$row['can_view_audit']   = current_user_can( Capabilities::VIEW_AUDIT_LOG );
		$row['audit']            = $row['can_view_audit'] ? $this->trail->for_campaign( $campaign_id, $this->campaigns->org_id( $campaign_id ) ) : array();

		// Only while a decision is waiting, and only then is it worth the
		// validator's queries.
		$row['readiness'] = Approval_Readiness::applies_to( $row['status'] ) ? $this->readiness->for_campaign( $campaign_id ) : null;
		$this->line_items->ensure_default( $campaign_id );
		$row['line_items'] = array_map( array( Line_Item_Labels::class, 'labelled' ), $this->line_items->for_campaign( $campaign_id ) );

		/*
		 * Whether a delivery-policy save would be accepted, from the same check
		 * the line-item route runs (`Edit_Window::allows()`), so the screen
		 * never offers an edit the server would refuse. Staff may edit in every
		 * status today (`Post_Statuses::staff_editable()`), so this is true on
		 * this screen; it is asked rather than assumed so that narrowing the
		 * staff window — or organization-scoped roles — needs no change here.
		 */
		$row['delivery_editable'] = $this->window->allows( $campaign_id );

		return $row;
	}

	/**
	 * A campaign's run window as one readable phrase.
	 *
	 * @param int $start_ts Start timestamp.
	 * @param int $end_ts   End timestamp.
	 * @return string
	 */
	private static function schedule_text( int $start_ts, int $end_ts ): string {
		if ( $start_ts <= 0 ) {
			return __( 'Not scheduled', 'aggressive-ads' );
		}

		$start = Review_Format::date( $start_ts );

		if ( $end_ts <= 0 ) {
			return $start;
		}

		return sprintf(
			/* translators: 1: campaign start date. 2: campaign end date. */
			__( '%1$s – %2$s', 'aggressive-ads' ),
			$start,
			Review_Format::date( $end_ts )
		);
	}

	/**
	 * The advertiser's request, carrying the label staff will read.
	 *
	 * The label is resolved here because `Campaign_Action_Requests` owns the
	 * wording and it is translated; a client that mapped the status slug to a
	 * word itself would be a second vocabulary to keep in step.
	 *
	 * @param array{action: string, reason: string, at: int, by: int}|array{} $request Stored request.
	 * @return array<string, mixed>
	 */
	private static function labelled_request( array $request ): array {
		if ( array() === $request ) {
			return array();
		}

		$request['action_label'] = Campaign_Action_Requests::request_label( $request['action'] );

		return $request;
	}

	/**
	 * The transitions a reviewer may drive from a status, and may perform.
	 *
	 * Read from Transition_Table rather than listed here. A second list of
	 * legal edges is a second lifecycle, and the two disagree within a release.
	 * The capability check is per-edge because approval needs two capabilities
	 * and a reviewer may hold only one of them.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $status      Current status.
	 * @return array<int, array{to: string, label: string, needs_notes: bool, destructive: bool, positive: bool}>
	 */
	public function actions_for( int $campaign_id, string $status ): array {
		$actions = array();

		foreach ( Transition_Table::available_to( $status, Transition_Table::ACTOR_STAFF ) as $transition ) {
			foreach ( $transition->capabilities as $capability ) {
				if ( ! current_user_can( $capability, $campaign_id ) ) {
					continue 2;
				}
			}

			$actions[] = array(
				'to'          => $transition->to,
				'label'       => self::action_label( $transition->to ),
				'needs_notes' => $transition->has_guard( Transition_Table::GUARD_REVIEW_NOTES ),
				'destructive' => in_array( $transition->to, array( Post_Statuses::REJECTED, Post_Statuses::CANCELLED ), true ),

				/*
				 * Approval is the one edge that puts a campaign in front of the
				 * public, so it is the one the screen colours as an assertion
				 * rather than as a step. Decided here rather than in the client
				 * for the same reason the label is: the status vocabulary lives
				 * on this side, and a second copy of it drifts.
				 */
				'positive'    => Post_Statuses::APPROVED === $transition->to,
			);
		}

		return $actions;
	}

	/**
	 * One campaign, shaped for a queue row.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<string, mixed>
	 */
	private function row( int $campaign_id ): array {
		$status      = $this->campaigns->status( $campaign_id );
		$reviewer_id = $this->campaigns->reviewed_by( $campaign_id );
		$names       = array();

		foreach ( $this->campaigns->placement_ids( $campaign_id ) as $placement_id ) {
			$name = $this->placements->name( $placement_id );

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return array(
			'id'               => $campaign_id,
			'title'            => $this->campaigns->title( $campaign_id ),
			'status'           => $status,
			'status_text'      => Review_Format::status( $status ),
			'pill'             => View_Data::pill_for( $status ),
			'org_id'           => $this->campaigns->org_id( $campaign_id ),
			'org_name'         => $this->orgs->name( $this->campaigns->org_id( $campaign_id ) ),

			/*
			 * The portal, not a wp-admin screen. Editing on a client's behalf
			 * uses the advertiser's own wizard, so staff see the campaign the
			 * way the client does and there is only one editor to keep correct.
			 */
			'edit_url'         => Routes::url( 'campaigns', $campaign_id ),
			'placements'       => $names,

			/*
			 * The advertiser writes these under the label "Notes for the
			 * review team", and until this line they reached no reviewer:
			 * not in this payload, not on the review screen. The only way to
			 * read one was to open the campaign in the portal editor.
			 */
			'advertiser_notes' => $this->campaigns->advertiser_notes( $campaign_id ),
			'submitted_at'     => $this->campaigns->submitted_at( $campaign_id ),

			/*
			 * Formatted here rather than in the client. wp_date() resolves the
			 * site's timezone and the reader's locale, and neither is knowable
			 * in a browser — a date built from the raw stamp in JavaScript is
			 * the visitor's timezone, silently, and off by hours for anyone
			 * whose is not the site's.
			 */
			'submitted_text'   => Review_Format::date( $this->campaigns->submitted_at( $campaign_id ), true ),
			'schedule_text'    => self::schedule_text(
				$this->campaigns->start_ts( $campaign_id ),
				$this->campaigns->end_ts( $campaign_id )
			),
			'modified_at'      => $this->campaigns->modified_ts( $campaign_id ),
			'reviewer_id'      => $reviewer_id,
			'reviewer'         => Review_Format::user( $reviewer_id ),
			'revision'         => $this->campaigns->revision( $campaign_id ),
			'review_notes'     => $this->campaigns->review_notes( $campaign_id ),
			'start_ts'         => $this->campaigns->start_ts( $campaign_id ),
			'end_ts'           => $this->campaigns->end_ts( $campaign_id ),
			'pending_updates'  => $this->campaigns->pending_update_count( $campaign_id ),
		);
	}

	/**
	 * Pending creative revisions awaiting a staff decision.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<int, array<string, mixed>>
	 */
	private function replacement_rows( int $campaign_id ): array {
		$rows = array();

		foreach ( $this->revisions->replacements_for_campaign( $campaign_id, array( Creative_Repository::CHANGE_PENDING ) ) as $creative ) {
			$current_id = $this->creatives->replacement_target_id( $creative['id'] );
			$current    = $this->creatives->details( $current_id );

			if ( null === $current ) {
				continue;
			}

			$rows[] = array(
				'id'            => $creative['id'],
				'current_id'    => $current_id,
				'placement'     => $this->placements->name( $creative['placement_id'] ),
				'size'          => $creative['size'],
				'dimensions'    => $creative['width'] . '×' . $creative['height'],
				'click_url'     => $creative['click_url'],
				'alt_text'      => $creative['alt_text'],
				'current_url'   => $current['click_url'],
				'current_alt'   => $current['alt_text'],
				'requested_at'  => $this->revisions->requested_at( $creative['id'] ),

				/*
				 * Derived from the two checksums, never from the request that
				 * created the revision. A reviewer seeing "artwork unchanged"
				 * is being told something the server verified, which is the
				 * whole basis for approving it at a glance.
				 */
				'text_only'     => $this->revisions->is_text_only_revision( (int) $creative['id'] ),
				'preview'       => $this->creative_preview( (int) $creative['id'] ),
				'preview_frame' => $this->creative_preview_frame( (int) $creative['id'] ),
				'file_missing'  => ! $this->promoter->has_artwork( (int) $creative['id'] ),
			);
		}

		return $rows;
	}

	/**
	 * Where to load a creative's image from.
	 *
	 * Approval promotes the artwork into the Media Library and deletes the
	 * private original, so the authenticated file route answers 404 for
	 * everything approved — correctly, because there is nothing left to stream.
	 * Pointing at it regardless meant every promoted creative on the review
	 * screen rendered as a broken image, and a reviewer opening an approved
	 * campaign saw a wall of them.
	 *
	 * This is the same fault `Portal\View_Data::creative_preview()` was fixed
	 * for, on the screen nobody checked afterwards. The two now answer the same
	 * way; `ReviewPreviewTest` asserts it, so a third screen cannot quietly
	 * reintroduce it.
	 *
	 * The attachment URL is public, which is not a leak: it is the same file
	 * the ad already serves to every visitor. Unpromoted artwork has no
	 * attachment and keeps the authenticated route.
	 *
	 * @param int $creative_id Creative post id.
	 * @return string
	 */
	private function creative_preview( int $creative_id ): string {
		$promoted = $this->attachments->attachment_url( $creative_id );

		if ( '' !== $promoted ) {
			return $promoted;
		}

		return add_query_arg(
			'_wpnonce',
			wp_create_nonce( 'wp_rest' ),
			rest_url( Api::NAMESPACE . '/creatives/' . $creative_id . '/file' )
		);
	}

	/**
	 * What the device-preview frame loads.
	 *
	 * **A document, never the bytes.** Pointed at the artwork directly, a
	 * browser builds a viewer document around it with a script of its own,
	 * which the policy on those bytes correctly refuses — once per frame, in a
	 * console the next person to open has to read past. The preview route
	 * answers with one `img` under a policy that expects it, and picks the
	 * promoted or private source itself, so this does not branch on approval.
	 *
	 * @param int $creative_id Creative post id.
	 * @return string
	 */
	private function creative_preview_frame( int $creative_id ): string {
		return add_query_arg(
			'_wpnonce',
			wp_create_nonce( 'wp_rest' ),
			rest_url( Api::NAMESPACE . '/creatives/' . $creative_id . '/preview' )
		);
	}

	/**
	 * The campaign's creatives, as a reviewer needs to see them.
	 *
	 * The private path and the checksum stay out: a reviewer judges the
	 * artwork, not the filesystem, and the path is not something a browser
	 * needs to be told.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, array<string, mixed>>
	 */
	private function creative_rows( int $campaign_id ): array {
		$rows = array();

		/*
		 * Which creatives still need a decision.
		 *
		 * Read once for the campaign rather than per row: the answer depends on
		 * the campaign's status, which does not change while this loop runs.
		 */
		$awaiting = $this->approvals->awaiting( $campaign_id );

		/*
		 * Structure from the assignment table, values from the revision. See
		 * `Assigned_Creatives` for why the denormalized columns are not read on
		 * a screen where the underlying creative is still editable.
		 */
		foreach ( $this->assigned->revision_ids( $campaign_id ) as $revision_id ) {
			$creative = $this->creatives->details( $revision_id );

			if ( null === $creative || ! $this->creatives->is_active( $revision_id ) ) {
				continue;
			}

			$rows[] = array(
				'id'            => $creative['id'],
				'placement'     => $this->placements->name( $creative['placement_id'] ),
				'size'          => $creative['size'],
				'dimensions'    => $creative['width'] > 0 && $creative['height'] > 0
					? $creative['width'] . '×' . $creative['height']
					: '',
				'click_url'     => $creative['click_url'],
				'alt_text'      => $creative['alt_text'],

				/*
				 * Whether this creative is still waiting to be published.
				 * Having an attachment is what "published" means — promotion
				 * creates one, and `_aggr_review_state` is maintained only on
				 * the replacement path, so it reads `pending` for creatives
				 * that have been serving for weeks.
				 */
				'awaiting'      => in_array( (int) $creative['id'], $awaiting, true ),
				'preview'       => $this->creative_preview( (int) $creative['id'] ),
				'preview_frame' => $this->creative_preview_frame( (int) $creative['id'] ),

				/*
				 * The file is gone, so there is nothing to preview and nothing
				 * approval could publish. The card says so instead of framing
				 * the preview route's refusal.
				 */
				'file_missing'  => ! $this->promoter->has_artwork( (int) $creative['id'] ),
				'decisions'     => $this->decision_rows( (int) $creative['id'] ),
			);
		}

		return $rows;
	}

	/**
	 * What reviewers decided about this revision, newest first.
	 *
	 * The advertiser's card shows the same decisions without the actor. Staff
	 * are the people who need to see who refused an ad; the portal payload
	 * deliberately does not.
	 *
	 * @param int $creative_id Creative id.
	 * @return array<int, array{decision: string, reason: string, at: int, at_text: string, actor: string}>
	 */
	private function decision_rows( int $creative_id ): array {
		$rows = array();

		foreach ( array_reverse( $this->decisions->for_revision( $creative_id ) ) as $row ) {
			$at = (int) $row['decided_at_ts'];

			$rows[] = array(
				'decision' => (string) $row['decision'],
				'reason'   => (string) $row['reason'],
				'at'       => $at,
				'at_text'  => Review_Format::date( $at, true ),
				'actor'    => Review_Format::user( (int) $row['actor_user_id'] ),
			);
		}

		return $rows;
	}

	/**
	 * A filter's tab label.
	 *
	 * @param string $filter Filter key.
	 * @return string
	 */
	private static function label_for( string $filter ): string {
		return match ( $filter ) {
			'pending'  => __( 'Needs review', 'aggressive-ads' ),
			'updates'  => __( 'Ad updates', 'aggressive-ads' ),
			'requests' => __( 'Advertiser requests', 'aggressive-ads' ),
			'changes'  => __( 'With the advertiser', 'aggressive-ads' ),
			'decided'  => __( 'Decided', 'aggressive-ads' ),
			'running'  => __( 'Running', 'aggressive-ads' ),
			default    => __( 'Finished', 'aggressive-ads' ),
		};
	}

	/**
	 * What the button that performs a transition says.
	 *
	 * The verb, not the destination status: "Approve and publish" tells a
	 * reviewer what is about to happen to somebody's money. "Set to Approved"
	 * does not.
	 *
	 * @param string $to Target status.
	 * @return string
	 */
	private static function action_label( string $to ): string {
		return match ( $to ) {
			Post_Statuses::REVIEW    => __( 'Start review', 'aggressive-ads' ),
			Post_Statuses::SUBMITTED => __( 'Release back to the queue', 'aggressive-ads' ),
			Post_Statuses::CHANGES   => __( 'Request changes', 'aggressive-ads' ),
			Post_Statuses::REJECTED  => __( 'Reject', 'aggressive-ads' ),
			Post_Statuses::APPROVED  => __( 'Approve and publish', 'aggressive-ads' ),
			Post_Statuses::DRAFT     => __( 'Reopen as a draft', 'aggressive-ads' ),
			Post_Statuses::PAUSED    => __( 'Pause campaign', 'aggressive-ads' ),
			Post_Statuses::LIVE      => __( 'Resume campaign', 'aggressive-ads' ),
			Post_Statuses::CANCELLED => __( 'Cancel campaign', 'aggressive-ads' ),
			default                  => Review_Format::status( $to ),
		};
	}
}
