<?php
/**
 * Registers every service factory on the container.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal;

use LAAO_Advertiser_Portal\Admin\Creative_Change_Actions;
use LAAO_Advertiser_Portal\Admin\Organization_Data;
use LAAO_Advertiser_Portal\Admin\Organization_Screen;
use LAAO_Advertiser_Portal\Admin\Review_Data;
use LAAO_Advertiser_Portal\Admin\Review_Screen;
use LAAO_Advertiser_Portal\Admin\Placement_Mapping_Data;
use LAAO_Advertiser_Portal\Admin\Placement_Mapping_Screen;
use LAAO_Advertiser_Portal\Assets\Assets;
use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Install\Installer;
use LAAO_Advertiser_Portal\Install\Upgrader;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Domain\Transition_Table;
use LAAO_Advertiser_Portal\Integration\Ad_Provider_Interface;
use LAAO_Advertiser_Portal\Integration\Adsanity\Ad_Publisher;
use LAAO_Advertiser_Portal\Integration\Adsanity\Placement_Mapping;
use LAAO_Advertiser_Portal\Notification\Email_Change_Notification;
use LAAO_Advertiser_Portal\Notification\Ending_Soon_Mailer;
use LAAO_Advertiser_Portal\Notification\Notification_Service;
use LAAO_Advertiser_Portal\Notification\Organization_Notification;
use LAAO_Advertiser_Portal\Notification\Password_Notification;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Repository\Creative_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Access_Repository;
use LAAO_Advertiser_Portal\Repository\Package_Repository;
use LAAO_Advertiser_Portal\Portal\Email_Change_Actions;
use LAAO_Advertiser_Portal\Portal\Router;
use LAAO_Advertiser_Portal\Portal\View_Data;
use LAAO_Advertiser_Portal\Portal\Account_Actions;
use LAAO_Advertiser_Portal\Portal\Campaign_Actions;
use LAAO_Advertiser_Portal\Portal\Login_Actions;
use LAAO_Advertiser_Portal\Portal\Organization_Actions;
use LAAO_Advertiser_Portal\Portal\Password_Actions;
use LAAO_Advertiser_Portal\Portal\Signup_Actions;
use LAAO_Advertiser_Portal\Portal\Creative_Actions;
use LAAO_Advertiser_Portal\REST\Campaigns_Controller;
use LAAO_Advertiser_Portal\REST\Creative_Controller;
use LAAO_Advertiser_Portal\REST\Creative_File_Controller;
use LAAO_Advertiser_Portal\REST\Placements_Controller;
use LAAO_Advertiser_Portal\REST\Packages_Controller;
use LAAO_Advertiser_Portal\REST\Transitions_Controller;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;
use LAAO_Advertiser_Portal\Repository\User_Repository;
use LAAO_Advertiser_Portal\Storage\Private_Storage;
use LAAO_Advertiser_Portal\Update\Package_Verifier;
use LAAO_Advertiser_Portal\Update\Plugin_Updates;
use LAAO_Advertiser_Portal\Update\Release_Repository;
use LAAO_Advertiser_Portal\Update\Update_Http_Client;
use LAAO_Advertiser_Portal\Workflow\Campaign_State_Machine;
use LAAO_Advertiser_Portal\Workflow\Advertiser_Registration;
use LAAO_Advertiser_Portal\Workflow\Password_Reset;
use LAAO_Advertiser_Portal\Workflow\Organization_Membership;
use LAAO_Advertiser_Portal\Workflow\Campaign_Clock;
use LAAO_Advertiser_Portal\Workflow\Campaign_Editor;
use LAAO_Advertiser_Portal\Workflow\Campaign_Validator;
use LAAO_Advertiser_Portal\Workflow\Creative_Promoter;
use LAAO_Advertiser_Portal\Workflow\Creative_Change_Manager;
use LAAO_Advertiser_Portal\Workflow\Creative_Manager;
use LAAO_Advertiser_Portal\Workflow\Creative_Retention;
use LAAO_Advertiser_Portal\Workflow\Creative_Uploader;
use LAAO_Advertiser_Portal\Workflow\Ending_Soon_Notifier;
use LAAO_Advertiser_Portal\Workflow\Review_Actions;
use LAAO_Advertiser_Portal\Workflow\Review_Readiness;
use LAAO_Advertiser_Portal\Workflow\Placement_Mapping_Manager;
use LAAO_Advertiser_Portal\Workflow\Organization_State_Manager;
use LAAO_Advertiser_Portal\Workflow\Email_Change;
use LAAO_Advertiser_Portal\Workflow\Transition_Guards;
use LAAO_Advertiser_Portal\Security\Admin_Guard;
use LAAO_Advertiser_Portal\Security\Ownership;
use LAAO_Advertiser_Portal\Security\Rate_Limiter;
use LAAO_Advertiser_Portal\Security\Roles;
use LAAO_Advertiser_Portal\Service_Container;
use LAAO_Advertiser_Portal\Repository\Campaign_Lifecycle_Repository;
use LAAO_Advertiser_Portal\Notification\Notification_Delivery;

/**
 * Composition-root registration, kept out of Plugin so boot/init stay readable.
 *
 * Adding a service still costs two greppable edits: one register() here and one
 * entry in Plugin::service_init_order() when the service needs hooks.
 */
