<?php
/**
 * Campaign persistence.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;

/**
 * Every read and write of a campaign.
 *
 * The status setter is deliberately blunt and deliberately not public API:
 * only Campaign_State_Machine::apply() should reach it, and a listener writes
 * an audit row when a campaign's status changes without it. See
 * docs/campaign-workflow.md.
 */
final class Campaign_Repository {

	public const META_ORG_ID               = '_aggr_org_id';
	public const META_START_TS             = '_aggr_start_ts';
	public const META_END_TS               = '_aggr_end_ts';
	public const META_SUBMITTED_AT         = '_aggr_submitted_at';
	public const META_REVIEWED_BY          = '_aggr_reviewed_by';
	public const META_REVIEWED_AT          = '_aggr_reviewed_at';
	public const META_REVIEW_NOTES         = '_aggr_review_notes';
	public const META_INTERNAL_NOTES       = '_aggr_internal_notes';
	public const META_NOTIFICATION_RECEIPT = '_aggr_notification_receipt';
	public const META_REVISION             = '_aggr_revision';
	public const META_ADSANITY_ID          = '_aggr_adsanity_ad_id';
	public const META_PLACEMENT_ID         = '_aggr_placement_id';
	public const META_AUTOSAVE_REV         = '_aggr_autosave_rev';
	public const META_WIZARD_STEP          = '_aggr_wizard_step';
	public const META_ADVERTISER_NOTES     = '_aggr_advertiser_notes';
	public const META_PACKAGE_ID           = '_aggr_package_id';
	public const META_BUDGET_CENTS         = '_aggr_budget_cents';
	public const META_CURRENCY             = '_aggr_currency';
	public const META_PENDING_UPDATES      = '_aggr_pending_creative_updates';

	/**
	 * Transition locks held by this PHP request.
	 *
	 * MySQL advisory locks are reentrant on one connection, so request-local
	 * ownership prevents a nested transition from acquiring the same lock a
	 * second time.
	 *
	 * @var array<string, true>
	 */
	private static array $transition_locks = array();

	/**
	 * Creates an organization-scoped draft.
	 *
	 * Initial status assignment is creation, not a lifecycle transition. Every
	 * later status change still belongs exclusively to Campaign_State_Machine.
	 *
	 * @param int    $org_id  Owning organization, derived server-side.
	 * @param int    $user_id Authoring user.
	 * @param string $title   Campaign title.
	 * @return int|\WP_Error
	 */
	public function create_draft( int $org_id, int $user_id, string $title ) {
		$campaign_id = wp_insert_post(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
				'post_author' => $user_id,
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $campaign_id ) ) {
			return $campaign_id;
		}

		$campaign_id = (int) $campaign_id;

		update_post_meta( $campaign_id, self::META_ORG_ID, $org_id );
		update_post_meta( $campaign_id, self::META_AUTOSAVE_REV, 0 );
		update_post_meta( $campaign_id, self::META_WIZARD_STEP, 'details' );

