<?php
/**
 * The composition root.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads;

use Aggressive\Ads\Admin\Action_Notice;
use Aggressive\Ads\Admin\Menu;
use Aggressive\Ads\Admin\Organization_Screen;
use Aggressive\Ads\Admin\Package_Screen;
use Aggressive\Ads\Admin\Placement_Screen;
use Aggressive\Ads\Admin\Review_Screen;
use Aggressive\Ads\Admin\Settings_Screen;
use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Assets\Brand_Styles;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Install\Assignment_Health;
use Aggressive\Ads\Install\Decision_Health;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Install\Line_Item_Migrator;
use Aggressive\Ads\Install\Rewrite_Flusher;
use Aggressive\Ads\Install\Rewrite_Health;
use Aggressive\Ads\Install\Site_Lifecycle;
use Aggressive\Ads\Install\Upgrader;
use Aggressive\Ads\Notification\Ending_Soon_Mailer;
use Aggressive\Ads\Notification\Notification_Service;
use Aggressive\Ads\Notification\Request_Mailer;
use Aggressive\Ads\Portal\Account_Actions;
use Aggressive\Ads\Portal\Acting_Actions;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Creative_Actions;
use Aggressive\Ads\Portal\Email_Change_Actions;
use Aggressive\Ads\Portal\Login_Actions;
use Aggressive\Ads\Portal\Organization_Actions;
use Aggressive\Ads\Portal\Password_Actions;
use Aggressive\Ads\Portal\Report_Actions;
use Aggressive\Ads\Portal\Router;
use Aggressive\Ads\Portal\Signup_Actions;
use Aggressive\Ads\REST\Beacon_Controller;
use Aggressive\Ads\REST\Campaigns_Controller;
use Aggressive\Ads\REST\Line_Items_Controller;
use Aggressive\Ads\REST\Creative_Controller;
use Aggressive\Ads\REST\Creative_File_Controller;
use Aggressive\Ads\REST\Decision_Trace_Controller;
use Aggressive\Ads\REST\Decisions_Controller;
use Aggressive\Ads\REST\Fill_Controller;
use Aggressive\Ads\REST\Packages_Controller;
use Aggressive\Ads\REST\Organizations_Controller;
use Aggressive\Ads\REST\Settings_Controller;
use Aggressive\Ads\REST\Placements_Controller;
use Aggressive\Ads\REST\Review_Controller;
use Aggressive\Ads\REST\Transitions_Controller;
use Aggressive\Ads\Security\Admin_Guard;
use Aggressive\Ads\Security\Delivery_Health;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Private_Storage_Health;
use Aggressive\Ads\Admin\Media_Library;
use Aggressive\Ads\Update\Plugin_Updates;
use Aggressive\Ads\Workflow\Campaign_Change_Manager;
use Aggressive\Ads\Workflow\Campaign_Clock;
use Aggressive\Ads\Workflow\Campaign_State_Machine;
use Aggressive\Ads\Workflow\Line_Item_Lifecycle;
use Aggressive\Ads\Workflow\Click_Hop;
use Aggressive\Ads\Workflow\Audit_Retention;
use Aggressive\Ads\Workflow\Creative_Retention;
use Aggressive\Ads\Workflow\Ending_Soon_Notifier;
use Aggressive\Ads\Workflow\Event_Retention;
use Aggressive\Ads\Workflow\Fill_Cache;
use Aggressive\Ads\Workflow\Placement_Slot;
use Aggressive\Ads\Workflow\Rollup_Reconciler;

/**
 * Wires the application together, then starts it.
 *
 * Registration and initialization are deliberately separate. Registering must
 * never cause application behaviour — a factory is stored, nothing runs.
 * Behaviour begins only when init_services() calls init(), in an order this
 * file makes visible. Factories live in Service_Registrar so this class stays
 * about boot and init order.
 *
 * Adding a service costs two greppable edits: Service_Registrar::register() and,
 * when the service needs hooks, service_init_order() below.
 * See docs/architecture.md.
 */
final class Plugin {

	/**
	 * The single instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * The container.
	 *
	 * @var Service_Container
	 */
	private Service_Container $container;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Whether init_services() has already run.
	 *
	 * An instance property rather than a static local, so the guard belongs to
	 * the object it protects and a test can construct a fresh one.
	 *
	 * @var bool
	 */
	private bool $services_initialized = false;

