<?php
/**
 * The notices one portal request has to show.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

/**
 * A request-scoped queue of notices, drained once by the layout.
 *
 * **Screens say what happened; one place decides how it looks.** Before this,
 * every screen built its own alert markup and picked its own colours, which is
 * how the portal ended up with success in three shapes and a severity that
 * sometimes meant nothing. A screen now names a level and a sentence.
 *
 * Static because the queue belongs to the request rather than to any object:
 * a screen renders long before the layout's footer reaches it, and threading
 * an instance from one to the other would mean every partial in between
 * carrying a parameter it does not use.
 */
final class Portal_Notice {

	/**
	 * Severity levels a notice may carry.
	 *
	 * @var array<int, string>
	 */
	public const LEVELS = array( 'success', 'error', 'warning', 'info' );

	/**
	 * Notices queued for this request.
	 *
	 * @var array<int, array{text: string, level: string, href: string}>
	 */
	private static array $queue = array();

	/**
	 * Queues one notice.
	 *
	 * An unknown level becomes `info` rather than being refused: a screen
	 * reporting something real must not lose the message because it named the
	 * severity wrongly.
	 *
	 * @param string $text  The sentence to show.
	 * @param string $level success | error | warning | info.
	 * @param string $href  Optional element id to link to, without the '#'.
	 * @return void
	 */
	public static function add( string $text, string $level = 'info', string $href = '' ): void {
		$text = trim( $text );

		if ( '' === $text ) {
			return;
		}

		self::$queue[] = array(
			'text'  => $text,
			'level' => in_array( $level, self::LEVELS, true ) ? $level : 'info',
			'href'  => $href,
		);
	}

	/**
	 * Everything queued, and empties the queue.
	 *
	 * Draining is what stops a layout that renders the region twice — a
	 * screen and its own footer, say — showing every notice twice.
	 *
	 * @return array<int, array{text: string, level: string, href: string}>
	 */
	public static function pending(): array {
		$queued = self::$queue;

		self::$queue = array();

		return $queued;
	}

	/**
	 * Forgets everything queued.
	 *
	 * For tests, which share one process across many requests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$queue = array();
	}
}
