<?php
/**
 * Campaign persistence.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Repository;

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Post_Types;

/**
 * Every read and write of a campaign.
 *
 * The status setter is deliberately blunt and deliberately not public API:
 * only Campaign_State_Machine::apply() should reach it, and a listener writes
 * an audit row when a campaign's status changes without it. See
 * docs/adr/0008-explicit-transition-table.md.
 */
final class Campaign_Repository {

	public const META_ORG_ID               = '_laao_ads_org_id';
	public const META_START_TS             = '_laao_ads_start_ts';
	public const META_END_TS               = '_laao_ads_end_ts';
	public const META_SUBMITTED_AT         = '_laao_ads_submitted_at';
	public const META_REVIEWED_BY          = '_laao_ads_reviewed_by';
	public const META_REVIEWED_AT          = '_laao_ads_reviewed_at';
	public const META_REVIEW_NOTES         = '_laao_ads_review_notes';
	public const META_INTERNAL_NOTES       = '_laao_ads_internal_notes';
	public const META_NOTIFICATION_RECEIPT = '_laao_ads_notification_receipt';
	public const META_REVISION             = '_laao_ads_revision';
	public const META_ADSANITY_ID          = '_laao_ads_adsanity_ad_id';
	public const META_PLACEMENT_ID         = '_laao_ads_placement_id';
	public const META_AUTOSAVE_REV         = '_laao_ads_autosave_rev';
	public const META_WIZARD_STEP          = '_laao_ads_wizard_step';
	public const META_ADVERTISER_NOTES     = '_laao_ads_advertiser_notes';
	public const META_PACKAGE_ID           = '_laao_ads_package_id';
	public const META_BUDGET_CENTS         = '_laao_ads_budget_cents';
	public const META_CURRENCY             = '_laao_ads_currency';
	public const META_PENDING_UPDATES      = '_laao_ads_pending_creative_updates';

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
		if ( isset( $fields['title'] ) ) {
			$updated = wp_update_post(
				array(
					'ID'         => $campaign_id,
					'post_title' => (string) $fields['title'],
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		if ( isset( $fields['start_ts'] ) ) {
			update_post_meta( $campaign_id, self::META_START_TS, (int) $fields['start_ts'] );
		}

		if ( isset( $fields['end_ts'] ) ) {
			update_post_meta( $campaign_id, self::META_END_TS, (int) $fields['end_ts'] );
		}

		if ( isset( $fields['advertiser_notes'] ) ) {
			update_post_meta( $campaign_id, self::META_ADVERTISER_NOTES, (string) $fields['advertiser_notes'] );
		}

		if ( isset( $fields['wizard_step'] ) ) {
			update_post_meta( $campaign_id, self::META_WIZARD_STEP, (string) $fields['wizard_step'] );
		}

		if ( isset( $fields['package_id'] ) ) {
			update_post_meta( $campaign_id, self::META_PACKAGE_ID, (int) $fields['package_id'] );
		}

		if ( isset( $fields['budget_cents'] ) ) {
			update_post_meta( $campaign_id, self::META_BUDGET_CENTS, (int) $fields['budget_cents'] );
		}

		if ( isset( $fields['currency'] ) ) {
			update_post_meta( $campaign_id, self::META_CURRENCY, (string) $fields['currency'] );
		}

		if ( isset( $fields['placement_ids'] ) && is_array( $fields['placement_ids'] ) ) {
			delete_post_meta( $campaign_id, self::META_PLACEMENT_ID );

			foreach ( $fields['placement_ids'] as $placement_id ) {
				add_post_meta( $campaign_id, self::META_PLACEMENT_ID, (int) $placement_id );
			}
		}

		return true;
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
	 * for that is the `laao_ads_review_campaigns` capability, checked by the
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
		$wanted = array();

		foreach ( $statuses as $status ) {
			if ( is_string( $status ) && Post_Statuses::is_valid( $status ) ) {
				$wanted[] = $status;
			}
		}

		if ( array() === $wanted ) {
			return array(
				'ids'   => array(),
				'total' => 0,
				'pages' => 0,
			);
		}

		$args = array(
			'post_type'              => Post_Types::CAMPAIGN,
			'post_status'            => $wanted,
			'posts_per_page'         => self::PAGE_SIZE,
			'paged'                  => max( 1, $page ),
			'fields'                 => 'ids',
			'orderby'                => array(
				'modified' => 'ASC',
				'ID'       => 'ASC',
			),
			'update_post_term_cache' => false,
		);

		if ( $pending_updates_only ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Staff queue query against one bounded denormalized count.
				array(
					'key'     => self::META_PENDING_UPDATES,
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			);
		}

		$query = new \WP_Query( $args );

		$ids = array();

		foreach ( $query->posts as $post ) {
			$ids[] = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;
		}

		return array(
			'ids'   => $ids,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
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
	 * Campaign ids in the given statuses, oldest-modified first.
	 *
	 * For the reconciler, which sweeps every organization: there is no org
	 * clause here and there should not be, because the clock belongs to nobody.
	 * The caller is cron, not a request, and the transitions it drives carry no
	 * capability requirement by design — see Campaign_State_Machine::apply_system().
	 *
	 * Bounded by $limit rather than paged. A sweep that falls behind catches up
	 * on the next run, which is safe precisely because these transitions are
	 * functions of the clock: nothing is lost by arriving late, and a run that
	 * tried to process every campaign on a large site in one request would be
	 * the thing that guaranteed it never finished.
	 *
	 * @param array<int, string> $statuses Statuses to include.
	 * @param int                $limit    Maximum ids to return.
	 * @return array<int, int>
	 */
	public function ids_in_status( array $statuses, int $limit ): array {
		$wanted = array();

		foreach ( $statuses as $status ) {
			if ( is_string( $status ) && Post_Statuses::is_valid( $status ) ) {
				$wanted[] = $status;
			}
		}

		if ( array() === $wanted || $limit <= 0 ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'              => Post_Types::CAMPAIGN,
				'post_status'            => $wanted,
				'posts_per_page'         => $limit,
				'fields'                 => 'ids',
				'orderby'                => 'modified',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$ids = array();

		foreach ( $query->posts as $post ) {
			$ids[] = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;
		}

		return $ids;
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
		$counts = array();

		foreach ( $statuses as $status ) {
			if ( is_string( $status ) && Post_Statuses::is_valid( $status ) ) {
				$counts[ $status ] = 0;
			}
		}

		if ( array() === $counts ) {
			return array();
		}

		$totals = (array) wp_count_posts( Post_Types::CAMPAIGN );

		foreach ( array_keys( $counts ) as $status ) {
			$counts[ $status ] = isset( $totals[ $status ] ) ? (int) $totals[ $status ] : 0;
		}

		return $counts;
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
		if ( $org_id <= 0 ) {
			return array(
				'ids'   => array(),
				'total' => 0,
				'pages' => 0,
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'              => Post_Types::CAMPAIGN,
				'post_status'            => Post_Statuses::all(),
				'posts_per_page'         => self::PAGE_SIZE,
				'paged'                  => max( 1, $page ),
				'fields'                 => 'ids',
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed lookup on a single meta key; this is the org scope, and it belongs in SQL.
					array(
						'key'   => self::META_ORG_ID,
						'value' => (string) $org_id,
					),
				),
			)
		);

		// `fields => ids` yields integers, but WP_Query::$posts is typed as the
		// union either way. Narrowing here rather than casting blindly keeps
		// the method honest if somebody ever drops that argument.
		$ids = array();

		foreach ( $query->posts as $post ) {
			$ids[] = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;
		}

		return array(
			'ids'   => $ids,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
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
}
