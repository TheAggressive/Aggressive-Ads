<?php
/**
 * The headers a CSV download carries.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Core;

use Aggressive\Ads\Core\Csv_Download;
use PHPUnit\Framework\TestCase;

/**
 * **These are security headers, and nothing could read them.**
 *
 * `send()` ends in `exit`, so everything it did was out of reach of a test.
 * Mutation testing deleted `X-Content-Type-Options: nosniff` from a CSV whose
 * first cell is written by somebody else, and the whole suite stayed green.
 * Splitting the header map out of the sending is what makes this assertable at
 * all; the assertion is the point of the split.
 */
final class CsvDownloadTest extends TestCase {

	/**
	 * The full header set, pinned.
	 *
	 * Pinned rather than spot-checked: a header quietly dropped is exactly the
	 * change this exists to catch, and a test that only looked for the ones it
	 * remembered would not catch it.
	 *
	 * @return void
	 */
	public function test_the_header_set_is_pinned(): void {
		$this->assertSame(
			array(
				'Content-Type'           => 'text/csv; charset=utf-8',
				'Content-Disposition'    => 'attachment; filename="report.csv"',
				'Content-Length'         => '11',
				'X-Content-Type-Options' => 'nosniff',
			),
			Csv_Download::headers( 'report.csv', 11 )
		);
	}

	/**
	 * A browser is told not to sniff this back into something it will render.
	 *
	 * Called out separately from the pinned set above, because the pinned set
	 * can be updated wholesale by somebody adding a header, and this one is the
	 * reason any of it matters.
	 *
	 * @return void
	 */
	public function test_sniffing_is_refused(): void {
		$headers = Csv_Download::headers( 'x.csv', 0 );

		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] ?? '' );
	}

	/**
	 * The length is the document's, not the name's.
	 *
	 * @return void
	 */
	public function test_the_length_is_reported_exactly(): void {
		$this->assertSame( '0', Csv_Download::headers( 'x.csv', 0 )['Content-Length'] );
		$this->assertSame( '4096', Csv_Download::headers( 'x.csv', 4096 )['Content-Length'] );
	}
}
