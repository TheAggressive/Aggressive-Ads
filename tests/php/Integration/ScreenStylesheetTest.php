<?php
/**
 * Every class a screen prints is defined in a stylesheet that screen loads.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Menu;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Decision_Outcome;
use Aggressive\Ads\Domain\No_Fill_Reason;
use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * `bin/ci/check-styles.mjs` asks whether a class has a rule *somewhere* under
 * `src/`. That is the wrong question on its own, and the gap cost a WCAG fix
 * two attempts: `aggr-table-scroll` was defined in `admin.css`, the reports
 * screen loads only `admin-native.css`, and the guard reported ok twice over a
 * table that went on overflowing.
 *
 * The static guard cannot close that without being taught which stylesheet each
 * screen enqueues — a map it would have to duplicate and keep in step. This
 * asks WordPress instead. It fires `admin_enqueue_scripts` for a real hook
 * suffix, reads `wp_styles()->queue`, renders the screen, and compares the two
 * halves. Nothing is hardcoded, so nothing can drift.
 */
final class ScreenStylesheetTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install();

		wp_set_current_user(
			(int) self::factory()->user->create( array( 'role' => 'administrator' ) )
		);

		set_current_screen( 'dashboard' );

		$this->seed();
	}

	/**
	 * Puts the screens into a state where they print their real markup.
	 *
	 * **Without this the test proved nothing.** An unseeded reports screen
	 * renders "Reporting is switched off", and with the module on but no
	 * placements it returns before the utilisation tables — so the very class
	 * this test was written for never appeared in the markup, and it passed
	 * over the defect it exists to catch. An empty screen has no classes to
	 * check and looks exactly like a screen with no problems.
	 *
	 * @return void
	 */
	private function seed(): void {
		$container = Plugin::instance()->container();
		$settings  = $container->get( Settings::class );
		$rollups   = $container->get( Decision_Rollup_Repository::class );

		$rollups->install_table();

		$document = $settings->get();
		$document['modules'][ Settings_Schema::MODULE_REPORTING ] = true;
		$settings->save( $document );

		$placement = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => 'screen-styles-leaderboard',
				'post_title'  => 'Screen Styles Leaderboard',
			)
		);

		update_post_meta( $placement, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement, Placement_Repository::META_SIZE, '728x90' );

		$rollups->add(
			gmdate( 'Y-m-d' ),
			$placement,
			array(
				Decision_Outcome::REQUEST          => 400,
				Decision_Outcome::FILL             => 250,
				No_Fill_Reason::TARGETING_MISMATCH => 150,
			),
			Opportunity::PAGE
		);
	}

	/**
	 * Every Advertising screen, as slug to hook suffix.
	 *
	 * Enumerated from what actually registered rather than from a list here: a
	 * screen added later is covered without anyone remembering to add it, and a
	 * screen that stops registering shrinks the count this test asserts on.
	 *
	 * @return array<string, string>
	 */
	private function screens(): array {
		global $submenu;

		do_action( 'admin_menu' );

		$found = array();

		foreach ( $submenu[ Menu::PARENT_SLUG ] ?? array() as $entry ) {
			$slug = (string) ( $entry[2] ?? '' );

			if ( '' === $slug ) {
				continue;
			}

			$found[ $slug ] = get_plugin_page_hookname( $slug, Menu::PARENT_SLUG );
		}

		return $found;
	}

	/**
	 * The local paths of this plugin's stylesheets currently enqueued.
	 *
	 * @return array<int, string>
	 */
	private function enqueued_stylesheets(): array {
		$styles = wp_styles();
		$paths  = array();

		foreach ( $styles->queue as $handle ) {
			$registered = $styles->registered[ $handle ] ?? null;
			$src        = is_object( $registered ) ? (string) $registered->src : '';

			if ( '' === $src || ! str_starts_with( $src, AGGR_PLUGIN_URL ) ) {
				continue;
			}

			$path = AGGR_PLUGIN_DIR . substr( $src, strlen( AGGR_PLUGIN_URL ) );

			if ( is_file( $path ) ) {
				$paths[] = $path;
			}
		}

		return $paths;
	}

	/**
	 * Class names defined by a set of stylesheets.
	 *
	 * @param array<int, string> $paths Stylesheet paths.
	 * @return array<int, string>
	 */
	private function defined_in( array $paths ): array {
		$names = array();

		foreach ( $paths as $path ) {
			$css = (string) file_get_contents( $path );

			if ( 1 === preg_match_all( '/\.(aggr-[A-Za-z0-9_-]+)/', $css, $matches ) || array() !== ( $matches[1] ?? array() ) ) {
				foreach ( $matches[1] as $name ) {
					$names[] = $name;
				}
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * `aggr-` class names printed in some markup.
	 *
	 * @param string $html Rendered screen.
	 * @return array<int, string>
	 */
	private function used_in( string $html ): array {
		$names = array();

		preg_match_all( '/class="([^"]*)"/', $html, $attributes );

		foreach ( $attributes[1] as $value ) {
			$words = preg_split( '/\s+/', $value );

			foreach ( is_array( $words ) ? $words : array() as $word ) {
				if ( str_starts_with( $word, 'aggr-' ) ) {
					$names[] = $word;
				}
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Whether a stylesheet set defines a used name, allowing prefix matches.
	 *
	 * Mirrors `check-styles.mjs`: a runtime-built name such as
	 * `aggr-pill--${status}` is satisfied by anything sharing its prefix.
	 *
	 * @param string             $used    Class from markup.
	 * @param array<int, string> $defined Class names from stylesheets.
	 * @return bool
	 */
	private function satisfied( string $used, array $defined ): bool {
		if ( in_array( $used, $defined, true ) ) {
			return true;
		}

		foreach ( $defined as $name ) {
			if ( str_starts_with( $name, $used ) || str_starts_with( $used, $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * **A class printed by a screen resolves in a stylesheet that screen loads.**
	 *
	 * @return void
	 */
	public function test_every_screen_defines_the_classes_it_prints(): void {
		$screens  = $this->screens();
		$problems = array();
		$checked  = 0;
		$seen     = array();

		$this->assertNotEmpty( $screens, 'No Advertising screens registered; this test would prove nothing.' );

		foreach ( $screens as $slug => $hook ) {
			// A fresh queue per screen, or one screen's stylesheet would
			// satisfy the next screen's classes.
			$GLOBALS['wp_styles'] = null;

			$_GET['page'] = $slug;

			do_action( 'admin_enqueue_scripts', $hook );

			$html = $this->render( $hook );

			if ( '' === $html ) {
				continue;
			}

			$defined = $this->defined_in( $this->enqueued_stylesheets() );

			foreach ( $this->used_in( $html ) as $used ) {
				++$checked;
				$seen[] = $used;

				if ( ! $this->satisfied( $used, $defined ) ) {
					$problems[] = sprintf(
						'%s prints "%s", which no stylesheet it enqueues defines.',
						$slug,
						$used
					);
				}
			}
		}

		unset( $_GET['page'] );

		$this->assertSame( array(), $problems, implode( "\n", $problems ) );

		// A guard that stops matching reports success over code it is no longer
		// reading, so it says how much it read.
		$this->assertGreaterThan(
			5,
			$checked,
			'Too few classes were checked; the screens are no longer rendering.'
		);

		/*
		 * **The assertion that makes the rest of this test mean something.**
		 *
		 * `aggr-table-scroll` is printed only once the reports screen has
		 * reporting enabled *and* a placement to list. The first version of
		 * this test had neither, so it inspected an empty-state page, saw
		 * nothing, and passed cleanly with the stylesheet defect deliberately
		 * reintroduced. Naming one class that must be present is what stops it
		 * quietly returning to that.
		 */
		$this->assertContains(
			'aggr-table-scroll',
			$seen,
			'The reports screen did not render its utilisation tables, so the case this test exists for was never examined.'
		);
	}

	/**
	 * Renders one screen through the callback WordPress registered for it.
	 *
	 * `add_submenu_page()` registers the render callback on the page's hook, so
	 * firing the hook is the same path wp-admin takes. A screen that dies —
	 * a capability check, or data a bare fixture does not have — returns
	 * nothing and is skipped rather than failing this test, which is about
	 * stylesheets and not about whether every screen renders headless.
	 *
	 * @param string $hook Page hook name.
	 * @return string
	 */
	private function render( string $hook ): string {
		if ( ! has_action( $hook ) ) {
			return '';
		}

		ob_start();

		try {
			do_action( $hook );
		} catch ( \Throwable $error ) {
			ob_end_clean();

			return '';
		}

		return (string) ob_get_clean();
	}
}
