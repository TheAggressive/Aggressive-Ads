<?php
/**
 * Staff placement mapping management against real WordPress.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Integration;

use LAAO_Advertiser_Portal\Admin\Placement_Mapping_Data;
use LAAO_Advertiser_Portal\Admin\Placement_Mapping_Screen;
use LAAO_Advertiser_Portal\Audit\Audit_Event;
use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Install\Installer;
use LAAO_Advertiser_Portal\Integration\Adsanity\Adsanity;
use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;
use LAAO_Advertiser_Portal\Security\Roles;
use WP_Error;
use WP_UnitTestCase;

/**
 * Proves mapping changes are explicit, authorized, verified, and auditable.
 */
final class PlacementMappingAdminTest extends WP_UnitTestCase {

	/**
	 * Mapping screen controller.
	 *
	 * @var Placement_Mapping_Screen
	 */
	private Placement_Mapping_Screen $screen;

	/**
	 * Mapping read model.
	 *
	 * @var Placement_Mapping_Data
	 */
	private Placement_Mapping_Data $data;

	/**
	 * Placement persistence.
	 *
	 * @var Placement_Repository
	 */
	private Placement_Repository $placements;

	/**
	 * Audit persistence.
	 *
	 * @var Audit_Repository
	 */
	private Audit_Repository $audit;

	/**
	 * Administrator user id.
	 *
	 * @var int
	 */
	private int $administrator;

	/**
	 * Advertiser user id.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * Fixture placement post id.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Fixture provider group term id.
	 *
	 * @var int
	 */
	private int $group_id;

