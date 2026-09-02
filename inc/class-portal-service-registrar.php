<?php
/**
 * Advertiser portal service factories.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads;

use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Workflow\Edit_Window;
use Aggressive\Ads\Portal\Account_Actions;
use Aggressive\Ads\Portal\Acting_Actions;
use Aggressive\Ads\Portal\Acting_As;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Catalogue_View_Data;
use Aggressive\Ads\Portal\Creative_Actions;
use Aggressive\Ads\Portal\Creative_View_Data;
use Aggressive\Ads\Portal\Delivery_View_Data;
use Aggressive\Ads\Portal\Email_Change_Actions;
use Aggressive\Ads\Portal\Login_Actions;
use Aggressive\Ads\Portal\Organization_Actions;
use Aggressive\Ads\Portal\Password_Actions;
use Aggressive\Ads\Portal\Report_Actions;
use Aggressive\Ads\Portal\Router;
use Aggressive\Ads\Portal\Signup_Actions;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Creative_Revision_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Repository\Org_Access_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Notification\Password_Notification;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\User_Repository;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Workflow\Advertiser_Registration;
use Aggressive\Ads\Workflow\Assigned_Creatives;
use Aggressive\Ads\Workflow\Campaign_Change_Manager;
use Aggressive\Ads\Workflow\Campaign_Copier;
use Aggressive\Ads\Workflow\Campaign_Editor;
use Aggressive\Ads\Workflow\Campaign_State_Machine;
use Aggressive\Ads\Workflow\Creative_Change_Manager;
use Aggressive\Ads\Workflow\Creative_Manager;
use Aggressive\Ads\Workflow\Email_Change;
use Aggressive\Ads\Workflow\Organization_Membership;
use Aggressive\Ads\Workflow\Password_Reset;
use Aggressive\Ads\Workflow\Reporting_Read;
use Aggressive\Ads\Workflow\Review_Readiness;

/**
 * Registers everything the advertiser portal needs, and nothing else.
 *
 * Split out of `Service_Registrar` for the reason the REST and runtime
 * registrars already exist: **a registrar's job is a review standard, not a
 * line count.** A mistake in a portal factory produces a broken advertiser
 * screen; a mistake in the domain registrar throws on boot for everybody. They
 * do not deserve the same amount of attention, and keeping them in one file
 * meant every portal change touched the file that boots the plugin.
 *
 * Registration stores closures and instantiates nothing; behaviour begins at
 * `Plugin::init_services()`.
 */
final class Portal_Service_Registrar {

	/**
	 * Registers the portal factories.
	 *
	 * @param Service_Container $container Container to populate.
	 * @return void
	 */
	public function register( Service_Container $container ): void {
		$container->register(
			Acting_Actions::class,
			static fn ( Service_Container $c ): Acting_Actions => new Acting_Actions(
				$c->get( Acting_As::class )
			)
		);
		$container->register(
			Acting_As::class,
			static fn ( Service_Container $c ): Acting_As => new Acting_As(
				$c->get( User_Repository::class ),
				$c->get( Org_Repository::class ),
				$c->get( Audit_Repository::class )
			)
		);
		$container->register(
			Router::class,
			static fn ( Service_Container $c ): Router => new Router(
				$c->get( Advertiser_Registration::class )
			)
		);
		$container->register(
			View_Data::class,
			static fn ( Service_Container $c ): View_Data => new View_Data(
				$c->get( Campaign_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Org_Repository::class ),
				$c->get( Org_Access_Repository::class ),
				$c->get( Package_Repository::class ),
				$c->get( Campaign_Editor::class ),
				$c->get( Review_Readiness::class ),
				$c->get( Email_Change::class ),
				$c->get( Reporting_Read::class ),
				$c->get( Campaign_Change_Manager::class ),
				$c->get( Settings::class ),
				$c->get( Edit_Window::class ),
				$c->get( Acting_As::class ),
				$c->get( Line_Item_Repository::class ),
				$c->get( \Aggressive\Ads\Portal\Delivery_View_Data::class ),
				$c->get( \Aggressive\Ads\Portal\Creative_View_Data::class )
			)
		);
		$container->register(
			\Aggressive\Ads\Portal\Delivery_View_Data::class,
			static fn ( Service_Container $c ): \Aggressive\Ads\Portal\Delivery_View_Data => new \Aggressive\Ads\Portal\Delivery_View_Data(
				$c->get( Reporting_Read::class )
			)
		);
		$container->register(
			\Aggressive\Ads\Portal\Creative_View_Data::class,
			static fn ( Service_Container $c ): \Aggressive\Ads\Portal\Creative_View_Data => new \Aggressive\Ads\Portal\Creative_View_Data(
				$c->get( Campaign_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Creative_Revision_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Assigned_Creatives::class ),
				$c->get( \Aggressive\Ads\Workflow\Creative_Approval::class )
			)
		);
		$container->register(
			Campaign_Actions::class,
			static fn ( Service_Container $c ): Campaign_Actions => new Campaign_Actions(
				$c->get( Campaign_Editor::class ),
				$c->get( Campaign_Copier::class ),
				$c->get( Campaign_State_Machine::class ),
				$c->get( Rate_Limiter::class ),
				$c->get( Campaign_Change_Manager::class )
			)
		);
		$container->register(
			Login_Actions::class,
			static fn ( Service_Container $c ): Login_Actions => new Login_Actions(
				$c->get( Rate_Limiter::class ),
				$c->get( Audit_Repository::class ),
				$c->get( Org_Access_Repository::class )
			)
		);
		$container->register(
			Organization_Actions::class,
			static fn ( Service_Container $c ): Organization_Actions => new Organization_Actions(
				$c->get( Organization_Membership::class ),
				$c->get( Org_Repository::class ),
				$c->get( Rate_Limiter::class )
			)
		);
		$container->register(
			Signup_Actions::class,
			static fn ( Service_Container $c ): Signup_Actions => new Signup_Actions(
				$c->get( Advertiser_Registration::class ),
				$c->get( Rate_Limiter::class )
			)
		);
		$container->register(
			Password_Actions::class,
			static fn ( Service_Container $c ): Password_Actions => new Password_Actions(
				$c->get( User_Repository::class ),
				$c->get( Password_Notification::class ),
				$c->get( Password_Reset::class ),
				$c->get( Rate_Limiter::class ),
				$c->get( Audit_Repository::class )
			)
		);
		$container->register(
			Account_Actions::class,
			static fn ( Service_Container $c ): Account_Actions => new Account_Actions(
				$c->get( Password_Notification::class )
			)
		);
		$container->register(
			Email_Change_Actions::class,
			static fn ( Service_Container $c ): Email_Change_Actions => new Email_Change_Actions(
				$c->get( Email_Change::class ),
				$c->get( Rate_Limiter::class )
			)
		);
		$container->register(
			Report_Actions::class,
			static fn ( Service_Container $c ): Report_Actions => new Report_Actions(
				$c->get( Reporting_Read::class ),
				$c->get( Org_Repository::class )
			)
		);
		$container->register(
			Creative_Actions::class,
			static fn ( Service_Container $c ): Creative_Actions => new Creative_Actions(
				$c->get( Creative_Manager::class ),
				$c->get( Creative_Change_Manager::class )
			)
		);
	}
}
