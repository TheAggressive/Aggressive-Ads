<?php
/**
 * Global helper for theme template parts.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Domain\Slot_Options;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Workflow\Placement_Slot;

if ( ! function_exists( 'aggr_placement' ) ) {
	/**
	 * Prints one native placement slot.
	 *
	 * The options array takes the same names as the shortcode —
	 * `rotate`, `rotate_seconds`, `collapse_when_empty` — because a publisher
	 * moving a slot out of post content and into a template should not have to
	 * learn a second vocabulary for the same three settings.
	 *
	 * `collapse_when_empty` matters more here than anywhere else: a template
	 * region is fixed layout, and a header slot that removes itself when unsold
	 * moves the whole page up.
	 *
	 * @param string               $slug    Placement post_name.
	 * @param array<string, mixed> $options Per-slot settings; the defaults when absent.
	 * @return void
	 */
	function aggr_placement( string $slug, array $options = array() ): void {
		$plugin = Plugin::instance();
		$slot   = $plugin->container()->get( Placement_Slot::class );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Placement_Slot::markup() escapes.
		echo $slot->markup( $slug, false, Slot_Options::from_atts( $options ) );
	}
}
