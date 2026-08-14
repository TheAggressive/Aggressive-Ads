<?php
/**
 * Keeps advertisers out of wp-admin.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Security;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Portal\Routes;

/**
 * Redirects portal users away from the WordPress admin.
 *
 * **This is convenience, not security.** The capability model already denies
 * everything the redirect hides: an advertiser holds neither `edit_posts` nor
 * `upload_files`, so the admin has nothing to show them even with the guard
 * removed. What it buys is that an advertiser who bookmarks /wp-admin/ lands
 * somewhere useful instead of on a bare "You do not have sufficient
 * permissions" page.
 *
 * Both the wiring and the behaviour are tested, because a guard that silently
 * stops being hooked looks exactly like one that works.
 */
final class Admin_Guard implements Service {

	/**
	 * Attaches the guard.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_init', array( $this, 'guard' ) );
	}

	/**
	 * Sends portal users to the portal.
	 *
	 * @return void
	 */
	public function guard(): void {
		if ( ! $this->should_redirect() ) {
			return;
		}

		wp_safe_redirect( Routes::url(), 302 );

		exit;
	}

	/**
	 * Whether the current request should be bounced.
	 *
	 * @return bool
	 */
	public function should_redirect(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		// AJAX and admin-post both run through admin_init. Redirecting them
		// breaks every legitimate background request the site makes, including
		// core's own heartbeat, and the failure looks like a broken feature
		// rather than a redirect.
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		$pagenow = isset( $GLOBALS['pagenow'] ) && is_string( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';

		if ( in_array( $pagenow, array( 'admin-ajax.php', 'admin-post.php' ), true ) ) {
			return false;
		}

		// Staff belong in the admin: the review queue lives there.
		if ( current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			return false;
		}

		return current_user_can( Capabilities::ACCESS_PORTAL );
	}
}
