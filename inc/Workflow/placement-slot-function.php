<?php
/**
 * Global helper for theme template parts.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Workflow\Placement_Slot;

if ( ! function_exists( 'aggr_placement' ) ) {
	/**
	 * Prints one native placement slot.
	 *
	 * @param string $slug Placement post_name.
	 * @return void
	 */
	function aggr_placement( string $slug ): void {
		$plugin = Plugin::instance();
		$slot   = $plugin->container()->get( Placement_Slot::class );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Placement_Slot::markup() escapes.
		echo $slot->markup( $slug );
	}
}
