<?php
/**
 * Resource budgets for synchronous advertiser CSV exports.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/** Measurements and fixture methodology are recorded in docs/delivery-performance.md. */
final class Report_Export_Limits {

	public const MAX_ROWS       = 5000;
	public const MAX_NAME_BYTES = 1024;
	public const MAX_CSV_BYTES  = 8 * 1024 * 1024;
}
