<?php
/**
 * Registers the REST surface.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads;

use Aggressive\Ads\Admin\Organization_Data;
use Aggressive\Ads\Admin\Package_Data;
use Aggressive\Ads\Admin\Placement_Data;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Portal\Acting_As;
use Aggressive\Ads\REST\Campaigns_Controller;
use Aggressive\Ads\REST\Creative_Controller;
use Aggressive\Ads\REST\Creative_File_Controller;
use Aggressive\Ads\REST\Decision_Trace_Controller;
use Aggressive\Ads\REST\Decisions_Controller;
use Aggressive\Ads\REST\Line_Items_Controller;
use Aggressive\Ads\REST\Organizations_Controller;
use Aggressive\Ads\REST\Conversion_Definitions_Controller;
use Aggressive\Ads\REST\Conversion_Credentials_Controller;
use Aggressive\Ads\REST\Conversions_Controller;
use Aggressive\Ads\REST\Server_Conversions_Controller;
use Aggressive\Ads\REST\Packages_Controller;
use Aggressive\Ads\REST\Placements_Controller;
use Aggressive\Ads\REST\Settings_Controller;
use Aggressive\Ads\REST\Transitions_Controller;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Workflow\Assignment_Editor;
use Aggressive\Ads\Workflow\Campaign_Copier;
use Aggressive\Ads\Workflow\Campaign_Editor;
use Aggressive\Ads\Workflow\Campaign_State_Machine;
use Aggressive\Ads\Workflow\Creative_Change_Manager;
use Aggressive\Ads\Workflow\Creative_Manager;
use Aggressive\Ads\Workflow\Decision_Engine;
use Aggressive\Ads\Workflow\Edit_Window;
use Aggressive\Ads\Workflow\Line_Item_Editor;
use Aggressive\Ads\Workflow\Organization_Membership;
use Aggressive\Ads\Workflow\Organization_State_Manager;
use Aggressive\Ads\Workflow\Package_Manager;
use Aggressive\Ads\Workflow\Placement_Manager;
use Aggressive\Ads\Workflow\Reporting_Read;
use Aggressive\Ads\Workflow\Review_Readiness;
use Aggressive\Ads\Workflow\Reviewer_Access;


/**
 * The HTTP surface, separated from the services behind it.
 *
 * A mistake here exposes an endpoint; a mistake in `Service_Registrar` throws on
 * boot. Registration still stores a closure and runs nothing.
 */
final class Rest_Service_Registrar {