		return $campaign_id;
	}

	/**
	 * Hard-deletes a campaign. Compensating rollback for a failed copy only.
	 *
	 * @param int $campaign_id Campaign post id.
	 */
	public function delete( int $campaign_id ): bool {
		if ( ! $this->exists( $campaign_id ) ) {
			return false;
		}

		return null !== wp_delete_post( $campaign_id, true );
	}

	/**
	 * Updates the campaign fields exposed to an advertiser.
	 *
	 * The workflow validates and authorizes before calling this method. Keeping
	 * persistence here means no controller or template writes post meta.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $fields      Allowlisted, validated values.
	 * @return true|\WP_Error
	 */
	public function update_draft( int $campaign_id, array $fields ) {
		return ( new Campaign_Draft_Persistence( $this ) )->update( $campaign_id, $fields );
	}

	/**
	 * Current optimistic-concurrency token.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function autosave_revision( int $campaign_id ): int {
		return (int) get_post_meta( $campaign_id, self::META_AUTOSAVE_REV, true );
	}

	/**
	 * Atomically claims the next optimistic-concurrency token.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $expected    Client's last-seen revision.
	 * @return int|false New revision, or false when another write won.
	 */
	public function claim_autosave_revision( int $campaign_id, int $expected ): int|false {
		$next = $expected + 1;

		if ( '' === get_post_meta( $campaign_id, self::META_AUTOSAVE_REV, true ) ) {
			return add_post_meta( $campaign_id, self::META_AUTOSAVE_REV, $next, true ) ? $next : false;
		}

		$updated = update_post_meta( $campaign_id, self::META_AUTOSAVE_REV, $next, $expected );

		return false === $updated ? false : $next;
	}

	/**
	 * Atomically claims the campaign lifecycle for one request.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string Empty when another request owns the lock.
	 */
	public function claim_transition_lock( int $campaign_id ): string {
		global $wpdb;

		$lock_name = 'aggr_transition_' . get_current_blog_id() . '_' . $campaign_id;

		if ( isset( self::$transition_locks[ $lock_name ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The advisory lock is the atomic cross-request serialization primitive.
		$acquired = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The advisory lock is the atomic cross-request serialization primitive.
		);

		if ( 1 !== $acquired ) {
			return '';
		}

		self::$transition_locks[ $lock_name ] = true;

		return $lock_name;
	}

	/**
	 * Releases a lifecycle lock only for its owner.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $token       Claim token.
	 * @return void
	 */
	public function release_transition_lock( int $campaign_id, string $token ): void {
		global $wpdb;

		$expected = 'aggr_transition_' . get_current_blog_id() . '_' . $campaign_id;

		if ( $expected !== $token || ! isset( self::$transition_locks[ $token ] ) ) {
			return;
		}

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases only the exact advisory lock this request acquired.
			$wpdb->get_var(
				$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $token ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Releases only the exact advisory lock this request acquired.
			);
		} finally {
			unset( self::$transition_locks[ $token ] );
		}
	}

	/**
	 * The advertiser's private notes for staff.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public function advertiser_notes( int $campaign_id ): string {
		return (string) get_post_meta( $campaign_id, self::META_ADVERTISER_NOTES, true );
	}

	/**
	 * The saved wizard resume point.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public function wizard_step( int $campaign_id ): string {
		return (string) get_post_meta( $campaign_id, self::META_WIZARD_STEP, true );
	}

	/**
	 * Selected package id, or zero.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function package_id( int $campaign_id ): int {
		return (int) get_post_meta( $campaign_id, self::META_PACKAGE_ID, true );
	}

	/**
	 * Snapshotted package price in integer cents.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function budget_cents( int $campaign_id ): int {
		return (int) get_post_meta( $campaign_id, self::META_BUDGET_CENTS, true );
	}

	/**
	 * Snapshotted package currency.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public function currency( int $campaign_id ): string {
		return (string) get_post_meta( $campaign_id, self::META_CURRENCY, true );
	}

	/**
	 * Whether a post exists and is a campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return bool
	 */
	public function exists( int $campaign_id ): bool {
		return Post_Types::CAMPAIGN === get_post_type( $campaign_id );
	}

	/**
	 * The campaign's current status, or an empty string when it is not a
	 * campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public function status( int $campaign_id ): string {
		if ( ! $this->exists( $campaign_id ) ) {
			return '';
		}

		$status = get_post_status( $campaign_id );

		return is_string( $status ) ? $status : '';
	}

	/**
	 * The owning organization.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function org_id( int $campaign_id ): int {
		return (int) get_post_meta( $campaign_id, self::META_ORG_ID, true );
	}

	/**
	 * Writes the campaign's status.
	 *
	 * Uses wp_update_post so that transition_post_status fires and anything
	 * listening — caches, our own divergence listener — sees the change.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $status      One of the registered campaign statuses.
	 * @return bool
	 */
	public function update_status( int $campaign_id, string $status ): bool {
		if ( ! Post_Statuses::is_valid( $status ) ) {
			return false;
		}

		$result = wp_update_post(
			array(
				'ID'          => $campaign_id,
				'post_status' => $status,
			),
			true
		);

		return ! is_wp_error( $result );
	}

	/**
	 * The reviewer who has claimed this campaign, or 0 when unclaimed.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function reviewed_by( int $campaign_id ): int {
		return (int) get_post_meta( $campaign_id, self::META_REVIEWED_BY, true );
	}

	/**
	 * Claims or releases the campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $user_id     Reviewer id, or 0 to release.
	 * @return void
	 */
	public function set_reviewed_by( int $campaign_id, int $user_id ): void {
		update_post_meta( $campaign_id, self::META_REVIEWED_BY, $user_id );
		update_post_meta( $campaign_id, self::META_REVIEWED_AT, 0 === $user_id ? 0 : time() );
	}

	/**
	 * The advertiser-visible review feedback.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public function review_notes( int $campaign_id ): string {
		return (string) get_post_meta( $campaign_id, self::META_REVIEW_NOTES, true );
	}

	/**
	 * Records advertiser-visible review feedback.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $notes       Feedback.
	 * @return void
	 */
	public function set_review_notes( int $campaign_id, string $notes ): void {
		update_post_meta( $campaign_id, self::META_REVIEW_NOTES, $notes );
	}

	/**
	 * Staff-only notes that never leave the review interface.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public function internal_notes( int $campaign_id ): string {
		return (string) get_post_meta( $campaign_id, self::META_INTERNAL_NOTES, true );
	}

	/**
	 * Saves the staff-only notes for a campaign.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $notes       Internal notes.
	 * @return void
	 */
	public function set_internal_notes( int $campaign_id, string $notes ): void {
		update_post_meta( $campaign_id, self::META_INTERNAL_NOTES, $notes );
	}

	/**
	 * Reserves one recipient delivery for an idempotent notification fan-out.
	 *
	 * Repeated protected meta keeps the receipt with the campaign and out of
	 * generic APIs. The value comparison matters: add_post_meta()'s `$unique`
	 * flag makes the entire meta key unique, not one key/value pair, which would
	 * allow only the first reviewer to receive any notification.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $receipt     Stable notification/revision/recipient key.
	 * @return bool Whether this call acquired the receipt.
	 */
	public function reserve_notification_receipt( int $campaign_id, string $receipt ): bool {
		if ( ! $this->exists( $campaign_id ) || '' === $receipt ) {
			return false;
		}

		$receipts = get_post_meta( $campaign_id, self::META_NOTIFICATION_RECEIPT, false );

		if ( is_array( $receipts ) && in_array( $receipt, $receipts, true ) ) {
			return false;
		}

		return false !== add_post_meta( $campaign_id, self::META_NOTIFICATION_RECEIPT, $receipt );
	}

	/**
	 * Releases a reservation after a delivery failure, allowing a safe retry.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $receipt     Exact receipt reserved before delivery.
	 * @return void
	 */
	public function release_notification_receipt( int $campaign_id, string $receipt ): void {
		delete_post_meta( $campaign_id, self::META_NOTIFICATION_RECEIPT, $receipt );
	}

	/**
	 * How many times this campaign has been submitted.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function revision( int $campaign_id ): int {
		return (int) get_post_meta( $campaign_id, self::META_REVISION, true );
	}

	/**
	 * Bumps the revision counter and returns the new value.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function increment_revision( int $campaign_id ): int {
		$next = $this->revision( $campaign_id ) + 1;

		update_post_meta( $campaign_id, self::META_REVISION, $next );

		return $next;
	}

	/**
	 * Stamps the submission time.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $timestamp   UTC Unix seconds.
	 * @return void
	 */
	public function set_submitted_at( int $campaign_id, int $timestamp ): void {
		update_post_meta( $campaign_id, self::META_SUBMITTED_AT, $timestamp );
	}

	/**
	 * When the campaign was last submitted.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function submitted_at( int $campaign_id ): int {
		return (int) get_post_meta( $campaign_id, self::META_SUBMITTED_AT, true );
	}

	/**
	 * The campaign's start time, in UTC Unix seconds.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function start_ts( int $campaign_id ): int {
		return (int) get_post_meta( $campaign_id, self::META_START_TS, true );
	}

	/**
	 * The campaign's end time, in UTC Unix seconds. Zero means open-ended.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function end_ts( int $campaign_id ): int {
		return (int) get_post_meta( $campaign_id, self::META_END_TS, true );
	}

	/**
	 * How many campaigns one page of a listing returns.
	 *
	 * Paged server-side, always. An advertiser with four campaigns and one with
	 * four hundred hit the same query, and the second is the one that decides
	 * whether this page loads.
	 */
	public const PAGE_SIZE = 20;

	/**
	 * One page of every organization's campaigns in the given statuses.
	 *
	 * **Deliberately unscoped by organization.** This is the staff review
	 * queue: a reviewer works across every advertiser, and the authorization
	 * for that is the `aggr_review_campaigns` capability, checked by the
	 * caller before it ever gets here. It is the one listing in the plugin
	 * without an org clause, which is why it says so.
	 *
	 * Ordered oldest-modified first, so the campaign that has been waiting
	 * longest is at the top and nobody's submission can sit at the bottom of
	 * page three forever. `modified` rather than the submitted_at meta on
	 * purpose: every transition goes through wp_update_post(), so post_modified
	 * already tracks the last status change, and ordering by meta_value_num
	 * would join on the key and silently drop any campaign missing it — the
	 * same trap that hid placements without a sort order.
	 *
	 * @param array<int, string> $statuses Campaign statuses to include.
	 * @param int                $page     1-based page number.
	 * @param bool               $pending_updates_only Limit to campaigns with pending creative updates.
	 * @return array{ids: array<int, int>, total: int, pages: int}
	 */
	public function for_review( array $statuses, int $page = 1, bool $pending_updates_only = false ): array {
		return ( new Campaign_Query_Repository() )->for_review( $statuses, $page, $pending_updates_only );
	}

	/**
	 * Synchronizes the denormalized pending-update count used by the queue.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $count       Canonical count from the creative repository.
	 * @return void
	 */
	public function set_pending_update_count( int $campaign_id, int $count ): void {
		update_post_meta( $campaign_id, self::META_PENDING_UPDATES, max( 0, $count ) );
	}

	/**
	 * Pending replacement count for one campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return int
	 */
	public function pending_update_count( int $campaign_id ): int {
		return max( 0, (int) get_post_meta( $campaign_id, self::META_PENDING_UPDATES, true ) );
	}

	/**
	 * Number of campaigns with replacement creative awaiting review.
	 *
	 * @return int
	 */
	public function campaigns_with_pending_updates(): int {
		$query = new \WP_Query(
			array(
				'post_type'              => Post_Types::CAMPAIGN,
				'post_status'            => Post_Statuses::all(),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Count for the dedicated staff update queue.
					array(
						'key'     => self::META_PENDING_UPDATES,
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * How many campaigns sit in each of the given statuses.
	 *
	 * One query for the whole set rather than one per status: the queue's tabs
	 * all need a number, and five COUNT queries to draw five tabs is how an
	 * admin screen becomes the slowest page on the site.
	 *
	 * @param array<int, string> $statuses Campaign statuses to count.
	 * @return array<string, int> Status slug to count, including zeroes.
	 */
	public function count_by_status( array $statuses ): array {
		return ( new Campaign_Query_Repository() )->count_by_status( $statuses );
	}

	/**
	 * When the campaign was last touched, as a UTC timestamp.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function modified_ts( int $campaign_id ): int {
		$modified = get_post_field( 'post_modified_gmt', $campaign_id );

		if ( ! is_string( $modified ) || '' === $modified ) {
			return 0;
		}

		$timestamp = strtotime( $modified . ' UTC' );

		return false === $timestamp ? 0 : $timestamp;
	}

	/**
	 * One page of an organization's campaigns, newest first.
	 *
	 * Scoped by organization in the query rather than filtered afterwards. The
	 * array-filtering version works until somebody adds pagination, at which
	 * point page two is short for reasons nobody can explain, and the fix that
	 * suggests itself is to remove the filter.
	 *
	 * @param int $org_id Owning organization.
	 * @param int $page   1-based page number.
	 * @return array{ids: array<int, int>, total: int, pages: int}
	 */
	public function for_org( int $org_id, int $page = 1 ): array {
		return ( new Campaign_Query_Repository() )->for_org( $org_id, $page );
	}

	/**
	 * The campaign's title.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public function title( int $campaign_id ): string {
		$title = get_the_title( $campaign_id );

		return is_string( $title ) ? $title : '';
	}

	/**
	 * Records a provider ad id against the campaign, without duplicating it.
	 *
	 * Repeated meta, one row per ad, so a retry can see exactly what already
	 * exists. Adding the same id twice would make "how many ads does this
	 * campaign have?" wrong in the direction that hides a problem.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $ad_id       Provider ad id.
	 * @return void
	 */
	public function add_provider_ad_id( int $campaign_id, int $ad_id ): void {
		if ( $ad_id <= 0 || in_array( $ad_id, $this->provider_ad_ids( $campaign_id ), true ) ) {
			return;
		}

		add_post_meta( $campaign_id, self::META_ADSANITY_ID, $ad_id );
	}

	/**
	 * The placements this campaign has selected.
	 *
	 * Repeated meta rather than a serialized array, so "which campaigns use
	 * placement 62?" stays an indexed lookup. A serialized array would need a
	 * LIKE '%i:62;%', which is also wrong, because it matches 162 and 620.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, int>
	 */
	public function placement_ids( int $campaign_id ): array {
		$ids = get_post_meta( $campaign_id, self::META_PLACEMENT_ID, false );

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * The provider ad ids published for this campaign.
	 *
	 * Repeated meta rather than a serialized array, so a retry can reconcile
	 * what already succeeded. See docs/campaign-workflow.md.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, int>
	 */
	public function provider_ad_ids( int $campaign_id ): array {
		$ids = get_post_meta( $campaign_id, self::META_ADSANITY_ID, false );

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Live campaigns occupying a placement, lowest id first.
	 *
	 * Fill rotates equally among this list. Order is stable so a
	 * test can name the members; it is not a serving priority.
	 *
	 * @param int $placement_id Placement post id.
	 * @return array<int, int>
	 */
	public function live_ids_for_placement( int $placement_id ): array {
		return ( new Campaign_Query_Repository() )->live_ids_for_placement( $placement_id );
	}
}
