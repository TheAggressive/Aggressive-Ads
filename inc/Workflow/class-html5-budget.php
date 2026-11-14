<?php
/**
 * Shared work budget across archive inspection, compilation and staging.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

/** A valid input must also finish within the documented ingest budget. */
final class Html5_Budget {

	/**
	 * Monotonic start of the whole ingest.
	 *
	 * @var int
	 */
	private readonly int $started;
	/**
	 * Allocated memory before parsing or compilation.
	 *
	 * @var int
	 */
	private readonly int $memory;

	/** Start before the first archive read. */
	public function __construct() {
		$this->started = hrtime( true );
		$this->memory  = memory_get_usage( true );
		// Measure this ingest's peak rather than an earlier operation in the request.
		memory_reset_peak_usage();
	}

	/**
	 * Refuse work rather than allowing bounded stages to multiply their budgets.
	 *
	 * @throws \RuntimeException Unsupported input or exceeded work budget.
	 */
	public function check(): void {
		if ( hrtime( true ) - $this->started > 5_000_000_000 || memory_get_peak_usage( true ) - $this->memory > 67108864 ) {
			throw new \RuntimeException( 'aggr_html5_budget' );
		}
	}
}
