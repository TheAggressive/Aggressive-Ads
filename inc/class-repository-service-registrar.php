<?php
/**
 * Repository factories.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads;

use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Lifecycle_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Asset_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Attachment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Creative_Revision_Repository;
use Aggressive\Ads\Repository\Delivery_Repository;
use Aggressive\Ads\Repository\Forecast_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Repository\Org_Access_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Page_Context_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Rate_Limit_Repository;
use Aggressive\Ads\Repository\Reservation_Repository;
use Aggressive\Ads\Repository\User_Repository;
use Aggressive\Ads\Update\Release_Repository;
use Aggressive\Ads\Update\Update_Http_Client;

/**
 * Registers every repository.
 *
 * Split out of `Service_Registrar` when that file passed nine hundred lines
 * with a hundred and twenty-eight imports. Repositories are the natural seam:
 * almost all of them take no collaborators, so this file is a list rather than
 * a graph, and the registrar left behind is the part where wiring is a
 * decision.
 *
 * Registration still stores a closure and runs nothing. `Plugin` composes the
 * registrars in a visible order, and adding one costs a line there.
 */
final class Repository_Service_Registrar {

	/**
	 * Registers repository factories.
	 *
	 * @param Service_Container $container Container to populate.
	 */
	public function register( Service_Container $container ): void {
		$container->register(
			Release_Repository::class,
			static fn ( Service_Container $c ): Release_Repository => new Release_Repository(
				$c->get( Update_Http_Client::class )
			)
		);
		$container->register(
			Org_Access_Repository::class,
			static fn (): Org_Access_Repository => new Org_Access_Repository()
		);
		$container->register(
			Audit_Repository::class,
			static fn (): Audit_Repository => new Audit_Repository()
		);
		$container->register(
			Org_Repository::class,
			static fn ( Service_Container $c ): Org_Repository => new Org_Repository(
				$c->get( Org_Access_Repository::class )
			)
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
			Creative_Asset_Repository::class,
			static fn (): Creative_Asset_Repository => new Creative_Asset_Repository()
		);
		$container->register(
			Creative_Assignment_Repository::class,
			static fn (): Creative_Assignment_Repository => new Creative_Assignment_Repository()
		);
		$container->register(
			Creative_Revision_Repository::class,
			static fn ( Service_Container $c ): Creative_Revision_Repository => new Creative_Revision_Repository(
				$c->get( Creative_Repository::class )
			)
		);
		$container->register(
			Forecast_Repository::class,
			static fn (): Forecast_Repository => new Forecast_Repository()
		);
		$container->register(
			Reservation_Repository::class,
			static fn (): Reservation_Repository => new Reservation_Repository()
		);
		$container->register(
			Campaign_Lifecycle_Repository::class,
			static fn (): Campaign_Lifecycle_Repository => new Campaign_Lifecycle_Repository()
		);
		$container->register(
			User_Repository::class,
			static fn (): User_Repository => new User_Repository()
		);
		$container->register(
			Creative_Attachment_Repository::class,
			static fn (): Creative_Attachment_Repository => new Creative_Attachment_Repository()
		);
		$container->register(
			Creative_Repository::class,
			static fn ( $c ): Creative_Repository => new Creative_Repository(
				$c->get( Creative_Attachment_Repository::class )
			)
		);
		$container->register(
			Delivery_Repository::class,
			static fn (): Delivery_Repository => new Delivery_Repository()
		);
		$container->register(
			Placement_Repository::class,
			static fn (): Placement_Repository => new Placement_Repository()
		);
		$container->register(
			Page_Context_Repository::class,
			static fn (): Page_Context_Repository => new Page_Context_Repository()
		);
		$container->register(
			Package_Repository::class,
			static fn (): Package_Repository => new Package_Repository()
		);
		$container->register(
			Rate_Limit_Repository::class,
			static fn (): Rate_Limit_Repository => new Rate_Limit_Repository()
		);
	}
}