	/**
	 * Registers every REST controller factory.
	 *
	 * @param Service_Container $container Container to register into.
	 * @return void
	 */
	public function register( Service_Container $container ): void {
		$container->register(
			Campaigns_Controller::class,
			static fn ( Service_Container $c ): Campaigns_Controller => new Campaigns_Controller(
				$c->get( Campaign_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Org_Repository::class ),
				$c->get( Campaign_Editor::class ),
				$c->get( Campaign_Copier::class ),
				$c->get( Review_Readiness::class ),
				$c->get( Rate_Limiter::class ),
				$c->get( Reporting_Read::class ),
				$c->get( Edit_Window::class ),
				$c->get( Acting_As::class ),
				$c->get( Line_Item_Repository::class )
			)
		);
		$container->register(
			Line_Items_Controller::class,
			static fn ( Service_Container $c ): Line_Items_Controller => new Line_Items_Controller(
				$c->get( Line_Item_Repository::class ),
				$c->get( Campaign_Repository::class ),
				$c->get( Line_Item_Editor::class ),
				$c->get( Rate_Limiter::class ),
				$c->get( Creative_Assignment_Repository::class ),
				$c->get( Assignment_Editor::class )
			)
		);
		$container->register(
			Placements_Controller::class,
			static fn ( Service_Container $c ): Placements_Controller => new Placements_Controller(
				$c->get( Placement_Repository::class ),
				$c->get( Placement_Manager::class ),
				$c->get( Placement_Data::class )
			)
		);
		$container->register(
			Conversions_Controller::class,
			static fn ( Service_Container $c ): Conversions_Controller => new Conversions_Controller(
				$c->get( \Aggressive\Ads\Workflow\Fill_Service::class ),
				$c->get( \Aggressive\Ads\Workflow\Fill_Token::class ),
				$c->get( \Aggressive\Ads\Security\Rate_Limiter::class ),
				$c->get( \Aggressive\Ads\Workflow\Conversion_Recorder::class )
			)
		);

		$container->register(
			Conversion_Definitions_Controller::class,
			static fn ( Service_Container $c ): Conversion_Definitions_Controller => new Conversion_Definitions_Controller(
				$c->get( \Aggressive\Ads\Repository\Conversion_Definition_Repository::class ),
				$c->get( \Aggressive\Ads\Workflow\Conversion_Definition_Manager::class )
			)
		);

		$container->register(
			Conversion_Credentials_Controller::class,
			static fn ( Service_Container $c ): Conversion_Credentials_Controller => new Conversion_Credentials_Controller(
				$c->get( \Aggressive\Ads\Repository\Conversion_Credential_Repository::class ),
				$c->get( \Aggressive\Ads\Workflow\Conversion_Credential_Manager::class ),
				$c->get( \Aggressive\Ads\Repository\Org_Repository::class )
			)
		);

		$container->register(
			Server_Conversions_Controller::class,
			static fn ( Service_Container $c ): Server_Conversions_Controller => new Server_Conversions_Controller(
				$c->get( \Aggressive\Ads\Workflow\Fill_Service::class ),
				$c->get( \Aggressive\Ads\Workflow\Fill_Token::class ),
				$c->get( \Aggressive\Ads\Security\Rate_Limiter::class ),
				$c->get( \Aggressive\Ads\Workflow\Conversion_Recorder::class ),
				$c->get( \Aggressive\Ads\Workflow\Conversion_Credential_Manager::class ),
				$c->get( \Aggressive\Ads\Repository\Conversion_Definition_Repository::class )
			)
		);

		$container->register(
			Packages_Controller::class,
			static fn ( Service_Container $c ): Packages_Controller => new Packages_Controller(
				$c->get( Package_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Campaign_Editor::class ),
				$c->get( Package_Manager::class ),
				$c->get( Package_Data::class )
			)
		);
		$container->register(
			Organizations_Controller::class,
			static fn ( Service_Container $c ): Organizations_Controller => new Organizations_Controller(
				$c->get( Organization_State_Manager::class ),
				$c->get( Organization_Data::class ),
				$c->get( Organization_Membership::class )
			)
		);
		$container->register(
			Settings_Controller::class,
			static fn ( Service_Container $c ): Settings_Controller => new Settings_Controller(
				$c->get( Settings::class ),
				$c->get( Reviewer_Access::class )
			)
		);
		$container->register(
			Creative_Controller::class,
			static fn ( Service_Container $c ): Creative_Controller => new Creative_Controller(
				$c->get( Creative_Manager::class ),
				$c->get( Creative_Change_Manager::class )
			)
		);
		$container->register(
			Transitions_Controller::class,
			static fn ( Service_Container $c ): Transitions_Controller => new Transitions_Controller(
				$c->get( Campaign_State_Machine::class ),
				$c->get( Campaign_Repository::class ),
				$c->get( Rate_Limiter::class )
			)
		);
		$container->register(
			Creative_File_Controller::class,
			static fn ( Service_Container $c ): Creative_File_Controller => new Creative_File_Controller(
				$c->get( Creative_Repository::class ),
				$c->get( Private_Storage::class )
			)
		);
		$container->register(
			Decision_Trace_Controller::class,
			static fn ( Service_Container $c ): Decision_Trace_Controller => new Decision_Trace_Controller(
				$c->get( Decision_Engine::class ),
				$c->get( Placement_Repository::class )
			)
		);
		$container->register(
			Decisions_Controller::class,
			static fn ( Service_Container $c ): Decisions_Controller => new Decisions_Controller(
				$c->get( \Aggressive\Ads\Workflow\Fill_Service::class ),
				$c->get( \Aggressive\Ads\Security\Rate_Limiter::class )
			)
		);
	}
}
