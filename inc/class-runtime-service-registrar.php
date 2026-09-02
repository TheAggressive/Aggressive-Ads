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
use Aggressive\Ads\Admin\Conversions_Screen;
use Aggressive\Ads\Admin\Package_Screen;
use Aggressive\Ads\Admin\Placement_Data;
use Aggressive\Ads\Admin\Placement_Screen;
use Aggressive\Ads\Admin\Action_Notice;
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
use Aggressive\Ads\Domain\Decision_Pipeline;
use Aggressive\Ads\Domain\Frequency_Store;
use Aggressive\Ads\Workflow\Transient_Frequency_Store;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Creative_Revision_Repository;
use Aggressive\Ads\Workflow\Assigned_Creatives;
use Aggressive\Ads\Repository\Delivery_Repository;
use Aggressive\Ads\Repository\Conversion_Definition_Repository;
use Aggressive\Ads\Repository\Conversion_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
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
use Aggressive\Ads\Workflow\Decision_Engine;
use Aggressive\Ads\Workflow\Decision_Metrics;
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
				$c->get( Creative_Revision_Repository::class ),
				$c->get( Assigned_Creatives::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Org_Repository::class ),
				$c->get( Audit_Repository::class ),
				$c->get( Campaign_Change_Manager::class ),
				$c->get( Line_Item_Repository::class ),
				$c->get( \Aggressive\Ads\Workflow\Creative_Approval::class ),
				$c->get( \Aggressive\Ads\Admin\Pending_Work::class )
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
			\Aggressive\Ads\Workflow\Creative_Approval::class,
			static fn ( Service_Container $c ): \Aggressive\Ads\Workflow\Creative_Approval => new \Aggressive\Ads\Workflow\Creative_Approval(
				$c->get( \Aggressive\Ads\Repository\Campaign_Repository::class ),
				$c->get( \Aggressive\Ads\Repository\Creative_Repository::class ),
				$c->get( \Aggressive\Ads\Repository\Creative_Assignment_Repository::class ),
				$c->get( \Aggressive\Ads\Workflow\Creative_Promoter::class ),
				$c->get( \Aggressive\Ads\Workflow\Assignment_Projection::class ),
				$c->get( \Aggressive\Ads\Workflow\Fill_Cache::class ),
				$c->get( \Aggressive\Ads\Repository\Audit_Repository::class )
			)
		);

		$container->register(
			Review_Controller::class,
			static fn ( Service_Container $c ): Review_Controller => new Review_Controller(
				$c->get( Review_Data::class ),
				$c->get( Review_Actions::class ),
				$c->get( Campaign_Change_Actions::class ),
				$c->get( \Aggressive\Ads\Workflow\Creative_Approval::class )
			)
		);

		$container->register(
			Action_Notice::class,
			static fn ( Service_Container $c ): Action_Notice => new Action_Notice(
				$c->get( Review_Data::class )
			)
		);

		$container->register(
			Review_Screen::class,
			static fn ( Service_Container $c ): Review_Screen => new Review_Screen(
				$c->get( Review_Data::class ),
				$c->get( \Aggressive\Ads\Admin\Pending_Work::class )
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
			Conversions_Screen::class,
			static fn ( Service_Container $c ): Conversions_Screen => new Conversions_Screen(
				$c->get( Org_Repository::class ),
				$c->get( \Aggressive\Ads\Repository\Package_Repository::class ),
				$c->get( \Aggressive\Ads\REST\Conversion_Definitions_Controller::class ),
				$c->get( \Aggressive\Ads\REST\Conversion_Credentials_Controller::class )
			)
		);

		$container->register(
			Package_Screen::class,
			static fn ( Service_Container $c ): Package_Screen => new Package_Screen(
				$c->get( Package_Data::class )
			)
		);

		$container->register( Event_Repository::class, static fn (): Event_Repository => new Event_Repository() );
		$container->register( Conversion_Repository::class, static fn (): Conversion_Repository => new Conversion_Repository() );
		$container->register( Conversion_Definition_Repository::class, static fn (): Conversion_Definition_Repository => new Conversion_Definition_Repository() );
		$container->register(
			\Aggressive\Ads\Workflow\Conversion_Recorder::class,
			static fn ( Service_Container $c ): \Aggressive\Ads\Workflow\Conversion_Recorder => new \Aggressive\Ads\Workflow\Conversion_Recorder(
				$c->get( Conversion_Repository::class ),
				$c->get( Conversion_Definition_Repository::class ),
				$c->get( Event_Repository::class ),
				$c->get( Rollup_Repository::class ),
				$c->get( \Aggressive\Ads\Repository\Campaign_Repository::class ),
				$c->get( Creative_Assignment_Repository::class ),
				$c->get( \Aggressive\Ads\Workflow\Conversion_Metrics::class )
			)
		);

		$container->register(
			\Aggressive\Ads\Workflow\Conversion_Metrics::class,
			static fn (): \Aggressive\Ads\Workflow\Conversion_Metrics => new \Aggressive\Ads\Workflow\Conversion_Metrics()
		);
		$container->register(
			\Aggressive\Ads\Repository\Conversion_Credential_Repository::class,
			static fn (): \Aggressive\Ads\Repository\Conversion_Credential_Repository => new \Aggressive\Ads\Repository\Conversion_Credential_Repository()
		);
		$container->register(
			\Aggressive\Ads\Workflow\Conversion_Credential_Manager::class,
			static fn ( Service_Container $c ): \Aggressive\Ads\Workflow\Conversion_Credential_Manager => new \Aggressive\Ads\Workflow\Conversion_Credential_Manager(
				$c->get( \Aggressive\Ads\Repository\Conversion_Credential_Repository::class ),
				$c->get( \Aggressive\Ads\Repository\Org_Repository::class ),
				$c->get( \Aggressive\Ads\Repository\Audit_Repository::class )
			)
		);
		$container->register(
			\Aggressive\Ads\Workflow\Conversion_Definition_Manager::class,
			static fn ( Service_Container $c ): \Aggressive\Ads\Workflow\Conversion_Definition_Manager => new \Aggressive\Ads\Workflow\Conversion_Definition_Manager(
				$c->get( Conversion_Definition_Repository::class ),
				$c->get( \Aggressive\Ads\Repository\Audit_Repository::class )
			)
		);
		$container->register( Rollup_Repository::class, static fn (): Rollup_Repository => new Rollup_Repository() );
		$container->register( \Aggressive\Ads\Repository\Decision_Rollup_Repository::class, static fn (): \Aggressive\Ads\Repository\Decision_Rollup_Repository => new \Aggressive\Ads\Repository\Decision_Rollup_Repository() );
		$container->register( Fill_Token::class, static fn (): Fill_Token => new Fill_Token() );
		$container->register(
			Event_Recorder::class,
			static fn ( Service_Container $c ): Event_Recorder => new Event_Recorder(
				$c->get( Event_Repository::class ),
				$c->get( Rollup_Repository::class ),
				$c->get( Creative_Assignment_Repository::class )
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
			Decision_Metrics::class,
			static fn ( Service_Container $c ): Decision_Metrics => new Decision_Metrics(
				$c->get( \Aggressive\Ads\Repository\Decision_Rollup_Repository::class )
			)
		);

		$container->register(
			Frequency_Store::class,
			static fn (): Frequency_Store => new Transient_Frequency_Store()
		);

		$container->register(
			Decision_Pipeline::class,
			static fn ( Service_Container $c ): Decision_Pipeline => Decision_Pipeline::standard(
				$c->get( Frequency_Store::class )
			)
		);

		$container->register(
			Decision_Engine::class,
			static fn ( Service_Container $c ): Decision_Engine => new Decision_Engine(
				$c->get( Creative_Assignment_Repository::class ),
				$c->get( Creative_Assignment_Migrator::class ),
				$c->get( Decision_Metrics::class ),
				$c->get( Decision_Pipeline::class ),
				$c->get( Fill_Cache::class ),
				$c->get( Frequency_Store::class ),
				$c->get( \Aggressive\Ads\Repository\Line_Item_Repository::class )
			)
		);

		$container->register(
			Fill_Service::class,
			static fn ( Service_Container $c ): Fill_Service => new Fill_Service(
				$c->get( Settings::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Delivery_Repository::class ),
				$c->get( Fill_Token::class ),
				$c->get( Decision_Engine::class )
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
				$c->get( Event_Recorder::class ),
				$c->get( Event_Repository::class )
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
