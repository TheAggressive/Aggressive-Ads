<?php
/**
 * Optional runtime capabilities for static HTML5, independent of image delivery.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Support;

/** PHP's HTML5 parser is required; do not substitute the older HTML4 DOM parser. */
final class Html5_Runtime {

	/** The ingestion and review profile must use the same parser/compression capabilities. */
	public static function available(): bool {
		return class_exists( \ZipArchive::class ) && class_exists( \Dom\HTMLDocument::class );
	}
}
