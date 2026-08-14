<?php
/**
 * One hard rewrite flush for portal and click-hop rules.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Portal\Router;
use Aggressive\Ads\Workflow\Click_Hop;

/**
 * Activation, a new network site, and a file-only version bump all have to
 * write the same rules. Three callers flushing separately double-wrote
 * .htaccess and left Install reaching for Plugin::instance().
 */
final class Rewrite_Flusher implements Service {

	/**
	 * Constructor. Reads nothing.
	 *
	 * @param Router    $router Portal rules.
	 * @param Click_Hop $hop    Click-hop rule.
	 */
	public function __construct(
		private readonly Router $router,
		private readonly Click_Hop $hop
	) {
	}

	/**
	 * Version-gated flush for file-only deploys, where the activation hook
	 * never ran.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'maybe_flush' ), 99 );
	}

	/**
	 * Flushes exactly once when either declared version moves.
	 *
	 * Never on every request: flush_rewrite_rules() regenerates every rule on
	 * the site and rewrites .htaccess, and calling it per request is a
	 * well-known way to make a site inexplicably slow.
	 *
	 * @return void
	 */
	public function maybe_flush(): void {
		if (
			(int) get_option( Router::OPTION_REWRITE_VERSION, 0 ) === Router::REWRITE_VERSION
			&& (int) get_option( Click_Hop::OPTION_REWRITE, 0 ) === Click_Hop::REWRITE_VERSION
		) {
			return;
		}

		$this->flush();
	}

	/**
	 * Registers portal and click-hop rules, then writes them the same way
	 * Settings → Permalinks → Save does.
	 *
	 * Pretty permalinks must already be on; this does not change
	 * `permalink_structure`. Soft flush (`false`) updates the option and
	 * leaves `.htaccess` stale, which is the Apache 404.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->router->register_rules();
		$this->hop->register_rules();

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Activation, new-site, and version-gated deploys only, never per request. Soft flush is why /advertiser/ 404s until Save Permalinks; the hard form writes the Apache rules. See docs/portal-routing-and-ui.md.
		flush_rewrite_rules( true );

		update_option( Router::OPTION_REWRITE_VERSION, Router::REWRITE_VERSION, true );
		update_option( Click_Hop::OPTION_REWRITE, Click_Hop::REWRITE_VERSION, true );
	}
}
