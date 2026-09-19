<?php
/**
 * What the portal screens render.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Core\Money;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Domain\Campaign_Filter;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Reporting_Rules;
use Aggressive\Ads\Domain\Transition_Table;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Campaign_Request_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Creative_Revision_Repository;
use Aggressive\Ads\Workflow\Assigned_Creatives;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Org_Access_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\REST\Creative_File_Controller;
use Aggressive\Ads\Workflow\Edit_Window;
use Aggressive\Ads\Workflow\Link_Checker;
use Aggressive\Ads\Workflow\Campaign_Action_Requests;
use Aggressive\Ads\Workflow\Campaign_Change_Manager;
use Aggressive\Ads\Workflow\Campaign_Editor;
use Aggressive\Ads\Workflow\Creative_Approval;
use Aggressive\Ads\Workflow\Email_Change;
use Aggressive\Ads\Workflow\Reporting_Read;
use Aggressive\Ads\Workflow\Review_Readiness;

/**
 * Assembles a screen's data, so templates render and nothing else.
 *
 * Templates that query are templates nobody can test and nobody can reason
 * about the cost of. Everything a screen needs arrives as a plain array,
 * already scoped to the caller's own organization — the scoping happens here,
 * once, rather than in each template where forgetting it is invisible.
 */
final class View_Data {

