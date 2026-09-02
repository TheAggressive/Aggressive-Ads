<?php
/**
 * Registers core and request-facing service factories on the container.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads;

use Aggressive\Ads\Admin\Campaign_Change_Actions;
use Aggressive\Ads\Admin\Menu;
use Aggressive\Ads\Admin\Organization_Data;
use Aggressive\Ads\Admin\Package_Data;
use Aggressive\Ads\Admin\Placement_Data;
use Aggressive\Ads\Admin\Settings_Screen;
use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Assets\Brand_Styles;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Install\Migration_Map;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Asset_Repository;
use Aggressive\Ads\Install\Assignment_Health;
use Aggressive\Ads\Install\Decision_Health;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Install\Line_Item_Migrator;
use Aggressive\Ads\Install\Rewrite_Flusher;
use Aggressive\Ads\Install\Site_Lifecycle;
use Aggressive\Ads\Install\Upgrader;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Domain\Transition_Table;
use Aggressive\Ads\Integration\Ad_Provider_Interface;
use Aggressive\Ads\Integration\Native\Publisher;
use Aggressive\Ads\Notification\Email_Change_Notification;
use Aggressive\Ads\Notification\Creative_Mailer;
use Aggressive\Ads\Notification\Ending_Soon_Mailer;
use Aggressive\Ads\Notification\Organization_Notification;
use Aggressive\Ads\Notification\Password_Notification;
use Aggressive\Ads\Notification\Request_Mailer;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Creative_Revision_Repository;
use Aggressive\Ads\Repository\Delivery_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Org_Access_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Rate_Limit_Repository;
use Aggressive\Ads\Portal\Acting_Actions;
use Aggressive\Ads\Portal\Acting_As;
use Aggressive\Ads\Portal\Email_Change_Actions;
use Aggressive\Ads\Portal\Router;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Portal\Account_Actions;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Login_Actions;
use Aggressive\Ads\Portal\Organization_Actions;
use Aggressive\Ads\Portal\Password_Actions;
use Aggressive\Ads\Portal\Report_Actions;
use Aggressive\Ads\Portal\Signup_Actions;
use Aggressive\Ads\Portal\Creative_Actions;
use Aggressive\Ads\REST\Campaigns_Controller;
use Aggressive\Ads\REST\Line_Items_Controller;
use Aggressive\Ads\REST\Creative_Controller;
use Aggressive\Ads\REST\Creative_File_Controller;
use Aggressive\Ads\REST\Placements_Controller;
use Aggressive\Ads\REST\Packages_Controller;
use Aggressive\Ads\REST\Organizations_Controller;
use Aggressive\Ads\REST\Settings_Controller;
use Aggressive\Ads\REST\Transitions_Controller;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Repository\User_Repository;
use Aggressive\Ads\Storage\Creative_Cipher;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Update\Package_Verifier;
use Aggressive\Ads\Update\Plugin_Updates;
use Aggressive\Ads\Update\Release_Repository;
use Aggressive\Ads\Update\Update_Http_Client;
use Aggressive\Ads\Workflow\Campaign_State_Machine;
use Aggressive\Ads\Workflow\Advertiser_Registration;
use Aggressive\Ads\Workflow\Password_Reset;
use Aggressive\Ads\Workflow\Organization_Membership;
use Aggressive\Ads\Workflow\Campaign_Change_Manager;
use Aggressive\Ads\Workflow\Campaign_Clock;
use Aggressive\Ads\Workflow\Campaign_Copier;
use Aggressive\Ads\Workflow\Campaign_Editor;
use Aggressive\Ads\Workflow\Line_Item_Editor;
use Aggressive\Ads\Workflow\Assignment_Projection;
use Aggressive\Ads\Workflow\Line_Item_Lifecycle;
use Aggressive\Ads\Workflow\Line_Item_Validator;
use Aggressive\Ads\Workflow\Assigned_Creatives;
use Aggressive\Ads\Workflow\Assignment_Editor;
use Aggressive\Ads\Workflow\Edit_Window;
use Aggressive\Ads\Workflow\Campaign_Validator;
use Aggressive\Ads\Workflow\Coverage_Service;
use Aggressive\Ads\Workflow\Decision_Engine;
use Aggressive\Ads\Workflow\Decision_Metrics;
use Aggressive\Ads\Workflow\Creative_Promoter;
use Aggressive\Ads\Workflow\Creative_Change_Manager;
use Aggressive\Ads\Workflow\Creative_Manager;
use Aggressive\Ads\Workflow\Audit_Retention;
use Aggressive\Ads\Workflow\Creative_Retention;
use Aggressive\Ads\Workflow\Creative_Uploader;
use Aggressive\Ads\Workflow\Ending_Soon_Notifier;
use Aggressive\Ads\Workflow\Fill_Cache;
use Aggressive\Ads\Workflow\Reporting_Read;
use Aggressive\Ads\Workflow\Revision_Policy;
use Aggressive\Ads\Workflow\Reviewer_Access;
use Aggressive\Ads\Workflow\Review_Readiness;
use Aggressive\Ads\Workflow\Placement_Manager;
use Aggressive\Ads\Workflow\Organization_State_Manager;
use Aggressive\Ads\Workflow\Package_Manager;
use Aggressive\Ads\Workflow\Email_Change;
use Aggressive\Ads\Workflow\Transition_Guards;
use Aggressive\Ads\Security\Admin_Guard;
use Aggressive\Ads\Security\Delivery_Health;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Private_Storage_Health;
use Aggressive\Ads\Admin\Media_Library;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Service_Container;
use Aggressive\Ads\Repository\Campaign_Lifecycle_Repository;
use Aggressive\Ads\Notification\Notification_Delivery;

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
			Settings::class,
			static fn (): Settings => new Settings()
		);

		$container->register(
			\Aggressive\Ads\Admin\Pending_Work::class,
			static fn ( Service_Container $c ): \Aggressive\Ads\Admin\Pending_Work => new \Aggressive\Ads\Admin\Pending_Work(
				$c->get( Campaign_Repository::class )
			)
		);

		$container->register(
			Menu::class,
			static fn ( Service_Container $c ): Menu => new Menu(
				$c->get( Settings::class ),
				$c->get( \Aggressive\Ads\Admin\Pending_Work::class )
			)
		);

		$container->register(
			Settings_Screen::class,
			static fn ( Service_Container $c ): Settings_Screen => new Settings_Screen(
				$c->get( Settings::class ),
				$c->get( Reviewer_Access::class )
			)
		);

		$container->register(
			Brand_Styles::class,
			static fn ( Service_Container $c ): Brand_Styles => new Brand_Styles(
				$c->get( Settings::class )
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
				$c->get( Roles::class ),
				$c->get( Line_Item_Repository::class )
			)
		);

		$container->register(
			Site_Lifecycle::class,
			static fn ( Service_Container $c ): Site_Lifecycle => new Site_Lifecycle(
				$c->get( Upgrader::class ),
				$c->get( Installer::class ),
				static function () use ( $c ): void {
					$c->get( Rewrite_Flusher::class )->flush();
				}
			)
		);

		$container->register(
			Upgrader::class,
			static fn ( Service_Container $c ): Upgrader => new Upgrader(
				$c->get( Installer::class ),
				$c->get( Audit_Repository::class ),
				Migration_Map::steps( $c )
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
			Line_Item_Repository::class,
			static fn ( Service_Container $c ): Line_Item_Repository => new Line_Item_Repository(
				$c->get( Campaign_Repository::class )
			)
		);

		$container->register(
			Line_Item_Migrator::class,
			static fn ( Service_Container $c ): Line_Item_Migrator => new Line_Item_Migrator(
				$c->get( Line_Item_Repository::class ),
				$c->get( Campaign_Repository::class )
			)
		);

		$container->register(
			Creative_Asset_Repository::class,
			static fn (): Creative_Asset_Repository => new Creative_Asset_Repository()
		);

		$container->register(
			Creative_Assignment_Repository::class,
			static fn (): Creative_Assignment_Repository => new Creative_Assignment_Repository()
		);

		$container->register(
			Creative_Assignment_Migrator::class,
			static fn ( Service_Container $c ): Creative_Assignment_Migrator => new Creative_Assignment_Migrator(
				$c->get( Creative_Repository::class ),
				$c->get( Creative_Asset_Repository::class ),
				$c->get( Creative_Assignment_Repository::class ),
				$c->get( Line_Item_Repository::class ),
				$c->get( Campaign_Repository::class ),
				$c->get( Creative_Revision_Repository::class )
			)
		);

		$container->register(
			Assignment_Health::class,
			static fn ( Service_Container $c ): Assignment_Health => new Assignment_Health(
				$c->get( Creative_Assignment_Repository::class ),
				$c->get( Creative_Assignment_Migrator::class )
			)
		);

		$container->register(
			\Aggressive\Ads\Install\Conversion_Health::class,
			static fn ( Service_Container $c ): \Aggressive\Ads\Install\Conversion_Health => new \Aggressive\Ads\Install\Conversion_Health(
				$c->get( \Aggressive\Ads\Repository\Conversion_Definition_Repository::class ),
				$c->get( \Aggressive\Ads\Repository\Rollup_Repository::class ),
				$c->get( \Aggressive\Ads\Workflow\Conversion_Metrics::class )
			)
		);

		$container->register(
			\Aggressive\Ads\Install\Viewability_Health::class,
			static fn ( Service_Container $c ): \Aggressive\Ads\Install\Viewability_Health => new \Aggressive\Ads\Install\Viewability_Health(
				$c->get( \Aggressive\Ads\Repository\Rollup_Repository::class )
			)
		);

		$container->register(
			Decision_Health::class,
			static fn ( Service_Container $c ): Decision_Health => new Decision_Health(
				$c->get( Decision_Engine::class ),
				$c->get( Creative_Assignment_Migrator::class )
			)
		);

		$container->register(
			Creative_Revision_Repository::class,
			static fn ( Service_Container $c ): Creative_Revision_Repository => new Creative_Revision_Repository(
				$c->get( Creative_Repository::class )
			)
		);

		$container->register(
			Assignment_Editor::class,
			static fn ( Service_Container $c ): Assignment_Editor => new Assignment_Editor(
				$c->get( Creative_Assignment_Repository::class ),
				$c->get( Line_Item_Repository::class ),
				$c->get( Campaign_Repository::class ),
				$c->get( Audit_Repository::class ),
				$c->get( Edit_Window::class )
			)
		);

		$container->register(
			Coverage_Service::class,
			static fn ( Service_Container $c ): Coverage_Service => new Coverage_Service(
				$c->get( Assigned_Creatives::class ),
				$c->get( Creative_Assignment_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Placement_Repository::class )
			)
		);

		$container->register(
			Revision_Policy::class,
			static fn ( Service_Container $c ): Revision_Policy => new Revision_Policy(
				$c->get( Creative_Repository::class ),
				$c->get( Creative_Revision_Repository::class ),
				$c->get( Creative_Assignment_Repository::class ),
				$c->get( Line_Item_Repository::class )
			)
		);

		$container->register(
			Assigned_Creatives::class,
			static fn ( Service_Container $c ): Assigned_Creatives => new Assigned_Creatives(
				$c->get( Creative_Assignment_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Creative_Assignment_Migrator::class )
			)
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
				$c->get( Audit_Repository::class ),
				$c->get( Settings::class )
			)
		);

		$container->register(
			Creative_Repository::class,
			static fn (): Creative_Repository => new Creative_Repository()
		);

		$container->register(
			Delivery_Repository::class,
			static fn (): Delivery_Repository => new Delivery_Repository()
		);

		$container->register(
			Private_Storage_Health::class,
			static fn ( Service_Container $c ): Private_Storage_Health => new Private_Storage_Health(
				$c->get( Private_Storage::class )
			)
		);

		$container->register(
			Media_Library::class,
			static fn (): Media_Library => new Media_Library()
		);

		$container->register(
			Delivery_Health::class,
			static fn ( Service_Container $c ): Delivery_Health => new Delivery_Health(
				$c->get( Event_Repository::class ),
				$c->get( Rollup_Repository::class )
			)
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
			Edit_Window::class,
			static fn ( Service_Container $c ): Edit_Window => new Edit_Window(
				$c->get( Campaign_Repository::class ),
				$c->get( Org_Repository::class )
			)
		);

		$container->register(
			Campaign_Editor::class,
			static fn ( Service_Container $c ): Campaign_Editor => new Campaign_Editor(
				$c->get( Campaign_Repository::class ),
				$c->get( Org_Repository::class ),
				$c->get( Package_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Audit_Repository::class ),
				$c->get( Edit_Window::class ),
				$c->get( Fill_Cache::class ),
				$c->get( Line_Item_Repository::class )
			)
		);

		$container->register( Line_Item_Validator::class, static fn (): Line_Item_Validator => new Line_Item_Validator() );

		$container->register(
			Line_Item_Editor::class,
			static fn ( Service_Container $c ): Line_Item_Editor => new Line_Item_Editor(
				$c->get( Line_Item_Repository::class ),
				$c->get( Campaign_Repository::class ),
				$c->get( Line_Item_Validator::class ),
				$c->get( Audit_Repository::class ),
				$c->get( Edit_Window::class )
			)
		);

		$container->register(
			Assignment_Projection::class,
			static fn ( Service_Container $c ): Assignment_Projection => new Assignment_Projection(
				$c->get( \Aggressive\Ads\Repository\Campaign_Repository::class ),
				$c->get( \Aggressive\Ads\Repository\Creative_Assignment_Repository::class ),
				$c->get( \Aggressive\Ads\Repository\Creative_Repository::class )
			)
		);

		$container->register(
			Line_Item_Lifecycle::class,
			static fn ( Service_Container $c ): Line_Item_Lifecycle => new Line_Item_Lifecycle(
				$c->get( Line_Item_Repository::class ),
				$c->get( Creative_Assignment_Repository::class )
			)
		);

		$container->register(
			Campaign_Copier::class,
			static fn ( Service_Container $c ): Campaign_Copier => new Campaign_Copier(
				$c->get( Campaign_Editor::class ),
				$c->get( Campaign_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Private_Storage::class ),
				$c->get( Audit_Repository::class ),
				$c->get( Line_Item_Repository::class )
			)
		);

		$container->register(
			Reporting_Read::class,
			static fn ( Service_Container $c ): Reporting_Read => new Reporting_Read(
				$c->get( Settings::class ),
				$c->get( Rollup_Repository::class )
			)
		);

		$container->register(
			Campaign_Validator::class,
			static fn ( Service_Container $c ): Campaign_Validator => new Campaign_Validator(
				$c->get( Campaign_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Coverage_Service::class ),
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
			Creative_Mailer::class,
			static fn ( Service_Container $c ): Creative_Mailer => new Creative_Mailer(
				$c->get( \Aggressive\Ads\Workflow\Creative_Approval::class ),
				$c->get( Campaign_Repository::class ),
				$c->get( Org_Repository::class ),
				$c->get( User_Repository::class ),
				$c->get( Audit_Repository::class ),
				$c->get( Notification_Delivery::class )
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
			Request_Mailer::class,
			static fn ( Service_Container $c ): Request_Mailer => new Request_Mailer(
				$c->get( Campaign_Repository::class ),
				$c->get( Org_Repository::class ),
				$c->get( User_Repository::class ),
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
			Audit_Retention::class,
			static fn ( Service_Container $c ): Audit_Retention => new Audit_Retention(
				$c->get( Audit_Repository::class ),
				$c->get( Settings::class )
			)
		);

		$container->register(
			Creative_Retention::class,
			static fn ( Service_Container $c ): Creative_Retention => new Creative_Retention(
				$c->get( Campaign_Lifecycle_Repository::class ),
				$c->get( Campaign_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Private_Storage::class ),
				$c->get( Audit_Repository::class ),
				$c->get( Settings::class )
			)
		);

		$container->register(
			Review_Readiness::class,
			static fn ( Service_Container $c ): Review_Readiness => new Review_Readiness(
				$c->get( Campaign_Validator::class )
			)
		);

		$container->register(
			Placement_Manager::class,
			static fn ( Service_Container $c ): Placement_Manager => new Placement_Manager(
				$c->get( Placement_Repository::class ),
				$c->get( Audit_Repository::class ),
				$c->get( Fill_Cache::class )
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
			Placement_Data::class,
			static fn ( Service_Container $c ): Placement_Data => new Placement_Data(
				$c->get( Placement_Repository::class )
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
						Transition_Table::GUARD_VALIDATOR  => $c->get( Campaign_Validator::class )->as_guard(),
						Transition_Table::GUARD_APPROVABLE => $c->get( Campaign_Validator::class )->as_approval_guard(),
					)
				);
			}
		);

		$container->register(
			Creative_Cipher::class,
			static fn (): Creative_Cipher => new Creative_Cipher()
		);

		$container->register(
			Private_Storage::class,
			static fn ( Service_Container $c ): Private_Storage => new Private_Storage(
				$c->get( Creative_Cipher::class )
			)
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
				$c->get( Audit_Repository::class ),
				$c->get( Edit_Window::class ),
				$c->get( \Aggressive\Ads\Workflow\Creative_Approval::class )
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
			static fn ( Service_Container $c ): Router => new Router(
				$c->get( Advertiser_Registration::class )
			)
		);

		$container->register(
			View_Data::class,
			static fn ( Service_Container $c ): View_Data => new View_Data(
				$c->get( Campaign_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Assigned_Creatives::class ),
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
				$c->get( \Aggressive\Ads\Workflow\Creative_Approval::class ),
				$c->get( Creative_Revision_Repository::class )
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

		$container->register(
			Rate_Limit_Repository::class,
			static fn (): Rate_Limit_Repository => new Rate_Limit_Repository()
		);

		$container->register(
			Rate_Limiter::class,
			static fn ( Service_Container $c ): Rate_Limiter => new Rate_Limiter(
				$c->get( Audit_Repository::class ),
				$c->get( Rate_Limit_Repository::class )
			)
		);

		$container->register(
			Publisher::class,
			static fn ( Service_Container $c ): Publisher => new Publisher(
				$c->get( Fill_Cache::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Creative_Promoter::class )
			)
		);

		$container->register(
			Ad_Provider_Interface::class,
			static fn ( Service_Container $c ): Ad_Provider_Interface => $c->get( Publisher::class )
		);

		$container->register(
			Creative_Change_Manager::class,
			static fn ( Service_Container $c ): Creative_Change_Manager => new Creative_Change_Manager(
				$c->get( Campaign_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Creative_Revision_Repository::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Creative_Uploader::class ),
				$c->get( Private_Storage::class ),
				$c->get( Rate_Limiter::class ),
				$c->get( Ad_Provider_Interface::class ),
				$c->get( Audit_Repository::class )
			)
		);

		$container->register(
			Campaign_Change_Actions::class,
			static fn ( Service_Container $c ): Campaign_Change_Actions => new Campaign_Change_Actions(
				$c->get( Campaign_Change_Manager::class )
			)
		);

		$container->register(
			Reviewer_Access::class,
			static fn ( Service_Container $c ): Reviewer_Access => new Reviewer_Access(
				$c->get( User_Repository::class ),
				$c->get( Audit_Repository::class )
			)
		);

		$container->register(
			Campaign_Change_Manager::class,
			static fn ( Service_Container $c ): Campaign_Change_Manager => new Campaign_Change_Manager(
				$c->get( Campaign_Repository::class ),
				$c->get( Creative_Repository::class ),
				$c->get( Revision_Policy::class ),
				$c->get( Placement_Repository::class ),
				$c->get( Settings::class ),
				$c->get( Fill_Cache::class ),
				$c->get( Rate_Limiter::class ),
				$c->get( Audit_Repository::class )
			)
		);

		( new Runtime_Service_Registrar() )->register( $container );
	}
}
