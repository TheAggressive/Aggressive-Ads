<?php
/**
 * The signal a trapped redirect raises.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests;

use Exception;

/**
 * Raised in place of the exit() that follows a redirect.
 *
 * See Redirect_Trap for why this exists rather than a boolean return.
 */
final class Redirect_Signal extends Exception {

	/**
	 * Where the code under test tried to send the visitor.
	 *
	 * @var string
	 */
	public string $location = '';
}