	/**
	 * Statuses in which a campaign can have delivered, and so has figures.
	 *
	 * @var array<int, string>
	 */
	private const DELIVERY_STATUSES = array(
		Post_Statuses::APPROVED,
		Post_Statuses::SCHEDULED,
		Post_Statuses::LIVE,
		Post_Statuses::PAUSED,
		Post_Statuses::COMPLETE,
		Post_Statuses::CANCELLED,
	);


	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository         $campaigns  Campaign persistence.
	 * @param Placement_Repository        $placements Placement persistence.
	 * @param Org_Repository              $orgs       Organization lookups.
	 * @param Org_Access_Repository       $org_access Organization access persistence.
	 * @param Package_Repository          $packages   Package persistence.
	 * @param Campaign_Editor             $editor     Shared package validation.
	 * @param Review_Readiness            $readiness  Safe canonical review readiness.
	 * @param Email_Change                $emails     Pending email-change lookup.
	 * @param Reporting_Read              $reporting  Native rollup reads.
	 * @param Campaign_Change_Manager     $changes  Running-campaign change proposals.
	 * @param Settings                    $settings   Brand and support details.
	 * @param Edit_Window                 $window     When editing is permitted.
	 * @param Acting_As                   $acting     Staff acting for an advertiser.
	 * @param Line_Item_Repository        $line_items Campaign delivery strategies.
	 * @param Delivery_View_Data          $delivery   Dashboard delivery numbers.
	 * @param Creative_View_Data          $creative_view Campaign creative rows.
	 * @param Campaign_Request_Repository $requests      Advertiser requests and proposed changes.
	 * @param Campaign_List_View_Data     $campaign_list The campaigns list and its counts.
	 * @param Link_Checker                $links         The campaign link check.
	 * @param Campaign_History_View_Data  $history       The campaign's own audit trail.
	 * @param Campaign_Edit_View_Data     $edit_view     What the running-campaign edit flow renders.
	 * @param Campaign_Action_Requests    $action_requests Pause, restart and cancel requests.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Placement_Repository $placements,
		private readonly Org_Repository $orgs,
		private readonly Org_Access_Repository $org_access,
		private readonly Package_Repository $packages,
		private readonly Campaign_Editor $editor,
		private readonly Review_Readiness $readiness,
		private readonly Email_Change $emails,
		private readonly Reporting_Read $reporting,
		private readonly Campaign_Change_Manager $changes,
		private readonly Settings $settings,
		private readonly Edit_Window $window,
		private readonly Acting_As $acting,
		private readonly Line_Item_Repository $line_items,
		private readonly Delivery_View_Data $delivery,
		private readonly Creative_View_Data $creative_view,
		private readonly Campaign_Request_Repository $requests,
		private readonly Campaign_List_View_Data $campaign_list,
		private readonly Link_Checker $links,
		private readonly Campaign_History_View_Data $history,
		private readonly Campaign_Edit_View_Data $edit_view,
		private readonly Campaign_Action_Requests $action_requests
	) {
	}

	/**
	 * The organization the current user is acting for, or 0.
	 *
	 * @return int
	 */
	public function org_id(): int {
		/*
		 * An open acting-as session decides which organization this screen is
		 * about. It changes scope only — every capability check and every
		 * Ownership decision below is untouched, so this cannot show staff
		 * anything their own capabilities did not already allow against the
		 * client's objects.
		 */
		$acting = $this->acting->org_id();

		if ( $acting > 0 ) {
			return $acting;
		}

		$orgs = $this->orgs->org_ids_for_user( get_current_user_id() );

		return array() === $orgs ? 0 : $orgs[0];
	}

	/**
	 * The organization's name, for the top bar.
	 *
	 * @return string
	 */
	public function org_name(): string {
		return $this->orgs->name( $this->org_id() );
	}

	/**
	 * Up to two initials for the avatar.
	 *
	 * @return string
	 */
	public function org_initials(): string {
		$name = $this->org_name();

		if ( '' === $name ) {
			return '—';
		}

		$initials = '';
		$words    = preg_split( '/\s+/', $name );
		$words    = is_array( $words ) ? $words : array();

		foreach ( $words as $word ) {
			if ( '' !== $word ) {
				$initials .= mb_strtoupper( mb_substr( $word, 0, 1 ) );
			}

			if ( 2 === mb_strlen( $initials ) ) {
				break;
			}
		}

		return $initials;
	}

	/**
	 * The caller's campaigns, ready to render.
	 *
	 * @param int    $page     1-based page.
	 * @param string $filter   Slice to show, or '' for every campaign.
	 * @param string $search   Words to find in campaign names, or '' for none.
	 * @param int    $per_page Rows per page.
	 * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int, show_metrics: bool}
	 */
	public function campaigns( int $page = 1, string $filter = '', string $search = '', int $per_page = Campaign_Repository::PAGE_SIZE ): array {
		return $this->campaign_list->campaigns( $this->org_id(), $page, $filter, $search, $per_page );
	}

	/**
	 * Where each of the caller's campaigns has got to, one count per slice.
	 *
	 * @return array<int, array{label: string, value: int, filter: string}>
	 */
	public function counts(): array {
		return $this->campaign_list->counts( $this->org_id() );
	}

	/**
	 * The caller's campaigns waiting on them, each with why and what to do.
	 *
	 * @return array{rows: list<array{id: int, title: string, url: string, reason: string, action: string}>, total: int}
	 */
	public function attention(): array {
		return $this->campaign_list->attention( $this->org_id() );
	}

	/**
	 * A search term as the list query takes it.
	 *
	 * @param string $search Raw words.
	 * @return string
	 */
	public static function search_term( string $search ): string {
		return Campaign_List_View_Data::search_term( $search );
	}

	/**
	 * What one slice is called, wherever it is named.
	 *
	 * @param string $filter Filter slug, or '' for no filter.
	 * @return string
	 */
	public function filter_label( string $filter ): string {
		return $this->campaign_list->filter_label( $filter );
	}

	/**
	 * One campaign in full, or null when the caller may not see it.
	 *
	 * The authorization is `read_post`, not a comparison against the caller's
	 * own org id. Both would work today; only one keeps working when a user
	 * belongs to two organizations, or when staff open an advertiser's campaign
	 * during review. `Security\Ownership` is the single answer to "may they?",
	 * and asking it here means this screen cannot drift away from it.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<string, mixed>|null
	 */
	public function campaign( int $campaign_id ): ?array {
		if ( $campaign_id <= 0 || ! $this->campaigns->exists( $campaign_id ) ) {
			return null;
		}

		if ( ! current_user_can( 'read_post', $campaign_id ) ) {
			return null;
		}

		$row = $this->reporting->attach_one( $this->campaign_list->row( $campaign_id ) );

		$row['review_notes']       = $this->campaigns->review_notes( $campaign_id );
		$row['revision']           = $this->campaigns->revision( $campaign_id );
		$row['submitted_at']       = $this->campaigns->submitted_at( $campaign_id );
		$row['creatives']          = $this->creative_view->creative_rows( $campaign_id );
		$row['creative_updates']   = $this->creative_view->creative_update_rows( $campaign_id );
		$row['creative_slots']     = $this->creative_view->creative_slots( $campaign_id, $row['creatives'] );
		$row['variant_comparison'] = $this->delivery->variant_comparison( $this->campaigns->org_id( $campaign_id ), $campaign_id, $row['creatives'] );

		/*
		 * The campaign's own delivery, on the same card as the dashboard's.
		 * Read only once it could have delivered, and only here, after the
		 * `read_post` check above: the organization is the campaign's own,
		 * so staff opening a client's campaign see that client's figures.
		 */
		$delivers               = in_array( (string) $row['status'], self::DELIVERY_STATUSES, true );
		$row['delivery']        = $delivers ? $this->delivery->counts( $this->campaigns->org_id( $campaign_id ), null, $campaign_id ) : array();
		$row['delivery_series'] = $delivers && array() !== $row['delivery'] ? $this->delivery->series( $this->campaigns->org_id( $campaign_id ), $campaign_id ) : array();
		$row['placement_ids']   = $this->campaigns->placement_ids( $campaign_id );

		/*
		 * The link the next upload starts from: the one set for the campaign,
		 * or else the first one its ads already have. A package of three sizes
		 * nearly always sends all three to one page, so asking for the address
		 * on every card was typing the same thing three times — and a chance to
		 * mistype it twice.
		 */
		$row['default_click_url'] = $this->campaigns->default_click_url( $campaign_id );

		foreach ( '' === $row['default_click_url'] ? $row['creatives'] : array() as $creative ) {
			if ( '' !== (string) ( $creative['click_url'] ?? '' ) ) {
				$row['default_click_url'] = (string) $creative['click_url'];
				break;
			}
		}

		// Null until it is checked, and null again the moment the link changes.
		$row['link_check'] = $this->links->last( $campaign_id );
		$row['history']    = $this->history->history( $campaign_id, $this->org_id() );

		/*
		 * Whether the name is still the one the wizard invented.
		 *
		 * Recorded rather than inferred — comparing the stored title to the
		 * placeholder string breaks the moment the site language changes.
		 * The first step reads it to decide whether choosing a package may
		 * carry somebody straight on: advancing an unnamed campaign only
		 * sends them back here from review with a title error.
		 */
		$row['title_is_placeholder'] = $this->campaigns->title_is_placeholder( $campaign_id );
		$row['placement_options']    = $this->placement_options();

		$row['package_id']       = $this->campaigns->package_id( $campaign_id );
		$row['package_name']     = $row['package_id'] > 0 ? $this->packages->name( $row['package_id'] ) : '';
		$row['package_options']  = $this->package_options();
		$row['budget_cents']     = $this->campaigns->budget_cents( $campaign_id );
		$row['currency']         = $this->campaigns->currency( $campaign_id );
		$row['package_price']    = '' === $row['currency'] ? '' : $this->format_money( $row['budget_cents'], $row['currency'] );
		$row['wizard_step']      = $this->campaigns->wizard_step( $campaign_id );
		$row['start_date']       = $this->date_input_value( $this->campaigns->start_ts( $campaign_id ) );
		$row['end_date']         = $this->date_input_value( $this->campaigns->end_ts( $campaign_id ) );
		$row['min_start_date']   = $this->min_start_date( $row['start_date'] );
		$row['advertiser_notes'] = $this->campaigns->advertiser_notes( $campaign_id );
		$row['autosave_rev']     = $this->campaigns->autosave_revision( $campaign_id );
		$row['readiness']        = $this->readiness->for_campaign( $campaign_id );
		$row['editable']         = $this->window->allows( $campaign_id );
		$row['on_behalf']        = $this->window->is_on_behalf( $campaign_id );
		$this->line_items->ensure_default( $campaign_id );
		$row['line_items'] = $this->line_items->for_campaign( $campaign_id );

		// The campaign's organization, not the viewer's. Staff have none, so
		// the top bar's org name is blank for them and cannot name the client
		// whose campaign this actually is.
		$row['org_name']            = $this->orgs->name( $this->campaigns->org_id( $campaign_id ) );
		$row['can_copy']            = current_user_can( Capabilities::SUBMIT_CAMPAIGN );
		$row['copy_label']          = Post_Statuses::COMPLETE === $this->campaigns->status( $campaign_id )
			? __( 'Renew campaign', 'aggressive-ads' )
			: __( 'Duplicate campaign', 'aggressive-ads' );
		$row['can_request_updates'] = in_array( $this->campaigns->status( $campaign_id ), array( Post_Statuses::SCHEDULED, Post_Statuses::LIVE ), true );

		/*
		 * Withdrawal reopens editing, and `submitted` is the only status it
		 * runs from — a reviewer claiming the campaign moves it to `review`,
		 * so the status alone already expresses "nobody has started".
		 *
		 * Transition_Table's `unclaimed` guard is still the authority: if a
		 * reviewer claims it between this render and the click, the transition
		 * refuses and the advertiser is told. Rendering a button that can lose
		 * a race is correct here; hiding it by pre-checking the guard would
		 * only narrow the window, never close it.
		 */
		$row['can_withdraw'] = Post_Statuses::SUBMITTED === $this->campaigns->status( $campaign_id )
			&& current_user_can( Capabilities::SUBMIT_CAMPAIGN );

		/*
		 * Changes to a running campaign. `live_edit_fields` is the site's
		 * allowlist, and the template renders one input per entry — so a field
		 * the owner did not enable has no control, no label and nothing in the
		 * POST the handler will look for. Absent, not disabled.
		 */
		$row['pending_edits']       = $this->changes->pending_summary( $campaign_id );
		$row['draft_edits']         = $this->changes->draft_summary( $campaign_id );
		$row['edits_submitted']     = $this->requests->pending_edits_submitted( $campaign_id );
		$row['can_request_changes'] = $this->changes->accepts_changes( $campaign_id )
			&& current_user_can( Capabilities::SUBMIT_CAMPAIGN )
			&& ! $row['edits_submitted'];
		$row['live_edit_fields']    = $this->changes->accepts_changes( $campaign_id )
			? $this->changes->allowed_fields()
			: array();

		$row = $this->edit_view->add( $campaign_id, $row );

		$row['action_request']       = $this->requests->action_request( $campaign_id );
		$row['requestable_actions']  = array() === $row['action_request']
			? $this->action_requests->requestable_actions( $campaign_id )
			: array();
		$row['action_request_label'] = array() === $row['action_request']
			? ''
			: Campaign_Action_Requests::request_label( (string) $row['action_request']['action'] );

		$row['can_cancel']   = $this->advertiser_may_cancel( $campaign_id, $this->campaigns->status( $campaign_id ) );
		$row['cancel_label'] = Post_Statuses::DRAFT === $this->campaigns->status( $campaign_id )
			? __( 'Delete campaign', 'aggressive-ads' )
			: __( 'Cancel campaign', 'aggressive-ads' );


		return $row;
	}

	/**
	 * Whether this advertiser may end this campaign themselves.
	 *
	 * Read from Transition_Table rather than from a list of statuses kept here.
	 * The table already says an advertiser may cancel a draft, a
	 * changes-requested campaign and a scheduled one, and may *not* cancel a
	 * campaign that is already live — pulling a running advertisement is a
	 * conversation, not a button. A second copy of that policy in the portal
	 * would be a second policy, and the two would disagree within a release.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $status      Current status.
	 */
	private function advertiser_may_cancel( int $campaign_id, string $status ): bool {
		foreach ( Transition_Table::available_to( $status, Transition_Table::ACTOR_ADVERTISER ) as $transition ) {
			if ( Post_Statuses::CANCELLED !== $transition->to ) {
				continue;
			}

			foreach ( $transition->capabilities as $capability ) {
				if ( ! current_user_can( $capability, $campaign_id ) ) {
					continue 2;
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * The caller's organization, for the organization screen.
	 *
	 * @return array<string, mixed>|null Null when the caller has no organization.
	 */
	public function organization(): ?array {
		$org_id = $this->org_id();

		if ( 0 === $org_id ) {
			return null;
		}

		$campaigns  = $this->campaigns->for_org( $org_id, 1 );
		$can_manage = $this->orgs->is_owner( $org_id, get_current_user_id() )
			|| current_user_can( Capabilities::MANAGE_ORGS );

		return array(
			'id'                 => $org_id,
			'name'               => $this->orgs->name( $org_id ),
			'active'             => $this->orgs->is_active( $org_id ),
			'members'            => $this->member_rows( $org_id ),
			'campaigns'          => $campaigns['total'],
			'can_manage_members' => $can_manage,
			'pending_access'     => $can_manage ? $this->pending_access_rows( $org_id ) : array(),
		);
	}

	/**
	 * Pending rows contain only addresses already scoped to this organization.
	 *
	 * @param int $org_id Organization id.
	 * @return array<int, array{id: int, email: string, kind: string, expires_at_ts: int}>
	 */
	private function pending_access_rows( int $org_id ): array {
		$rows = array();

		foreach ( $this->org_access->pending_for_org( $org_id ) as $row ) {
			$rows[] = array(
				'id'            => (int) ( $row['id'] ?? 0 ),
				'email'         => (string) ( $row['email'] ?? '' ),
				'kind'          => (string) ( $row['kind'] ?? '' ),
				'expires_at_ts' => (int) ( $row['expires_at_ts'] ?? 0 ),
			);
		}

		return $rows;
	}

	/**
	 * Everyone in the organization, with the caller marked.
	 *
	 * Email addresses are included because these are colleagues in the same
	 * organization, which is the one group already able to see each other's
	 * campaigns. No address from outside it ever appears here.
	 *
	 * @param int $org_id Organization post id.
	 * @return array<int, array{id: int, name: string, email: string, is_you: bool, is_owner: bool}>
	 */
	private function member_rows( int $org_id ): array {
		$rows     = array();
		$owner_id = 0;
		$user_ids = $this->orgs->user_ids_for_org( $org_id );

		if ( array() !== $user_ids ) {
			// user_ids_for_org() returns the owner first, by contract.
			$owner_id = $user_ids[0];
		}

		foreach ( $user_ids as $user_id ) {
			$user = get_userdata( $user_id );

			if ( false === $user ) {
				continue;
			}

			$rows[] = array(
				'id'       => $user_id,
				'name'     => (string) $user->display_name,
				'email'    => (string) $user->user_email,
				'is_you'   => get_current_user_id() === $user_id,
				'is_owner' => $owner_id === $user_id,
			);
		}

		return $rows;
	}

	/**
	 * The caller's own account details.
	 *
	 * @return array<string, mixed>
	 */
	public function account(): array {
		$user = wp_get_current_user();

		return array(
			'id'            => (int) $user->ID,
			'login'         => (string) $user->user_login,
			'email'         => (string) $user->user_email,
			'display_name'  => (string) $user->display_name,
			'first_name'    => (string) get_user_meta( $user->ID, 'first_name', true ),
			'last_name'     => (string) get_user_meta( $user->ID, 'last_name', true ),
			'org_name'      => $this->org_name(),
			'pending_email' => $this->emails->pending_email( (int) $user->ID ),
		);
	}

	/**
	 * What the help screen explains.
	 *
	 * @return array<string, mixed>
	 */
	public function help(): array {
		return $this->catalogue()->help();
	}

	/**
	 * Active placements with the preparation details an advertiser needs.
	 *
	 * @return array<int, array{id: int, name: string, size: string}>
	 */
	public function placement_options(): array {
		return $this->catalogue()->placement_options();
	}

	/**
	 * Active, complete packages with their advertiser-facing catalogue details.
	 *
	 * @return array<int, array{id: int, name: string, duration: string, price: string, placements: array<int, string>, is_default: bool, duration_days: int, sizes: array<int, array{label: string, width: int, height: int}>}>
	 */
	public function package_options(): array {
		return $this->catalogue()->package_options();
	}

	/**
	 * Focused catalogue presenter shared by help and campaign creation.
	 *
	 * @return Catalogue_View_Data
	 */
	private function catalogue(): Catalogue_View_Data {
		return new Catalogue_View_Data( $this->placements, $this->packages, $this->editor, $this->settings );
	}

	/**
	 * Formats an integer minor-unit amount without changing stored precision.
	 *
	 * @param int    $cents    Amount in cents.
	 * @param string $currency ISO 4217 currency code.
	 * @return string
	 */
	private function format_money( int $cents, string $currency ): string {
		return Money::format( $cents, $currency );
	}

	/**
	 * Formats a stored UTC timestamp for an HTML date input in site time.
	 *
	 * @param int $timestamp UTC Unix timestamp, or zero.
	 * @return string
	 */
	private function date_input_value( int $timestamp ): string {
		return Date_Input::format( $timestamp );
	}

	/**
	 * The earliest date the start picker may offer.
	 *
	 * Today, so a campaign can begin now — but **never later than the date
	 * already stored**, because `min` is enforced by the browser before the
	 * form reaches the server at all.
	 *
	 * A campaign edited a week after it was drafted carries a start that is now
	 * in the past. A `min` above it makes the field reject its own value: the
	 * person cannot save the step, cannot reach the server's explanation, and
	 * is told only "Value must be … or later" by a tooltip with nothing behind
	 * it. The form becomes unsubmittable for a reason it will not explain.
	 *
	 * Letting the stored value through does not make a past start legal.
	 * `Campaign_Rules::validate_window()` still refuses one, with a message
	 * that says what to do — which is the difference between a dead end and an
	 * instruction.
	 *
	 * @param string $stored_start `Y-m-d` already on the campaign, or empty.
	 * @return string
	 */
	private function min_start_date( string $stored_start ): string {
		$today = (string) wp_date( 'Y-m-d', time(), wp_timezone() );

		if ( '' !== $stored_start && $stored_start < $today ) {
			return $stored_start;
		}

		return $today;
	}

	/**
	 * Native delivery totals for the caller's organization.
	 *
	 * @return array<int, array{label: string, value: string}>
	 */
	public function delivery_counts(): array {
		return $this->delivery->counts( $this->org_id() );
	}

	/**
	 * Impression series for the dashboard sparkline, over the chosen window.
	 *
	 * Not seven days, whatever this said before: the chart follows the tiles,
	 * because a page whose figures covered one window and whose chart covered
	 * another would invite a comparison that was always wrong. A docblock
	 * naming a fixed window is how someone later "fixes" the code to match it.
	 *
	 * @return list<array{day: string, label: string, date: string, impressions: int, clicks: int, height: int, previous: int, previous_clicks: int, previous_date: string}>
	 */
	public function delivery_series(): array {
		return $this->delivery->series( $this->org_id() );
	}

	/**
	 * The window the delivery tiles cover, in words.
	 */
	public function delivery_range_label(): string {
		return $this->delivery->range_label();
	}

	/**
	 * The first UTC day whose figures may still change, or '' when none may.
	 */
	public function delivery_counting_from(): string {
		return $this->delivery->unreconciled_from();
	}

	/**
	 * Which days in that window may still change, as a sentence, or ''.
	 */
	public function delivery_freshness_note(): string {
		return $this->delivery->freshness_note();
	}

	/**
	 * The chosen window, and the slice of it the export will produce.
	 *
	 * One call rather than five, because a template that fetched these
	 * separately could be edited into fetching them from different requests.
	 *
	 * @return array{from: string, to: string, days: int, rejected: bool, export_days: int, export_from: string, export_to: string}
	 */
	public function delivery_window(): array {
		$period = $this->delivery->period();
		$export = $this->delivery->export_period();

		return array(
			'from'        => $period->start,
			'to'          => $period->end,
			'days'        => $period->days,
			'rejected'    => $this->delivery->range_rejected(),
			'export_days' => $export->days,
			'export_from' => $export->start,
			'export_to'   => $export->end,
		);
	}

	/**
	 * The pill modifier a status renders with.
	 *
	 * A campaign's colour is derived from its status here, once. Deriving it in
	 * each template is how "paused" ends up green on one screen and grey on
	 * another.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function pill_for( string $status ): string {
		/*
		 * One colour per meaning, so a list reads at a glance: grey not
		 * started, amber with the review team, orange back with the
		 * advertiser, blue approved and waiting to start, green running,
		 * purple paused, slate finished, red refused or ended early. Draft and
		 * finished were two greys nobody could tell apart, and "sent back to
		 * you" wore the same amber as "being reviewed".
		 */
		return match ( $status ) {
			Post_Statuses::SUBMITTED,
			Post_Statuses::REVIEW    => 'pending',
			Post_Statuses::CHANGES   => 'attention',
			Post_Statuses::APPROVED,
			Post_Statuses::SCHEDULED => 'info',
			Post_Statuses::LIVE      => 'live',
			Post_Statuses::PAUSED    => 'paused',
			Post_Statuses::COMPLETE  => 'ended',
			Post_Statuses::REJECTED,
			Post_Statuses::CANCELLED => 'danger',
			default                  => 'neutral',
		};
	}
}
