<?php
/**
 * The campaign lifecycle statuses.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Core;

/**
 * Registers the eleven campaign statuses.
 *
 * Only Campaign_State_Machine::apply() ever writes one of these onto a post.
 * See docs/campaign-workflow.md and docs/adr/0008-explicit-transition-table.md.
 */
final class Post_Statuses implements Service {

	public const DRAFT     = 'lap_draft';
	public const SUBMITTED = 'lap_submitted';
	public const REVIEW    = 'lap_review';
	public const CHANGES   = 'lap_changes';
	public const APPROVED  = 'lap_approved';
	public const SCHEDULED = 'lap_scheduled';
	public const LIVE      = 'lap_live';
	public const PAUSED    = 'lap_paused';
	public const COMPLETE  = 'lap_complete';
	public const CANCELLED = 'lap_cancelled';
	public const REJECTED  = 'lap_rejected';

	/**
	 * `wp_posts.post_status` is varchar(20), same trap as post_type.
	 *
	 * This is why these eleven use the `lap_` storage prefix rather than
	 * `laao_ads_`: `laao_ads_changes_requested` is 26 characters, which would
	 * truncate on write and then never match on read, producing campaigns in
	 * no status at all. It is the only place in the codebase using `lap_`, and
	 * the inconsistency is deliberate.
	 */
	public const MAX_SLUG_LENGTH = 20;

	/**
	 * Attaches registration.
	 *
	 * Priority 5, alongside the post types — a status must exist before any
	 * query filters on it.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register' ), 5 );
	}

	/**
	 * Registers every status.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( self::registration_args() as $slug => $args ) {
			$args['label']       = self::label_for( $slug );
			$args['label_count'] = self::label_count_for( $slug );

			register_post_status( $slug, $args );
		}
	}

	/**
	 * Every status slug, in lifecycle order.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array(
			self::DRAFT,
			self::SUBMITTED,
			self::REVIEW,
			self::CHANGES,
			self::REJECTED,
			self::APPROVED,
			self::SCHEDULED,
			self::LIVE,
			self::PAUSED,
			self::COMPLETE,
			self::CANCELLED,
		);
	}

	/**
	 * Statuses with no outgoing transition.
	 *
	 * A completed campaign is duplicated into a new draft, never reopened —
	 * "renew campaign" is a copy operation, not a transition backwards.
	 *
	 * @return array<int, string>
	 */
	public static function terminal(): array {
		return array( self::COMPLETE, self::CANCELLED );
	}

	/**
	 * Statuses in which the advertiser may still edit the campaign.
	 *
	 * @return array<int, string>
	 */
	public static function advertiser_editable(): array {
		return array( self::DRAFT, self::CHANGES );
	}

	/**
	 * Statuses whose campaigns have live AdSanity objects behind them.
	 *
	 * @return array<int, string>
	 */
	public static function published(): array {
		return array( self::SCHEDULED, self::LIVE, self::PAUSED );
	}

