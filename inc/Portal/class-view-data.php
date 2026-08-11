<?php
/**
 * What the portal screens render.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Portal;

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Domain\Upload_Rules;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Repository\Creative_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Repository\Package_Repository;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;
use LAAO_Advertiser_Portal\REST\Creative_File_Controller;
use LAAO_Advertiser_Portal\Workflow\Campaign_Editor;
use LAAO_Advertiser_Portal\Workflow\Review_Readiness;

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
	 * Constructor.
	 *
	 * @param Campaign_Repository  $campaigns  Campaign persistence.
	 * @param Placement_Repository $placements Placement persistence.
	 * @param Creative_Repository  $creatives  Creative persistence.
	 * @param Org_Repository       $orgs       Organization lookups.
	 * @param Package_Repository   $packages   Package persistence.
	 * @param Campaign_Editor      $editor     Shared package validation.
	 * @param Review_Readiness     $readiness  Safe canonical review readiness.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Placement_Repository $placements,
		private readonly Creative_Repository $creatives,
		private readonly Org_Repository $orgs,
		private readonly Package_Repository $packages,
		private readonly Campaign_Editor $editor,
		private readonly Review_Readiness $readiness
	) {
	}

	/**
	 * The organization the current user is acting for, or 0.
	 *
	 * @return int
	 */
	public function org_id(): int {
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
	 * @param int $page 1-based page.
	 * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int}
	 */
	public function campaigns( int $page = 1 ): array {
		$org_id = $this->org_id();

		/*
		 * Not the isolation boundary — that is the org meta_query inside
		 * for_org(), and it is there whatever this returns. This only skips a
		 * query that can only ever match nothing, and keeps the array shape
		 * constant so a template rendering during an expired session gets an
		 * empty list rather than a fatal.
		 */
		if ( 0 === $org_id ) {
			return array(
				'rows'  => array(),
				'total' => 0,
				'pages' => 0,
				'page'  => 1,
			);
		}

		$result = $this->campaigns->for_org( $org_id, $page );
		$rows   = array();

		foreach ( $result['ids'] as $campaign_id ) {
			$rows[] = $this->campaign_row( $campaign_id );
		}

		return array(
			'rows'  => $rows,
			'total' => $result['total'],
			'pages' => $result['pages'],
			'page'  => max( 1, $page ),
		);
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

		$row = $this->campaign_row( $campaign_id );

		$row['review_notes']      = $this->campaigns->review_notes( $campaign_id );
		$row['revision']          = $this->campaigns->revision( $campaign_id );
		$row['submitted_at']      = $this->campaigns->submitted_at( $campaign_id );
		$row['creatives']         = $this->creative_rows( $campaign_id );
		$row['creative_slots']    = $this->creative_slots( $campaign_id, $row['creatives'] );
		$row['placement_ids']     = $this->campaigns->placement_ids( $campaign_id );
		$row['placement_options'] = $this->placement_options();
		$row['package_id']        = $this->campaigns->package_id( $campaign_id );
		$row['package_name']      = $row['package_id'] > 0 ? $this->packages->name( $row['package_id'] ) : '';
		$row['package_options']   = $this->package_options();
		$row['budget_cents']      = $this->campaigns->budget_cents( $campaign_id );
		$row['currency']          = $this->campaigns->currency( $campaign_id );
		$row['package_price']     = '' === $row['currency'] ? '' : $this->format_money( $row['budget_cents'], $row['currency'] );
		$row['wizard_step']       = $this->campaigns->wizard_step( $campaign_id );
		$row['start_date']        = $this->date_input_value( $this->campaigns->start_ts( $campaign_id ) );
		$row['end_date']          = $this->date_input_value( $this->campaigns->end_ts( $campaign_id ) );
		$row['advertiser_notes']  = $this->campaigns->advertiser_notes( $campaign_id );
		$row['autosave_rev']      = $this->campaigns->autosave_revision( $campaign_id );
		$row['readiness']         = $this->readiness->for_campaign( $campaign_id );
		$row['editable']          = in_array( $this->campaigns->status( $campaign_id ), Post_Statuses::advertiser_editable(), true );

		return $row;
	}

	/**
	 * The caller's organization, for the organization screen.
	 *
	 * Read-only by design. Renaming an organization, inviting a colleague and
	 * removing one are Phase 8, and each needs an authorization answer this
	 * screen does not have yet — "may this member remove that member?" is not
	 * the same question as "may they see the portal?".
	 *
	 * @return array<string, mixed>|null Null when the caller has no organization.
	 */
	public function organization(): ?array {
		$org_id = $this->org_id();

		if ( 0 === $org_id ) {
			return null;
		}

		$campaigns = $this->campaigns->for_org( $org_id, 1 );

		return array(
			'id'        => $org_id,
			'name'      => $this->orgs->name( $org_id ),
			'active'    => $this->orgs->is_active( $org_id ),
			'members'   => $this->member_rows( $org_id ),
			'campaigns' => $campaigns['total'],
		);
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
			'id'           => (int) $user->ID,
			'login'        => (string) $user->user_login,
			'email'        => (string) $user->user_email,
			'display_name' => (string) $user->display_name,
			'first_name'   => (string) get_user_meta( $user->ID, 'first_name', true ),
			'last_name'    => (string) get_user_meta( $user->ID, 'last_name', true ),
			'org_name'     => $this->org_name(),
		);
	}

	/**
	 * What the help screen explains, derived rather than written down twice.
	 *
	 * The status glossary comes from the registered statuses and the creative
	 * limits from Domain\Upload_Rules, so a rule change updates the help text
	 * by itself. Help that is maintained by hand is help that is wrong, and
	 * wrong help costs more than none because people act on it.
	 *
	 * @return array<string, mixed>
	 */
	public function help(): array {
		$statuses = array();

		foreach ( Post_Statuses::all() as $status ) {
			$object = get_post_status_object( $status );

			$statuses[] = array(
				'label'       => null === $object ? $status : (string) $object->label,
				'pill'        => self::pill_for( $status ),
				'description' => self::status_description( $status ),
			);
		}

		/*
		 * Formats, not extensions. Listing ALLOWED_EXTENSIONS shows "JPG, JPEG"
		 * — one format twice — which reads like the page does not know what it
		 * is talking about. The MIME allowlist is the same rule expressed once
		 * per format, and it is still derived rather than retyped here.
		 */
		$labels = array(
			'image/jpeg' => 'JPEG',
			'image/png'  => 'PNG',
			'image/gif'  => 'GIF',
			'image/webp' => 'WebP',
		);

		$types = array();

		/*
		 * No fallback, deliberately. ALLOWED_MIME is a closed set, so PHPStan
		 * proves this lookup total — which means adding a format to the rules
		 * without naming it here fails static analysis rather than shipping a
		 * help screen that describes the wrong thing. A default arm would only
		 * have hidden that.
		 */
		foreach ( Upload_Rules::ALLOWED_MIME as $mime ) {
			$types[] = $labels[ $mime ];
		}

		return array(
			'statuses'   => $statuses,
			'placements' => $this->placement_options(),
			'max_size'   => size_format( Upload_Rules::MAX_BYTES ),
			'file_types' => array_values( array_unique( $types ) ),
			'contact'    => (string) get_option( 'admin_email', '' ),
		);
	}

	/**
	 * What a status means to the advertiser reading it.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	private static function status_description( string $status ): string {
		return match ( $status ) {
			Post_Statuses::DRAFT     => __( 'Yours to edit. Nobody else can see it yet.', 'laao-advertiser-portal' ),
			Post_Statuses::SUBMITTED => __( 'Waiting for the review team. You can still withdraw it until someone starts reviewing.', 'laao-advertiser-portal' ),
			Post_Statuses::REVIEW    => __( 'Someone is reviewing it now.', 'laao-advertiser-portal' ),
			Post_Statuses::CHANGES   => __( 'The review team has asked for changes. Edit it and submit again.', 'laao-advertiser-portal' ),
			Post_Statuses::REJECTED  => __( 'Not approved. The reason is on the campaign.', 'laao-advertiser-portal' ),
			Post_Statuses::APPROVED  => __( 'Approved, and it will start on its scheduled date.', 'laao-advertiser-portal' ),
			Post_Statuses::SCHEDULED => __( 'Ready and waiting for its start date.', 'laao-advertiser-portal' ),
			Post_Statuses::LIVE      => __( 'Being shown on the site right now.', 'laao-advertiser-portal' ),
			Post_Statuses::PAUSED    => __( 'Temporarily not being shown. Get in touch if this is unexpected.', 'laao-advertiser-portal' ),
			Post_Statuses::COMPLETE  => __( 'Finished. Duplicate it to run the campaign again.', 'laao-advertiser-portal' ),
			default                  => __( 'Cancelled and no longer running.', 'laao-advertiser-portal' ),
		};
	}

	/**
	 * Active placements with the preparation details an advertiser needs.
	 *
	 * @return array<int, array{id: int, name: string, size: string}>
	 */
	public function placement_options(): array {
		$options = array();

		foreach ( $this->placements->active_ids() as $placement_id ) {
			$options[] = array(
				'id'   => $placement_id,
				'name' => $this->placements->name( $placement_id ),
				'size' => $this->placements->size( $placement_id ),
			);
		}

		return $options;
	}

	/**
	 * Active, complete packages with their advertiser-facing catalogue details.
	 *
	 * @return array<int, array{id: int, name: string, duration: string, price: string, placements: array<int, string>}>
	 */
	public function package_options(): array {
		$options = array();

		foreach ( $this->packages->active_ids() as $package_id ) {
			$snapshot = $this->editor->package_snapshot( $package_id );

			if ( is_wp_error( $snapshot ) ) {
				continue;
			}

			$placement_names = array();

			foreach ( $snapshot['placement_ids'] as $placement_id ) {
				$name = $this->placements->name( $placement_id );
				$size = $this->placements->size( $placement_id );

				$placement_names[] = '' === $size ? $name : sprintf( '%1$s (%2$s px)', $name, $size );
			}

			$duration = $this->packages->duration_days( $package_id );

			$options[] = array(
				'id'         => $package_id,
				'name'       => $this->packages->name( $package_id ),
				'duration'   => sprintf(
					/* translators: %s: number of days. */
					_n( '%s day', '%s days', $duration, 'laao-advertiser-portal' ),
					number_format_i18n( $duration )
				),
				'price'      => $this->format_money( $snapshot['budget_cents'], $snapshot['currency'] ),
				'placements' => $placement_names,
			);
		}

		return $options;
	}

	/**
	 * Formats an integer minor-unit amount without changing stored precision.
	 *
	 * @param int    $cents    Amount in cents.
	 * @param string $currency ISO 4217 currency code.
	 * @return string
	 */
	private function format_money( int $cents, string $currency ): string {
		return sprintf( '%1$s %2$s', $currency, number_format_i18n( $cents / 100, 2 ) );
	}

	/**
	 * Formats a stored UTC timestamp for an HTML date input in site time.
	 *
	 * @param int $timestamp UTC Unix timestamp, or zero.
	 * @return string
	 */
	private function date_input_value( int $timestamp ): string {
		return $timestamp > 0 ? (string) wp_date( 'Y-m-d', $timestamp, wp_timezone() ) : '';
	}

	/**
	 * The campaign's creatives, shaped for display.
	 *
	 * No file path, no storage token and no checksum: those describe where the
	 * bytes live on disk, and a private-storage path is not something a browser
	 * ever needs to be told.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, array{id: int, placement_id: int, placement: string, size: string, dimensions: string, click_url: string, alt_text: string, approved: bool, name: string, bytes: int, preview: string}>
	 */
	private function creative_rows( int $campaign_id ): array {
		$rows = array();

		foreach ( $this->creatives->for_campaign( $campaign_id ) as $creative ) {
			$stored = $this->creatives->storage_details( $creative['id'] );

			$rows[] = array(
				'id'           => $creative['id'],
				'placement_id' => $creative['placement_id'],
				'placement'    => $this->placements->name( $creative['placement_id'] ),
				'size'         => $creative['size'],
				'dimensions'   => $creative['width'] > 0 && $creative['height'] > 0
					? $creative['width'] . '×' . $creative['height']
					: '',
				'click_url'    => $creative['click_url'],
				'alt_text'     => $creative['alt_text'],
				'approved'     => $this->creatives->has_attachment( $creative['id'] ),
				'name'         => null === $stored ? '' : $stored['name'],
				'bytes'        => null === $stored ? 0 : $stored['bytes'],
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
	 * Selected placements paired with any creative already covering them.
	 *
	 * @param int                              $campaign_id Campaign post id.
	 * @param array<int, array<string, mixed>> $creatives   Render-ready creative rows.
	 * @return array<int, array{id: int, name: string, size: string, active: bool, creatives: array<int, array<string, mixed>>}>
	 */
	private function creative_slots( int $campaign_id, array $creatives ): array {
		$slots = array();

		foreach ( $this->campaigns->placement_ids( $campaign_id ) as $placement_id ) {
			$matching = array();

			foreach ( $creatives as $creative ) {
				if ( (int) ( $creative['placement_id'] ?? 0 ) === $placement_id ) {
					$matching[] = $creative;
				}
			}

			$slots[] = array(
				'id'        => $placement_id,
				'name'      => $this->placements->name( $placement_id ),
				'size'      => $this->placements->size( $placement_id ),
				'active'    => $this->placements->is_active( $placement_id ),
				'creatives' => $matching,
			);
		}

		return $slots;
	}

	/**
	 * Counts worth putting on a dashboard.
	 *
	 * Only what the plugin actually knows. Impressions, clicks and spend are a
	 * later phase and there is no data behind them — a dashboard showing
	 * invented business figures is worse than one showing fewer real ones.
	 *
	 * @return array<int, array{label: string, value: int}>
	 */
	public function counts(): array {
		$campaigns = $this->campaigns( 1 );

		$running   = 0;
		$reviewing = 0;
		$drafts    = 0;

		foreach ( $campaigns['rows'] as $row ) {
			$status = (string) $row['status'];

			if ( in_array( $status, Post_Statuses::published(), true ) ) {
				++$running;

				continue;
			}

			if ( in_array( $status, array( Post_Statuses::SUBMITTED, Post_Statuses::REVIEW ), true ) ) {
				++$reviewing;

				continue;
			}

			if ( in_array( $status, Post_Statuses::advertiser_editable(), true ) ) {
				++$drafts;
			}
		}

		return array(
			array(
				'label' => __( 'Running', 'laao-advertiser-portal' ),
				'value' => $running,
			),
			array(
				'label' => __( 'In review', 'laao-advertiser-portal' ),
				'value' => $reviewing,
			),
			array(
				'label' => __( 'Needs your attention', 'laao-advertiser-portal' ),
				'value' => $drafts,
			),
		);
	}

	/**
	 * One campaign, shaped for a table row.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<string, mixed>
	 */
	private function campaign_row( int $campaign_id ): array {
		$status = $this->campaigns->status( $campaign_id );

		$names = array();

		foreach ( $this->campaigns->placement_ids( $campaign_id ) as $placement_id ) {
			$name = $this->placements->name( $placement_id );

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return array(
			'id'          => $campaign_id,
			'title'       => $this->campaigns->title( $campaign_id ),
			'status'      => $status,
			'status_text' => $this->status_label( $status ),
			'pill'        => self::pill_for( $status ),
			'placements'  => $names,
			'dates'       => $this->window( $campaign_id ),
			'url'         => Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id ),
		);
	}

	/**
	 * The campaign's window, in the site's own timezone and format.
	 *
	 * Formatted with wp_date() rather than date(): the stored values are UTC
	 * integers, and the reader is not in UTC.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	private function window( int $campaign_id ): string {
		$start = $this->campaigns->start_ts( $campaign_id );
		$end   = $this->campaigns->end_ts( $campaign_id );

		if ( 0 === $start ) {
			return __( 'Not scheduled', 'laao-advertiser-portal' );
		}

		$format = (string) get_option( 'date_format', 'M j, Y' );
		$from   = (string) wp_date( $format, $start );

		if ( 0 === $end ) {
			return sprintf(
				/* translators: %s: campaign start date. */
				__( 'From %s', 'laao-advertiser-portal' ),
				$from
			);
		}

		return sprintf(
			/* translators: 1: campaign start date. 2: campaign end date. */
			__( '%1$s – %2$s', 'laao-advertiser-portal' ),
			$from,
			(string) wp_date( $format, $end )
		);
	}

	/**
	 * The status's human label, from the registered status itself.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	private function status_label( string $status ): string {
		$object = get_post_status_object( $status );

		return null === $object ? $status : (string) $object->label;
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
		if ( in_array( $status, Post_Statuses::published(), true ) ) {
			return Post_Statuses::PAUSED === $status ? 'pending' : 'live';
		}

		return match ( $status ) {
			Post_Statuses::APPROVED  => 'live',
			Post_Statuses::SUBMITTED,
			Post_Statuses::REVIEW,
			Post_Statuses::CHANGES   => 'pending',
			Post_Statuses::COMPLETE  => 'ended',
			Post_Statuses::REJECTED,
			Post_Statuses::CANCELLED => 'danger',
			default                  => 'neutral',
		};
	}
}
