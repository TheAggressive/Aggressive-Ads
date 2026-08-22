<?php
/**
 * Form handlers for the acting-as session.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Security\Capabilities;

/**
 * Leaving an acting-as session.
 *
 * Its own handler rather than another case in `Campaign_Actions`, because it
 * is not about a campaign: it ends a session that spans every portal screen,
 * and it is reachable from the rail on all of them.
 */
final class Acting_Actions implements Service {

	public const LEAVE_ACTION = 'aggr_leave_acting_as';

	/**
	 * Wires the session.
	 *
	 * @param Acting_As $acting Staff acting for an advertiser.
	 */
	public function __construct( private readonly Acting_As $acting ) {
	}

	/**
	 * Attaches the handler.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_post_' . self::LEAVE_ACTION, array( $this, 'handle_leave' ) );
	}

	/**
	 * Ends the session and returns staff to the review queue.
	 *
	 * A form post rather than a link, because a link is followed by anything
	 * that prefetches — and ending the session from a page the staff member
	 * never clicked is exactly the confusion the session exists to prevent.
	 *
	 * @return void
	 */
	public function handle_leave(): void {
		if ( ! is_user_logged_in() || ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			wp_safe_redirect( Routes::url() );

			exit;
		}

		check_admin_referer( self::LEAVE_ACTION );

		$this->acting->leave();

		wp_safe_redirect( admin_url( 'admin.php?page=aggr-review' ) );

		exit;
	}
}
