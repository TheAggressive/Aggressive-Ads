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
	 * This screen's hook suffix, assigned by add_submenu_page().
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Registers a capability-owned submenu under Advertising.
	 */
	public function register_menu(): void {
		$hook = add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Reports', 'aggressive-ads' ),
			__( 'Reports', 'aggressive-ads' ),
			Capabilities::VIEW_REPORTS,
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		$this->hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Loads the utilisation tables' bundle, and only on this screen.
	 *
	 * The rest of the page needs no JavaScript and does not get any. Reports is
	 * what somebody opens when something is already wrong, so the headline
	 * figures, the no-fill reasons and the export stay server-rendered and
	 * readable without a bundle. Only the two tables whose question is a sort
	 * are mounted.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		if ( ! $this->data->surfaces() ) {
			return;
		}

		$asset = AGGR_PLUGIN_DIR . 'dist/admin/reports.asset.php';

		if ( ! is_file( $asset ) ) {
			return;
		}

		$meta    = require $asset;
		$version = is_string( $meta['version'] ?? null ) ? $meta['version'] : AGGR_VERSION;

		// The bundle's .asset.php names aggr-dataviews as a dependency, because
		// the build rewrote its @wordpress/dataviews import onto the shared
		// copy. Registering it here is what lets WordPress resolve that.
		Shared_Assets::register();

		wp_enqueue_script(
			'aggr-reports',
			AGGR_PLUGIN_URL . 'dist/admin/reports.js',
			is_array( $meta['dependencies'] ?? null ) ? $meta['dependencies'] : array(),
			$version,
			true
		);

		wp_enqueue_style( 'wp-components' );

		$style = AGGR_PLUGIN_DIR . 'dist/admin/reports.css';

		if ( is_file( $style ) ) {
			wp_enqueue_style(
				'aggr-reports',
				AGGR_PLUGIN_URL . 'dist/admin/reports.css',
				array( 'wp-components' ),
				$version
			);
		}
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

		$refresh = $fill['refresh'];

		printf(
			'<ul><li>%1$s</li><li>%2$s</li><li>%3$s</li><li>%4$s</li><li>%5$s</li><li>%6$s</li></ul>',
			esc_html(
				sprintf(
					/* translators: %s: a count of page-view advertisement requests. */
					__( 'Page requests: %s', 'aggressive-ads' ),
					number_format_i18n( $fill['requests'] )
				)
			),
			esc_html(
				sprintf(
					/* translators: %s: a count of filled page-view requests. */
					__( 'Page filled: %s', 'aggressive-ads' ),
					number_format_i18n( $fill['fills'] )
				)
			),
			esc_html(
				sprintf(
					/* translators: %s: page fill rate as a percentage, or an em dash. */
					__( 'Page fill rate: %s', 'aggressive-ads' ),
					$this->rate( $fill['fill_rate'] )
				)
			),
			esc_html(
				sprintf(
					/* translators: %s: a count of refresh advertisement requests. */
					__( 'Refresh requests: %s', 'aggressive-ads' ),
					number_format_i18n( $refresh['requests'] )
				)
			),
			esc_html(
				sprintf(
					/* translators: %s: a count of filled refresh requests. */
					__( 'Refresh filled: %s', 'aggressive-ads' ),
					number_format_i18n( $refresh['fills'] )
				)
			),
			esc_html(
				sprintf(
					/* translators: %s: refresh fill rate as a percentage, or an em dash. */
					__( 'Refresh fill rate: %s', 'aggressive-ads' ),
					$this->rate( $refresh['fill_rate'] )
				)
			)
		);
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
	 * Mounts the utilisation tables.
	 *
	 * **Only these two tables are a bundle.** Sorting is the question they
	 * exist to answer — "which placements are underselling" is a sort by fill
	 * rate, and a static table cannot answer it. Everything above them on this
	 * page stays server-rendered, so the headline figures and the no-fill
	 * reasons are still readable with JavaScript off.
	 *
	 * **Page opportunities only, and the heading says so.** A refresh fills the
	 * same slot again on a timer, so counting it here would let a publisher
	 * raise their apparent utilisation by rotating faster. A reader who assumes
	 * this includes every impression draws the opposite conclusion from the
	 * number, which is why the caveat is rendered rather than implied.
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

			return;
		}

		$unaccounted = 0;

		foreach ( $view['placements'] as $row ) {
			$unaccounted += (int) $row['unaccounted'];
		}

		$payload = array(
			'view' => $view,
			'i18n' => array(
				'placement'      => __( 'Placement', 'aggressive-ads' ),
				'groups'         => __( 'Groups', 'aggressive-ads' ),
				'requests'       => __( 'Page requests', 'aggressive-ads' ),
				'fills'          => __( 'Filled', 'aggressive-ads' ),
				'utilisation'    => __( 'Utilisation', 'aggressive-ads' ),
				'group'          => __( 'Group', 'aggressive-ads' ),
				'placementCount' => __( 'Placements', 'aggressive-ads' ),
				'byGroup'        => __( 'Utilisation by group', 'aggressive-ads' ),

				/*
				 * Region names, kept clear of every other label on this screen.
				 * An accessible name matches by substring, so "Utilisation by
				 * placement" here would also answer to the "Placement" filter
				 * and make that select ambiguous to anyone asking by name.
				 */
				'detailRegion'   => __( 'Utilisation detail', 'aggressive-ads' ),
				'groupRegion'    => __( 'Group totals', 'aggressive-ads' ),
			),
		);

		printf(
			'<noscript><div class="notice notice-warning"><p>%1$s</p></div></noscript><div id="aggr-reports-root" data-aggr-reports="%2$s"></div>',
			esc_html__( 'The utilisation tables need JavaScript enabled. Every other figure on this page is above.', 'aggressive-ads' ),
			esc_attr( (string) wp_json_encode( $payload ) )
		);

		$this->render_unaccounted( $unaccounted );
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
