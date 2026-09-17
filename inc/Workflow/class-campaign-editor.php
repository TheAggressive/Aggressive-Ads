<?php
/**
 * Advertiser campaign creation and draft editing.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Ad_Sizes;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Security\Capabilities;
use WP_Error;

/**
 * One workflow for REST autosave and the progressively enhanced HTML forms.
 *
 * Delivery layers pass candidate values; this class owns authorization,
 * allowlisting, cross-field validation, optimistic concurrency and auditing.
 */
final class Campaign_Editor {

	public const MAX_TITLE_LENGTH = 160;

	/**
	 * Wizard steps that may be persisted as a resume point.
	 *
	 * Three, not five. The destination step only read back addresses the
	 * creative step had already collected, its dates belonged beside the
	 * package they are priced against, and the submit step was the review
	 * screen with a notes box — each cost a page load and asked nothing new.
	 *
	 * @var array<int, string>
	 */
	public const WIZARD_STEPS = array( 'details', 'creative', 'review' );

	/**
	 * Displayable wizard steps. Submission happens on review.
	 *
	 * @var array<int, string>
	 */
	public const DISPLAY_STEPS = array( 'details', 'creative', 'review' );

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository       $campaigns  Campaign persistence.
	 * @param Org_Repository            $orgs       Organization resolution.
	 * @param Package_Repository        $packages   Package validation.
	 * @param Placement_Repository      $placements Placement validation.
	 * @param Creative_Repository       $creatives  Creative coverage validation.
	 * @param Audit_Repository          $audit      Audit persistence.
	 * @param Edit_Window               $window     When editing is permitted.
	 * @param Fill_Cache                $cache      Native fill cache.
	 * @param Line_Item_Repository|null $line_items Line-item compatibility persistence.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Org_Repository $orgs,
		private readonly Package_Repository $packages,
		private readonly Placement_Repository $placements,
		private readonly Creative_Repository $creatives,
		private readonly Audit_Repository $audit,
		private readonly Edit_Window $window,
		private readonly Fill_Cache $cache,
		private readonly ?Line_Item_Repository $line_items = null
	) {
	}

	/**
	 * Creates a draft for the current user's organization.
	 *
	 * @param string $title Optional initial title.
	 * @return int|WP_Error
	 */
	public function create( string $title = '' ): int|WP_Error {
		if ( ! current_user_can( Capabilities::SUBMIT_CAMPAIGN ) || ! current_user_can( 'create_aggr_campaigns' ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to create a campaign.', 'aggressive-ads' ), 403 );
		}

		$org_ids = $this->orgs->org_ids_for_user( get_current_user_id() );
		$org_id  = array() === $org_ids ? 0 : $org_ids[0];

		if ( $org_id <= 0 ) {
			return $this->error( 'aggr_organization_missing', __( 'Your account is not connected to an organization.', 'aggressive-ads' ), 409 );
		}

		return $this->create_in_org( $org_id, $title, false );
	}

	/**
	 * Creates a draft for an organization the caller names.
	 *
	 * Staff only, and the capability is checked here rather than left to the
	 * route, because this is the one entry point where organization identity
	 * is chosen by input rather than derived from the caller. Everything else
	 * in the plugin reads the org off an object that already has one.
	 *
	 * @param int    $org_id Target organization post id.
	 * @param string $title  Optional initial title.
	 * @return int|WP_Error
	 */
	public function create_for_org( int $org_id, string $title = '' ): int|WP_Error {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) || ! current_user_can( 'create_aggr_campaigns' ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to create a campaign for an advertiser.', 'aggressive-ads' ), 403 );
		}

		// An id that names no organization must not become a campaign owned by
		// nothing, which no org-scoped query would ever return and no advertiser
		// could ever reach.
		if ( $org_id <= 0 || ! $this->orgs->exists( $org_id ) ) {
			return $this->error( 'aggr_organization_missing', __( 'That advertiser could not be found.', 'aggressive-ads' ), 404, 'org_id' );
		}

		return $this->create_in_org( $org_id, $title, ! in_array( $org_id, $this->orgs->org_ids_for_user( get_current_user_id() ), true ) );
	}

	/**
	 * Creates the draft, once the organization is settled.
	 *
	 * @param int    $org_id    Owning organization.
	 * @param string $title     Optional initial title.
	 * @param bool   $on_behalf Whether staff are creating this for someone else.
	 * @return int|WP_Error
	 */
	private function create_in_org( int $org_id, string $title, bool $on_behalf ): int|WP_Error {
		$user_id = get_current_user_id();

		if ( ! $this->orgs->is_active( $org_id ) ) {
			return $this->error( 'aggr_organization_inactive', __( 'This organization cannot create campaigns. Please get in touch.', 'aggressive-ads' ), 403 );
		}

		$title = $this->clean_title( $title );

		if ( mb_strlen( $title ) > self::MAX_TITLE_LENGTH ) {
			return $this->error( 'aggr_title_too_long', __( 'Use 160 characters or fewer for the campaign name.', 'aggressive-ads' ), 422, 'title' );
		}

		$placeholder = '' === $title;

		if ( $placeholder ) {
			$title = __( 'Untitled campaign', 'aggressive-ads' );
		}

		$campaign_id = $this->campaigns->create_draft( $org_id, $user_id, $title );

		if ( ! is_wp_error( $campaign_id ) && $placeholder ) {
			// Recorded, not inferred: comparing the stored title against the
			// placeholder string would stop working the moment the site
			// language changed, and the campaign would submit unnamed.
			$this->campaigns->set_title_is_placeholder( (int) $campaign_id, true );
		}

		if ( is_wp_error( $campaign_id ) ) {
			return $this->error( 'aggr_campaign_not_created', __( 'The campaign could not be created. Please try again.', 'aggressive-ads' ), 500 );
		}

		if ( null !== $this->line_items && null === $this->line_items->ensure_default( (int) $campaign_id ) ) {
			$this->campaigns->delete( (int) $campaign_id );

			return $this->error( 'aggr_line_item_not_created', __( 'The campaign delivery strategy could not be created. Please try again.', 'aggressive-ads' ), 500 );
		}

		$this->audit->insert(
			new Audit_Event(
				event: $on_behalf ? 'campaign.created_on_behalf' : 'campaign.created',
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $org_id,
				to_state: Post_Statuses::DRAFT,
				message: $on_behalf
					? 'Campaign draft created by staff for the organization.'
					: 'Campaign draft created.',
				actor_user_id: $user_id
			)
		);

		return $campaign_id;
	}

	/**
	 * Saves allowlisted fields to an editable campaign draft.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $fields      Candidate values.
	 * @param int                  $expected_rev Client's last-seen revision.
	 * @return int|WP_Error New autosave revision on success.
	 */
	public function save( int $campaign_id, array $fields, int $expected_rev ): int|WP_Error {
		$authorized = $this->authorize_edit( $campaign_id );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$current_rev = $this->campaigns->autosave_revision( $campaign_id );

		if ( $expected_rev < 0 || $expected_rev !== $current_rev ) {
			return new WP_Error(
				'aggr_edit_conflict',
				__( 'This campaign changed in another window. Reload it before saving again.', 'aggressive-ads' ),
				array(
					'status'       => 409,
					'current_rev'  => $current_rev,
					'expected_rev' => $expected_rev,
				)
			);
		}

		$clean = $this->validate_fields( $campaign_id, $fields );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$revision = $this->campaigns->claim_autosave_revision( $campaign_id, $expected_rev );

		if ( false === $revision ) {
			return new WP_Error(
				'aggr_edit_conflict',
				__( 'This campaign changed in another window. Reload it before saving again.', 'aggressive-ads' ),
				array(
					'status'      => 409,
					'current_rev' => $this->campaigns->autosave_revision( $campaign_id ),
				)
			);
		}

		$saved = $this->campaigns->update_draft( $campaign_id, $clean );

		if ( is_wp_error( $saved ) ) {
			/*
			 * **Give the revision back.** It was claimed above, before the
			 * write, so the compare-and-swap protects against a concurrent
			 * editor — but a write that then fails has saved nothing, and
			 * keeping the claim leaves every open page a revision behind a
			 * campaign that did not change. The browser only updates its copy
			 * on success, so its very next save was refused as a conflict:
			 * one failed save became "This campaign changed in another
			 * window", repeated for as long as the page stayed open.
			 */
			$this->campaigns->release_autosave_revision( $campaign_id, $revision, $expected_rev );

			return $this->error( 'aggr_campaign_not_saved', __( 'The campaign could not be saved. Please try again.', 'aggressive-ads' ), 500 );
		}

		// A posted title is one the advertiser chose: validate_fields() has
		// already refused an empty one, so reaching here means the wizard's
		// name, placeholder or automatic, is gone for good. A title in $clean
		// that nobody posted is the wizard naming the campaign from its plan.
		if ( array_key_exists( 'title', $fields ) ) {
			$this->campaigns->set_title_is_placeholder( $campaign_id, false );
			$this->campaigns->set_title_is_automatic( $campaign_id, false );
		} elseif ( array_key_exists( 'title', $clean ) ) {
			$this->campaigns->set_title_is_placeholder( $campaign_id, false );
			$this->campaigns->set_title_is_automatic( $campaign_id, true );
		}

		if ( null !== $this->line_items && array_intersect( array( 'start_ts', 'end_ts', 'package_id', 'budget_cents' ), array_keys( $clean ) ) ) {
			$this->line_items->sync_default_from_campaign( $campaign_id );
		}

		$this->record_edit( $campaign_id, array_keys( $clean ) );

		return $revision;
	}

	/**
	 * Leaves the creative step for review.
	 *
	 * Nothing is posted with it: the dates were chosen on details and the ads
	 * were uploaded one by one. What this adds is the promise that both are
	 * complete, checked against what is stored — so a date that has passed
	 * since details was saved is caught here rather than at submission.
	 *
	 * @param int $campaign_id  Campaign post id.
	 * @param int $expected_rev Client's last-seen revision.
	 * @return int|WP_Error New autosave revision on success.
	 */
	public function complete_creative( int $campaign_id, int $expected_rev ): int|WP_Error {
		return $this->save( $campaign_id, array( 'wizard_step' => 'review' ), $expected_rev );
	}

	/**
	 * Records an edit, and corrects delivery if the campaign is serving.
	 *
	 * Both halves exist because staff can now edit outside a draft.
	 *
	 * The audit event is different for an on-behalf edit rather than merely
	 * carrying the actor id. A timeline is read to answer "who changed this",
	 * and "campaign.draft_updated by a user the client has never heard of" is
	 * the same sentence whether staff fixed a typo or an account was misused.
	 * A distinct event makes the support action legible as a support action.
	 *
	 * Busting fill matters more than it looks: a live campaign is served from
	 * cache, so without this the corrected creative or destination URL would
	 * reach the page only after the TTL expired — which is precisely the
	 * window in which somebody is on the phone asking why the ad still shows
	 * the wrong thing.
	 *
	 * @param int                $campaign_id Campaign post id.
	 * @param array<int, string> $fields      The field names written.
	 * @return void
	 */
	private function record_edit( int $campaign_id, array $fields ): void {
		$on_behalf = $this->window->is_on_behalf( $campaign_id );
		$status    = $this->campaigns->status( $campaign_id );

		$this->audit->insert(
			new Audit_Event(
				event: $on_behalf ? 'campaign.edited_on_behalf' : 'campaign.draft_updated',
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: $on_behalf
					? 'Campaign edited by staff on the organization\'s behalf.'
					: 'Campaign draft updated.',
				context: array(
					'fields' => $fields,
					'status' => $status,
				),
				actor_user_id: get_current_user_id()
			)
		);

		if ( in_array( $status, Post_Statuses::published(), true ) ) {
			$this->cache->bust_campaign( $campaign_id );
		}
	}

	/**
	 * Whether the caller may edit the named campaign now.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	private function authorize_edit( int $campaign_id ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::SUBMIT_CAMPAIGN ) || ! $this->campaigns->exists( $campaign_id ) || ! current_user_can( 'edit_aggr_campaign', $campaign_id ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to edit that campaign.', 'aggressive-ads' ), 403 );
		}

		if ( ! $this->window->allows( $campaign_id ) ) {
			return $this->error( 'aggr_campaign_not_editable', __( 'This campaign cannot be changed right now.', 'aggressive-ads' ), 409 );
		}

		return true;
	}

	/**
	 * Normalizes the public field allowlist and validates related values.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $fields      Candidate values.
	 * @return array<string, mixed>|WP_Error
	 */
	private function validate_fields( int $campaign_id, array $fields ): array|WP_Error {
		$clean = array();

		if ( array_key_exists( 'title', $fields ) ) {
			$title = $this->clean_title( (string) $fields['title'] );

			if ( '' === $title ) {
				return $this->error( 'aggr_title_required', __( 'Enter a campaign name.', 'aggressive-ads' ), 422, 'title' );
			}

			if ( mb_strlen( $title ) > self::MAX_TITLE_LENGTH ) {
				return $this->error( 'aggr_title_too_long', __( 'Use 160 characters or fewer for the campaign name.', 'aggressive-ads' ), 422, 'title' );
			}

			$clean['title'] = $title;
		}

		if ( array_key_exists( 'placement_ids', $fields ) ) {
			$ids = is_array( $fields['placement_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', $fields['placement_ids'] ) ) ) ) : array();

			foreach ( $ids as $placement_id ) {
				if ( ! $this->placements->is_active( $placement_id ) ) {
					return $this->error( 'aggr_placement_unavailable', __( 'One of the selected placements is not available.', 'aggressive-ads' ), 422, 'placement_ids' );
				}
			}

			$clean['placement_ids'] = $ids;
		}

		if ( array_key_exists( 'package_id', $fields ) ) {
			$package = $this->package_snapshot( max( 0, (int) $fields['package_id'] ) );

			if ( is_wp_error( $package ) ) {
				return $package;
			}

			$clean = array_merge( $clean, $package );
		}

		$start = array_key_exists( 'start_ts', $fields ) ? max( 0, (int) $fields['start_ts'] ) : $this->campaigns->start_ts( $campaign_id );
		$end   = array_key_exists( 'end_ts', $fields ) ? max( 0, (int) $fields['end_ts'] ) : $this->campaigns->end_ts( $campaign_id );

		if ( array_key_exists( 'start_ts', $fields ) ) {
			$clean['start_ts'] = $start;
		}

		if ( array_key_exists( 'end_ts', $fields ) ) {
			$clean['end_ts'] = $end;
		}

		$package_id = array_key_exists( 'package_id', $clean ) ? (int) $clean['package_id'] : $this->campaigns->package_id( $campaign_id );
		$plan_moved = array_key_exists( 'package_id', $clean ) || array_key_exists( 'start_ts', $clean );

		/*
		 * **A fixed package decides its own end date.** The advertiser bought a
		 * number of days, so the portal no longer asks for an end it would only
		 * have to check against the package. Derived whenever the package or
		 * the start moves, and only when no end was supplied: a REST client or
		 * a staff correction that names one explicitly still wins.
		 */
		if ( $plan_moved && ! array_key_exists( 'end_ts', $fields ) && $package_id > 0 && ! $this->packages->has_custom_duration( $package_id ) ) {
			$end             = Campaign_Rules::fixed_end_ts( $start, $this->packages->duration_days( $package_id ), wp_timezone()->getName() );
			$clean['end_ts'] = $end;
		}

		/*
		 * The wizard names the campaign after its plan until somebody names it
		 * themselves, so nobody has to invent a label before choosing what
		 * they are buying. It keeps following the plan — switching package
		 * renames it — for exactly as long as the name is still the wizard's.
		 */
		if ( $plan_moved && ! array_key_exists( 'title', $fields ) && $package_id > 0 && ( $this->campaigns->title_is_placeholder( $campaign_id ) || $this->campaigns->title_is_automatic( $campaign_id ) ) ) {
			$automatic = $this->automatic_title( $package_id, $start );

			if ( '' !== $automatic ) {
				$clean['title'] = $automatic;
			}
		}

		if ( 0 !== $end && ( 0 === $start || $end <= $start ) ) {
			return $this->error( 'aggr_end_before_start', __( 'The end date must be after the start date.', 'aggressive-ads' ), 422, 'end_ts' );
		}

		if ( array_key_exists( 'advertiser_notes', $fields ) ) {
			$clean['advertiser_notes'] = sanitize_textarea_field( (string) $fields['advertiser_notes'] );
		}

		/*
		 * Empty is allowed and clears it: the link is a starting point for the
		 * next upload, and every creative still carries and validates its own.
		 * Anything else must be a link a creative could be saved with, so the
		 * address the cards fill in is never one the upload would then refuse.
		 */
		if ( array_key_exists( 'default_click_url', $fields ) ) {
			$link = trim( (string) $fields['default_click_url'] );

			// `example.com/page` is how people type a link; it means https.
			if ( '' !== $link && 1 !== preg_match( '#^[a-z][a-z0-9+.-]*:#i', $link ) ) {
				$link = 'https://' . $link;
			}

			if ( '' !== $link && ( ! Campaign_Rules::is_valid_click_url( $link ) || false === wp_http_validate_url( $link ) ) ) {
				return $this->error( 'aggr_default_click_url_invalid', __( 'Enter a valid http or https link, such as https://example.com.', 'aggressive-ads' ), 422, 'default_click_url' );
			}

			$clean['default_click_url'] = $link;
		}

		if ( array_key_exists( 'wizard_step', $fields ) ) {
			$step = sanitize_key( (string) $fields['wizard_step'] );

			if ( ! in_array( $step, self::WIZARD_STEPS, true ) ) {
				return $this->error( 'aggr_wizard_step_invalid', __( 'That campaign step is not valid.', 'aggressive-ads' ), 422, 'wizard_step' );
			}

			$clean['wizard_step'] = $step;
		}

		// The placements this save leaves in place, which is what everything
		// below has to be judged against.
		$placement_ids = $clean['placement_ids'] ?? $this->campaigns->placement_ids( $campaign_id );

		if ( 'review' === ( $clean['wizard_step'] ?? '' ) ) {
			$ready = $this->validate_schedule_completion( $campaign_id, $start, $end, $placement_ids );

			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
		}

		/*
		 * Leaving details with a date is a promise about that date. Refusing a
		 * past start here, beside the field, beats carrying it through the
		 * uploads to be refused on review. An empty start still leaves: a draft
		 * has to be savable before its schedule is decided.
		 */
		if ( 'creative' === ( $clean['wizard_step'] ?? '' ) && array_key_exists( 'start_ts', $clean ) && $start > 0 ) {
			$dated = $this->validate_dates( $start, $end );

			if ( is_wp_error( $dated ) ) {
				return $dated;
			}
		}

		/*
		 * A campaign that is already serving has to stay coherent on every
		 * save, not only when a wizard step advances. Staff can edit a live
		 * campaign, and the save busts fill cache — so dropping a placement
		 * that has no creative, or adding one, would reach the page
		 * immediately and serve nothing.
		 */
		if ( in_array( $this->campaigns->status( $campaign_id ), Post_Statuses::published(), true ) ) {
			$covered = $this->validate_creative_coverage( $campaign_id, $placement_ids );

			if ( is_wp_error( $covered ) ) {
				return $covered;
			}
		}

		return $clean;
	}

	/**
	 * One creative per selected placement, and no placement without one.
	 *
	 * Checked against the placements this save leaves in place rather than the
	 * stored ones. A single request can change `placement_ids` and advance the
	 * step together, and reading the stored value there validates the set the
	 * campaign is moving away from.
	 *
	 * @param int             $campaign_id   Campaign post id.
	 * @param array<int, int> $placement_ids Effective placement ids.
	 * @return true|WP_Error
	 */
	private function validate_creative_coverage( int $campaign_id, array $placement_ids ): bool|WP_Error {
		$creative_rows = $this->creatives->for_campaign( $campaign_id );
		$coverage      = array();

		foreach ( $creative_rows as $creative ) {
			$placement_id              = $creative['placement_id'];
			$coverage[ $placement_id ] = ( $coverage[ $placement_id ] ?? 0 ) + 1;
		}

		if ( array() === $placement_ids || count( $creative_rows ) !== count( $placement_ids ) ) {
			return $this->error( 'aggr_creatives_incomplete', __( 'Add an ad for every size in the package before continuing.', 'aggressive-ads' ), 422, 'creatives' );
		}

		foreach ( $placement_ids as $placement_id ) {
			if ( 1 !== ( $coverage[ $placement_id ] ?? 0 ) ) {
				return $this->error( 'aggr_creatives_incomplete', __( 'Add an ad for every size in the package before continuing.', 'aggressive-ads' ), 422, 'creatives' );
			}
		}

		return true;
	}

	/**
	 * Applies the additional invariants required to advance to review.
	 *
	 * Called only after campaign authorization and optimistic revision checks,
	 * so coverage failures cannot reveal whether another tenant's object exists.
	 *
	 * @param int             $campaign_id   Campaign post id.
	 * @param int             $start_ts      Candidate start timestamp.
	 * @param int             $end_ts        Candidate end timestamp.
	 * @param array<int, int> $placement_ids Placements this save leaves in place.
	 * @return true|WP_Error
	 */
	private function validate_schedule_completion( int $campaign_id, int $start_ts, int $end_ts, array $placement_ids ): bool|WP_Error {
		$covered = $this->validate_creative_coverage( $campaign_id, $placement_ids );

		if ( is_wp_error( $covered ) ) {
			return $covered;
		}

		return $this->validate_dates( $start_ts, $end_ts );
	}

	/**
	 * The submission-grade date window, as one delivery-safe error.
	 *
	 * @param int $start_ts Candidate start timestamp.
	 * @param int $end_ts   Candidate end timestamp.
	 * @return true|WP_Error
	 */
	private function validate_dates( int $start_ts, int $end_ts ): bool|WP_Error {
		$window = Campaign_Rules::validate_window( $start_ts, $end_ts, Campaign_Rules::day_start_ts( time(), wp_timezone()->getName() ) );
		$window->absorb( Campaign_Rules::validate_day_boundaries( $start_ts, $end_ts, wp_timezone()->getName() ) );

		// Staff edit campaigns that have already started — that is most of what
		// editing on a client's behalf means. Demanding a future start date
		// would make every such campaign unfixable, and moving the date to
		// satisfy the rule would rewrite when the campaign actually ran.
		if ( $this->window->is_staff() ) {
			$window = $window->without( Campaign_Rules::ERROR_START_IN_PAST );
		}

		if ( $window->is_valid() ) {
			return true;
		}

		$problem = $window->problems()[0];
		$code    = match ( $problem['code'] ) {
			Campaign_Rules::ERROR_START_MISSING    => 'aggr_start_date_required',
			Campaign_Rules::ERROR_START_IN_PAST    => 'aggr_start_date_past',
			Campaign_Rules::ERROR_START_NOT_MIDNIGHT => 'aggr_start_date_not_midnight',
			Campaign_Rules::ERROR_END_MISSING      => 'aggr_end_date_required',
			Campaign_Rules::ERROR_END_BEFORE_START => 'aggr_end_before_start',
			Campaign_Rules::ERROR_END_NOT_DAY_END  => 'aggr_end_date_not_day_end',
			default                                => 'aggr_schedule_invalid',
		};

		return $this->error(
			$code,
			Campaign_Validator::message_for( $problem['code'], $problem['context'] ),
			422,
			'start_ts' === $problem['field'] ? 'start_ts' : 'end_ts'
		);
	}

	/**
	 * Validates a package and builds the campaign snapshot written on selection.
	 *
	 * Package configuration is mutable, but a campaign's price and currency must
	 * not change underneath it after selection. Those commercial values are
	 * therefore copied onto the campaign together with the package placement set.
	 *
	 * @param int $package_id Package post id, or zero to clear selection.
	 * @return array{package_id: int, placement_ids: array<int, int>, budget_cents: int, currency: string}|WP_Error
	 */
	public function package_snapshot( int $package_id ): array|WP_Error {
		if ( 0 === $package_id ) {
			return array(
				'package_id'    => 0,
				'placement_ids' => array(),
				'budget_cents'  => 0,
				'currency'      => '',
			);
		}

		if ( ! $this->packages->is_active( $package_id ) || ! current_user_can( 'read_aggr_package', $package_id ) ) {
			return $this->error( 'aggr_package_unavailable', __( 'That package is not available.', 'aggressive-ads' ), 422, 'package_id' );
		}

		$placement_ids = $this->packages->placement_ids( $package_id );
		$duration_days = $this->packages->duration_days( $package_id );
		$price_cents   = $this->packages->price_cents( $package_id );
		$currency      = $this->packages->currency( $package_id );

		$duration_valid = $duration_days > 0 || $this->packages->has_custom_duration( $package_id );

		if ( array() === $placement_ids || ! $duration_valid || $price_cents < 0 || 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return $this->error( 'aggr_package_misconfigured', __( 'That package is not configured completely. Please choose another package or get in touch.', 'aggressive-ads' ), 422, 'package_id' );
		}

		foreach ( $placement_ids as $placement_id ) {
			if ( ! $this->placements->is_active( $placement_id ) || ! Ad_Sizes::is_valid( $this->placements->size( $placement_id ) ) ) {
				return $this->error( 'aggr_package_misconfigured', __( 'That package includes a placement that is not available. Please choose another package or get in touch.', 'aggressive-ads' ), 422, 'package_id' );
			}
		}

		return array(
			'package_id'    => $package_id,
			'placement_ids' => $placement_ids,
			'budget_cents'  => $price_cents,
			'currency'      => $currency,
		);
	}

	/**
	 * The name the wizard gives a campaign from its plan.
	 *
	 * The month is the site's, through wp_date(), because that is the calendar
	 * the start date was chosen in.
	 *
	 * @param int $package_id Selected package.
	 * @param int $start_ts   Start, or zero when not chosen yet.
	 * @return string Empty when the package has no name to build from.
	 */
	private function automatic_title( int $package_id, int $start_ts ): string {
		$name = $this->clean_title( $this->packages->name( $package_id ) );

		if ( '' === $name ) {
			return '';
		}

		$title = 0 === $start_ts
			? $name
			: sprintf(
				/* translators: 1: package name. 2: month and year the campaign starts, e.g. October 2026. */
				__( '%1$s – %2$s', 'aggressive-ads' ),
				$name,
				(string) wp_date( 'F Y', $start_ts )
			);

		return mb_substr( $this->clean_title( $title ), 0, self::MAX_TITLE_LENGTH );
	}

	/**
	 * Cleans and bounds a title before persistence.
	 *
	 * @param string $title Candidate title.
	 * @return string
	 */
	private function clean_title( string $title ): string {
		$title = trim( sanitize_text_field( $title ) );

		return $title;
	}

	/**
	 * Builds a delivery-safe error with a consistent status and field hint.
	 *
	 * @param string $code    Error code.
	 * @param string $message User-facing message.
	 * @param int    $status  HTTP status.
	 * @param string $field   Related form field.
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
