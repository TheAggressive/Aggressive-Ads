<?php
/**
 * Container failures.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal;

use RuntimeException;

/**
 * Thrown when the service container is asked for something it cannot honour.
 *
 * Every case this covers is a programming error discovered at boot — an
 * unregistered service, a duplicate registration, a factory returning the
 * wrong type, a dependency cycle. None of them is a runtime condition a user
 * can cause, so an exception is correct here where WP_Error would be correct
 * for a denied transition. See docs/adr/0008-explicit-transition-table.md.
 */
final class Container_Exception extends RuntimeException {
}
