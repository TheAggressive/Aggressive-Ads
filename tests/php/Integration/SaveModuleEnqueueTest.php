<?php
/**
 * The asynchronous-save module reaching the page it is written for.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Router;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use ReflectionClass;
use WP_UnitTestCase;

/**
 * A module that is written, built and never enqueued does nothing.
 *
 * Every other test of asynchronous saving reads the source: the module binds
 * `form[data-aggr-save]`, the templates carry it, the handler answers JSON.
 * All of that is true of a module the page never loads, which is the one
 * failure none of them can see.
 */
final class SaveModuleEnqueueTest extends WP_UnitTestCase {

	/**
	 * Advertiser who owns the campaign.
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * Campaign under test.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * Builds an editable campaign owned by an advertiser.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->owner = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $org_id, Org_Repository::META_OWNER_USER, $this->owner );

		$this->campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
				'post_author' => $this->owner,
			)
		);
		update_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, $org_id );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		wp_set_current_user( $this->owner );

		/*
		 * A fresh module queue per test. The queue is a property of a service
		 * that survives the whole process, so "is it enqueued?" otherwise
		 * answers yes for any test running after one that enqueued it —
		 * including the off-portal test below, which would then be reporting
		 * on its predecessor.
		 */
		( new ReflectionClass( wp_script_modules() ) )
			->getProperty( 'queue' )
			->setValue( wp_script_modules(), array() );

		// Rewrite rules only exist under pretty permalinks, and go_to() cannot
		// resolve a portal route until they are in this process.
		$this->set_permalink_structure( '/%postname%/' );
		Plugin::instance()->container()->get( Router::class )->register_rules();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Test setup: the rules have to exist before go_to() can match one.
		flush_rewrite_rules( false );
	}

	/**
	 * Restores permalinks.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->set_permalink_structure( '' );

		parent::tear_down();
	}

	/**
	 * The module ids currently enqueued.
	 *
	 * WordPress exposes no reader for this, so the registry is read directly.
	 * The alternative is asserting on rendered `<script type="module">` tags,
	 * which is a test of the printer rather than of this plugin.
	 *
	 * @return array<int, string>
	 */
	private function enqueued_modules(): array {
		$modules = wp_script_modules();

		/*
		 * The queue, not a flag on the registry. `WP_Script_Modules::enqueue()`
		 * appends to `$queue` and only touches `$registered` when it is given
		 * a src, so reading an `enqueue` key off the registry returns nothing
		 * for every module — a helper that reports "none enqueued" whatever
		 * the page did. The dialog assertion below exists because this is
		 * exactly the mistake it caught.
		 *
		 * No `setAccessible()`: it has done nothing since PHP 8.1 and is a
		 * deprecation notice on 8.5, which this suite treats as a failure.
		 */
		$queue = ( new ReflectionClass( $modules ) )
			->getProperty( 'queue' )
			->getValue( $modules );

		return array_map( 'strval', is_array( $queue ) ? $queue : array() );
	}

	/**
	 * **The save module loads on the campaign screen its forms live on.**
	 *
	 * Asserted beside the dialog module rather than alone: if the whole
	 * enqueue block stops running, an assertion about one module reports the
	 * same failure as an assertion about all of them, and naming the
	 * neighbour is what distinguishes "save was forgotten" from "nothing
	 * loads here any more".
	 *
	 * @return void
	 */
	public function test_the_save_module_is_enqueued_on_a_campaign_screen(): void {
		$this->go_to( Routes::url( Request::ROUTE_CAMPAIGNS, $this->campaign_id ) );

		Plugin::instance()->container()->get( Assets::class )->enqueue();

		$enqueued = $this->enqueued_modules();

		$this->assertContains(
			Assets::MODULE_DIALOG,
			$enqueued,
			'No interactivity module is enqueued at all, so this proves nothing about the save module.'
		);
		$this->assertContains(
			Assets::MODULE_SAVE,
			$enqueued,
			'Every form marked for asynchronous saving falls back to a full page post.'
		);
	}

	/**
	 * And nowhere else, because nothing there posts through it.
	 *
	 * @return void
	 */
	public function test_the_save_module_does_not_load_off_the_portal(): void {
		$this->go_to( home_url( '/' ) );

		Plugin::instance()->container()->get( Assets::class )->enqueue();

		$this->assertNotContains( Assets::MODULE_SAVE, $this->enqueued_modules() );
	}
}
