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

		add_action( 'plugins_loaded', array( $this, 'init_services' ), 10 );
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

		/*
		 * Remaining services land here as the phases build them — installer,
		 * upgrader, audit, ownership, router, assets — each as one register()
		 * line, each also listed in service_init_order() below.
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
		);
	}
}