final class Service_Registrar {

	/**
	 * Stores a factory for every service. Instantiates nothing.
	 *
	 * @param Service_Container $container Application container.
	 * @return void
	 */
	public function register( Service_Container $container ): void {
		$container->register(
			Update_Http_Client::class,
			static fn (): Update_Http_Client => new Update_Http_Client()
		);

		$container->register(
			Release_Repository::class,
			static fn ( Service_Container $c ): Release_Repository => new Release_Repository(
				$c->get( Update_Http_Client::class )
			)
		);

		$container->register(
			Package_Verifier::class,
			static fn ( Service_Container $c ): Package_Verifier => new Package_Verifier(
				$c->get( Release_Repository::class ),
				$c->get( Update_Http_Client::class )
			)
		);

		$container->register(
			Plugin_Updates::class,
			static fn ( Service_Container $c ): Plugin_Updates => new Plugin_Updates(
				$c->get( Release_Repository::class ),
				$c->get( Package_Verifier::class )
			)
		);

		$container->register(
			Org_Access_Repository::class,
			static fn (): Org_Access_Repository => new Org_Access_Repository()
		);

		$container->register(
			Post_Types::class,
			static fn (): Post_Types => new Post_Types()
		);

		$container->register(
			Post_Statuses::class,
			static fn (): Post_Statuses => new Post_Statuses()
		);

		$container->register(
			Audit_Repository::class,
			static fn (): Audit_Repository => new Audit_Repository()
		);

		$container->register(
			Roles::class,
			static fn (): Roles => new Roles()
		);

		$container->register(
			Installer::class,
			static fn ( Service_Container $c ): Installer => new Installer(
				$c->get( Audit_Repository::class ),
				$c->get( Roles::class )
			)
		);

		$container->register(
			Upgrader::class,
			static fn ( Service_Container $c ): Upgrader => new Upgrader(
				$c->get( Installer::class ),
				$c->get( Audit_Repository::class ),
				array(
					2 => static function () use ( $c ): void {
						$c->get( Installer::class )->install_org_access();
					},
				)
			)
		);

		$container->register(
			Org_Repository::class,
			static fn ( Service_Container $c ): Org_Repository => new Org_Repository(
				$c->get( Org_Access_Repository::class )
			)
		);

		$container->register(
			Ownership::class,
			static fn ( Service_Container $c ): Ownership => new Ownership(
				$c->get( Org_Repository::class )
			)
		);

		$container->register(
			Admin_Guard::class,
			static fn (): Admin_Guard => new Admin_Guard()
		);

		$container->register(
			Campaign_Repository::class,
			static fn (): Campaign_Repository => new Campaign_Repository()
		);

		$container->register(
			Campaign_Lifecycle_Repository::class,
			static fn (): Campaign_Lifecycle_Repository => new Campaign_Lifecycle_Repository()
		);

		$container->register(
			Notification_Delivery::class,
			static fn ( Service_Container $c ): Notification_Delivery => new Notification_Delivery(
				$c->get( Campaign_Repository::class ),
				$c->get( Audit_Repository::class )
			)
		);

		$container->register(
			User_Repository::class,
			static fn (): User_Repository => new User_Repository()
		);

		$container->register(
			Password_Notification::class,
			static fn ( Service_Container $c ): Password_Notification => new Password_Notification(
				$c->get( User_Repository::class )
			)
		);

		$container->register(
			Email_Change_Notification::class,
			static fn (): Email_Change_Notification => new Email_Change_Notification()
		);

		$container->register(
			Email_Change::class,
			static fn ( Service_Container $c ): Email_Change => new Email_Change(
				$c->get( User_Repository::class ),
				$c->get( Email_Change_Notification::class ),
				$c->get( Audit_Repository::class )
			)
		);

		$container->register(
			Organization_Notification::class,
			static fn ( Service_Container $c ): Organization_Notification => new Organization_Notification(
				$c->get( User_Repository::class )
			)
		);

		$container->register(
			Password_Reset::class,
			static fn ( Service_Container $c ): Password_Reset => new Password_Reset(
				$c->get( Audit_Repository::class )
			)
		);

		$container->register(
			Organization_Membership::class,
			static fn ( Service_Container $c ): Organization_Membership => new Organization_Membership(
				$c->get( Org_Access_Repository::class ),
				$c->get( Org_Repository::class ),
				$c->get( User_Repository::class ),
				$c->get( Password_Notification::class ),
				$c->get( Organization_Notification::class ),
				$c->get( Audit_Repository::class )
			)
		);

		$container->register(
			Advertiser_Registration::class,
			static fn ( Service_Container $c ): Advertiser_Registration => new Advertiser_Registration(
				$c->get( User_Repository::class ),
				$c->get( Org_Repository::class ),
				$c->get( Organization_Membership::class ),
				$c->get( Password_Notification::class ),
				$c->get( Audit_Repository::class )
			)
		);

		$container->register(
			Creative_Repository::class,
			static fn (): Creative_Repository => new Creative_Repository()
		);

		$container->register(
			Placement_Repository::class,
			static fn (): Placement_Repository => new Placement_Repository()
		);

		$container->register(
			Package_Repository::class,
			static fn (): Package_Repository => new Package_Repository()
		);

		$container->register(
			Campaign_Editor::class,
			static fn ( Service_Container $c ): Campaign_Editor => new Campaign_Editor(
				$c->get( Campaign_Repository::class ),
				$c->get( Org_Repository::class ),
				$c->get( Package_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Audit_Repository::class )
			)
		);

		$container->register(
			Campaign_Validator::class,
			static fn ( Service_Container $c ): Campaign_Validator => new Campaign_Validator(
				$c->get( Campaign_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Org_Repository::class ),
				$c->get( Package_Repository::class )
			)
		);

		$container->register(
			Campaign_Clock::class,
			static fn ( Service_Container $c ): Campaign_Clock => new Campaign_Clock(
				$c->get( Campaign_State_Machine::class ),
				$c->get( Campaign_Lifecycle_Repository::class ),
				$c->get( Campaign_Repository::class )
			)
		);

		$container->register(
			Ending_Soon_Mailer::class,
			static fn ( Service_Container $c ): Ending_Soon_Mailer => new Ending_Soon_Mailer(
				$c->get( Campaign_Repository::class ),
				$c->get( Org_Repository::class ),
				$c->get( Audit_Repository::class ),
				$c->get( Notification_Delivery::class )
			)
		);

		$container->register(
			Ending_Soon_Notifier::class,
			static fn ( Service_Container $c ): Ending_Soon_Notifier => new Ending_Soon_Notifier(
				$c->get( Campaign_Lifecycle_Repository::class ),
				$c->get( Ending_Soon_Mailer::class )
			)
		);

		$container->register(
			Creative_Retention::class,
			static fn ( Service_Container $c ): Creative_Retention => new Creative_Retention(
				$c->get( Campaign_Lifecycle_Repository::class ),
				$c->get( Campaign_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Private_Storage::class ),
				$c->get( Audit_Repository::class )
			)
		);

		$container->register(
			Review_Readiness::class,
			static fn ( Service_Container $c ): Review_Readiness => new Review_Readiness(
				$c->get( Campaign_Validator::class )
			)
		);

		$container->register(
			Placement_Mapping::class,
			static fn ( Service_Container $c ): Placement_Mapping => new Placement_Mapping(
				$c->get( Placement_Repository::class )
			)
		);

		$container->register(
			Placement_Mapping_Manager::class,
			static fn ( Service_Container $c ): Placement_Mapping_Manager => new Placement_Mapping_Manager(
				$c->get( Placement_Repository::class ),
				$c->get( Placement_Mapping::class ),
				$c->get( Audit_Repository::class )
			)
		);

		$container->register(
			Organization_State_Manager::class,
			static fn ( Service_Container $c ): Organization_State_Manager => new Organization_State_Manager(
				$c->get( Org_Repository::class ),
				$c->get( Audit_Repository::class )
			)
		);

		$container->register(
			Placement_Mapping_Data::class,
			static fn ( Service_Container $c ): Placement_Mapping_Data => new Placement_Mapping_Data(
				$c->get( Placement_Repository::class ),
				$c->get( Placement_Mapping::class )
			)
		);

		$container->register(
			Organization_Data::class,
			static fn ( Service_Container $c ): Organization_Data => new Organization_Data(
				$c->get( Org_Repository::class ),
				$c->get( Campaign_Repository::class ),
				$c->get( User_Repository::class )
			)
		);

		$container->register(
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

		$container->register(
			Private_Storage::class,
			static fn (): Private_Storage => new Private_Storage()
		);

		$container->register(
			Creative_Uploader::class,
			static fn ( Service_Container $c ): Creative_Uploader => new Creative_Uploader(
				$c->get( Private_Storage::class )
			)
		);

		$container->register(
			Creative_Manager::class,
			static fn ( Service_Container $c ): Creative_Manager => new Creative_Manager(
				$c->get( Campaign_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Creative_Uploader::class ),
				$c->get( Private_Storage::class ),
				$c->get( Rate_Limiter::class ),
				$c->get( Audit_Repository::class )
			)
		);

		$container->register(
			Creative_Promoter::class,
			static fn ( Service_Container $c ): Creative_Promoter => new Creative_Promoter(
				$c->get( Creative_Repository::class ),
				$c->get( Private_Storage::class )
			)
		);

		$container->register(
			Router::class,
			static fn (): Router => new Router()
		);

		$container->register(
			View_Data::class,
			static fn ( Service_Container $c ): View_Data => new View_Data(
				$c->get( Campaign_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Org_Repository::class ),
				$c->get( Org_Access_Repository::class ),
				$c->get( Package_Repository::class ),
				$c->get( Campaign_Editor::class ),
				$c->get( Review_Readiness::class ),
				$c->get( Email_Change::class )
			)
		);

		$container->register(
			Assets::class,
			static fn ( Service_Container $c ): Assets => new Assets(
				$c->get( Router::class )
			)
		);

		$container->register(
			Campaign_Actions::class,
			static fn ( Service_Container $c ): Campaign_Actions => new Campaign_Actions(
				$c->get( Campaign_Editor::class ),
				$c->get( Campaign_State_Machine::class ),
				$c->get( Rate_Limiter::class )
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
			Creative_Actions::class,
			static fn ( Service_Container $c ): Creative_Actions => new Creative_Actions(
				$c->get( Creative_Manager::class ),
				$c->get( Creative_Change_Manager::class )
			)
		);

		$container->register(
			Campaigns_Controller::class,
			static fn ( Service_Container $c ): Campaigns_Controller => new Campaigns_Controller(
				$c->get( Campaign_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Org_Repository::class ),
				$c->get( Campaign_Editor::class ),
				$c->get( Review_Readiness::class ),
				$c->get( Rate_Limiter::class )
			)
		);

		$container->register(
			Placements_Controller::class,
			static fn ( Service_Container $c ): Placements_Controller => new Placements_Controller(
				$c->get( Placement_Repository::class )
			)
		);

		$container->register(
			Packages_Controller::class,
			static fn ( Service_Container $c ): Packages_Controller => new Packages_Controller(
				$c->get( Package_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Campaign_Editor::class )
			)
		);

		$container->register(
			Rate_Limiter::class,
			static fn ( Service_Container $c ): Rate_Limiter => new Rate_Limiter(
				$c->get( Audit_Repository::class )
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
			Ad_Publisher::class,
			static fn ( Service_Container $c ): Ad_Publisher => new Ad_Publisher(
				$c->get( Campaign_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Placement_Mapping::class ),
				$c->get( Creative_Promoter::class )
			)
		);

		$container->register(
			Ad_Provider_Interface::class,
			static fn ( Service_Container $c ): Ad_Provider_Interface => $c->get( Ad_Publisher::class )
		);

		$container->register(
			Creative_Change_Manager::class,
			static fn ( Service_Container $c ): Creative_Change_Manager => new Creative_Change_Manager(
				$c->get( Campaign_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Creative_Uploader::class ),
				$c->get( Private_Storage::class ),
				$c->get( Rate_Limiter::class ),
				$c->get( Ad_Provider_Interface::class ),
				$c->get( Audit_Repository::class )
			)
		);

		$container->register(
			Creative_Change_Actions::class,
			static fn ( Service_Container $c ): Creative_Change_Actions => new Creative_Change_Actions(
				$c->get( Creative_Change_Manager::class )
			)
		);

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
				$c->get( Audit_Repository::class )
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
			Review_Screen::class,
			static fn ( Service_Container $c ): Review_Screen => new Review_Screen(
				$c->get( Review_Data::class ),
				$c->get( Review_Actions::class )
			)
		);

		$container->register(
			Placement_Mapping_Screen::class,
			static fn ( Service_Container $c ): Placement_Mapping_Screen => new Placement_Mapping_Screen(
				$c->get( Placement_Mapping_Data::class ),
				$c->get( Placement_Mapping_Manager::class )
			)
		);

		$container->register(
			Organization_Screen::class,
			static fn ( Service_Container $c ): Organization_Screen => new Organization_Screen(
				$c->get( Organization_Data::class ),
				$c->get( Organization_State_Manager::class )
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
}
