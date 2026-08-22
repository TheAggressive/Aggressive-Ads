<?php
/**
 * Staff organization suspension screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\REST\Creative_File_Controller;
use Aggressive\Ads\Security\Capabilities;

/**
 * Delivers suspension controls without exposing the generic organization editor.
 */
final class Organization_Screen implements Service {

	public const MENU_SLUG = 'aggr-organizations';

	/**
	 * Constructor.
	 *
	 * @param Organization_Data $data Screen read model.
	 */
	public function __construct( private readonly Organization_Data $data ) {
	}

	/**
	 * Attaches the menu and the screen's bundle.
	 *
	 * There are no admin-post handlers any more. State changes go to
	 * REST\Organizations_Controller, which calls the same workflow the handlers
	 * called — one authenticated path to suspension rather than two.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * The screen's own hook suffix, captured at registration.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Loads the screen's bundle, on this screen only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$asset = AGGR_PLUGIN_DIR . 'dist/admin/organizations.asset.php';

		if ( ! is_file( $asset ) ) {
			return;
		}

		$meta = require $asset;

		$version = is_string( $meta['version'] ?? null ) ? $meta['version'] : AGGR_VERSION;

		wp_enqueue_script(
			'aggr-organizations',
			AGGR_PLUGIN_URL . 'dist/admin/organizations.js',
			is_array( $meta['dependencies'] ?? null ) ? $meta['dependencies'] : array(),
			$version,
			true
		);

		wp_enqueue_style( 'wp-components' );

		/*
		 * DataViews ships its own stylesheet, and it is not optional.
		 *
		 * WordPress registers no `wp-dataviews` style handle any more than it
		 * registers the script one, so the bundle carries its own copy. Without
		 * it the table renders as unstyled markup that still technically works
		 * — which is the failure mode worth naming, because nothing errors.
		 *
		 * `wp-components` is listed as a dependency so core's variables and
		 * resets load first and DataViews can override them, not the reverse.
		 */
		wp_enqueue_style(
			'aggr-organizations',
			AGGR_PLUGIN_URL . 'dist/admin/organizations.css',
			array( 'wp-components' ),
			$version
		);

		// The build emits organizations-rtl.css beside it; core swaps the file
		// wholesale rather than appending overrides.
		wp_style_add_data( 'aggr-organizations', 'rtl', 'replace' );
	}

	/**
	 * Registers a capability-owned submenu under Advertising.
	 */
	public function register_menu(): void {
		$hook = add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Organizations', 'aggressive-ads' ),
			__( 'Organizations', 'aggressive-ads' ),
			Capabilities::MANAGE_ORGS,
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		$this->hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/** Renders the authorized organization table. */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ORGS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'aggressive-ads' ),
				'',
				array( 'response' => 403 )
			);
		}

		$this->render_screen();
	}

	/**
	 * Prints the mount point and the roster it edits.
	 *
	 * @return void
	 */
	private function render_screen(): void {
		if ( ! is_file( AGGR_PLUGIN_DIR . 'dist/admin/organizations.asset.php' ) ) {
			printf(
				'<div class="wrap"><h1>%1$s</h1><div class="notice notice-error"><p>%2$s</p></div></div>',
				esc_html__( 'Organizations', 'aggressive-ads' ),
				esc_html__( 'The organizations screen has not been built. Run “pnpm build” and reload.', 'aggressive-ads' )
			);

			return;
		}

		$payload = array(
			'view'     => $this->data->view(),
			'restPath' => '/' . Creative_File_Controller::NAMESPACE . '/organizations',
			'i18n'     => array(
				'empty'           => __( 'No organizations exist yet.', 'aggressive-ads' ),
				'stateActive'     => __( 'Active', 'aggressive-ads' ),
				'stateSuspended'  => __( 'Suspended', 'aggressive-ads' ),
				'suspend'         => __( 'Suspend', 'aggressive-ads' ),
				'membersSection'  => __( 'Members', 'aggressive-ads' ),
				'onlyOwner'       => __( 'The owner is the only member. Ownership can only move to another member, so invite someone first.', 'aggressive-ads' ),
				'name'            => __( 'Organization name', 'aggressive-ads' ),
				'rename'          => __( 'Rename', 'aggressive-ads' ),
				'renamed'         => __( 'Organization renamed.', 'aggressive-ads' ),
				'ownerTag'        => __( 'owner', 'aggressive-ads' ),
				'makeOwner'       => __( 'Make owner', 'aggressive-ads' ),
				'ownerChanged'    => __( 'Ownership transferred.', 'aggressive-ads' ),
				'removeMember'    => __( 'Remove', 'aggressive-ads' ),
				'memberRemoved'   => __( 'Member removed.', 'aggressive-ads' ),
				'inviteMember'    => __( 'Invite someone to this organization', 'aggressive-ads' ),
				'inviteHelp'      => __( 'Email address. To move somebody between organizations, remove them here and invite them there — they keep no access in between.', 'aggressive-ads' ),
				'invite'          => __( 'Send invitation', 'aggressive-ads' ),
				'invited'         => __( 'Invitation sent.', 'aggressive-ads' ),
				'reactivate'      => __( 'Reactivate', 'aggressive-ads' ),
				'suspended'       => __( 'Organization suspended.', 'aggressive-ads' ),
				'reactivated'     => __( 'Organization reactivated.', 'aggressive-ads' ),
				/* translators: %s: organization name. */
				'confirmSuspend'  => __( 'Suspend %s? Every campaign it is running stops serving immediately.', 'aggressive-ads' ),
				/* translators: %d: number of members. */
				'memberOne'       => __( '%d member', 'aggressive-ads' ),
				/* translators: %d: number of members. */
				'memberMany'      => __( '%d members', 'aggressive-ads' ),
				/* translators: %d: number of campaigns. */
				'campaignOne'     => __( '%d campaign', 'aggressive-ads' ),
				/* translators: %d: number of campaigns. */
				'campaignMany'    => __( '%d campaigns', 'aggressive-ads' ),
				'saveFailed'      => __( 'That organization could not be updated.', 'aggressive-ads' ),
				'retry'           => __( 'Try again', 'aggressive-ads' ),
				'ownerColumn'     => __( 'Owner', 'aggressive-ads' ),
				'campaignsColumn' => __( 'Campaigns', 'aggressive-ads' ),
				'stateColumn'     => __( 'State', 'aggressive-ads' ),
				'manageMembers'   => __( 'Manage members', 'aggressive-ads' ),
				'cancel'          => __( 'Cancel', 'aggressive-ads' ),
			),
		);

		printf(
			'<div class="wrap aggr-admin"><h1>%1$s</h1><noscript><div class="notice notice-error"><p>%2$s</p></div></noscript><div id="aggr-organizations-root" data-aggr-organizations="%3$s"></div></div>',
			esc_html__( 'Organizations', 'aggressive-ads' ),
			esc_html__( 'The organizations screen needs JavaScript enabled.', 'aggressive-ads' ),
			esc_attr( (string) wp_json_encode( $payload ) )
		);
	}

	/** Screen URL. */
	public static function url(): string {
		return add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) );
	}
}
