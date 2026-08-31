<?php
/**
 * The conversion definitions screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Conversion_Rules;
use Aggressive\Ads\REST\Creative_File_Controller;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Capabilities;

/**
 * Where a publisher says what counts as a conversion.
 *
 * Until this existed a definition could only be created over REST, which meant
 * the whole conversion path — three merged pull requests of it — was unreachable
 * without curl. The screen is deliberately thin: every write goes to
 * `REST\Conversion_Definitions_Controller`, which is thin over
 * `Conversion_Definition_Manager`, so there is one authenticated path to a
 * definition rather than two that have to be kept in agreement.
 */
final class Conversions_Screen implements Service {

	public const MENU_SLUG = 'aggr-conversions';

	/**
	 * This screen's admin hook suffix, or empty before the menu registers.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Org_Repository $orgs Organization lookups, for the credential scope.
	 */
	public function __construct( private readonly Org_Repository $orgs ) {
	}

	/**
	 * Attaches the menu and the bundle.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Loads the screen's bundle, on this screen only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$asset = AGGR_PLUGIN_DIR . 'dist/admin/conversions.asset.php';

		if ( ! is_file( $asset ) ) {
			return;
		}

		$meta = require $asset;

		// The bundle's .asset.php names aggr-dataviews as a dependency, because
		// the build rewrote its @wordpress/dataviews import onto the shared
		// copy. Registering it here is what lets WordPress resolve that.
		Shared_Assets::register();

		wp_enqueue_script(
			'aggr-conversions',
			AGGR_PLUGIN_URL . 'dist/admin/conversions.js',
			is_array( $meta['dependencies'] ?? null ) ? $meta['dependencies'] : array(),
			is_string( $meta['version'] ?? null ) ? $meta['version'] : AGGR_VERSION,
			true
		);

		wp_enqueue_style( 'wp-components' );

		/*
		 * The DataViews stylesheet, enqueued rather than depended on: a script
		 * dependency does not bring a stylesheet, because WordPress resolves
		 * script and style handles separately. Without this the two tables
		 * render as unstyled markup that still technically works — the failure
		 * worth naming, because nothing errors.
		 *
		 * This screen ships no stylesheet of its own to hang it off, unlike the
		 * organization roster, so it is named here directly.
		 */
		wp_enqueue_style( Shared_Assets::DATAVIEWS );
	}

	/**
	 * Registers a capability-owned submenu under Advertising.
	 *
	 * `aggr_manage_settings`, the same capability the REST routes require. A
	 * definition carries the public key a page reports against, so reading one
	 * is as sensitive as writing it — there is no browse-only tier here the way
	 * there is for packages.
	 */
	public function register_menu(): void {
		$hook = add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Conversions', 'aggressive-ads' ),
			__( 'Conversions', 'aggressive-ads' ),
			Capabilities::MANAGE_SETTINGS,
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		$this->hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Renders the screen for an authorized caller.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'aggressive-ads' ),
				'',
				array( 'response' => 403 )
			);
		}

		if ( ! is_file( AGGR_PLUGIN_DIR . 'dist/admin/conversions.asset.php' ) ) {
			printf(
				'<div class="wrap"><h1>%1$s</h1><div class="notice notice-error"><p>%2$s</p></div></div>',
				esc_html__( 'Conversions', 'aggressive-ads' ),
				esc_html__( 'The conversions screen has not been built. Run “pnpm build” and reload.', 'aggressive-ads' )
			);

			return;
		}

		$payload = array(
			'restPath'        => '/' . Creative_File_Controller::NAMESPACE . '/conversion-definitions',
			'credentialsPath' => '/' . Creative_File_Controller::NAMESPACE . '/conversion-credentials',
			'windows'         => self::windows(),
			'advertisers'     => $this->advertisers(),
			'i18n'            => array(
				'newDefinition'     => __( 'New conversion', 'aggressive-ads' ),
				'existing'          => __( 'Conversions', 'aggressive-ads' ),
				'none'              => __( 'No conversions are defined yet. Nothing will be recorded until one is.', 'aggressive-ads' ),
				'name'              => __( 'Name', 'aggressive-ads' ),
				'window'            => __( 'Attribution window', 'aggressive-ads' ),
				'windowHelp'        => __( 'How long after a click an outcome still counts.', 'aggressive-ads' ),
				'value'             => __( 'Value', 'aggressive-ads' ),
				'valueHelp'         => __( 'What one conversion is worth. Leave empty for an outcome with no value, such as a signup.', 'aggressive-ads' ),
				'currency'          => __( 'Currency', 'aggressive-ads' ),
				'currencyHelp'      => __( 'Three letters, such as USD. Required when a value is set.', 'aggressive-ads' ),
				'orgScoped'         => __( 'Limit to one advertiser', 'aggressive-ads' ),
				'orgScopedHelp'     => __( 'Off means any campaign may be credited. On means only that advertiser’s campaigns can.', 'aggressive-ads' ),
				'orgId'             => __( 'Organization ID', 'aggressive-ads' ),
				'snippetKey'        => __( 'Reporting key', 'aggressive-ads' ),
				'status'            => __( 'Status', 'aggressive-ads' ),
				'actions'           => __( 'Actions', 'aggressive-ads' ),
				'active'            => __( 'Accepting reports', 'aggressive-ads' ),
				'archived'          => __( 'Archived', 'aggressive-ads' ),
				'archive'           => __( 'Archive', 'aggressive-ads' ),
				'create'            => __( 'Create conversion', 'aggressive-ads' ),
				'days'              => __( 'days', 'aggressive-ads' ),
				'loadFailed'        => __( 'The conversions could not be loaded.', 'aggressive-ads' ),
				'saveFailed'        => __( 'That conversion could not be saved.', 'aggressive-ads' ),
				'allowS2s'          => __( 'Accept reports from the advertiser’s server', 'aggressive-ads' ),
				'allowS2sHelp'      => __( 'Off means only a browser may report this conversion, and never with a value. On also needs a credential, below.', 'aggressive-ads' ),
				'serverReports'     => __( 'Server reports', 'aggressive-ads' ),
				'yes'               => __( 'Yes', 'aggressive-ads' ),
				'no'                => __( 'No', 'aggressive-ads' ),

				'credentials'       => __( 'Server-to-server credentials', 'aggressive-ads' ),
				'credentialsHelp'   => __( 'A credential lets an advertiser’s own server report a conversion, and state what it was worth. Give one to a single integration, so revoking it stops that integration and nothing else.', 'aggressive-ads' ),
				'credentialsList'   => __( 'Issued credentials', 'aggressive-ads' ),
				'credentialsNone'   => __( 'No credentials have been issued. A server cannot report a conversion without one.', 'aggressive-ads' ),
				'newCredential'     => __( 'Issue a credential', 'aggressive-ads' ),
				'label'             => __( 'Name', 'aggressive-ads' ),
				'labelHelp'         => __( 'Where this credential is used, so it can be told apart when one has to be revoked.', 'aggressive-ads' ),
				'advertiser'        => __( 'Advertiser', 'aggressive-ads' ),
				'advertiserHelp'    => __( 'The only organization this credential may report for.', 'aggressive-ads' ),
				'noAdvertisers'     => __( 'A credential reports for one advertiser, and there are no active advertisers yet.', 'aggressive-ads' ),
				'issue'             => __( 'Issue credential', 'aggressive-ads' ),
				'issuedOnce'        => __( 'Copy this secret now. It is shown once and cannot be read again — if it is lost, revoke it and issue another.', 'aggressive-ads' ),
				'copy'              => __( 'Copy', 'aggressive-ads' ),
				'copied'            => __( 'Copied', 'aggressive-ads' ),
				'issued'            => __( 'Issued', 'aggressive-ads' ),
				'lastUsed'          => __( 'Last used', 'aggressive-ads' ),
				'never'             => __( 'Never', 'aggressive-ads' ),
				'live'              => __( 'Live', 'aggressive-ads' ),
				'revoke'            => __( 'Revoke', 'aggressive-ads' ),
				'revoked'           => __( 'Revoked', 'aggressive-ads' ),
				'revokeConfirm'     => __( 'Revoke this credential? Anything still using it stops reporting immediately.', 'aggressive-ads' ),
				'credentialFailed'  => __( 'That credential could not be issued.', 'aggressive-ads' ),
				'searchDefinitions' => __( 'Search conversions', 'aggressive-ads' ),
				'searchCredentials' => __( 'Search credentials', 'aggressive-ads' ),
			),
		);

		printf(
			'<div class="wrap aggr-admin"><h1>%1$s</h1><noscript><div class="notice notice-error"><p>%2$s</p></div></noscript><div id="aggr-conversions-root" data-aggr-conversions="%3$s"></div></div>',
			esc_html__( 'Conversions', 'aggressive-ads' ),
			esc_html__( 'The conversions screen needs JavaScript enabled.', 'aggressive-ads' ),
			esc_attr( (string) wp_json_encode( $payload ) )
		);
	}

	/**
	 * Active advertisers, as the only scopes a credential may be issued for.
	 *
	 * Only active ones, because `Conversion_Credential_Manager::issue()` refuses
	 * an organization that does not resolve — offering an inactive one would be
	 * offering a choice that cannot succeed.
	 *
	 * Organization 0 is absent and cannot be typed here, which matches the
	 * manager rather than restating it: an org-0 definition accepts a conversion
	 * from any campaign because the visitor reporting it is anonymous, and a
	 * credential never is.
	 *
	 * @return array<int, array{id: int, name: string}>
	 */
	private function advertisers(): array {
		$rows = array();

		foreach ( $this->orgs->all_ids() as $org_id ) {
			if ( ! $this->orgs->is_active( $org_id ) ) {
				continue;
			}

			$rows[] = array(
				'id'   => $org_id,
				'name' => $this->orgs->name( $org_id ),
			);
		}

		return $rows;
	}

	/**
	 * The attribution windows the screen offers.
	 *
	 * Each candidate is passed through `Conversion_Rules::window_seconds()`,
	 * which is the same clamp the validator applies on save. So the value the
	 * select offers is by construction the value that would be stored — a
	 * control cannot promise a one-day window that quietly saves as an hour.
	 *
	 * Filtering a hand-written list against the bounds was the first attempt.
	 * PHPStan pointed out the comparison could never be true for the list as
	 * written, which is the same lesson the deleted conversions-boundary option
	 * taught: a guard that cannot fire is decoration. Clamping through the
	 * domain is the version that actually holds when somebody adds a year.
	 *
	 * Duplicates are dropped, because two candidates either side of a bound
	 * clamp to the same second and a select with the same option twice looks
	 * broken.
	 *
	 * @return array<int, array{label: string, value: string}>
	 */
	private static function windows(): array {
		$options = array();
		$seen    = array();

		foreach ( array( 1, 7, 14, 30, 60, 90 ) as $count ) {
			$seconds = Conversion_Rules::window_seconds( $count * DAY_IN_SECONDS );

			if ( isset( $seen[ $seconds ] ) ) {
				continue;
			}

			$seen[ $seconds ] = true;
			$days             = (int) round( $seconds / DAY_IN_SECONDS );

			$options[] = array(
				/* translators: %d: number of days. */
				'label' => sprintf( _n( '%d day', '%d days', $days, 'aggressive-ads' ), $days ),
				'value' => (string) $seconds,
			);
		}

		return $options;
	}

	/**
	 * Screen URL.
	 */
	public static function url(): string {
		return add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) );
	}
}