	/**
	 * Installs capabilities and creates one placement and provider group.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->audit      = new Audit_Repository();
		$this->placements = new Placement_Repository();

		( new Installer( $this->audit, new Roles() ) )->install_roles();

		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->advertiser    = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->placement_id  = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage leaderboard',
			)
		);

		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );
		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );

		$term = wp_insert_term( '728x90 Header', Adsanity::TAXONOMY );
		$this->assertIsArray( $term );
		$this->group_id = (int) $term['term_id'];

		$this->screen = Plugin::instance()->container()->get( Placement_Mapping_Screen::class );
		$this->data   = Plugin::instance()->container()->get( Placement_Mapping_Data::class );
	}

	/**
	 * Clears request state changed by handler and render tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * Menu, assets, and authenticated handler are attached.
	 *
	 * @return void
	 */
	public function test_mapping_surface_is_wired(): void {
		$this->assertNotFalse( has_action( 'admin_menu', array( $this->screen, 'register_menu' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( $this->screen, 'enqueue' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . Placement_Mapping_Screen::ACTION, array( $this->screen, 'handle_update' ) ) );
	}

	/**
	 * An administrator can map by immutable term id and the change is audited.
	 *
	 * @return void
	 */
	public function test_authorized_mapping_is_saved_verified_and_audited(): void {
		wp_set_current_user( $this->administrator );

		$this->assertTrue( $this->screen->process_update( $this->placement_id, $this->group_id ) );
		$this->assertSame( $this->group_id, $this->placements->adgroup_term_id( $this->placement_id ) );

		$events = $this->audit->for_object( 'placement', $this->placement_id, 0 );

		$this->assertSame( 'placement.mapping_updated', $events[0]['event'] );
		$this->assertSame( 'ok', $events[0]['outcome'] );
	}

	/**
	 * Clearing is explicit and returns the placement to a blocking state.
	 *
	 * @return void
	 */
	public function test_mapping_can_be_cleared_explicitly(): void {
		wp_set_current_user( $this->administrator );
		$this->placements->set_adgroup_term_id( $this->placement_id, $this->group_id );

		$this->assertTrue( $this->screen->process_update( $this->placement_id, 0 ) );
		$this->assertSame( 0, $this->placements->adgroup_term_id( $this->placement_id ) );
	}

	/**
	 * An arbitrary term id never reaches persistence.
	 *
	 * @return void
	 */
	public function test_unknown_provider_group_is_rejected(): void {
		wp_set_current_user( $this->administrator );

		$result = $this->screen->process_update( $this->placement_id, 999999 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'laao_ads_invalid_adgroup', $result->get_error_code() );
		$this->assertSame( 0, $this->placements->adgroup_term_id( $this->placement_id ) );
	}

	/**
	 * A group deleted between page render and form submission fails closed.
	 *
	 * @return void
	 */
	public function test_deleted_provider_group_is_rejected(): void {
		wp_set_current_user( $this->administrator );
		wp_delete_term( $this->group_id, Adsanity::TAXONOMY );

		$result = $this->screen->process_update( $this->placement_id, $this->group_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'laao_ads_invalid_adgroup', $result->get_error_code() );
	}

	/**
	 * Advertisers cannot mutate the global delivery configuration.
	 *
	 * @return void
	 */
	public function test_advertiser_is_denied_and_the_mapping_is_unchanged(): void {
		wp_set_current_user( $this->advertiser );

		$result = $this->screen->process_update( $this->placement_id, $this->group_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'laao_ads_forbidden', $result->get_error_code() );
		$this->assertSame( 0, $this->placements->adgroup_term_id( $this->placement_id ) );

		$events = $this->audit->for_object( 'placement', $this->placement_id, 0 );
		$this->assertSame( Audit_Event::OUTCOME_DENIED, $events[0]['outcome'] );
	}

	/**
	 * The read model surfaces mapped, unmapped, dangling, active and inactive.
	 *
	 * @return void
	 */
	public function test_read_model_names_every_configuration_state(): void {
		$inactive = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Inactive sidebar',
			)
		);
		$dangling = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Deleted-group placement',
			)
		);

		$this->placements->set_adgroup_term_id( $this->placement_id, $this->group_id );
		update_post_meta( $dangling, Placement_Repository::META_ADGROUP_TERM, 999999 );

		$rows   = $this->data->view()['rows'];
		$states = array_column( $rows, 'state', 'id' );
		$active = array_column( $rows, 'active', 'id' );

		$this->assertSame( 'mapped', $states[ $this->placement_id ] );
		$this->assertSame( 'unmapped', $states[ $inactive ] );
		$this->assertSame( 'dangling', $states[ $dangling ] );
		$this->assertFalse( $active[ $inactive ] );
	}

	/**
	 * The rendered table contains scoped nonces and escapes provider names.
	 *
	 * @return void
	 */
	public function test_screen_renders_accessible_mapping_forms(): void {
		wp_set_current_user( $this->administrator );

		ob_start();
		$this->screen->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Ad delivery mappings', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'AdSanity group for Homepage leaderboard', $html );
		$this->assertStringContainsString( '728x90 Header', $html );
		$this->assertStringContainsString( 'ID ' . $this->group_id, $html );
	}

	/**
	 * With no provider groups, no writable form is rendered.
	 *
	 * @return void
	 */
	public function test_empty_provider_catalogue_disables_mapping_controls(): void {
		wp_set_current_user( $this->administrator );
		wp_delete_term( $this->group_id, Adsanity::TAXONOMY );

		ob_start();
		$this->screen->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No AdSanity ad groups exist', $html );
		$this->assertStringNotContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'Mapping controls unavailable.', $html );
	}

	/**
	 * Provider absence is not misreported as a deleted mapping target.
	 *
	 * @return void
	 */
	public function test_provider_absence_marks_existing_mapping_as_not_checked(): void {
		$this->placements->set_adgroup_term_id( $this->placement_id, $this->group_id );
		unregister_taxonomy( Adsanity::TAXONOMY );

		try {
			$view = $this->data->view();
		} finally {
			laao_ads_stub_register_adsanity();
		}

		$states = array_column( $view['rows'], 'state', 'id' );
		$terms  = array_column( $view['rows'], 'term_id', 'id' );

		$this->assertInstanceOf( WP_Error::class, $view['provider_error'] );
		$this->assertSame( 'unavailable', $states[ $this->placement_id ] );
		$this->assertSame( $this->group_id, $terms[ $this->placement_id ], 'Provider absence cleared the stored mapping.' );
	}

	/**
	 * Missing and cross-placement nonces die before the workflow runs.
	 *
	 * @return void
	 */
	public function test_handler_rejects_a_missing_nonce(): void {
		wp_set_current_user( $this->administrator );
		$_POST = array(
			'placement_id'    => (string) $this->placement_id,
			'adgroup_term_id' => (string) $this->group_id,
		);

		$this->expectException( 'WPDieException' );
		$this->screen->handle_update();
	}

	/**
	 * Capability denial precedes nonce acceptance.
	 *
	 * @return void
	 */
	public function test_handler_rejects_an_advertiser_with_a_valid_nonce(): void {
		wp_set_current_user( $this->advertiser );
		$_POST = array(
			'placement_id'    => (string) $this->placement_id,
			'adgroup_term_id' => (string) $this->group_id,
			'_wpnonce'        => wp_create_nonce( Placement_Mapping_Screen::nonce_action( $this->placement_id ) ),
		);

		$this->expectException( 'WPDieException' );
		$this->screen->handle_update();
	}
}
