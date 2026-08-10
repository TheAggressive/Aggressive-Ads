<?php
/**
 * The composition root.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal;

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Install\Installer;
use LAAO_Advertiser_Portal\Install\Upgrader;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Domain\Transition_Table;
use LAAO_Advertiser_Portal\Integration\Adsanity\Ad_Publisher;
use LAAO_Advertiser_Portal\Integration\Adsanity\Placement_Mapping;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Repository\Creative_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\REST\Creative_Controller;
use LAAO_Advertiser_Portal\REST\Creative_File_Controller;
use LAAO_Advertiser_Portal\REST\Transitions_Controller;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;
use LAAO_Advertiser_Portal\Storage\Private_Storage;
use LAAO_Advertiser_Portal\Workflow\Campaign_State_Machine;
use LAAO_Advertiser_Portal\Workflow\Campaign_Validator;
use LAAO_Advertiser_Portal\Workflow\Creative_Promoter;
use LAAO_Advertiser_Portal\Workflow\Creative_Uploader;
use LAAO_Advertiser_Portal\Workflow\Transition_Guards;
use LAAO_Advertiser_Portal\Security\Admin_Guard;
use LAAO_Advertiser_Portal\Security\Ownership;
use LAAO_Advertiser_Portal\Security\Rate_Limiter;
use LAAO_Advertiser_Portal\Security\Roles;

/**
 * Wires the application together, then starts it.
 *
 * Registration and initialization are deliberately separate. Registering must
 * never cause application behaviour — a closure is stored, nothing runs.
 * Behaviour begins only when init_services() calls init(), in an order this
 * file makes visible.
 *
 * Adding a service costs two edits in one file, and that is the point.
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
		$this->container->register(
			Post_Types::class,
			static fn (): Post_Types => new Post_Types()
		);

		$this->container->register(
			Post_Statuses::class,
			static fn (): Post_Statuses => new Post_Statuses()
		);

		$this->container->register(
			Audit_Repository::class,
			static fn (): Audit_Repository => new Audit_Repository()
		);

		$this->container->register(
			Roles::class,
			static fn (): Roles => new Roles()
		);

		$this->container->register(
			Installer::class,
			static fn ( Service_Container $c ): Installer => new Installer(
				$c->get( Audit_Repository::class ),
				$c->get( Roles::class )
			)
		);

		$this->container->register(
			Upgrader::class,
			static fn ( Service_Container $c ): Upgrader => new Upgrader(
				$c->get( Installer::class ),
				$c->get( Audit_Repository::class )
			)
		);

		$this->container->register(
			Org_Repository::class,
			static fn (): Org_Repository => new Org_Repository()
		);

		$this->container->register(
			Ownership::class,
			static fn ( Service_Container $c ): Ownership => new Ownership(
				$c->get( Org_Repository::class )
			)
		);

		$this->container->register(
			Admin_Guard::class,
			static fn (): Admin_Guard => new Admin_Guard()
		);

		$this->container->register(
			Campaign_Repository::class,
			static fn (): Campaign_Repository => new Campaign_Repository()
		);

		$this->container->register(
			Creative_Repository::class,
			static fn (): Creative_Repository => new Creative_Repository()
		);

		$this->container->register(
			Placement_Repository::class,
			static fn (): Placement_Repository => new Placement_Repository()
		);

		$this->container->register(
			Campaign_Validator::class,
			static fn ( Service_Container $c ): Campaign_Validator => new Campaign_Validator(
				$c->get( Campaign_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Org_Repository::class )
			)
		);

		$this->container->register(
			Placement_Mapping::class,
			static fn ( Service_Container $c ): Placement_Mapping => new Placement_Mapping(
				$c->get( Placement_Repository::class )
			)
		);

		$this->container->register(
			Transition_Guards::class,
			static function ( Service_Container $c ): Transition_Guards {
				$campaigns = $c->get( Campaign_Repository::class );

				return new Transition_Guards(
					$campaigns,
					array(
						Transition_Table::GUARD_VALIDATOR => $c->get( Campaign_Validator::class )->as_guard(),
						Transition_Table::GUARD_MAPPINGS_RESOLVE => $c->get( Placement_Mapping::class )->as_guard(
							static fn ( int $campaign_id ): array => $campaigns->placement_ids( $campaign_id )
						),
					)
				);
			}
		);

		$this->container->register(
			Private_Storage::class,
			static fn (): Private_Storage => new Private_Storage()
		);

		$this->container->register(
			Creative_Uploader::class,
			static fn ( Service_Container $c ): Creative_Uploader => new Creative_Uploader(
				$c->get( Private_Storage::class )
			)
		);

		$this->container->register(
			Creative_Promoter::class,
			static fn ( Service_Container $c ): Creative_Promoter => new Creative_Promoter(
				$c->get( Creative_Repository::class ),
				$c->get( Private_Storage::class )
			)
		);

		$this->container->register(
			Rate_Limiter::class,
			static fn ( Service_Container $c ): Rate_Limiter => new Rate_Limiter(
				$c->get( Audit_Repository::class )
			)
		);

		$this->container->register(
			Creative_Controller::class,
			static fn ( Service_Container $c ): Creative_Controller => new Creative_Controller(
				$c->get( Campaign_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Creative_Uploader::class ),
				$c->get( Rate_Limiter::class )
			)
		);

		$this->container->register(
			Transitions_Controller::class,
			static fn ( Service_Container $c ): Transitions_Controller => new Transitions_Controller(
				$c->get( Campaign_State_Machine::class ),
				$c->get( Campaign_Repository::class ),
				$c->get( Rate_Limiter::class )
			)
		);

		$this->container->register(
			Creative_File_Controller::class,
			static fn ( Service_Container $c ): Creative_File_Controller => new Creative_File_Controller(
				$c->get( Creative_Repository::class ),
				$c->get( Private_Storage::class )
			)
		);

		$this->container->register(
			Ad_Publisher::class,
			static fn ( Service_Container $c ): Ad_Publisher => new Ad_Publisher(
				$c->get( Campaign_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Placement_Mapping::class )
			)
		);

		$this->container->register(
			Campaign_State_Machine::class,
			static fn ( Service_Container $c ): Campaign_State_Machine => new Campaign_State_Machine(
				$c->get( Campaign_Repository::class ),
				$c->get( Audit_Repository::class ),
				$c->get( Transition_Guards::class ),
				array_merge(
					array( Transition_Table::EFFECT_PUBLISH => $c->get( Ad_Publisher::class )->as_effect() ),
					$c->get( Ad_Publisher::class )->lifecycle_effects()
				)
			)
		);

		/*
		 * Remaining services land here as the phases build them — router,
		 * assets, REST — each as one register() line, each also listed in
		 * service_init_order() below when it needs hooks.
		 *
		 * The validator and placement-mapping guards, and the publish and
		 * unpublish effects, are injected into Transition_Guards and
		 * Campaign_State_Machine when their phases build them. Until then both
		 * fail closed, so a transition depending on one refuses rather than
		 * skipping the check.
		 */
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

			// REST last: routes are registered on rest_api_init, which fires
			// well after this, so ordering here is about nothing but reading.
			Creative_File_Controller::class,
			Creative_Controller::class,
			Transitions_Controller::class,
		);
	}
}
