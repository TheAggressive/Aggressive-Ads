<?php
/**
 * The campaigns list, its counts, and the campaigns waiting on the advertiser.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Domain\Campaign_Filter;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Workflow\Reporting_Read;

/**
 * Out of `View_Data`, which had reached the file-length gate, along the seam
 * `Catalogue_View_Data` already cut: a group of screens' data with its own
 * reads. Every method takes the organization rather than resolving one, so
 * the caller's tenancy is decided in one place and passed down.
 *
 * **One page is a handful of queries, not one per row.** Each row reads a
 * campaign's post and a dozen meta keys, then its package and placements by
 * id. Loaded one at a time that was about two and a half queries a row —
 * forty-nine for a page of twenty. The page's posts and meta, and then its
 * packages and placements, are loaded together before any row is built.
 */
final class Campaign_List_View_Data {

	/**
	 * Campaigns the dashboard's attention card names before "And N more".
	 */
	public const ATTENTION_SHOWN = 5;

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository  $campaigns  Campaign persistence.
	 * @param Placement_Repository $placements Placement names.
	 * @param Package_Repository   $packages   Package names.
	 * @param Reporting_Read       $reporting  All-time metrics for list rows.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Placement_Repository $placements,
		private readonly Package_Repository $packages,
		private readonly Reporting_Read $reporting
	) {
	}

	/**
	 * The caller's campaigns, ready to render.
	 *
	 * @param int    $org_id   The organization whose campaigns these are.
	 * @param int    $page     1-based page.
	 * @param string $filter   Slice to show, or '' for every campaign.
	 * @param string $search   Words to find in campaign names, or '' for none.
	 * @param int    $per_page Rows per page; the dashboard shows five.
	 * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int, show_metrics: bool}
	 */
	public function campaigns( int $org_id, int $page = 1, string $filter = '', string $search = '', int $per_page = Campaign_Repository::PAGE_SIZE ): array {
		/*
		 * Not the isolation boundary — that is the org meta_query inside
		 * for_org(), and it is there whatever this returns. This only skips a
		 * query that can only ever match nothing, and keeps the array shape
		 * constant so a template rendering during an expired session gets an
		 * empty list rather than a fatal.
		 */
		if ( 0 === $org_id ) {
			return array(
				'rows'         => array(),
				'total'        => 0,
				'pages'        => 0,
				'page'         => 1,
				'show_metrics' => $this->reporting->surfaces(),
			);
		}

		$result = $this->campaigns->for_org( $org_id, $page, Campaign_Filter::statuses( $filter ), self::search_term( $search ), $per_page );

		$this->prime( $result['ids'] );
		$rows = array();

		foreach ( $result['ids'] as $campaign_id ) {
			$rows[] = $this->row( $campaign_id );
		}

		return array(
			'rows'         => $this->reporting->attach( $rows ),
			'total'        => $result['total'],
			'pages'        => $result['pages'],
			'page'         => max( 1, $page ),
			'show_metrics' => $this->reporting->surfaces(),
		);
	}

	/**
	 * Counts worth putting on a dashboard.
	 *
	 * Campaign-by-state tiles always ship. Impression, click and CTR tiles
	 * are `delivery_counts()` and stay absent unless both reporting modules
	 * are on — a dashboard of invented zeros is worse than fewer real numbers.
	 *
	 * @param int $org_id The organization being counted.
	 * @return array<int, array{label: string, value: int, filter: string}>
	 */
	public function counts( int $org_id ): array {
		$totals = array();

		/*
		 * **Counted by the query that lists them, not from a page of rows.**
		 * These were classified from `campaigns( 1 )`, which is one page of
		 * twenty: an advertiser with a twenty-first campaign saw tiles that
		 * stopped counting while the lists they linked to kept going.
		 */
		foreach ( Campaign_Filter::all() as $filter ) {
			$totals[ $filter ] = $org_id > 0
				? $this->campaigns->count_for_org( $org_id, Campaign_Filter::statuses( $filter ) )
				: 0;
		}

		$running   = $totals[ Campaign_Filter::RUNNING ];
		$reviewing = $totals[ Campaign_Filter::IN_REVIEW ];
		$drafts    = $totals[ Campaign_Filter::ATTENTION ];

		return array(
			array(
				'label'  => $this->filter_label( Campaign_Filter::RUNNING ),
				'value'  => $running,
				'filter' => Campaign_Filter::RUNNING,
			),
			array(
				'label'  => $this->filter_label( Campaign_Filter::IN_REVIEW ),
				'value'  => $reviewing,
				'filter' => Campaign_Filter::IN_REVIEW,
			),
			array(
				'label'  => $this->filter_label( Campaign_Filter::ATTENTION ),
				'value'  => $drafts,
				'filter' => Campaign_Filter::ATTENTION,
			),
		);
	}

	/**
	 * Every campaign waiting on the advertiser, each with why and what to do.
	 *
	 * The dashboard's card named the first one only, so an advertiser with
	 * three campaigns sent back saw one reason and no sign of the others.
	 * The reason for a campaign sent back is the review team's note — the
	 * advertiser-visible one, never the internal notes — and a draft's is that
	 * it has not been submitted.
	 *
	 * @param int $org_id The organization whose campaigns wait on it.
	 * @return array{rows: list<array{id: int, title: string, url: string, reason: string, action: string}>, total: int}
	 */
	public function attention( int $org_id ): array {
		$slice = $this->campaigns( $org_id, 1, Campaign_Filter::ATTENTION, '', self::ATTENTION_SHOWN );
		$rows  = array();

		foreach ( $slice['rows'] as $row ) {
			$sent_back = Post_Statuses::CHANGES === (string) $row['status'];
			$notes     = trim( (string) ( $row['review_notes'] ?? '' ) );

			$rows[] = array(
				'id'     => (int) $row['id'],
				'title'  => (string) $row['title'],
				'url'    => (string) $row['url'],
				'reason' => $sent_back
					? ( '' !== $notes ? $notes : __( 'The review team asked for changes.', 'aggressive-ads' ) )
					: __( 'Not submitted yet.', 'aggressive-ads' ),
				'action' => $sent_back ? __( 'Make changes', 'aggressive-ads' ) : __( 'Continue setup', 'aggressive-ads' ),
			);
		}

		return array(
			'rows'  => $rows,
			'total' => (int) $slice['total'],
		);
	}

	/**
	 * A search term as the list query takes it: trimmed, and bounded.
	 *
	 * @param string $search Raw words.
	 * @return string
	 */
	public static function search_term( string $search ): string {
		return mb_substr( trim( $search ), 0, 100 );
	}

	/**
	 * What one slice is called, wherever it is named.
	 *
	 * The dashboard tile and the campaign list's filter notice name the same
	 * slice, and a reader who clicked "Needs your attention" and arrived at a
	 * page saying "Showing only: Drafts" would reasonably wonder whether they
	 * had landed somewhere else. Labels live here rather than in
	 * `Campaign_Filter` because they are translated, and `inc/Domain/` calls no
	 * WordPress function.
	 *
	 * @param string $filter Filter slug, or '' for no filter.
	 * @return string
	 */
	public function filter_label( string $filter ): string {
		return match ( $filter ) {
			Campaign_Filter::RUNNING   => __( 'Running', 'aggressive-ads' ),
			Campaign_Filter::IN_REVIEW => __( 'In review', 'aggressive-ads' ),
			Campaign_Filter::ATTENTION => __( 'Needs your attention', 'aggressive-ads' ),
			default                    => __( 'All campaigns', 'aggressive-ads' ),
		};
	}

	/**
	 * One campaign, shaped for a table row.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<string, mixed>
	 */
	public function row( int $campaign_id ): array {
		$status = $this->campaigns->status( $campaign_id );

		$names = array();

		foreach ( $this->campaigns->placement_ids( $campaign_id ) as $placement_id ) {
			$name = $this->placements->name( $placement_id );

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		$package_id = $this->campaigns->package_id( $campaign_id );

		return array(
			'id'           => $campaign_id,
			'title'        => $this->campaigns->title( $campaign_id ),
			'status'       => $status,
			'status_text'  => $this->status_label( $status ),
			'pill'         => View_Data::pill_for( $status ),
			'placements'   => $names,
			'dates'        => $this->window( $campaign_id ),
			'url'          => Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id ),

			// What a list row shows under the name, and in its own columns.
			'package'      => $package_id > 0 ? $this->packages->name( $package_id ) : '',
			'sizes'        => count( $names ),
			'schedule'     => $this->short_window( $campaign_id ),
			'review_notes' => $this->campaigns->review_notes( $campaign_id ),
		);
	}

	/**
	 * The campaign's window for a list row: short dates, in the site timezone.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	private function short_window( int $campaign_id ): string {
		$start = $this->campaigns->start_ts( $campaign_id );
		$end   = $this->campaigns->end_ts( $campaign_id );

		if ( 0 === $start ) {
			return __( 'Not scheduled', 'aggressive-ads' );
		}

		$from = (string) wp_date( 'M j', $start );

		if ( 0 === $end ) {
			return sprintf(
				/* translators: %s: campaign start date. */
				__( 'From %s', 'aggressive-ads' ),
				$from
			);
		}

		return sprintf(
			/* translators: 1: campaign start date, e.g. Sep 6. 2: campaign end date, e.g. Oct 4. */
			__( '%1$s → %2$s', 'aggressive-ads' ),
			$from,
			(string) wp_date( 'M j', $end )
		);
	}

	/**
	 * The campaign's window, in the site's own timezone and format.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	private function window( int $campaign_id ): string {
		$start = $this->campaigns->start_ts( $campaign_id );
		$end   = $this->campaigns->end_ts( $campaign_id );

		if ( 0 === $start ) {
			return __( 'Not scheduled', 'aggressive-ads' );
		}

		$format = (string) get_option( 'date_format', 'M j, Y' );
		$from   = (string) wp_date( $format, $start );

		if ( 0 === $end ) {
			return sprintf(
				/* translators: %s: campaign start date. */
				__( 'From %s', 'aggressive-ads' ),
				$from
			);
		}

		return sprintf(
			/* translators: 1: campaign start date. 2: campaign end date. */
			__( '%1$s – %2$s', 'aggressive-ads' ),
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
	 * Loads a page's campaigns, then their packages and placements, in batches.
	 *
	 * @param array<int, int> $campaign_ids The page's campaigns.
	 * @return void
	 */
	private function prime( array $campaign_ids ): void {
		if ( array() === $campaign_ids ) {
			return;
		}

		$this->campaigns->prime( $campaign_ids );

		$placement_ids = array();
		$package_ids   = array();

		foreach ( $campaign_ids as $campaign_id ) {
			$placement_ids = array_merge( $placement_ids, $this->campaigns->placement_ids( $campaign_id ) );
			$package_ids[] = $this->campaigns->package_id( $campaign_id );
		}

		$this->placements->prime( $placement_ids );
		$this->packages->prime( $package_ids );
	}
}
