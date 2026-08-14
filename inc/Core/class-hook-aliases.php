<?php
/**
 * Dual-fires renamed hooks for one release.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Core;

use Aggressive\Ads\Domain\Identity_Maps;

/**
 * Applies the current filter/action and its previous name.
 *
 * Callers use the new name. Sites that hooked the old name keep working
 * until the alias is removed.
 */
final class Hook_Aliases {

	/**
	 * Filters a value on the current hook, then on its previous name.
	 *
	 * @param non-empty-string $hook  Current hook name.
	 * @param mixed            $value Filtered value.
	 * @param mixed            ...$args Additional arguments.
	 * @return mixed
	 */
	public static function apply( string $hook, mixed $value, mixed ...$args ): mixed {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Names come from Identity_Maps and are plugin-owned.
		$value = apply_filters( $hook, $value, ...$args );

		$legacy = array_search( $hook, Identity_Maps::filter_aliases(), true );

		if ( is_string( $legacy ) && '' !== $legacy ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- One-release alias of a plugin-owned hook.
			$value = apply_filters( $legacy, $value, ...$args );
		}

		return $value;
	}

	/**
	 * Fires the current action, then its previous name.
	 *
	 * @param non-empty-string $hook Current hook name.
	 * @param mixed            ...$args Arguments.
	 * @return void
	 */
	public static function fire( string $hook, mixed ...$args ): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Names come from Identity_Maps and are plugin-owned.
		do_action( $hook, ...$args );

		$legacy = array_search( $hook, Identity_Maps::action_aliases(), true );

		if ( is_string( $legacy ) && '' !== $legacy ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- One-release alias of a plugin-owned hook.
			do_action( $legacy, ...$args );
		}
	}
}