	/**
	 * Constructor. Private — use instance().
	 */
	private function __construct() {
		$this->container = new Service_Container();
	}

	/**
	 * Returns the single instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers services and schedules their initialization.
	 *
	 * Initialization is deferred to `plugins_loaded` priority 10 so that every
	 * other plugin on the site has declared itself before any of our services
	 * look for it. The upgrader runs earlier, at priority 5, because schema
	 * must be current before anything reads it.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->register_services();

		register_activation_hook( AGGR_PLUGIN_FILE, array( $this, 'activate' ) );

		// Priority 5, ahead of init_services at 10: schema must be current
		// before any service reads it. Activation is only a hint — it does not
		// run on a file-only deploy, an in-place update, or a database restore.
		add_action( 'plugins_loaded', array( $this, 'run_upgrade_check' ), 5 );
		add_action( 'plugins_loaded', array( $this, 'init_services' ), 10 );

		// On `init`, not earlier: since WordPress 6.7 loading a text domain
		// before `init` is a _doing_it_wrong notice, because the locale is not
		// settled until then.
		add_action( 'init', array( $this, 'load_translations' ) );
	}

	/**
	 * Registers the directory this plugin ships its own catalogs in.
	 *
	 * **Without this call the catalogs are never loaded.** Just-in-time loading
	 * does not search a plugin's own folder: `WP_Textdomain_Registry` looks in
	 * `WP_LANG_DIR/plugins`, `WP_LANG_DIR/themes`, and a custom path that is
	 * only ever set by `load_plugin_textdomain()` / `load_theme_textdomain()`.
	 * A plugin that ships `languages/` and calls neither gets translations from
	 * wp-content/languages if a language pack happens to be installed there,
	 * and English otherwise.
	 *
	 * That failure is completely silent. `load_plugin_textdomain()` returns
	 * true whether or not a catalog was found, every string simply falls back
	 * to its source text, and the POT, the catalogs and the compiled .mo can
	 * all be perfectly valid the whole time. `TranslationLoadingTest` asserts
	 * on `__()` output for that reason — never on the return value here.
	 *
	 * Public because it is an `add_action` callback.
	 *
	 * @return void
	 */
	public function load_translations(): void {
		load_plugin_textdomain(
			'aggressive-ads',
			false,
			dirname( plugin_basename( AGGR_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Activation hook. Migrates, then repairs schema, roles, options, and
	 * rewrite rules.
	 *
	 * Public because it is a hook callback. The upgrader must run first:
	 * install() stamps the current db version, and doing that before a
	 * numbered migration would make a later request skip the unfinished step.
	 * WordPress does not re-fire this on a file-only update — plugins_loaded
	 * priority 5 is the path that covers those — but a deactivate/reactivate
	 * of new code against an old database does fire it.
	 *
	 * Rewrite flush belongs here, not on a later `init`. `activate_plugin()`
	 * includes this file after `init` has already run, so the version-gated
	 * flush never sees this request. Without it, `/advertiser/` 404s at Apache
	 * until someone clicks Save Permalinks.
	 *
	 * @return void
	 */
	public function activate(): void {
		$this->container->get( Upgrader::class )->maybe_upgrade();
		$this->container->get( Installer::class )->install();
		$this->container->get( Rewrite_Flusher::class )->flush();
	}

	/**
	 * Brings the site's schema, roles and options up to the code's versions.
	 *
	 * Public because it is a hook callback. Cheap when there is nothing to do:
	 * three autoloaded option reads and three comparisons.
	 *
	 * @return void
	 */
	public function run_upgrade_check(): void {
		$this->container->get( Upgrader::class )->maybe_upgrade();
	}

	/**
	 * The container, for tests and for services that legitimately need it.
	 *
	 * @return Service_Container
	 */
	public function container(): Service_Container {
		return $this->container;
	}

	/**
	 * Builds the factory for every service. Instantiates nothing.
	 *
	 * @return void
	 */
	private function register_services(): void {
		( new Service_Registrar() )->register( $this->container );
		( new Rest_Service_Registrar() )->register( $this->container );
	}

	/**
	 * Initializes every registered service, in the order listed here.
	 *
	 * Public because it is an `add_action` callback, not because anything else
	 * should call it. Calling it twice would double every hook, so it guards.
	 *
	 * @return void
	 */
	public function init_services(): void {
		if ( $this->services_initialized ) {
			return;
		}

		$this->services_initialized = true;

		foreach ( $this->service_init_order() as $id ) {
			$service = $this->container->get( $id );

			if ( $service instanceof Service ) {
				$service->init();
			}
		}
	}

	/**
	 * Service ids in initialization order.
	 *
	 * Explicit rather than "whatever order they were registered in", because
	 * order matters — post types must exist before anything queries them, and
	 * a reordering should be a deliberate, reviewable edit.
	 *
	 * @return array<int, class-string>
	 */
	private function service_init_order(): array {
		return array(
			// Update hooks are independent of the application's data model and
			// must be available on every admin and cron update check.
			Plugin_Updates::class,

			// Network site create/delete. Hooks only; the work runs later on
			// wp_initialize_site / wp_uninitialize_site, never on fill.
			Site_Lifecycle::class,

			// Data shapes first: nothing may query a post type that does not
			// exist yet, and a status must be registered before any query
			// filters on it.
			Post_Types::class,
			Post_Statuses::class,

			// Ownership before anything that could ask a capability question,
			// so no surface ever resolves an object check through core's
			// author comparison instead of ours.
			Ownership::class,
			Admin_Guard::class,
			Private_Storage_Health::class,
			Delivery_Health::class,

			// Filters attachment queries, so it must be listening before any
			// admin screen runs one.
			Media_Library::class,

			// Attaches the listener that notices a campaign status written
			// without going through the state machine.
			Campaign_State_Machine::class,
			Line_Item_Lifecycle::class,
			Line_Item_Migrator::class,
			Creative_Assignment_Migrator::class,
			Assignment_Health::class,
			Decision_Health::class,
			\Aggressive\Ads\Install\Viewability_Health::class,

			// After the state machine, whose transition action it listens to.
			Campaign_Change_Manager::class,

			// After the state machine, whose listener must be attached before
			// the clock drives a single transition through it.
			Fill_Cache::class,
			Campaign_Clock::class,
			Ending_Soon_Mailer::class,
			Ending_Soon_Notifier::class,
			Audit_Retention::class,
			Creative_Retention::class,
			Rollup_Reconciler::class,
			Event_Retention::class,
			Notification_Service::class,
			Request_Mailer::class,
			Menu::class,
			Review_Screen::class,
			Action_Notice::class,
			Placement_Screen::class,
			Organization_Screen::class,
			Package_Screen::class,
			Settings_Screen::class,

			// REST last: routes are registered on rest_api_init, which fires
			// well after this, so ordering here is about nothing but reading.
			// The router registers rewrite rules on init, so it has to be
			// initialized before init runs — which is why services are
			// initialized at plugins_loaded rather than later.
			Router::class,
			Assets::class,
			Brand_Styles::class,
			Campaign_Actions::class,
			Acting_Actions::class,
			Creative_Actions::class,
			Account_Actions::class,
			Email_Change_Actions::class,
			Report_Actions::class,
			Organization_Actions::class,
			Login_Actions::class,
			Signup_Actions::class,
			Password_Actions::class,

			Creative_File_Controller::class,
			Creative_Controller::class,
			Transitions_Controller::class,
			Review_Controller::class,
			Campaigns_Controller::class,
			Line_Items_Controller::class,
			Placements_Controller::class,
			Packages_Controller::class,
			Organizations_Controller::class,
			Settings_Controller::class,
			Fill_Controller::class,
			Decisions_Controller::class,
			Decision_Trace_Controller::class,
			Beacon_Controller::class,
			Click_Hop::class,

			// After both rule owners have hooked `init` priority 10, so a
			// version bump on this request flushes rules that are already in
			// $wp_rewrite. Activation calls flush() directly and never waits.
			Rewrite_Flusher::class,

			// After the flusher, so a version bump on this request is applied
			// before the check that reports whether the rules are installed.
			// The other order reports a stale state that has already been
			// repaired, one page load out of date.
			Rewrite_Health::class,
			Placement_Slot::class,
		);
	}
}
