<?php
/**
 * The composition root.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal;

use LAAO_Advertiser_Portal\Admin\Creative_Change_Actions;
use LAAO_Advertiser_Portal\Admin\Organization_Screen;
use LAAO_Advertiser_Portal\Admin\Placement_Mapping_Screen;
use LAAO_Advertiser_Portal\Admin\Review_Screen;
use LAAO_Advertiser_Portal\Assets\Assets;
use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Install\Installer;
use LAAO_Advertiser_Portal\Install\Upgrader;
use LAAO_Advertiser_Portal\Notification\Ending_Soon_Mailer;
use LAAO_Advertiser_Portal\Notification\Notification_Service;
use LAAO_Advertiser_Portal\Portal\Account_Actions;
use LAAO_Advertiser_Portal\Portal\Campaign_Actions;
use LAAO_Advertiser_Portal\Portal\Creative_Actions;
use LAAO_Advertiser_Portal\Portal\Email_Change_Actions;
use LAAO_Advertiser_Portal\Portal\Login_Actions;
use LAAO_Advertiser_Portal\Portal\Organization_Actions;
use LAAO_Advertiser_Portal\Portal\Password_Actions;
use LAAO_Advertiser_Portal\Portal\Router;
use LAAO_Advertiser_Portal\Portal\Signup_Actions;
use LAAO_Advertiser_Portal\REST\Campaigns_Controller;
use LAAO_Advertiser_Portal\REST\Creative_Controller;
use LAAO_Advertiser_Portal\REST\Creative_File_Controller;
use LAAO_Advertiser_Portal\REST\Packages_Controller;
use LAAO_Advertiser_Portal\REST\Placements_Controller;
use LAAO_Advertiser_Portal\REST\Transitions_Controller;
use LAAO_Advertiser_Portal\Security\Admin_Guard;
use LAAO_Advertiser_Portal\Security\Ownership;
use LAAO_Advertiser_Portal\Update\Plugin_Updates;
use LAAO_Advertiser_Portal\Workflow\Campaign_Clock;
use LAAO_Advertiser_Portal\Workflow\Campaign_State_Machine;
use LAAO_Advertiser_Portal\Workflow\Creative_Retention;
use LAAO_Advertiser_Portal\Workflow\Ending_Soon_Notifier;

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
	 * plugin on the site — AdSanity in particular — has declared itself before
	 * any of our services look for it. The upgrader runs earlier, at priority
	 * 5, because schema must be current before anything reads it.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->register_services();

		register_activation_hook( LAAO_ADS_PLUGIN_FILE, array( $this, 'activate' ) );

		// Priority 5, ahead of init_services at 10: schema must be current
		// before any service reads it. Activation is only a hint — it does not
		// run on a file-only deploy, an in-place update, or a database restore.
		add_action( 'plugins_loaded', array( $this, 'run_upgrade_check' ), 5 );
		add_action( 'plugins_loaded', array( $this, 'init_services' ), 10 );
	}

	/**
	 * Activation hook. Installs schema, roles and options.
	 *
	 * Public because it is a hook callback. The upgrader performs the same work
	 * on any request where a version option is behind, so this being missed is
	 * survivable by design.
	 *
	 * @return void
	 */
	public function activate(): void {
		$this->container->get( Installer::class )->install();
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

			// Attaches the listener that notices a campaign status written
			// without going through the state machine.
			Campaign_State_Machine::class,

			// After the state machine, whose listener must be attached before
			// the clock drives a single transition through it.
			Campaign_Clock::class,
			Ending_Soon_Mailer::class,
			Ending_Soon_Notifier::class,
			Creative_Retention::class,
			Notification_Service::class,
			Review_Screen::class,
			Creative_Change_Actions::class,
			Placement_Mapping_Screen::class,
			Organization_Screen::class,

			// REST last: routes are registered on rest_api_init, which fires
			// well after this, so ordering here is about nothing but reading.
			// The router registers rewrite rules on init, so it has to be
			// initialized before init runs — which is why services are
			// initialized at plugins_loaded rather than later.
			Router::class,
			Assets::class,
			Campaign_Actions::class,
			Creative_Actions::class,
			Account_Actions::class,
			Email_Change_Actions::class,
			Organization_Actions::class,
			Login_Actions::class,
			Signup_Actions::class,
			Password_Actions::class,

			Creative_File_Controller::class,
			Creative_Controller::class,
			Transitions_Controller::class,
			Campaigns_Controller::class,
			Placements_Controller::class,
			Packages_Controller::class,
		);
	}
}
