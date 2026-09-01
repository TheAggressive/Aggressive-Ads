<?php
/**
 * The conversions screen is reachable, and only by the right people.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Conversions_Screen;
use Aggressive\Ads\Admin\Menu;
use Aggressive\Ads\Admin\Shared_Assets;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Domain\Conversion_Definition;
use Aggressive\Ads\REST\Conversion_Credentials_Controller;
use Aggressive\Ads\REST\Conversion_Definitions_Controller;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Conversion_Credential_Repository;
use Aggressive\Ads\Repository\Conversion_Definition_Repository;
use Aggressive\Ads\Workflow\Conversion_Credential_Manager;
use Aggressive\Ads\Workflow\Conversion_Definition_Manager;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * Until this screen existed, three merged pull requests of conversion tracking
 * were unreachable without curl — a definition could only be created over REST.
 * These assertions are about that being fixed and staying fixed.
 */
final class ConversionsScreenTest extends WP_UnitTestCase {

	/**
	 * Screen under test.
	 *
	 * @var Conversions_Screen
	 */
	private Conversions_Screen $screen;

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->screen = Plugin::instance()->container()->get( Conversions_Screen::class );
	}

	/**
	 * Registers the plugin's admin menu as one user would see it.
	 *
	 * @param int $user_id Acting user.
	 * @return array<int, array<int, string>> Submenu rows under Advertising.
	 */
	private function submenu_for( int $user_id ): array {
		global $submenu, $menu, $_registered_pages, $_parent_pages;

		$submenu           = array();
		$menu              = array();
		$_registered_pages = array();
		$_parent_pages     = array();

		wp_set_current_user( $user_id );
		set_current_screen( 'dashboard' );

		do_action( 'admin_menu', '' );

		return $submenu[ Menu::PARENT_SLUG ] ?? array();
	}

	/**
	 * Whether a submenu list contains the conversions page.
	 *
	 * @param array<int, array<int, string>> $rows Submenu rows.
	 */
	private function has_conversions( array $rows ): bool {
		foreach ( $rows as $row ) {
			if ( Conversions_Screen::MENU_SLUG === ( $row[2] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * **The screen exists in the menu**, which is the whole point of it.
	 */
	public function test_a_settings_manager_sees_the_screen(): void {
		$admin = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue(
			user_can( $admin, Capabilities::MANAGE_SETTINGS ),
			'The fixture must hold the capability, or the next assertion passes for the wrong reason.'
		);

		$this->assertTrue( $this->has_conversions( $this->submenu_for( $admin ) ) );
	}

	/**
	 * An advertiser does not, and neither does a reviewer.
	 *
	 * Reviewing campaigns and configuring measurement are deliberately separate
	 * capabilities. The REST routes assert the same split; this is the half a
	 * person actually sees.
	 *
	 * @return array<string, array{string}>
	 */
	public static function refused_roles(): array {
		return array(
			'an advertiser' => array( Roles::ADVERTISER ),
			'a reviewer'    => array( Roles::REVIEWER ),
			'a subscriber'  => array( 'subscriber' ),
		);
	}

	/**
	 * Asserts one role cannot reach the screen.
	 *
	 * @dataProvider refused_roles
	 *
	 * @param string $role Role slug.
	 */
	public function test_a_role_without_the_capability_does_not_see_it( string $role ): void {
		$user_id = (int) self::factory()->user->create( array( 'role' => $role ) );

		$this->assertFalse( user_can( $user_id, Capabilities::MANAGE_SETTINGS ) );
		$this->assertFalse( $this->has_conversions( $this->submenu_for( $user_id ) ) );
	}

	/**
	 * **The render callback refuses too, not only the menu.**
	 *
	 * A hidden menu item is not authorization: `admin.php?page=aggr-conversions`
	 * is a URL anybody can type, and WordPress runs the callback for whoever
	 * asks. The capability check inside `render()` is what actually holds.
	 */
	public function test_the_render_callback_refuses_an_unauthorized_caller(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		$died = false;

		add_filter(
			'wp_die_handler',
			static function () use ( &$died ): callable {
				return static function () use ( &$died ): void {
					$died = true;

					throw new \RuntimeException( 'wp_die' );
				};
			}
		);

		try {
			ob_start();
			$this->screen->render();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			ob_end_clean();
			$this->assertSame( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died, 'An advertiser reached the conversions screen by URL.' );
	}

	/**
	 * And the refusal emits no public key, which is a stronger claim than dying.
	 *
	 * The payload now carries the definitions themselves, so this screen prints
	 * every reporting key on the site. `wp_die()` after some output has already
	 * been flushed still refuses the request and still leaks — the capability
	 * check runs first for that reason, and this is what says so.
	 */
	public function test_an_unauthorized_render_emits_no_reporting_key(): void {
		$container = Plugin::instance()->container();

		$definitions = $container->get( Conversion_Definition_Repository::class );
		$definitions->install_table();
		$container->get( Audit_Repository::class )->install_table();

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$id = $container->get( Conversion_Definition_Manager::class )->create(
			array(
				'name'                 => 'Purchase',
				'org_id'               => 12,
				'window_seconds'       => 2592000,
				'default_value_micros' => 4990000,
				'currency'             => 'USD',
				'allow_s2s'            => true,
				'status'               => Conversion_Definition::STATUS_ACTIVE,
			)
		);

		$this->assertIsInt( $id );

		$row = $definitions->find( $id );

		$this->assertIsArray( $row );

		$key = (string) $row['public_key'];

		$this->assertNotSame( '', $key, 'Without a key to look for this test proves nothing.' );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		add_filter(
			'wp_die_handler',
			static fn (): callable => static function (): void {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		ob_start();

		try {
			$this->screen->render();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}

		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString(
			$key,
			$html,
			'An advertiser was handed a reporting key belonging to somebody else.'
		);
	}

	/**
	 * The screen prints its mount point and the REST path it writes through.
	 */
	public function test_an_authorized_caller_gets_the_mount_point(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		$this->screen->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="aggr-conversions-root"', $html );
		$this->assertStringContainsString( 'conversion-definitions', $html );
		$this->assertStringContainsString( 'noscript', $html, 'A JavaScript-only screen must say so without it.' );
	}

	/**
	 * **The shared DataViews assets reach the page, script and style alike.**
	 *
	 * Both tables on this screen are DataViews, and its stylesheet is the half
	 * that fails silently: WordPress resolves script and style handles
	 * separately, so a script dependency does not bring one. Losing it renders
	 * unstyled markup that still technically works, which is why this asserts
	 * the style as well as the script rather than trusting the dependency.
	 */
	public function test_the_screen_enqueues_the_shared_dataviews_assets(): void {
		$admin = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Registers the menu as WordPress would, which is what gives the screen
		// the hook suffix it compares against below.
		$this->submenu_for( $admin );

		$this->screen->enqueue(
			(string) get_plugin_page_hookname( Conversions_Screen::MENU_SLUG, Menu::PARENT_SLUG )
		);

		$this->assertTrue(
			wp_script_is( 'aggr-conversions', 'enqueued' ),
			'The screen did not load its own bundle, so the rest of this proves nothing.'
		);
		$this->assertTrue(
			wp_style_is( 'aggr-conversions', 'enqueued' ),
			'The screen loaded without its own rules: full-width fields on the grey canvas.'
		);

		/*
		 * Named as a dependency rather than enqueued beside it, so it loads
		 * first — this screen's rules give DataViews its container and would
		 * lose to it otherwise. Asserted through the registry rather than with
		 * `wp_style_is( …, 'enqueued' )`, because a dependency is resolved at
		 * print time and is not in the queue until then.
		 */
		$registered = wp_styles()->registered['aggr-conversions'] ?? null;

		$this->assertNotNull( $registered );
		$this->assertContains(
			Shared_Assets::DATAVIEWS,
			$registered->deps,
			'DataViews would render as unstyled markup, and nothing would error.'
		);
	}

	/**
	 * The screen's payload, as the bundle parses it out of the attribute.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		ob_start();
		$this->screen->render();
		$html = (string) ob_get_clean();

		$matches = array();
		preg_match( '/data-aggr-conversions="([^"]+)"/', $html, $matches );

		$payload = json_decode( html_entity_decode( $matches[1] ?? '', ENT_QUOTES ), true );

		$this->assertIsArray( $payload, 'The screen rendered no payload at all.' );

		return $payload;
	}

	/**
	 * **The screen offers the credential route and the scopes it accepts.**
	 *
	 * A credential is the only way an advertiser's own server may report a
	 * conversion — and state what it was worth — so until this screen carried
	 * the route, that half of P12 took a curl request to switch on.
	 *
	 * Only active organizations are offered, because
	 * `Conversion_Credential_Manager::issue()` refuses an inactive one. An
	 * offered choice that cannot succeed is the control-that-lies shape the
	 * window options are asserted against below.
	 */
	public function test_the_payload_carries_the_credential_route_and_its_active_scopes(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$orgs = Plugin::instance()->container()->get( Org_Repository::class );

		$active = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Bright Angle Media',
			)
		);

		$suspended = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Dormant Holdings',
			)
		);

		$this->assertTrue( $orgs->set_state( $suspended, Org_Repository::STATE_SUSPENDED ) );

		$payload = $this->payload();

		$this->assertSame( '/aggr/v1/conversion-credentials', $payload['credentialsPath'] );

		$offered = array_column( $payload['advertisers'], 'id' );

		$this->assertContains( $active, $offered );
		$this->assertNotContains(
			$suspended,
			$offered,
			'The screen offers a scope the manager would refuse.'
		);
		$this->assertCount( 1, $offered, 'The scopes are not the active organizations.' );
	}

	/**
	 * **The currency options are ones the domain will actually accept.**
	 *
	 * The same property the window options carry, and it matters more here
	 * because this control replaced a free-text field: a select that offered a
	 * code the validator refuses would turn a typo somebody could fix into a
	 * refusal with no way out of it.
	 *
	 * The empty option is the exception and is deliberate — no currency is a
	 * real state for a definition worth nothing, and it has to be choosable
	 * again after somebody picks one by mistake.
	 */
	public function test_every_offered_currency_survives_validation(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$payload = $this->payload();

		$this->assertGreaterThan( 5, count( $payload['currencies'] ), 'The screen offers almost no currencies.' );

		$empty = 0;

		foreach ( $payload['currencies'] as $option ) {
			$code = (string) $option['value'];

			if ( '' === $code ) {
				++$empty;
				continue;
			}

			$this->assertTrue(
				\Aggressive\Ads\Domain\Conversion_Rules::is_valid_currency( $code ),
				"The screen offers {$code}, which the validator refuses."
			);
			$this->assertNotSame( '', (string) $option['label'], 'A currency with no label is an empty row.' );
		}

		$this->assertSame( 1, $empty, 'There must be exactly one way to say "no currency".' );
	}

	/**
	 * **A default is only offered when the site prices in one currency.**
	 *
	 * Guessing between two would fill the field with a plausible wrong answer,
	 * and a wrong currency is not a typo: it silently changes what every total
	 * built from that definition means.
	 */
	public function test_a_default_currency_is_only_offered_when_it_is_unambiguous(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$packages = Plugin::instance()->container()->get( Package_Repository::class );

		$first = $packages->create( 'Sponsorship' );

		$this->assertIsInt( $first );
		$this->assertTrue( update_post_meta( $first, Package_Repository::META_CURRENCY, 'EUR' ) !== false );

		$this->assertSame( 'EUR', $this->payload()['defaultCurrency'], 'One priced currency is the answer.' );

		$second = $packages->create( 'Takeover' );

		$this->assertIsInt( $second );
		$this->assertTrue( update_post_meta( $second, Package_Repository::META_CURRENCY, 'GBP' ) !== false );

		$this->assertSame( '', $this->payload()['defaultCurrency'], 'Two priced currencies must not be guessed between.' );
	}

	/**
	 * **The window options are ones the domain will actually accept.**
	 *
	 * A select offering a window the validator clamps is a control that lies:
	 * the publisher picks one day, the definition saves as one hour, and
	 * nothing says so. Built from `Conversion_Rules`, asserted here.
	 */
	public function test_every_offered_window_survives_validation(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$payload = $this->payload();

		$this->assertNotEmpty( $payload['windows'], 'The screen offers no attribution windows at all.' );

		foreach ( $payload['windows'] as $option ) {
			$seconds = (int) $option['value'];

			$this->assertSame(
				$seconds,
				\Aggressive\Ads\Domain\Conversion_Rules::window_seconds( $seconds ),
				'The screen offers a window the domain would clamp.'
			);
		}
	}

	/**
	 * **The lists travel with the page, and are the lists REST would return.**
	 *
	 * The screen used to fetch both on mount, so it rendered a spinner over data
	 * the server had already assembled while rendering the markup around it —
	 * a whole round trip after React had booted, on the one screen that pays for
	 * the 530K DataViews bundle first.
	 *
	 * Asserted against the controller's own response rather than against a
	 * hand-written expectation, because the failure that matters is not an empty
	 * array — it is the seeded rows and the refetched rows drifting apart. The
	 * screen calls `index()` for exactly that reason; this is what stops someone
	 * "simplifying" it into a second shaping.
	 */
	public function test_the_payload_carries_the_definitions_rest_would_return(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$container = Plugin::instance()->container();

		$definitions = $container->get( Conversion_Definition_Repository::class );
		$definitions->install_table();
		$container->get( Audit_Repository::class )->install_table();

		$id = $container->get( Conversion_Definition_Manager::class )->create(
			array(
				'name'                 => 'Purchase',
				'org_id'               => 12,
				'window_seconds'       => 2592000,
				'default_value_micros' => 4990000,
				'currency'             => 'USD',
				'allow_s2s'            => true,
				'status'               => Conversion_Definition::STATUS_ACTIVE,
			)
		);

		$this->assertIsInt( $id, 'The fixture must exist before the payload can carry it.' );

		$payload = $this->payload();

		$this->assertArrayHasKey( 'definitions', $payload );
		$this->assertCount(
			1,
			$payload['definitions'],
			'The screen shipped no rows, so it is still fetching them on mount.'
		);

		$rest = $container->get( Conversion_Definitions_Controller::class )->index()->get_data();

		$this->assertSame(
			$rest['definitions'],
			$payload['definitions'],
			'The seeded rows and the ones a refetch returns have diverged.'
		);

		// And that the row is the real one, not an empty shape of the right size.
		$this->assertSame( 'Purchase', $payload['definitions'][0]['name'] );
	}

	/**
	 * The same for credentials, whose composition is the more fragile of the two.
	 *
	 * Its rows carry an organization name and three timestamps formatted in the
	 * site's timezone — the reason that list is composed on the server at all.
	 * A second copy built for the first paint would be a second place for the
	 * date format to be decided, and the two would disagree on exactly the
	 * screen an incident is read from.
	 */
	public function test_the_payload_carries_the_credentials_rest_would_return(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$container = Plugin::instance()->container();

		$container->get( Conversion_Credential_Repository::class )->install_table();
		$container->get( Audit_Repository::class )->install_table();

		$org = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Bright Angle Media',
			)
		);

		$issued = $container->get( Conversion_Credential_Manager::class )->issue( $org, 'Storefront' );

		$this->assertIsArray( $issued, 'The fixture must exist before the payload can carry it.' );

		$payload = $this->payload();

		$this->assertArrayHasKey( 'credentials', $payload );
		$this->assertCount( 1, $payload['credentials'] );

		$rest = $container->get( Conversion_Credentials_Controller::class )->index()->get_data();

		$this->assertSame(
			$rest['credentials'],
			$payload['credentials'],
			'The seeded rows and the ones a refetch returns have diverged.'
		);

		$this->assertSame( 'Bright Angle Media', $payload['credentials'][0]['org_name'] );

		// The secret is issued once and never listed. The seeded copy is a
		// second chance to leak it, so it gets the same assertion the route has.
		$this->assertArrayNotHasKey( 'token', $payload['credentials'][0] );
		$this->assertArrayNotHasKey( 'token_hash', $payload['credentials'][0] );
	}
}
