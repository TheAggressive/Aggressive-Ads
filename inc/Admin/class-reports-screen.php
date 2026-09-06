<?php
/**
 * Where a publisher finds out why a slot is empty.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Reporting_Read;

/**
 * The first reader P13's decision counters have ever had.
 *
 * Server-rendered, in core's own admin markup, and working with JavaScript
 * switched off. Nothing on this screen needs a client runtime: it is a filter,
 * two figures and a table, and a bundle would add a build artefact, a loading
 * state and an accessibility surface to maintain in exchange for nothing.
 *
 * **Read-only, so the filter is a GET.** There is no nonce because there is
 * nothing to forge — no state changes, and every value is validated against a
 * closed list before it reaches a query. A nonce on a link a publisher wants to
 * bookmark and send to a colleague would break the one thing that makes a
 * report shareable.
 */
final class Reports_Screen implements Service {

	public const MENU_SLUG = 'aggr-reports';

	/**
	 * Constructor.
	 *
	 * @param Report_Data $data Fill figures and their labels.
	 */
	public function __construct( private readonly Report_Data $data ) {
	}

	/**
	 * Attaches the menu.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Registers a capability-owned submenu under Advertising.
	 */
	public function register_menu(): void {
		add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Reports', 'aggressive-ads' ),
			__( 'Reports', 'aggressive-ads' ),
			Capabilities::VIEW_REPORTS,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the authorized report.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW_REPORTS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'aggressive-ads' ),
				'',
				array( 'response' => 403 )
			);
		}

		// `wrap aggr-admin` is what every staff screen here carries: `wrap` for
		// core's margins, `aggr-admin` to scope the plugin's design tokens and
		// to give the browser suite one selector to axe.
		echo '<div class="wrap aggr-admin">';
		printf( '<h1>%s</h1>', esc_html__( 'Advertising reports', 'aggressive-ads' ) );

		if ( ! $this->data->surfaces() ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div></div>',
				esc_html__( 'Reporting is switched off for this site. Turn it on under Advertising → Settings → Modules to see delivery figures here.', 'aggressive-ads' )
			);

			return;
		}

		$days      = $this->requested_days();
		$placement = $this->requested_placement();
		$period    = $this->data->period( $days );
		$fill      = $this->data->fill( $period, $placement );

		$this->render_filter( $period->days, $placement );
		$this->render_summary( $period, $fill );
		$this->render_reasons( $fill );
		$this->render_utilisation( $this->data->utilisation( $period ) );
		$this->render_export( $period, $placement );

		echo '</div>';
	}

	/**
	 * The download, beside the figures it contains.
	 *
	 * A POST rather than a link: it is a nonce-protected action, and a GET
	 * download is a URL a browser or a prefetcher may follow on its own. The
	 * button names the number of days it will actually produce, which is not
	 * always the number on screen — the export assembles its whole document in
	 * memory and caps tighter than a read does.
	 *
	 * @param Report_Period $period    Window on screen.
	 * @param int           $placement Placement filter, or 0.
	 * @return void
	 */
	private function render_export( Report_Period $period, int $placement ): void {
		$days = min( $period->days, Report_Export::MAX_DAYS );

		printf(
			'<form method="post" action="%1$s"><input type="hidden" name="action" value="%2$s"><input type="hidden" name="days" value="%3$d"><input type="hidden" name="placement" value="%4$d">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( Report_Export::EXPORT_ACTION ),
			(int) $period->days,
			(int) $placement
		);

		// Printed by core rather than returned into the markup above: its output
		// is already escaped, and passing it through a printf argument is
		// indistinguishable to the escaping sniff from passing anything else.
		wp_nonce_field( Report_Export::EXPORT_ACTION );

		printf(
			'<button type="submit" class="button">%s</button></form>',
			esc_html(
				sprintf(
					/* translators: %d: number of days the download will cover. */
					_n( 'Download %d day (CSV)', 'Download %d days (CSV)', $days, 'aggressive-ads' ),
					$days
				)
			)
		);
	}

	/**
	 * The requested window, or 0 when none was asked for.
	 *
	 * Validation is `Report_Data::period()`'s; this only reads the parameter.
	 */
	private function requested_days(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter on a capability-gated screen; the value is validated against a closed list before it reaches a query.
		return isset( $_GET['days'] ) ? absint( wp_unslash( $_GET['days'] ) ) : 0;
	}

	/**
	 * The requested placement, or 0 for the whole site.
	 *
	 * An id that does not exist reads as zero rather than as an error: the
	 * counters are keyed by placement id and a stale bookmark should show the
	 * site, not a failure.
	 */
	private function requested_placement(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter on a capability-gated screen; the id is checked against the catalogue below.
		$id = isset( $_GET['placement'] ) ? absint( wp_unslash( $_GET['placement'] ) ) : 0;

		foreach ( $this->data->placements() as $placement ) {
			if ( $placement['id'] === $id ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * The window and placement controls.
	 *
	 * @param int $days      Current window.
	 * @param int $placement Current placement, or 0.
	 * @return void
	 */
	private function render_filter( int $days, int $placement ): void {
		echo '<form method="get" action="">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::MENU_SLUG ) );

		printf(
			'<label for="aggr-report-days" class="screen-reader-text">%s</label>',
			esc_html__( 'Reporting window', 'aggressive-ads' )
		);
		echo '<select name="days" id="aggr-report-days">';

		foreach ( Report_Data::WINDOWS as $option ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $option,
				selected( $option, $days, false ),
				esc_html(
					sprintf(
						/* translators: %d: number of days. */
						_n( 'Last %d day (UTC)', 'Last %d days (UTC)', (int) $option, 'aggressive-ads' ),
						(int) $option
					)
				)
			);
		}

		echo '</select> ';

		printf(
			'<label for="aggr-report-placement" class="screen-reader-text">%s</label>',
			esc_html__( 'Placement', 'aggressive-ads' )
		);
		echo '<select name="placement" id="aggr-report-placement">';
		printf(
			'<option value="0"%1$s>%2$s</option>',
			selected( 0, $placement, false ),
			esc_html__( 'Every placement', 'aggressive-ads' )
		);

		foreach ( $this->data->placements() as $option ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $option['id'],
				selected( (int) $option['id'], $placement, false ),
				esc_html( (string) $option['name'] )
			);
		}

		echo '</select> ';
		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Show', 'aggressive-ads' ) );
		echo '</form>';
	}

	/**
	 * Requests, fills and the rate between them.
	 *
	 * @param Report_Period                                                                                                                                                                                                                           $period Window reported.
	 * @param array{requests: int, fills: int, fill_rate: float|null, unaccounted: int, reasons: list<array<string, mixed>>, refresh: array{requests: int, fills: int, fill_rate: float|null, unaccounted: int, reasons: list<array<string, mixed>>}} $fill Figures.
	 * @return void
	 */
	private function render_summary( Report_Period $period, array $fill ): void {
		printf(
			'<p><strong>%1$s</strong> &ndash; <strong>%2$s</strong> %3$s</p>',
			esc_html( $period->start ),
			esc_html( $period->end ),
			esc_html__( '(UTC)', 'aggressive-ads' )
		);

		$note = $this->data->freshness_note( $period );

		if ( '' !== $note ) {
			printf( '<p class="description">%s</p>', esc_html( $note ) );
		}

		/*
		 * Two cards, not six lines.
		 *
		 * Page and refresh are different kinds of inventory, and keeping them
		 * apart is the whole point of the grain this phase defined — so the
		 * layout says so, rather than interleaving them in one list where the
		 * fill rate reads as the fourth sentence of a paragraph. It is the
		 * number this screen exists to deliver.
		 *
		 * `postbox` is WordPress's own card and `aggr-card-grid` was already in
		 * the stylesheet waiting for a screen. Nothing is invented here, which
		 * is what keeps this inside what `admin-native.css` is willing to own.
		 */
		echo '<div id="poststuff"><div class="aggr-card-grid">';

		$this->render_kind_card(
			__( 'Page', 'aggressive-ads' ),
			$fill,
			__( 'of page requests filled', 'aggressive-ads' ),
			__( 'No page requests in this window.', 'aggressive-ads' )
		);

		$this->render_kind_card(
			__( 'Refresh', 'aggressive-ads' ),
			$fill['refresh'],
			__( 'of refresh requests filled', 'aggressive-ads' ),
			__( 'No refreshes in this window.', 'aggressive-ads' )
		);

		echo '</div></div>';
	}

	/**
	 * One inventory kind, as a card.
	 *
	 * The rate leads because it is the question — "is my inventory selling" —
	 * and the counts it came from sit under it, because a rate with no
	 * denominator on screen is a number nobody can check.
	 *
	 * When nothing was requested there is no figure at all, only the sentence
	 * saying so. Nothing was requested and a placement nobody asked for did not
	 * fail to fill — but an em dash at figure size renders as a bar, so the
	 * absence is written out instead of symbolised.
	 *
	 * @param string                                                  $heading Kind name.
	 * @param array{requests: int, fills: int, fill_rate: float|null} $figures Figures for that kind.
	 * @param string                                                  $caption Caption under the rate.
	 * @param string                                                  $none    Sentence shown when nothing was requested.
	 * @return void
	 */
	private function render_kind_card( string $heading, array $figures, string $caption, string $none ): void {
		$requests = (int) $figures['requests'];
		$rate     = $figures['fill_rate'];

		printf(
			'<div class="postbox"><div class="postbox-header"><h2 class="hndle">%s</h2></div><div class="inside">',
			esc_html( $heading )
		);

		if ( null === $rate ) {
			/*
			 * No figure at all when there is nothing to report.
			 *
			 * An em dash in the figure slot renders as a thick horizontal bar
			 * that reads as a redaction mark — seen in a rendering, and not
			 * fixed by making it lighter. An absence does not need a
			 * placeholder glyph; it needs a sentence, and the sentence is the
			 * one this card would have captioned the figure with anyway.
			 */
			printf( '<p class="aggr-figure--none">%s</p>', esc_html( $none ) );
		} else {
			printf( '<p class="aggr-figure">%s</p>', esc_html( $this->rate( $rate ) ) );
			printf( '<p class="aggr-figure__caption">%s</p>', esc_html( $caption ) );
		}

		printf(
			'<p class="aggr-figure__detail">%1$s<br>%2$s</p>',
			esc_html(
				sprintf(
					/* translators: %s: a count of advertisement requests. */
					__( '%s requests', 'aggressive-ads' ),
					number_format_i18n( $requests )
				)
			),
			esc_html(
				sprintf(
					/* translators: %s: a count of filled requests. */
					__( '%s filled', 'aggressive-ads' ),
					number_format_i18n( (int) $figures['fills'] )
				)
			)
		);

		echo '</div></div>';
	}

	/**
	 * Why the unfilled requests were unfilled.
	 *
	 * Two tables, one kind each. A single table that opened on page
	 * requests used to print "every request was filled" while refresh
	 * no-fills sat only in the CSV — the same grain-summed-away defect
	 * as the headline figures, one heading lower.
	 *
	 * @param array{requests: int, fills: int, fill_rate: float|null, unaccounted: int, reasons: list<array<string, mixed>>, refresh: array{requests: int, fills: int, fill_rate: float|null, unaccounted: int, reasons: list<array<string, mixed>>}} $fill Figures.
	 * @return void
	 */
	private function render_reasons( array $fill ): void {
		$refresh = $fill['refresh'];

		if ( 0 === $fill['requests'] && 0 === $refresh['requests'] ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'No advertisement was requested in this window, so there is nothing to explain yet.', 'aggressive-ads' )
			);

			return;
		}

		$this->render_reason_group(
			$fill,
			__( 'Why page requests were not filled', 'aggressive-ads' ),
			__( 'Reasons a page request was not filled', 'aggressive-ads' ),
			__( 'Every page request was filled.', 'aggressive-ads' )
		);
		$this->render_reason_group(
			$refresh,
			__( 'Why refresh requests were not filled', 'aggressive-ads' ),
			__( 'Reasons a refresh request was not filled', 'aggressive-ads' ),
			__( 'Every refresh request was filled.', 'aggressive-ads' )
		);
	}

	/**
	 * One inventory kind's no-fill table, or silence when that kind had none.
	 *
	 * @param array<string, mixed> $figures Figures for one kind.
	 * @param string               $heading Visible heading.
	 * @param string               $caption Screen-reader caption.
	 * @param string               $filled  Copy when every request of this kind filled.
	 * @return void
	 */
	private function render_reason_group( array $figures, string $heading, string $caption, string $filled ): void {
		if ( 0 === $figures['requests'] ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html( $heading ) );

		if ( array() === $figures['reasons'] ) {
			printf( '<p>%s</p>', esc_html( $filled ) );
			$this->render_unaccounted( $figures['unaccounted'] );

			return;
		}

		echo '<table class="widefat striped">';
		printf( '<caption class="screen-reader-text">%s</caption>', esc_html( $caption ) );
		printf(
			'<thead><tr><th scope="col">%1$s</th><th scope="col">%2$s</th><th scope="col">%3$s</th></tr></thead><tbody>',
			esc_html__( 'Reason', 'aggressive-ads' ),
			esc_html__( 'Requests', 'aggressive-ads' ),
			esc_html__( 'Share', 'aggressive-ads' )
		);

		foreach ( $figures['reasons'] as $reason ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td><td>%3$s</td></tr>',
				esc_html( (string) $reason['label'] ),
				esc_html( number_format_i18n( (int) $reason['events'] ) ),
				esc_html( $this->rate( isset( $reason['share'] ) ? $reason['share'] : null ) )
			);
		}

		echo '</tbody></table>';
		$this->render_unaccounted( $figures['unaccounted'] );
	}

	/**
	 * How much of each placement's page inventory was actually sold.
	 *
	 * **Page opportunities only, and the heading says so.** A refresh is the
	 * same slot filled again by a timer, not new supply, so counting it here
	 * would let a publisher raise their apparent utilisation by rotating
	 * faster. The wording is not decoration: a reader who assumes this includes
	 * every impression will draw the opposite conclusion from the number.
	 *
	 * Not filtered by the placement selector. The point of this table is
	 * comparing placements against each other, and a one-row comparison is the
	 * summary above.
	 *
	 * @param array{placements: list<array<string, mixed>>, groups: list<array<string, mixed>>} $view Utilisation view.
	 * @return void
	 */
	private function render_utilisation( array $view ): void {
		printf( '<h2>%s</h2>', esc_html__( 'Utilisation by placement', 'aggressive-ads' ) );

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Page opportunities only. A refresh fills the same slot again on a timer, so it is delivery rather than new inventory and is deliberately not counted here.', 'aggressive-ads' )
		);

		if ( array() === $view['placements'] ) {
			printf( '<p>%s</p>', esc_html__( 'No placements are configured yet.', 'aggressive-ads' ) );

			/*
			 * Still said, because this is the case that needs it most: no
			 * placements and a headline in the thousands is the widest the two
			 * figures can disagree, and an early return here is what made the
			 * disagreement unexplainable in the first place.
			 */
			$this->render_unattributed( $view['unattributed'] ?? array() );

			return;
		}

		/*
		 * Named so it collides with nothing. An accessible name is matched by
		 * substring, so "Utilisation by placement" also answered to the
		 * "Placement" filter's label and made that control ambiguous to any
		 * caller asking for it by name — a browser test, and a screen reader
		 * user navigating by form control.
		 */
		$this->open_scroll_region( __( 'Utilisation detail', 'aggressive-ads' ) );

		echo '<table class="widefat striped">';
		printf(
			'<caption class="screen-reader-text">%s</caption>',
			esc_html__( 'Page requests, fills and utilisation for each placement.', 'aggressive-ads' )
		);
		printf(
			'<thead><tr><th scope="col">%1$s</th><th scope="col">%2$s</th><th scope="col">%3$s</th><th scope="col">%4$s</th><th scope="col">%5$s</th></tr></thead><tbody>',
			esc_html__( 'Placement', 'aggressive-ads' ),
			esc_html__( 'Groups', 'aggressive-ads' ),
			esc_html__( 'Page requests', 'aggressive-ads' ),
			esc_html__( 'Filled', 'aggressive-ads' ),
			esc_html__( 'Utilisation', 'aggressive-ads' )
		);

		foreach ( $view['placements'] as $row ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( (string) $row['name'] ),
				esc_html( $this->group_list( $row['groups'] ) ),
				esc_html( number_format_i18n( (int) $row['requests'] ) ),
				esc_html( number_format_i18n( (int) $row['fills'] ) ),
				esc_html( $this->rate( $row['fill_rate'] ) )
			);
		}

		echo '</tbody></table></div>';

		/*
		 * The unexplained-outcome warning is printed once, above, against the
		 * whole site. Summing it again per placement said the same thing with a
		 * different number and identical wording, which reads as two separate
		 * defects rather than one seen from two angles.
		 */
		$this->render_unattributed( $view['unattributed'] ?? array() );
		$this->render_group_utilisation( $view['groups'] );
	}

	/**
	 * The same figure totalled over each group.
	 *
	 * Summed from the placement counters rather than averaged from their rates
	 * — a mean of rates weights a placement with nine requests the same as one
	 * with nine thousand, and would report a nearly empty group as
	 * three-quarters sold. The arithmetic is in `Admin\Report_Data`; this only
	 * prints it.
	 *
	 * @param list<array<string, mixed>> $groups Group rows.
	 * @return void
	 */
	private function render_group_utilisation( array $groups ): void {
		if ( array() === $groups ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html__( 'Utilisation by group', 'aggressive-ads' ) );

		// Not a superstring of the table region's name above, for the same
		// substring-matching reason.
		$this->open_scroll_region( __( 'Group totals', 'aggressive-ads' ) );

		echo '<table class="widefat striped">';
		printf(
			'<caption class="screen-reader-text">%s</caption>',
			esc_html__( 'Page requests, fills and utilisation totalled for each placement group.', 'aggressive-ads' )
		);
		printf(
			'<thead><tr><th scope="col">%1$s</th><th scope="col">%2$s</th><th scope="col">%3$s</th><th scope="col">%4$s</th><th scope="col">%5$s</th></tr></thead><tbody>',
			esc_html__( 'Group', 'aggressive-ads' ),
			esc_html__( 'Placements', 'aggressive-ads' ),
			esc_html__( 'Page requests', 'aggressive-ads' ),
			esc_html__( 'Filled', 'aggressive-ads' ),
			esc_html__( 'Utilisation', 'aggressive-ads' )
		);

		foreach ( $groups as $group ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( (string) $group['slug'] ),
				esc_html( number_format_i18n( (int) $group['placements'] ) ),
				esc_html( number_format_i18n( (int) $group['requests'] ) ),
				esc_html( number_format_i18n( (int) $group['fills'] ) ),
				esc_html( $this->rate( $group['fill_rate'] ) )
			);
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Opens a horizontally scrollable region around a wide table.
	 *
	 * WCAG 1.4.10 requires the *page* to reflow at 320 CSS pixels without
	 * two-dimensional scrolling. These two tables carry five columns of figures
	 * that a publisher compares against each other, so narrowing them to fit
	 * would mean dropping a column and hiding data rather than presenting it.
	 * Scrolling the table inside its own region satisfies the criterion without
	 * that trade.
	 *
	 * **`tabindex` is what makes it legitimate.** A scrollable region that only
	 * a mouse can scroll is unreachable by keyboard, which axe reports as
	 * `scrollable-region-focusable` and which is a worse failure than the one
	 * being fixed. Focusable regions need an accessible name, hence the label.
	 *
	 * @param string $label Accessible name for the region.
	 * @return void
	 */
	private function open_scroll_region( string $label ): void {
		printf(
			'<div class="aggr-table-scroll" role="region" tabindex="0" aria-label="%s">',
			esc_attr( $label )
		);
	}

	/**
	 * A placement's groups as one readable cell.
	 *
	 * An em dash rather than an empty cell for a placement in no group: an
	 * empty table cell reads as missing data rather than as a deliberate
	 * nothing.
	 *
	 * @param array<int, string> $groups Group slugs.
	 * @return string
	 */
	private function group_list( array $groups ): string {
		if ( array() === $groups ) {
			return __( '—', 'aggressive-ads' );
		}

		// A plain separator rather than a translatable one: a bare ", " gives a
		// translator no context to work from and nothing to get right.
		return implode( ', ', $groups );
	}

	/**
	 * Counters belonging to placements that no longer exist.
	 *
	 * Printed because the headline figures above count them and no row below
	 * can. Without this the two disagree by a number nobody can explain — the
	 * summary says three thousand page requests, every placement says nought,
	 * and both are telling the truth about different sets of rows.
	 *
	 * Ordinary on a development site that has been reseeded, and worth a second
	 * look on a live one: it means history exists for inventory that was
	 * deleted rather than deactivated.
	 *
	 * @param array{requests?: int, fills?: int} $figures Unattributed totals.
	 * @return void
	 */
	private function render_unattributed( array $figures ): void {
		$requests = (int) ( $figures['requests'] ?? 0 );

		if ( $requests <= 0 ) {
			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: a count of page requests recorded against deleted placements. */
					__( '%s page requests belong to placements that no longer exist, so they are counted in the totals above but cannot appear in any row. This is normal after a placement is deleted.', 'aggressive-ads' ),
					number_format_i18n( $requests )
				)
			)
		);
	}

	/**
	 * A rate as a percentage, or an em dash when there is no denominator.
	 *
	 * @param float|null $rate Share or fill rate, or null.
	 */
	private function rate( ?float $rate ): string {
		if ( null === $rate ) {
			return __( '—', 'aggressive-ads' );
		}

		return sprintf(
			/* translators: %s: a percentage, e.g. 92.4. */
			__( '%s%%', 'aggressive-ads' ),
			number_format_i18n( $rate * 100, 1 )
		);
	}

	/**
	 * P13's invariant is that requests equal fills plus every reason. It is
	 * a property of the decision engine rather than of the table, so a
	 * screen that normalised a discrepancy away would hide the defect worth
	 * finding. On a healthy site this never prints.
	 *
	 * @param int $unaccounted Requests with neither a fill nor a reason.
	 * @return void
	 */
	private function render_unaccounted( int $unaccounted ): void {
		if ( $unaccounted <= 0 ) {
			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: a count of requests with no recorded outcome. */
					__( '%s requests have no recorded outcome. This is a defect worth reporting: every request should be either a fill or a reason.', 'aggressive-ads' ),
					number_format_i18n( $unaccounted )
				)
			)
		);
	}
}
