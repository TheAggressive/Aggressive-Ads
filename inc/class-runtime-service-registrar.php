<?php
/**
 * Registers hooked admin, delivery, and lifecycle services.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads;

use Aggressive\Ads\Admin\Campaign_Change_Actions;
use Aggressive\Ads\Admin\Organization_Data;
use Aggressive\Ads\Admin\Organization_Screen;
use Aggressive\Ads\Admin\Package_Data;
use Aggressive\Ads\Admin\Package_Screen;
use Aggressive\Ads\Admin\Placement_Data;
use Aggressive\Ads\Admin\Placement_Screen;
use Aggressive\Ads\Admin\Review_Data;
use Aggressive\Ads\Admin\Review_Screen;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Workflow\Campaign_Change_Manager;
use Aggressive\Ads\Install\Rewrite_Flusher;
use Aggressive\Ads\Install\Rewrite_Health;
use Aggressive\Ads\Integration\Ad_Provider_Interface;
use Aggressive\Ads\Notification\Notification_Delivery;
use Aggressive\Ads\Notification\Notification_Service;
use Aggressive\Ads\Portal\Router;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Delivery_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Repository\User_Repository;
use Aggressive\Ads\REST\Beacon_Controller;
use Aggressive\Ads\REST\Fill_Controller;
use Aggressive\Ads\REST\Review_Controller;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Workflow\Campaign_State_Machine;
use Aggressive\Ads\Workflow\Click_Hop;
use Aggressive\Ads\Workflow\Creative_Change_Manager;
use Aggressive\Ads\Workflow\Event_Recorder;
use Aggressive\Ads\Workflow\Event_Retention;
use Aggressive\Ads\Workflow\Fill_Cache;
use Aggressive\Ads\Workflow\Fill_Service;
use Aggressive\Ads\Workflow\Fill_Token;
use Aggressive\Ads\Workflow\Organization_State_Manager;
use Aggressive\Ads\Workflow\Package_Manager;
use Aggressive\Ads\Workflow\Placement_Manager;
use Aggressive\Ads\Workflow\Placement_Slot;
use Aggressive\Ads\Workflow\Review_Actions;
use Aggressive\Ads\Workflow\Rollup_Reconciler;
use Aggressive\Ads\Workflow\Transition_Guards;

/**
 * Second half of the composition root, split by runtime-hook responsibility.
 */
final class Runtime_Service_Registrar {

