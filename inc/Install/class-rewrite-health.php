<?php
/**
 * Site Health assertion and manual repair for the portal's rewrite rules.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Workflow\Click_Hop;

/**
 * Stale rewrite rules are the failure that looks like a broken deploy.
 *
 * `Rewrite_Flusher` writes the rules on activation, on a new network site, and
 * once when a declared version moves. Every one of those is a write that has to
 * have happened in the past — none of them can prove the rules are in the
 * database *now*. A file-only deploy that changes a route without bumping
 * `Router::REWRITE_VERSION`, a migration that restores an older `rewrite_rules`
 * option, or another plugin regenerating rules from a cached copy all leave the
 * portal 404ing with nothing in any log.
 *
 * So this asserts the end state rather than the procedure, and offers the one
 * repair that fixes every cause. See docs/known-issues.md.
 */
final class Rewrite_Health implements Service {

	/**
	 * The admin-post action backing the manual re-flush.
	 */
	public const ACTION_FLUSH = 'aggr_flush_rewrites';

	/**
	 * Query argument carrying the outcome back to Site Health.
	 */
	private const NOTICE_ARG = 'aggr_rewrites';

	/**
	 * Constructor.
	 *
	 * @param Rewrite_Flusher $flusher Rule writer.
	 */
	public function __construct( private readonly Rewrite_Flusher $flusher ) {
	}

	/**
	 * Registers the test and the repair.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'site_status_tests', array( $this, 'register_test' ) );
		add_action( 'admin_post_' . self::ACTION_FLUSH, array( $this, 'handle_flush' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Adds the test without disturbing tests registered by core or plugins.
	 *
	 * @param array<string, mixed> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public function register_test( array $tests ): array {
		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}

		$tests['direct']['aggr_rewrite_rules'] = array(
			'label' => __( 'The advertiser portal is reachable', 'aggressive-ads' ),
			'test'  => array( $this, 'run_test' ),
		);

		return $tests;
	}

	/**
	 * Reports whether the rules that serve the portal are actually installed.
	 *
	 * @return array<string, mixed> Site Health direct-test result.
	 */
	public function run_test(): array {
		// Checked first because it is the one cause the button cannot repair.
		// Flushing with plain permalinks writes nothing and would report
		// success, sending an administrator back to a portal that still 404s.
		if ( '' === (string) get_option( 'permalink_structure', '' ) ) {
			return $this->result(
				'critical',
				__( 'The advertiser portal cannot be reached', 'aggressive-ads' ),
				__( 'This site uses plain permalinks, and the advertiser portal needs pretty permalinks to resolve. Choose any other option under Settings → Permalinks.', 'aggressive-ads' ),
				sprintf(
					'<p><a class="button button-primary" href="%s">%s</a></p>',
					esc_url( admin_url( 'options-permalink.php' ) ),
					esc_html__( 'Open Permalink Settings', 'aggressive-ads' )
				)
			);
		}

		$missing = $this->missing_rules();

		if ( array() === $missing ) {
			return $this->result(
				'good',
				__( 'The advertiser portal is reachable', 'aggressive-ads' ),
				__( 'The portal and click-tracking rewrite rules are installed and current.', 'aggressive-ads' )
			);
		}

		return $this->result(
			'critical',
			__( 'The advertiser portal is not reachable', 'aggressive-ads' ),
			sprintf(
				/* translators: %s: comma-separated list of URL paths, already translated and escaped by the caller. */
				__( 'These paths have no rewrite rule and will return 404: %s. This normally follows a deployment that changed files without reactivating the plugin.', 'aggressive-ads' ),
				implode( ', ', $missing )
			),
			$this->repair_button()
		);
	}

	/**
	 * The advertised paths that no installed rule would match.
	 *
	 * Reads the stored rules rather than the declared version, because the
	 * version only records that a flush was *attempted*. A restored database,
	 * a caching plugin serving an older option, or a hand-edited row all leave
	 * the version current and the rules gone — and that is the state the
	 * administrator is actually looking at.
	 *
	 * @return array<int, string> Human-readable paths, or an empty array.
	 */
	private function missing_rules(): array {
		$rules   = get_option( 'rewrite_rules', array() );
		$rules   = is_array( $rules ) ? $rules : array();
		$pattern = implode( '|', array_map( 'strval', array_keys( $rules ) ) );

		$missing = array();

		if ( ! str_contains( $pattern, Routes::base() ) ) {
			$missing[] = '/' . Routes::base() . '/';
		}

		if ( ! str_contains( $pattern, Click_Hop::PATH ) ) {
			$missing[] = '/' . Click_Hop::PATH . '/';
		}

		return $missing;
	}

	/**
	 * The repair control, shown only to somebody who could already do this by
	 * hand through Settings → Permalinks.
	 *
	 * @return string Trusted markup, or an empty string.
	 */
	private function repair_button(): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$url = wp_nonce_url(
			add_query_arg( 'action', self::ACTION_FLUSH, admin_url( 'admin-post.php' ) ),
			self::ACTION_FLUSH
		);

		return sprintf(
			'<p><a class="button button-primary" href="%s">%s</a></p>',
			esc_url( $url ),
			esc_html__( 'Reinstall advertising rewrite rules', 'aggressive-ads' )
		);
	}

	/**
	 * Rewrites the rules on request, then returns to Site Health.
	 *
	 * A GET that changes state, so it carries a nonce and a capability check.
	 * The capability is `manage_options` rather than an advertising one: this
	 * regenerates every rewrite rule on the site, not only ours, which is the
	 * same reach as Settings → Permalinks.
	 *
	 * @return void
	 */
	public function handle_flush(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to reinstall rewrite rules on this site.', 'aggressive-ads' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::ACTION_FLUSH );

		$this->flusher->flush();

		// Re-read rather than trusting the write. flush_rewrite_rules() is
		// silent when the rules cannot be persisted, so reporting success
		// because the call returned would be reporting that it was called.
		$outcome = array() === $this->missing_rules() ? 'ok' : 'failed';

		wp_safe_redirect(
			add_query_arg(
				self::NOTICE_ARG,
				$outcome,
				admin_url( 'site-health.php' )
			)
		);
		exit;
	}

	/**
	 * Reports the outcome of a manual re-flush.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads a redirect marker to choose a message; changes nothing. The write it reports on was nonce-checked in handle_flush().
		$outcome = isset( $_GET[ self::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';

		if ( '' === $outcome || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 'ok' === $outcome ) {
			wp_admin_notice(
				esc_html__( 'Advertising rewrite rules reinstalled. The advertiser portal is reachable.', 'aggressive-ads' ),
				array( 'type' => 'success' )
			);
			return;
		}

		wp_admin_notice(
			esc_html__( 'The advertising rewrite rules could not be written. Open Settings → Permalinks and save, then run this check again.', 'aggressive-ads' ),
			array( 'type' => 'error' )
		);
	}

	/**
	 * Builds the common Site Health result shape.
	 *
	 * @param string $status      Site Health status.
	 * @param string $label       Result heading.
	 * @param string $description Result explanation.
	 * @param string $actions     Optional trusted actions markup.
	 * @return array<string, mixed>
	 */
	private function result( string $status, string $label, string $description, string $actions = '' ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Advertising', 'aggressive-ads' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $actions,
			'test'        => 'aggr_rewrite_rules',
		);
	}
}
