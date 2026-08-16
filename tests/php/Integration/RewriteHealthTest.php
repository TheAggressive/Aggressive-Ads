<?php
/**
 * Rewrite-staleness Site Health verification and repair.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Install\Rewrite_Health;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Workflow\Click_Hop;
use WP_UnitTestCase;

/**
 * The check has to read the rules that are installed, not the version that
 * records a flush being attempted — those are the same value right up until
 * the moment the check is worth having.
 */
final class RewriteHealthTest extends WP_UnitTestCase {

	/**
	 * Subject.
	 *
	 * @var Rewrite_Health
	 */
	private Rewrite_Health $health;

	/**
	 * Resolves the service and gives the site pretty permalinks.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->health = Plugin::instance()->container()->get( Rewrite_Health::class );

		update_option( 'permalink_structure', '/%postname%/' );
	}

	/**
	 * Restores the default structure so later suites are unaffected.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		update_option( 'permalink_structure', '' );
		delete_option( 'rewrite_rules' );

		parent::tear_down();
	}

	/**
	 * Installs both rule sets the way a flush does.
	 *
	 * @return void
	 */
	private function install_rules(): void {
		update_option(
			'rewrite_rules',
			array(
				'^' . Routes::base() . '/?$'          => 'index.php?aggr_portal=1',
				'^' . Click_Hop::PATH . '/([^/]+)/?$' => 'index.php?aggr_click=$matches[1]',
			)
		);
	}

	/**
	 * The hook is attached and existing tests survive registration.
	 *
	 * @return void
	 */
	public function test_site_health_test_is_registered(): void {
		$this->assertNotFalse( has_filter( 'site_status_tests', array( $this->health, 'register_test' ) ) );

		$tests  = array( 'direct' => array( 'core_test' => array( 'test' => '__return_true' ) ) );
		$result = $this->health->register_test( $tests );

		$this->assertArrayHasKey( 'core_test', $result['direct'] );
		$this->assertArrayHasKey( 'aggr_rewrite_rules', $result['direct'] );
	}

	/**
	 * Installed rules report good.
	 *
	 * @return void
	 */
	public function test_installed_rules_are_good(): void {
		$this->install_rules();

		$this->assertSame( 'good', $this->health->run_test()['status'] );
	}

	/**
	 * A rule that merely contains the portal base does not satisfy the check.
	 *
	 * The first version concatenated every rule key and searched for the base as
	 * a substring, so an ordinary page at /advertiser-terms/ made the check
	 * report the portal reachable while every /advertiser/ URL 404ed. A health
	 * check that answers "fine" during the outage it exists to detect is worse
	 * than no check at all.
	 *
	 * @return void
	 */
	public function test_a_lookalike_rule_does_not_satisfy_the_check(): void {
		$base = Routes::base();

		update_option(
			'rewrite_rules',
			array(
				$base . '-terms/?$'             => 'index.php?pagename=' . $base . '-terms',
				'about-' . $base . '/?$'        => 'index.php?pagename=about',
				Click_Hop::PATH . '/([^/]+)/?$' => 'index.php?aggr_click=$matches[1]',
			)
		);

		$result = $this->health->run_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( '/' . $base . '/', (string) $result['description'] );
	}

	/**
	 * The whole point: rules absent from the option is critical even though
	 * the recorded rewrite version is perfectly current.
	 *
	 * A version-only check passes here, which is exactly the deploy that
	 * leaves the portal 404ing while every recorded marker says it is fine.
	 *
	 * @return void
	 */
	public function test_missing_rules_are_critical_despite_a_current_version(): void {
		update_option( 'rewrite_rules', array( '^somebody-elses-rule/?$' => 'index.php' ) );

		$result = $this->health->run_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( Routes::base(), $result['description'] );
		$this->assertStringContainsString( Click_Hop::PATH, $result['description'] );
	}

	/**
	 * A missing click-hop rule is reported on its own, so the message names
	 * the half that is actually broken.
	 *
	 * @return void
	 */
	public function test_a_single_missing_rule_is_named_alone(): void {
		update_option(
			'rewrite_rules',
			array( '^' . Routes::base() . '/?$' => 'index.php?aggr_portal=1' )
		);

		$result = $this->health->run_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( Click_Hop::PATH, $result['description'] );
		$this->assertStringNotContainsString( '/' . Routes::base() . '/,', $result['description'] );
	}

	/**
	 * Plain permalinks are reported before rule contents, because flushing
	 * cannot repair them and a re-flush would report success.
	 *
	 * @return void
	 */
	public function test_plain_permalinks_are_critical_and_offer_no_reflush(): void {
		update_option( 'permalink_structure', '' );
		$this->install_rules();

		$result = $this->health->run_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'options-permalink.php', $result['actions'] );
		$this->assertStringNotContainsString( Rewrite_Health::ACTION_FLUSH, $result['actions'] );
	}

	/**
	 * The repair control is capability-gated, not merely hidden.
	 *
	 * @return void
	 */
	public function test_repair_button_is_offered_only_to_an_administrator(): void {
		update_option( 'rewrite_rules', array() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( '', $this->health->run_test()['actions'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$actions = $this->health->run_test()['actions'];

		$this->assertStringContainsString( Rewrite_Health::ACTION_FLUSH, $actions );
		$this->assertStringContainsString( '_wpnonce', $actions );
	}
}
