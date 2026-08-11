<?php
/**
 * Advertiser campaign creation and draft editing.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Workflow;

use LAAO_Advertiser_Portal\Audit\Audit_Event;
use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Domain\Campaign_Rules;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Repository\Creative_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Repository\Package_Repository;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;
use LAAO_Advertiser_Portal\Security\Capabilities;
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
	 * @var array<int, string>
	 */
	public const WIZARD_STEPS = array( 'details', 'package', 'creative', 'destination', 'review' );

	/**
	 * Displayable wizard steps, including query-only final confirmation.
	 *
	 * @var array<int, string>
	 */
	public const DISPLAY_STEPS = array( 'details', 'package', 'creative', 'destination', 'review', 'submit' );

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository  $campaigns  Campaign persistence.
	 * @param Org_Repository       $orgs       Organization resolution.
	 * @param Package_Repository   $packages   Package validation.
	 * @param Placement_Repository $placements Placement validation.
	 * @param Creative_Repository  $creatives  Creative coverage validation.
	 * @param Audit_Repository     $audit      Audit persistence.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Org_Repository $orgs,
		private readonly Package_Repository $packages,
		private readonly Placement_Repository $placements,
		private readonly Creative_Repository $creatives,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Creates a draft for the current user's organization.
	 *
	 * @param string $title Optional initial title.
	 * @return int|WP_Error
	 */
	public function create( string $title = '' ): int|WP_Error {
		if ( ! current_user_can( Capabilities::SUBMIT_CAMPAIGN ) || ! current_user_can( 'create_laao_ads_campaigns' ) ) {
			return $this->error( 'laao_ads_forbidden', __( 'You do not have permission to create a campaign.', 'laao-advertiser-portal' ), 403 );
		}

		$user_id = get_current_user_id();
		$org_ids = $this->orgs->org_ids_for_user( $user_id );
		$org_id  = array() === $org_ids ? 0 : $org_ids[0];

		if ( $org_id <= 0 ) {
			return $this->error( 'laao_ads_organization_missing', __( 'Your account is not connected to an organization.', 'laao-advertiser-portal' ), 409 );
		}

		if ( ! $this->orgs->is_active( $org_id ) ) {
			return $this->error( 'laao_ads_organization_inactive', __( 'This organization cannot create campaigns. Please get in touch.', 'laao-advertiser-portal' ), 403 );
		}

		$title = $this->clean_title( $title );

		if ( mb_strlen( $title ) > self::MAX_TITLE_LENGTH ) {
			return $this->error( 'laao_ads_title_too_long', __( 'Use 160 characters or fewer for the campaign name.', 'laao-advertiser-portal' ), 422, 'title' );
		}

		if ( '' === $title ) {
			$title = __( 'Untitled campaign', 'laao-advertiser-portal' );
		}

		$campaign_id = $this->campaigns->create_draft( $org_id, $user_id, $title );

		if ( is_wp_error( $campaign_id ) ) {
			return $this->error( 'laao_ads_campaign_not_created', __( 'The campaign could not be created. Please try again.', 'laao-advertiser-portal' ), 500 );
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'campaign.created',
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $org_id,
				to_state: Post_Statuses::DRAFT,
				message: 'Campaign draft created.',
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
				'laao_ads_edit_conflict',
				__( 'This campaign changed in another window. Reload it before saving again.', 'laao-advertiser-portal' ),
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
				'laao_ads_edit_conflict',
				__( 'This campaign changed in another window. Reload it before saving again.', 'laao-advertiser-portal' ),
				array(
					'status'      => 409,
					'current_rev' => $this->campaigns->autosave_revision( $campaign_id ),
				)
			);
		}

		$saved = $this->campaigns->update_draft( $campaign_id, $clean );

		if ( is_wp_error( $saved ) ) {
			return $this->error( 'laao_ads_campaign_not_saved', __( 'The campaign could not be saved. Please try again.', 'laao-advertiser-portal' ), 500 );
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'campaign.draft_updated',
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: 'Campaign draft updated.',
				context: array( 'fields' => array_keys( $clean ) ),
				actor_user_id: get_current_user_id()
			)
		);

		return $revision;
	}

	/**
	 * Completes the destination-and-schedule step with submission-grade dates.
	 *
	 * Ordinary draft autosave may retain an unset or stale schedule. Completing
	 * this wizard step is a stronger promise, so it applies the same date-window
	 * rule used at submission and advances the durable resume point to review.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $start_ts    Start of the selected local day, as UTC Unix time.
	 * @param int $end_ts      End of the selected local day, or zero for open-ended.
	 * @param int $expected_rev Client's last-seen revision.
	 * @return int|WP_Error New autosave revision on success.
	 */
	public function save_schedule( int $campaign_id, int $start_ts, int $end_ts, int $expected_rev ): int|WP_Error {
		return $this->save(
			$campaign_id,
			array(
				'start_ts'    => $start_ts,
				'end_ts'      => $end_ts,
				'wizard_step' => 'review',
			),
			$expected_rev
		);
	}

	/**
	 * Whether the caller may edit the named campaign now.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	private function authorize_edit( int $campaign_id ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::SUBMIT_CAMPAIGN ) || ! $this->campaigns->exists( $campaign_id ) || ! current_user_can( 'edit_laao_ads_campaign', $campaign_id ) ) {
			return $this->error( 'laao_ads_forbidden', __( 'You do not have permission to edit that campaign.', 'laao-advertiser-portal' ), 403 );
		}

		if ( ! in_array( $this->campaigns->status( $campaign_id ), Post_Statuses::advertiser_editable(), true ) ) {
			return $this->error( 'laao_ads_campaign_not_editable', __( 'This campaign cannot be changed right now.', 'laao-advertiser-portal' ), 409 );
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
				return $this->error( 'laao_ads_title_required', __( 'Enter a campaign name.', 'laao-advertiser-portal' ), 422, 'title' );
			}

			if ( mb_strlen( $title ) > self::MAX_TITLE_LENGTH ) {
				return $this->error( 'laao_ads_title_too_long', __( 'Use 160 characters or fewer for the campaign name.', 'laao-advertiser-portal' ), 422, 'title' );
			}

			$clean['title'] = $title;
		}

		if ( array_key_exists( 'placement_ids', $fields ) ) {
			$ids = is_array( $fields['placement_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', $fields['placement_ids'] ) ) ) ) : array();

			foreach ( $ids as $placement_id ) {
				if ( ! $this->placements->is_active( $placement_id ) ) {
					return $this->error( 'laao_ads_placement_unavailable', __( 'One of the selected placements is not available.', 'laao-advertiser-portal' ), 422, 'placement_ids' );
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

		if ( 0 !== $end && ( 0 === $start || $end <= $start ) ) {
			return $this->error( 'laao_ads_end_before_start', __( 'The end date must be after the start date.', 'laao-advertiser-portal' ), 422, 'end_ts' );
		}

		if ( array_key_exists( 'advertiser_notes', $fields ) ) {
			$clean['advertiser_notes'] = sanitize_textarea_field( (string) $fields['advertiser_notes'] );
		}

		if ( array_key_exists( 'wizard_step', $fields ) ) {
			$step = sanitize_key( (string) $fields['wizard_step'] );

			if ( ! in_array( $step, self::WIZARD_STEPS, true ) ) {
				return $this->error( 'laao_ads_wizard_step_invalid', __( 'That campaign step is not valid.', 'laao-advertiser-portal' ), 422, 'wizard_step' );
			}

			$clean['wizard_step'] = $step;
		}

		if ( 'review' === ( $clean['wizard_step'] ?? '' ) ) {
			$ready = $this->validate_schedule_completion( $campaign_id, $start, $end );

			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
		}

		return $clean;
	}

	/**
	 * Applies the additional invariants required to leave wizard Step 4.
	 *
	 * Called only after campaign authorization and optimistic revision checks,
	 * so coverage failures cannot reveal whether another tenant's object exists.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $start_ts    Candidate start timestamp.
	 * @param int $end_ts      Candidate end timestamp.
	 * @return true|WP_Error
	 */
	private function validate_schedule_completion( int $campaign_id, int $start_ts, int $end_ts ): bool|WP_Error {
		$placement_ids = $this->campaigns->placement_ids( $campaign_id );
		$creative_rows = $this->creatives->for_campaign( $campaign_id );
		$coverage      = array();

		foreach ( $creative_rows as $creative ) {
			$placement_id              = $creative['placement_id'];
			$coverage[ $placement_id ] = ( $coverage[ $placement_id ] ?? 0 ) + 1;
		}

		if ( array() === $placement_ids || count( $creative_rows ) !== count( $placement_ids ) ) {
			return $this->error( 'laao_ads_creatives_incomplete', __( 'Upload one creative for every package placement before scheduling.', 'laao-advertiser-portal' ), 422, 'creatives' );
		}

		foreach ( $placement_ids as $placement_id ) {
			if ( 1 !== ( $coverage[ $placement_id ] ?? 0 ) ) {
				return $this->error( 'laao_ads_creatives_incomplete', __( 'Upload one creative for every package placement before scheduling.', 'laao-advertiser-portal' ), 422, 'creatives' );
			}
		}

		$window = Campaign_Rules::validate_window( $start_ts, $end_ts, time() );

		if ( $window->is_valid() ) {
			return true;
		}

		$problem = $window->problems()[0];
		$code    = match ( $problem['code'] ) {
			Campaign_Rules::ERROR_START_MISSING    => 'laao_ads_start_date_required',
			Campaign_Rules::ERROR_START_IN_PAST    => 'laao_ads_start_date_past',
			Campaign_Rules::ERROR_END_BEFORE_START => 'laao_ads_end_before_start',
			default                                => 'laao_ads_schedule_invalid',
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

		if ( ! $this->packages->is_active( $package_id ) || ! current_user_can( 'read_laao_ads_package', $package_id ) ) {
			return $this->error( 'laao_ads_package_unavailable', __( 'That package is not available.', 'laao-advertiser-portal' ), 422, 'package_id' );
		}

		$placement_ids = $this->packages->placement_ids( $package_id );
		$duration_days = $this->packages->duration_days( $package_id );
		$price_cents   = $this->packages->price_cents( $package_id );
		$currency      = $this->packages->currency( $package_id );

		if ( array() === $placement_ids || $duration_days <= 0 || $price_cents < 0 || 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return $this->error( 'laao_ads_package_misconfigured', __( 'That package is not configured completely. Please choose another package or get in touch.', 'laao-advertiser-portal' ), 422, 'package_id' );
		}

		foreach ( $placement_ids as $placement_id ) {
			if ( ! $this->placements->is_active( $placement_id ) || null === Campaign_Rules::parse_size( $this->placements->size( $placement_id ) ) ) {
				return $this->error( 'laao_ads_package_misconfigured', __( 'That package includes a placement that is not available. Please choose another package or get in touch.', 'laao-advertiser-portal' ), 422, 'package_id' );
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
