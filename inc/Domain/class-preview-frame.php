<?php
/**
 * How an unapproved creative is rendered for somebody deciding about it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * The widths a preview offers, and the isolation it renders inside.
 *
 * **A reviewer's browser is not a safer place to run a creative than a
 * visitor's.** P17 states that as an invariant, so the preview frame is at
 * least as isolated as delivery: an empty `sandbox`, which denies scripts,
 * forms, popups, navigation and same-origin access, over a response that
 * already carries `nosniff`, a type from an allowlist and a policy that
 * permits nothing.
 *
 * The numbers live here because two surfaces draw this frame — the
 * advertiser's portal in PHP and the reviewer's screen in React — and a phone
 * that is 390 pixels wide in one and 375 in the other is two different
 * answers to "how will this look".
 */
final class Preview_Frame {

	/**
	 * The `sandbox` attribute a preview frame carries.
	 *
	 * Empty on purpose: every restriction applies. Anything that needs a
	 * capability back has to say which and why, here, where the next reader
	 * meets the reason rather than a longer attribute.
	 */
	public const SANDBOX = '';

	/**
	 * The policy the creative's own response carries.
	 *
	 * `default-src 'none'` with `img-src 'self'` allows the bytes and nothing
	 * else — no script, no style, no fetch, no frame of its own. `sandbox`
	 * repeats the attribute for the case where the response is opened
	 * directly rather than framed, and `frame-ancestors 'self'` keeps it out
	 * of another site's page.
	 */
	public const POLICY = "default-src 'none'; img-src 'self' data:; sandbox; frame-ancestors 'self'; base-uri 'none'; form-action 'none'";

	/**
	 * The widths a creative is previewed at, narrowest first.
	 *
	 * A phone, a tablet and a desktop as the common cases rather than as
	 * exact devices: the question is whether the artwork survives a narrow
	 * column, not which handset somebody holds.
	 *
	 * @return array<string, int> Key to CSS pixels.
	 */
	public static function widths(): array {
		return array(
			'phone'   => 390,
			'tablet'  => 834,
			'desktop' => 1280,
		);
	}

	/**
	 * Whether a width key is one this offers.
	 *
	 * @param string $key Candidate key.
	 * @return bool
	 */
	public static function is_width( string $key ): bool {
		return array_key_exists( $key, self::widths() );
	}
}
