<?php
/**
 * The settings autosave route.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Security\Roles;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The endpoint that exists so the screen can save without navigating.
 *
 * The risk this route introduces is not that it fails — it is that it succeeds
 * where the form path would have refused. A second write path to the same
 * option is a second chance to skip the capability check or the schema, so both
 * are asserted here against the real router rather than against the controller.
 */
final class SettingsAutosaveTest extends WP_UnitTestCase {

	private const ROUTE = '/aggr/v1/settings';

	/**
	 * A user who may administer advertising settings.
	 *
	 * @var int
	 */
	private int $manager;

	/**
	 * A reviewer, who may not.
	 *
	 * @var int
	 */
	private int $reviewer;

	/**
	 * Installs roles and the routes.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->manager  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->reviewer = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );

		delete_option( Settings::OPTION );

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * A document that passes the schema, as the screen would send it.
	 *
	 * @return array<string, mixed>
	 */
	private function valid_document(): array {
		return array(
			'modules'    => array(
				Settings_Schema::MODULE_REPORTING     => true,
				Settings_Schema::MODULE_PUBLIC_SIGNUP => false,
				Settings_Schema::MODULE_BILLING       => false,
			),
			'live_edits' => array( Settings_Schema::EDIT_NOTES => true ),
			'brand'      => array(
				'product_name'  => 'Advertising',
				'tagline'       => '',
				'support_email' => '',
				'logo_url'      => '',
				'accent'        => '#ff3b2f',
				'accent_strong' => '#8e1f1f',
				'canvas'        => '#f7f4ee',
				'surface'       => '#ffffff',
				'text'          => '#111214',
			),
			'delivery'   => array(
				'fill_ttl'     => 30,
				'house_policy' => Settings_Schema::HOUSE_WHEN_EMPTY,
			),
			'tracking'   => array( 'retention_days' => 90 ),
		);
	}

	/**
	 * Dispatches one save.
	 *
	 * @param array<string, mixed> $document Body.
	 * @return \WP_REST_Response
	 */
	private function save( array $document ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $document ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The route is registered at all.
	 *
	 * @return void
	 */
	public function test_the_route_exists(): void {
		$this->assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes() );
	}

	/**
	 * A valid autosave stores the document.
	 *
	 * @return void
	 */
	public function test_a_manager_can_autosave(): void {
		wp_set_current_user( $this->manager );

		$response = $this->save( $this->valid_document() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue(
			( new Settings() )->module_enabled( Settings_Schema::MODULE_REPORTING )
		);
	}

	/**
	 * Without the capability there is no write, and no partial one either.
	 *
	 * @return void
	 */
	public function test_a_reviewer_cannot_autosave(): void {
		wp_set_current_user( $this->reviewer );

		$this->assertSame( 403, $this->save( $this->valid_document() )->get_status() );
		$this->assertNull( get_option( Settings::OPTION, null ) );
	}

	/**
	 * Logged out is refused too, and refused before the body is read.
	 *
	 * @return void
	 */
	public function test_an_anonymous_caller_cannot_autosave(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->save( $this->valid_document() )->get_status() );
		$this->assertNull( get_option( Settings::OPTION, null ) );
	}

	/**
	 * The WCAG contrast gate still fires on this path.
	 *
	 * This is the assertion the route was written to be checked against: the
	 * screen it serves has a colour picker, and a picker makes it trivial to
	 * choose text a person cannot read against the canvas behind it. If this
	 * ever passes, validation has been routed around rather than reused.
	 *
	 * @return void
	 */
	public function test_a_contrast_failure_is_rejected_and_stores_nothing(): void {
		wp_set_current_user( $this->manager );

		$this->assertSame( 200, $this->save( $this->valid_document() )->get_status() );

		$document                    = $this->valid_document();
		$document['brand']['text']   = '#f4f4f4';
		$document['brand']['canvas'] = '#ffffff';

		$response = $this->save( $document );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame(
			'#111214',
			( new Settings() )->get()['brand']['text'],
			'A rejected payload must leave the stored document untouched.'
		);
	}

	/**
	 * A JSON `false` turns a switch off.
	 *
	 * The form path signals "off" by omitting the key entirely, so shaping that
	 * tested presence alone would read `false` as on and silently invert every
	 * switch this path touches — turning a kill-switch back on by saving an
	 * unrelated field.
	 *
	 * @return void
	 */
	public function test_a_false_switch_is_stored_as_off(): void {
		wp_set_current_user( $this->manager );

		$this->save( $this->valid_document() );

		$document = $this->valid_document();

		$document['modules'][ Settings_Schema::MODULE_REPORTING ] = false;

		$this->assertSame( 200, $this->save( $document )->get_status() );
		$this->assertFalse(
			( new Settings() )->module_enabled( Settings_Schema::MODULE_REPORTING )
		);
	}

	/**
	 * A malformed support address is refused, not quietly blanked.
	 *
	 * Empty means "fall back to the site admin address", so sanitizing the input
	 * before validating it turned a typo into a silent un-setting: the schema's
	 * rule became unreachable, the autosave answered "Saved.", and the address
	 * advertisers see on the Help screen changed without anybody being told.
	 *
	 * @return void
	 */
	public function test_a_malformed_support_email_is_rejected(): void {
		wp_set_current_user( $this->manager );

		$good                           = $this->valid_document();
		$good['brand']['support_email'] = 'help@example.com';

		$this->assertSame( 200, $this->save( $good )->get_status() );

		$bad                           = $this->valid_document();
		$bad['brand']['support_email'] = 'not-an-address';

		$response = $this->save( $bad );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame(
			'help@example.com',
			( new Settings() )->get()['brand']['support_email'],
			'A rejected address must leave the stored one untouched.'
		);
	}

	/**
	 * A key the schema does not know is not stored.
	 *
	 * @return void
	 */
	public function test_an_unknown_key_is_dropped(): void {
		wp_set_current_user( $this->manager );

		$document                        = $this->valid_document();
		$document['modules']['invented'] = true;
		$document['brand']['invented']   = 'x';

		$this->assertSame( 200, $this->save( $document )->get_status() );

		$stored = ( new Settings() )->get();

		$this->assertArrayNotHasKey( 'invented', $stored['modules'] );
		$this->assertArrayNotHasKey( 'invented', $stored['brand'] );
	}
}
