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
use Aggressive\Ads\REST\Conversion_Credentials_Controller;
use Aggressive\Ads\REST\Conversion_Definitions_Controller;
use Aggressive\Ads\REST\Creative_File_Controller;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
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
	 * The two controllers are here to be *read from*, not routed through. Both
	 * lists are seeded into the mount payload by calling the same `index()` the
	 * browser would have called, so the rows the screen starts with and the rows
	 * it refetches after a write are composed once. Shaping them here instead
	 * would be the second rule this class's docblock exists to avoid.
	 *
	 * @param Org_Repository                    $orgs        Organization lookups, for the credential scope.
	 * @param Package_Repository                $packages    Currencies this site already prices in.
	 * @param Conversion_Definitions_Controller $definitions The definitions list, as REST composes it.
	 * @param Conversion_Credentials_Controller $credentials The credentials list, as REST composes it.
	 */
	public function __construct(
		private readonly Org_Repository $orgs,
		private readonly Package_Repository $packages,
		private readonly Conversion_Definitions_Controller $definitions,
		private readonly Conversion_Credentials_Controller $credentials
	) {
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
		 * This screen's own rules, with the shared DataViews stylesheet named
		 * as a dependency rather than enqueued beside it, so it always loads
		 * first: the rules here give DataViews its container and would lose to
		 * it otherwise.
		 *
		 * A script dependency does not bring a stylesheet — WordPress resolves
		 * script and style handles separately — so this is the only thing that
		 * puts DataViews' CSS on the page. Without it both tables render as
		 * unstyled markup that still technically works, which is the failure
		 * worth naming because nothing errors.
		 */
		wp_enqueue_style(
			'aggr-conversions',
			AGGR_PLUGIN_URL . 'dist/admin/conversions.css',
			array( 'wp-components', Shared_Assets::DATAVIEWS ),
			is_string( $meta['version'] ?? null ) ? $meta['version'] : AGGR_VERSION
		);

		// The build emits conversions-rtl.css beside it; core swaps the file
		// wholesale rather than appending overrides.
		wp_style_add_data( 'aggr-conversions', 'rtl', 'replace' );
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

			/*
			 * Both lists travel with the page, the way every other Advertising
			 * screen's rows do. Fetching them on mount cost a whole round trip
			 * *after* React had booted — so the screen rendered a spinner over
			 * data the server had already assembled while rendering the markup
			 * around it, on the one screen that pays for the DataViews bundle
			 * first.
			 *
			 * A refetch after a write still goes to REST. This seeds the first
			 * paint, it does not replace the routes.
			 *
			 * **These rows carry the public keys pages report against**, which
			 * is why `Conversion_Definitions_Controller::index()` answers
			 * `Cache-Control: no-store`: a shared cache holding one staff
			 * response and serving it to somebody else would hand over every
			 * one of them. Embedding the same rows in the page inherits that
			 * concern and `wp-admin/admin.php`'s own `nocache_headers()` is
			 * what answers it — plus the capability check above, which is the
			 * reason `ConversionsScreenTest` asserts an unauthorized render
			 * emits no key rather than merely that it dies.
			 */
			'definitions'     => $this->seeded( $this->definitions->index()->get_data(), 'definitions' ),
			'credentials'     => $this->seeded( $this->credentials->index()->get_data(), 'credentials' ),
			'windows'         => self::windows(),
			'currencies'      => Currency_Options::options(
				$this->priced_currencies(),
				__( 'Choose a currency', 'aggressive-ads' )
			),
			'defaultCurrency' => Currency_Options::default_for( $this->priced_currencies() ),
			'advertisers'     => $this->advertisers(),
			'i18n'            => array(
				'newDefinition'        => __( 'New conversion', 'aggressive-ads' ),
				'existing'             => __( 'Conversions', 'aggressive-ads' ),
				'none'                 => __( 'No conversions are defined yet. Nothing will be recorded until one is.', 'aggressive-ads' ),
				'name'                 => __( 'Name', 'aggressive-ads' ),
				'nameHelp'             => __( 'What the outcome is, in the words a report about it will use.', 'aggressive-ads' ),
				'window'               => __( 'Attribution window', 'aggressive-ads' ),
				'windowHelp'           => __( 'How long after a click an outcome still counts.', 'aggressive-ads' ),
				'value'                => __( 'Value', 'aggressive-ads' ),
				'valueHelp'            => __( 'What one conversion is worth. Leave empty for a signup or anything else with no price.', 'aggressive-ads' ),
				'currency'             => __( 'Currency', 'aggressive-ads' ),
				'currencyHelp'         => __( 'What the value above is denominated in.', 'aggressive-ads' ),
				'currencyDisabledHelp' => __( 'Set a value first. An outcome worth nothing needs no currency.', 'aggressive-ads' ),
				'orgScoped'            => __( 'Limit to one advertiser', 'aggressive-ads' ),
				'orgScopedHelp'        => __( 'Off means any campaign may be credited. On means only the advertiser you choose.', 'aggressive-ads' ),
				'snippetKey'           => __( 'Reporting key', 'aggressive-ads' ),
				'status'               => __( 'Status', 'aggressive-ads' ),
				'actions'              => __( 'Actions', 'aggressive-ads' ),
				'active'               => __( 'Accepting reports', 'aggressive-ads' ),
				'archived'             => __( 'Archived', 'aggressive-ads' ),
				'archive'              => __( 'Archive', 'aggressive-ads' ),
				'edit'                 => __( 'Edit', 'aggressive-ads' ),
				'editDefinition'       => __( 'Edit conversion', 'aggressive-ads' ),
				'save'                 => __( 'Save changes', 'aggressive-ads' ),
				'create'               => __( 'Create conversion', 'aggressive-ads' ),
				'days'                 => __( 'days', 'aggressive-ads' ),
				'saveFailed'           => __( 'That conversion could not be saved.', 'aggressive-ads' ),
				'allowS2s'             => __( 'Accept reports from the advertiser’s server', 'aggressive-ads' ),
				'allowS2sHelp'         => __( 'Off means only a browser may report this conversion, and never with a value. On also needs a credential, below.', 'aggressive-ads' ),
				'serverReports'        => __( 'Server reports', 'aggressive-ads' ),
				'yes'                  => __( 'Yes', 'aggressive-ads' ),
				'no'                   => __( 'No', 'aggressive-ads' ),

				'credentials'          => __( 'Server-to-server credentials', 'aggressive-ads' ),
				'credentialsHelp'      => __( 'A credential lets an advertiser’s own server report a conversion, and state what it was worth. Give one to a single integration, so revoking it stops that integration and nothing else.', 'aggressive-ads' ),
				'credentialsNone'      => __( 'No credentials have been issued. A server cannot report a conversion without one.', 'aggressive-ads' ),
				'newCredential'        => __( 'Issue a credential', 'aggressive-ads' ),
				'label'                => __( 'Name', 'aggressive-ads' ),
				'labelHelp'            => __( 'Where this credential is used, so it can be told apart when one has to be revoked.', 'aggressive-ads' ),
				'advertiser'           => __( 'Advertiser', 'aggressive-ads' ),
				'advertiserHelp'       => __( 'The only organization this credential may report for.', 'aggressive-ads' ),
				'noAdvertisers'        => __( 'A credential reports for one advertiser, and there are no active advertisers yet.', 'aggressive-ads' ),
				'issue'                => __( 'Issue credential', 'aggressive-ads' ),
				'issuedOnce'           => __( 'Copy this secret now. It is shown once and cannot be read again — if it is lost, revoke it and issue another.', 'aggressive-ads' ),
				'copy'                 => __( 'Copy', 'aggressive-ads' ),
				'copied'               => __( 'Copied', 'aggressive-ads' ),
				'issued'               => __( 'Issued', 'aggressive-ads' ),
				'lastUsed'             => __( 'Last used', 'aggressive-ads' ),
				'never'                => __( 'Never', 'aggressive-ads' ),
				'live'                 => __( 'Live', 'aggressive-ads' ),
				'revoke'               => __( 'Revoke', 'aggressive-ads' ),
				'revoked'              => __( 'Revoked', 'aggressive-ads' ),
				'revokeConfirm'        => __( 'Revoke this credential? Anything still using it stops reporting immediately.', 'aggressive-ads' ),
				'credentialFailed'     => __( 'That credential could not be issued.', 'aggressive-ads' ),
				'credentialIssued'     => __( 'Credential issued', 'aggressive-ads' ),
				'cancel'               => __( 'Cancel', 'aggressive-ads' ),
				'done'                 => __( 'Done', 'aggressive-ads' ),
				'searchDefinitions'    => __( 'Search conversions', 'aggressive-ads' ),
				'searchCredentials'    => __( 'Search credentials', 'aggressive-ads' ),
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
	 * One list out of a controller response, or an empty list.
	 *
	 * `get_data()` is typed as mixed because a `WP_REST_Response` can carry
	 * anything, so this narrows rather than trusts. What it buys at runtime is
	 * only that a changed envelope becomes an empty list instead of a malformed
	 * value in a `data-` attribute for the browser to choke on — the screen
	 * would then show no rows, which is a real failure and not a graceful one.
	 *
	 * What actually protects against that is `ConversionsScreenTest`, which
	 * asserts this payload equals the controller's own response. A controller
	 * that changed its envelope fails there, loudly, rather than shipping a
	 * screen that quietly renders nothing.
	 *
	 * @param mixed  $data Response data.
	 * @param string $key  Envelope key holding the list.
	 * @return array<int, mixed>
	 */
	private function seeded( mixed $data, string $key ): array {
		if ( ! is_array( $data ) || ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
			return array();
		}

		return array_values( $data[ $key ] );
	}

	/**
	 * Distinct currencies this site's packages are priced in.
	 *
	 * The order is the order `Currency_Options` puts first, so a publisher sees
	 * their own currency before a general list they have to search.
	 *
	 * @return list<string>
	 */
	private function priced_currencies(): array {
		$found = array();

		foreach ( $this->packages->all_ids() as $package_id ) {
			$code = strtoupper( $this->packages->currency( $package_id ) );

			if ( Conversion_Rules::is_valid_currency( $code ) && ! in_array( $code, $found, true ) ) {
				$found[] = $code;
			}
		}

		return $found;
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