	/**
	 * Reports whether a string is one of ours.
	 *
	 * @param string $status Candidate status.
	 * @return bool
	 */
	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	/**
	 * The register_post_status() arguments, minus labels.
	 *
	 * Free of WordPress calls and translation so the flags are unit-testable.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function registration_args(): array {
		$args = array();

		foreach ( self::all() as $slug ) {
			$args[ $slug ] = array(
				// Not public: a campaign is never a front-end URL.
				'public'                    => false,
				// Not internal either — internal statuses (auto-draft, inherit)
				// are hidden from admin list filters, and staff need to filter
				// the review queue by status.
				'internal'                  => false,
				'private'                   => false,
				// Protected keeps the post out of front-end queries while
				// leaving it visible to an authorized admin query.
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
			);
		}

		return $args;
	}

	/**
	 * The human label for a status.
	 *
	 * @param string $slug Status slug.
	 * @return string
	 */
	private static function label_for( string $slug ): string {
		return match ( $slug ) {
			self::DRAFT     => __( 'Draft', 'laao-advertiser-portal' ),
			self::SUBMITTED => __( 'Submitted', 'laao-advertiser-portal' ),
			self::REVIEW    => __( 'In Review', 'laao-advertiser-portal' ),
			self::CHANGES   => __( 'Changes Requested', 'laao-advertiser-portal' ),
			self::REJECTED  => __( 'Rejected', 'laao-advertiser-portal' ),
			self::APPROVED  => __( 'Approved', 'laao-advertiser-portal' ),
			self::SCHEDULED => __( 'Scheduled', 'laao-advertiser-portal' ),
			self::LIVE      => __( 'Live', 'laao-advertiser-portal' ),
			self::PAUSED    => __( 'Paused', 'laao-advertiser-portal' ),
			self::COMPLETE  => __( 'Completed', 'laao-advertiser-portal' ),
			default         => __( 'Cancelled', 'laao-advertiser-portal' ),
		};
	}

	/**
	 * The plural-aware count label shown in admin status lists.
	 *
	 * The shape is _n_noop()'s own: numeric keys for the singular and plural
	 * forms, plus named keys that translate_nooped_plural() reads.
	 *
	 * @param string $slug Status slug.
	 * @return array<int|string, string|null>
	 */
	private static function label_count_for( string $slug ): array {
		return match ( $slug ) {
			/* translators: %s: number of campaigns. */
			self::DRAFT     => _n_noop( 'Draft <span class="count">(%s)</span>', 'Draft <span class="count">(%s)</span>', 'laao-advertiser-portal' ),
			/* translators: %s: number of campaigns. */
			self::SUBMITTED => _n_noop( 'Submitted <span class="count">(%s)</span>', 'Submitted <span class="count">(%s)</span>', 'laao-advertiser-portal' ),
			/* translators: %s: number of campaigns. */
			self::REVIEW    => _n_noop( 'In Review <span class="count">(%s)</span>', 'In Review <span class="count">(%s)</span>', 'laao-advertiser-portal' ),
			/* translators: %s: number of campaigns. */
			self::CHANGES   => _n_noop( 'Changes Requested <span class="count">(%s)</span>', 'Changes Requested <span class="count">(%s)</span>', 'laao-advertiser-portal' ),
			/* translators: %s: number of campaigns. */
			self::REJECTED  => _n_noop( 'Rejected <span class="count">(%s)</span>', 'Rejected <span class="count">(%s)</span>', 'laao-advertiser-portal' ),
			/* translators: %s: number of campaigns. */
			self::APPROVED  => _n_noop( 'Approved <span class="count">(%s)</span>', 'Approved <span class="count">(%s)</span>', 'laao-advertiser-portal' ),
			/* translators: %s: number of campaigns. */
			self::SCHEDULED => _n_noop( 'Scheduled <span class="count">(%s)</span>', 'Scheduled <span class="count">(%s)</span>', 'laao-advertiser-portal' ),
			/* translators: %s: number of campaigns. */
			self::LIVE      => _n_noop( 'Live <span class="count">(%s)</span>', 'Live <span class="count">(%s)</span>', 'laao-advertiser-portal' ),
			/* translators: %s: number of campaigns. */
			self::PAUSED    => _n_noop( 'Paused <span class="count">(%s)</span>', 'Paused <span class="count">(%s)</span>', 'laao-advertiser-portal' ),
			/* translators: %s: number of campaigns. */
			self::COMPLETE  => _n_noop( 'Completed <span class="count">(%s)</span>', 'Completed <span class="count">(%s)</span>', 'laao-advertiser-portal' ),
			/* translators: %s: number of campaigns. */
			default         => _n_noop( 'Cancelled <span class="count">(%s)</span>', 'Cancelled <span class="count">(%s)</span>', 'laao-advertiser-portal' ),
		};
	}
}
