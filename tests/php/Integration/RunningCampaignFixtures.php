<?php
/**
 * A campaign already running on a package, for live-edit scenarios.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Campaign_Request_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Campaign_Change_Manager;
use Aggressive\Ads\Workflow\Campaign_Editor;

/**
 * An advertiser, their organization, a 30-day package selling one
 * leaderboard at USD 450.00, and a campaign bought on it through the wizard.
 *
 * Shared by the change suites that each outgrew one file; three copies of this
 * set-up had already started to differ.
 */
trait RunningCampaignFixtures {

	use CampaignEditorFixtures;

	private const DAY = 86400;

	/**
	 * The advertiser.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * Their organization.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * The placement the fixture package sells.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * The fixture package.
	 *
	 * @var int
	 */
	private int $package_id;

	/**
	 * Draft creation.
	 *
	 * @var Campaign_Editor
	 */
	private Campaign_Editor $editor;

	/**
	 * The portal's form handlers.
	 *
	 * @var Campaign_Actions
	 */
	private Campaign_Actions $actions;

	/**
	 * The service under test.
	 *
	 * @var Campaign_Change_Manager
	 */
	private Campaign_Change_Manager $changes;

	/**
	 * Staged edits.
	 *
	 * @var Campaign_Request_Repository
	 */
	private Campaign_Request_Repository $requests;

	/**
	 * Builds the fixture. Call from `set_up()` after the parent's.
	 *
	 * @return void
	 */
	private function set_up_running_campaign(): void {
		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->org_id     = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Bright Angle Media',
			)
		);

		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->advertiser );

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage Leaderboard',
			)
		);

		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );

		$this->package_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PACKAGE,
				'post_status' => 'publish',
				'post_title'  => 'Launch package',
			)
		);

		add_post_meta( $this->package_id, Package_Repository::META_PLACEMENT_ID, $this->placement_id );
		update_post_meta( $this->package_id, Package_Repository::META_DURATION_DAYS, 30 );
		update_post_meta( $this->package_id, Package_Repository::META_PRICE_CENTS, 45000 );
		update_post_meta( $this->package_id, Package_Repository::META_CURRENCY, 'USD' );
		update_post_meta( $this->package_id, Package_Repository::META_IS_ACTIVE, 1 );

		$container      = Plugin::instance()->container();
		$this->editor   = $container->get( Campaign_Editor::class );
		$this->actions  = $container->get( Campaign_Actions::class );
		$this->changes  = $container->get( Campaign_Change_Manager::class );
		$this->requests = $container->get( Campaign_Request_Repository::class );

		$container->get( Org_Repository::class )->flush_cache();
		$container->get( Ownership::class )->flush_cache();
	}

	/**
	 * Enables exactly these live-edit switches.
	 *
	 * @param array<int, string> $keys Settings_Schema::edit_keys() to turn on.
	 * @return void
	 */
	private function allow( array $keys ): void {
		$settings = Plugin::instance()->container()->get( Settings::class );
		$document = $settings->get();

		foreach ( Settings_Schema::edit_keys() as $key ) {
			$document['live_edits'][ $key ] = in_array( $key, $keys, true );
		}

		$this->assertTrue( $settings->save( $document ) );
	}

	/**
	 * A campaign bought on the fixture package, moved to a status and window.
	 *
	 * @param string $status Post status.
	 * @param int    $start  Start, UTC seconds; defaults to yesterday.
	 * @param int    $end    End, UTC seconds; defaults to tomorrow.
	 * @return int
	 */
	private function running_campaign( string $status = Post_Statuses::LIVE, int $start = 0, int $end = 0 ): int {
		$campaign_id = $this->complete_campaign( 'Running flight' );

		wp_update_post(
			array(
				'ID'          => $campaign_id,
				'post_status' => $status,
			)
		);

		update_post_meta( $campaign_id, Campaign_Repository::META_START_TS, 0 === $start ? time() - self::DAY : $start );
		update_post_meta( $campaign_id, Campaign_Repository::META_END_TS, 0 === $end ? time() + self::DAY : $end );

		return $campaign_id;
	}
}
