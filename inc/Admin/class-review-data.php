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
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\REST\Creative_File_Controller;
use Aggressive\Ads\Security\Capabilities;
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
	 * @param Campaign_Repository     $campaigns  Campaign persistence.
	 * @param Creative_Repository     $creatives  Creative persistence.
	 * @param Placement_Repository    $placements Placement persistence.
	 * @param Org_Repository          $orgs       Organization lookups.
	 * @param Audit_Repository        $audit      Audit history.
	 * @param Campaign_Change_Manager $changes    Running-campaign change proposals.
	 * @param Line_Item_Repository    $line_items Campaign delivery strategies.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Placement_Repository $placements,
		private readonly Org_Repository $orgs,
		private readonly Audit_Repository $audit,
		private readonly Campaign_Change_Manager $changes,
		private readonly Line_Item_Repository $line_items
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
	 * Formats a UTC timestamp in the site's timezone, or returns an empty value.
	 *
	 * @param int  $timestamp UTC Unix timestamp.
	 * @param bool $with_time Whether to include the site's time format.
	 * @return string
	 */
	public static function format_timestamp( int $timestamp, bool $with_time = false ): string {
		if ( $timestamp <= 0 ) {
			return '';
		}

		$format = (string) get_option( 'date_format', 'M j, Y' );

		if ( $with_time ) {
			$format .= ' ' . (string) get_option( 'time_format', 'g:i a' );
		}

		$formatted = wp_date( $format, $timestamp );

		return is_string( $formatted ) ? $formatted : '';
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
	 * Submitted campaigns plus advertiser requests — the two things a reviewer
	 * is expected to clear. Creative replacements are deliberately excluded:
	 * they already surface on their own tab, and a badge that counts everything
	 * is a badge nobody can act on.
	 *
	 * @return int
	 */
	public function pending_decision_count(): int {
		$counts = $this->campaigns->count_by_status( array( Post_Statuses::SUBMITTED, Post_Statuses::REVIEW ) );
		$total  = 0;

		foreach ( $counts as $count ) {
			$total += (int) $count;
		}

		return $total + $this->campaigns->campaigns_with_pending_requests();
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
		$row['action_request']   = self::labelled_request( $this->campaigns->action_request( $campaign_id ) );
		$row['actions']          = $this->actions_for( $campaign_id, $row['status'] );
		$row['internal_notes']   = $this->campaigns->internal_notes( $campaign_id );
		$row['can_view_audit']   = current_user_can( Capabilities::VIEW_AUDIT_LOG );
		$row['audit']            = $row['can_view_audit'] ? $this->audit_rows( $campaign_id ) : array();
		$this->line_items->ensure_default( $campaign_id );
		$row['line_items'] = $this->line_items->for_campaign( $campaign_id );

		return $row;
	}

	/**
	 * One audit row's sentence, in the reader's words rather than the schema's.
	 *
	 * A transition stores its own message as `Campaign moved from aggr_submitted
	 * to aggr_review.`, which is the right thing to *store* — an audit row is a
	 * record, and freezing a translated string into it would make the log read
	 * in whichever locale happened to be active when it was written. The status
	 * slugs are also kept in their own columns for exactly this reason.
	 *
	 * So the sentence is composed here, at render time, from those columns. That
	 * localizes it properly and fixes every row already in the table rather than
	 * only the ones written from now on.
	 *
	 * Scoped to `campaign.transitioned` on purpose. A denial carries from/to as
	 * well, and its own message says something this one does not.
	 *
	 * @param array{event: string, from_state: string, to_state: string, message: string} $event Stored row.
	 * @return string
	 */
	private static function event_message( array $event ): string {
		if (
			'campaign.transitioned' !== $event['event']
			|| '' === $event['from_state']
			|| '' === $event['to_state']
		) {
			return $event['message'];
		}

		return sprintf(
			/* translators: 1: previous campaign status, already translated. 2: new campaign status, already translated. */
			__( 'Campaign moved from %1$s to %2$s.', 'aggressive-ads' ),
			self::status_label( $event['from_state'] ),
			self::status_label( $event['to_state'] )
		);
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

		$start = self::format_timestamp( $start_ts );

		if ( $end_ts <= 0 ) {
			return $start;
		}

		return sprintf(
			/* translators: 1: campaign start date. 2: campaign end date. */
			__( '%1$s – %2$s', 'aggressive-ads' ),
			$start,
			self::format_timestamp( $end_ts )
		);
	}

	/**
	 * The advertiser's request, carrying the label staff will read.
	 *
	 * The label is resolved here because `Campaign_Change_Manager` owns the
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

		$request['action_label'] = Campaign_Change_Manager::request_label( $request['action'] );

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
			'id'              => $campaign_id,
			'title'           => $this->campaigns->title( $campaign_id ),
			'status'          => $status,
			'status_text'     => self::status_label( $status ),
			'pill'            => View_Data::pill_for( $status ),
			'org_id'          => $this->campaigns->org_id( $campaign_id ),
			'org_name'        => $this->orgs->name( $this->campaigns->org_id( $campaign_id ) ),

			/*
			 * The portal, not a wp-admin screen. Editing on a client's behalf
			 * uses the advertiser's own wizard, so staff see the campaign the
			 * way the client does and there is only one editor to keep correct.
			 */
			'edit_url'        => Routes::url( 'campaigns', $campaign_id ),
			'placements'      => $names,
			'submitted_at'    => $this->campaigns->submitted_at( $campaign_id ),

			/*
			 * Formatted here rather than in the client. wp_date() resolves the
			 * site's timezone and the reader's locale, and neither is knowable
			 * in a browser — a date built from the raw stamp in JavaScript is
			 * the visitor's timezone, silently, and off by hours for anyone
			 * whose is not the site's.
			 */
			'submitted_text'  => self::format_timestamp( $this->campaigns->submitted_at( $campaign_id ), true ),
			'schedule_text'   => self::schedule_text(
				$this->campaigns->start_ts( $campaign_id ),
				$this->campaigns->end_ts( $campaign_id )
			),
			'modified_at'     => $this->campaigns->modified_ts( $campaign_id ),
			'reviewer_id'     => $reviewer_id,
			'reviewer'        => self::user_name( $reviewer_id ),
			'revision'        => $this->campaigns->revision( $campaign_id ),
			'review_notes'    => $this->campaigns->review_notes( $campaign_id ),
			'start_ts'        => $this->campaigns->start_ts( $campaign_id ),
			'end_ts'          => $this->campaigns->end_ts( $campaign_id ),
			'pending_updates' => $this->campaigns->pending_update_count( $campaign_id ),
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

		foreach ( $this->creatives->replacements_for_campaign( $campaign_id, array( Creative_Repository::CHANGE_PENDING ) ) as $creative ) {
			$current_id = $this->creatives->replacement_target_id( $creative['id'] );
			$current    = $this->creatives->details( $current_id );

			if ( null === $current ) {
				continue;
			}

			$rows[] = array(
				'id'           => $creative['id'],
				'current_id'   => $current_id,
				'placement'    => $this->placements->name( $creative['placement_id'] ),
				'size'         => $creative['size'],
				'dimensions'   => $creative['width'] . '×' . $creative['height'],
				'click_url'    => $creative['click_url'],
				'alt_text'     => $creative['alt_text'],
				'current_url'  => $current['click_url'],
				'current_alt'  => $current['alt_text'],
				'requested_at' => $this->creatives->requested_at( $creative['id'] ),
				'preview'      => add_query_arg(
					'_wpnonce',
					wp_create_nonce( 'wp_rest' ),
					rest_url( Creative_File_Controller::NAMESPACE . '/creatives/' . $creative['id'] . '/file' )
				),
			);
		}

		return $rows;
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

		foreach ( $this->creatives->for_campaign( $campaign_id ) as $creative ) {
			$rows[] = array(
				'id'         => $creative['id'],
				'placement'  => $this->placements->name( $creative['placement_id'] ),
				'size'       => $creative['size'],
				'dimensions' => $creative['width'] > 0 && $creative['height'] > 0
					? $creative['width'] . '×' . $creative['height']
					: '',
				'click_url'  => $creative['click_url'],
				'alt_text'   => $creative['alt_text'],
				'preview'    => add_query_arg(
					'_wpnonce',
					wp_create_nonce( 'wp_rest' ),
					rest_url( Creative_File_Controller::NAMESPACE . '/creatives/' . $creative['id'] . '/file' )
				),
			);
		}

		return $rows;
	}

	/**
	 * Recent history for the staff timeline.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, array<string, mixed>>
	 */
	private function audit_rows( int $campaign_id ): array {
		$rows = array();

		foreach ( $this->audit->for_object( 'campaign', $campaign_id, $this->campaigns->org_id( $campaign_id ) ) as $event ) {
			$rows[] = array(
				'id'           => $event['id'],
				'created_at'   => $event['created_at_ts'],
				'created_text' => self::format_timestamp( $event['created_at_ts'], true ),
				'actor'        => 0 === $event['actor_user_id'] ? __( 'System', 'aggressive-ads' ) : self::user_name( $event['actor_user_id'] ),
				'event'        => $event['event'],
				'outcome'      => $event['outcome'],
				'message'      => self::event_message( $event ),
			);
		}

		return $rows;
	}

	/**
	 * A user's display name, or a dash.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private static function user_name( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$user = get_userdata( $user_id );

		return false === $user ? '' : (string) $user->display_name;
	}

	/**
	 * The status's human label, from the registered status itself.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	private static function status_label( string $status ): string {
		$object = get_post_status_object( $status );

		return null === $object ? $status : (string) $object->label;
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
			default                  => self::status_label( $to ),
		};
	}
}
