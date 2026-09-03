<?php
/**
 * Sending a CSV to a browser, in one place.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Core;

/**
 * The response half of every export.
 *
 * **One copy because these are security headers, not formatting.** Two exports
 * grew identical `send()` methods, and identical is the state before drift: the
 * next person to harden one of them hardens one of them. `X-Content-Type-Options`
 * in particular is what stops a browser sniffing a CSV back into something it
 * will render, and a CSV whose first cell is written by somebody else is not a
 * document any browser should be guessing about.
 *
 * Not in `Domain\` — `nocache_headers()` is a WordPress function and that
 * boundary does not bend for convenience. `Domain\Csv_Writer` still owns the
 * bytes; this owns handing them over.
 */
final class Csv_Download {

	/**
	 * The headers a CSV download carries, as a map.
	 *
	 * **Separated from sending them so they can be asserted.** `send()` ends in
	 * `exit`, which puts everything it does out of reach of a test — and
	 * mutation testing found exactly that: deleting the `nosniff` header broke
	 * nothing, because nothing could see it. A security header no test can read
	 * is a security header that leaves quietly.
	 *
	 * @param string $filename Download name, already sanitized by the caller.
	 * @param int    $length   Byte length of the document.
	 * @return array<string, string>
	 */
	public static function headers( string $filename, int $length ): array {
		return array(
			'Content-Type'           => 'text/csv; charset=utf-8',
			'Content-Disposition'    => 'attachment; filename="' . $filename . '"',
			'Content-Length'         => (string) $length,

			/*
			 * Stops a browser sniffing this back into something it will render.
			 * A CSV whose first cell is written by somebody else is not a
			 * document any browser should be guessing about.
			 */
			'X-Content-Type-Options' => 'nosniff',
		);
	}

	/**
	 * Sends a document as a download and stops.
	 *
	 * Ends in `exit` because a download that continues into WordPress's
	 * shutdown output is a corrupt file. Callers assert on the bytes through
	 * their own `document()` seam, and on the headers through `headers()`.
	 *
	 * @param string $body     CSV bytes, already written by `Csv_Writer`.
	 * @param string $filename Download name, already sanitized by the caller.
	 * @return void
	 */
	public static function send( string $body, string $filename ): void {
		nocache_headers();

		foreach ( self::headers( $filename, strlen( $body ) ) as $name => $value ) {
			header( $name . ': ' . $value );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV bytes, not markup. Csv_Writer quotes every field and neutralizes spreadsheet formulas; HTML escaping here would corrupt the file.
		echo $body;

		exit;
	}
}