	/**
	 * Stores factories without instantiating services.
	 *
	 * @param Service_Container $container Application container.
	 * @return void
	 */
	public function register( Service_Container $container ): void {
		$container->register(
			Campaign_State_Machine::class,
			static fn ( Service_Container $c ): Campaign_State_Machine => new Campaign_State_Machine(
				$c->get( Campaign_Repository::class ),
				$c->get( Audit_Repository::class ),
				$c->get( Transition_Guards::class ),
				$c->get( Ad_Provider_Interface::class )->transition_effects()
			)
		);

		$container->register(
			Notification_Service::class,
			static fn ( Service_Container $c ): Notification_Service => new Notification_Service(
				$c->get( Campaign_Repository::class ),
				$c->get( Org_Repository::class ),
				$c->get( User_Repository::class ),
				$c->get( Audit_Repository::class ),
				$c->get( Notification_Delivery::class )
			)
		);

		$container->register(
			Review_Data::class,
			static fn ( Service_Container $c ): Review_Data => new Review_Data(
				$c->get( Campaign_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Org_Repository::class ),
				$c->get( Audit_Repository::class ),
				$c->get( Campaign_Change_Manager::class )
			)
		);

		$container->register(
			Review_Actions::class,
			static fn ( Service_Container $c ): Review_Actions => new Review_Actions(
				$c->get( Campaign_State_Machine::class ),
				$c->get( Campaign_Repository::class ),
				$c->get( Audit_Repository::class )
			)
		);

		$container->register(
			Review_Controller::class,
			static fn ( Service_Container $c ): Review_Controller => new Review_Controller(
				$c->get( Review_Data::class ),
				$c->get( Review_Actions::class ),
				$c->get( Campaign_Change_Actions::class )
			)
		);

		$container->register(
			Review_Screen::class,
			static fn ( Service_Container $c ): Review_Screen => new Review_Screen(
				$c->get( Review_Data::class )
			)
		);

		$container->register(
			Placement_Screen::class,
			static fn ( Service_Container $c ): Placement_Screen => new Placement_Screen(
				$c->get( Placement_Data::class )
			)
		);

		$container->register(
			Organization_Screen::class,
			static fn ( Service_Container $c ): Organization_Screen => new Organization_Screen(
				$c->get( Organization_Data::class )
			)
		);

		$container->register(
			Package_Manager::class,
			static fn ( Service_Container $c ): Package_Manager => new Package_Manager(
				$c->get( Package_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Audit_Repository::class )
			)
		);

		$container->register(
			Package_Data::class,
			static fn ( Service_Container $c ): Package_Data => new Package_Data(
				$c->get( Package_Repository::class ),
				$c->get( Placement_Repository::class )
			)
		);

		$container->register(
			Package_Screen::class,
			static fn ( Service_Container $c ): Package_Screen => new Package_Screen(
				$c->get( Package_Data::class )
			)
		);

		$container->register( Event_Repository::class, static fn (): Event_Repository => new Event_Repository() );
		$container->register( Rollup_Repository::class, static fn (): Rollup_Repository => new Rollup_Repository() );
		$container->register( Fill_Token::class, static fn (): Fill_Token => new Fill_Token() );
		$container->register(
			Event_Recorder::class,
			static fn ( Service_Container $c ): Event_Recorder => new Event_Recorder(
				$c->get( Event_Repository::class ),
				$c->get( Rollup_Repository::class )
			)
		);
		$container->register(
			Rollup_Reconciler::class,
			static fn ( Service_Container $c ): Rollup_Reconciler => new Rollup_Reconciler(
				$c->get( Event_Repository::class ),
				$c->get( Rollup_Repository::class )
			)
		);

		$container->register(
			Fill_Cache::class,
			static fn ( Service_Container $c ): Fill_Cache => new Fill_Cache(
				$c->get( Campaign_Repository::class ),
				$c->get( Settings::class )
			)
		);

		$container->register(
			Fill_Service::class,
			static fn ( Service_Container $c ): Fill_Service => new Fill_Service(
				$c->get( Settings::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Delivery_Repository::class ),
				$c->get( Fill_Cache::class ),
				$c->get( Fill_Token::class )
			)
		);

		$container->register(
			Click_Hop::class,
			static fn ( Service_Container $c ): Click_Hop => new Click_Hop(
				$c->get( Fill_Service::class ),
				$c->get( Fill_Token::class ),
				$c->get( Rate_Limiter::class ),
				$c->get( Event_Recorder::class )
			)
		);

		$container->register(
			Rewrite_Flusher::class,
			static fn ( Service_Container $c ): Rewrite_Flusher => new Rewrite_Flusher(
				$c->get( Router::class ),
				$c->get( Click_Hop::class )
			)
		);

		$container->register(
			Rewrite_Health::class,
			static fn ( Service_Container $c ): Rewrite_Health => new Rewrite_Health(
				$c->get( Rewrite_Flusher::class )
			)
		);

		$container->register(
			Fill_Controller::class,
			static fn ( Service_Container $c ): Fill_Controller => new Fill_Controller( $c->get( Fill_Service::class ) )
		);

		$container->register(
			Beacon_Controller::class,
			static fn ( Service_Container $c ): Beacon_Controller => new Beacon_Controller(
				$c->get( Fill_Service::class ),
				$c->get( Fill_Token::class ),
				$c->get( Rate_Limiter::class ),
				$c->get( Event_Recorder::class )
			)
		);

		$container->register(
			Placement_Slot::class,
			static fn ( Service_Container $c ): Placement_Slot => new Placement_Slot(
				$c->get( Fill_Service::class ),
				$c->get( Placement_Repository::class )
			)
		);

		$container->register(
			Event_Retention::class,
			static fn ( Service_Container $c ): Event_Retention => new Event_Retention(
				$c->get( Event_Repository::class ),
				$c->get( Settings::class ),
				$c->get( Rollup_Reconciler::class )
			)
		);
	}
}
