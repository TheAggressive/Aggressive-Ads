<?php
/**
 * What the review queue renders.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Admin;

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Domain\Transition_Table;
use LAAO_Advertiser_Portal\Portal\View_Data;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Repository\Creative_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;
use LAAO_Advertiser_Portal\REST\Creative_File_Controller;
use LAAO_Advertiser_Portal\Security\Capabilities;

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
	 * @param Campaign_Repository  $campaigns  Campaign persistence.
	 * @param Creative_Repository  $creatives  Creative persistence.
	 * @param Placement_Repository $placements Placement persistence.
	 * @param Org_Repository       $orgs       Organization lookups.
	 * @param Audit_Repository     $audit      Audit history.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Placement_Repository $placements,
		private readonly Org_Repository $orgs,
		private readonly Audit_Repository $audit
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

			foreach ( $statuses as $status ) {
				$total += $counts[ $status ] ?? 0;
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
	 * One page of the queue.
	 *
	 * @param string $filter Filter key.
	 * @param int    $page   1-based page number.
	 * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int}
	 */
	public function queue( string $filter, int $page = 1 ): array {
		$result = $this->campaigns->for_review( self::statuses_for( $filter ), $page );
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

		$row['creatives']      = $this->creative_rows( $campaign_id );
		$row['actions']        = $this->actions_for( $campaign_id, $row['status'] );
		$row['internal_notes'] = $this->campaigns->internal_notes( $campaign_id );
		$row['can_view_audit'] = current_user_can( Capabilities::VIEW_AUDIT_LOG );
		$row['audit']          = $row['can_view_audit'] ? $this->audit_rows( $campaign_id ) : array();

		return $row;
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
	 * @return array<int, array{to: string, label: string, needs_notes: bool, destructive: bool}>
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
			'id'           => $campaign_id,
			'title'        => $this->campaigns->title( $campaign_id ),
			'status'       => $status,
			'status_text'  => self::status_label( $status ),
			'pill'         => View_Data::pill_for( $status ),
			'org_id'       => $this->campaigns->org_id( $campaign_id ),
			'org_name'     => $this->orgs->name( $this->campaigns->org_id( $campaign_id ) ),
			'placements'   => $names,
			'submitted_at' => $this->campaigns->submitted_at( $campaign_id ),
			'modified_at'  => $this->campaigns->modified_ts( $campaign_id ),
			'reviewer_id'  => $reviewer_id,
			'reviewer'     => self::user_name( $reviewer_id ),
			'revision'     => $this->campaigns->revision( $campaign_id ),
			'review_notes' => $this->campaigns->review_notes( $campaign_id ),
			'start_ts'     => $this->campaigns->start_ts( $campaign_id ),
			'end_ts'       => $this->campaigns->end_ts( $campaign_id ),
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
				'id'         => $event['id'],
				'created_at' => $event['created_at_ts'],
				'actor'      => 0 === $event['actor_user_id'] ? __( 'System', 'laao-advertiser-portal' ) : self::user_name( $event['actor_user_id'] ),
				'event'      => $event['event'],
				'outcome'    => $event['outcome'],
				'message'    => $event['message'],
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
			'pending'  => __( 'Needs review', 'laao-advertiser-portal' ),
			'changes'  => __( 'With the advertiser', 'laao-advertiser-portal' ),
			'decided'  => __( 'Decided', 'laao-advertiser-portal' ),
			'running'  => __( 'Running', 'laao-advertiser-portal' ),
			default    => __( 'Finished', 'laao-advertiser-portal' ),
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
			Post_Statuses::REVIEW    => __( 'Start review', 'laao-advertiser-portal' ),
			Post_Statuses::SUBMITTED => __( 'Release back to the queue', 'laao-advertiser-portal' ),
			Post_Statuses::CHANGES   => __( 'Request changes', 'laao-advertiser-portal' ),
			Post_Statuses::REJECTED  => __( 'Reject', 'laao-advertiser-portal' ),
			Post_Statuses::APPROVED  => __( 'Approve and publish', 'laao-advertiser-portal' ),
			Post_Statuses::DRAFT     => __( 'Reopen as a draft', 'laao-advertiser-portal' ),
			Post_Statuses::PAUSED    => __( 'Pause campaign', 'laao-advertiser-portal' ),
			Post_Statuses::LIVE      => __( 'Resume campaign', 'laao-advertiser-portal' ),
			Post_Statuses::CANCELLED => __( 'Cancel campaign', 'laao-advertiser-portal' ),
			default                  => self::status_label( $to ),
		};
	}
}
