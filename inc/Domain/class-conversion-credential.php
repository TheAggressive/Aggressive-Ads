<?php
/**
 * What a server-to-server reporting credential may be.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Pure rules for issuing and presenting a credential.
 *
 * The interesting decisions here are about what a *caller* may hand back, not
 * about what staff may configure. A bearer token arrives on a public endpoint
 * from an unauthenticated stranger until the moment it verifies, so its shape
 * is checked before anything is looked up: a value that cannot be a credential
 * must cost a string comparison rather than an indexed read.
 */
final class Conversion_Credential {

	/**
	 * The scheme the Authorization header must use.
	 *
	 * Spelled out rather than accepting any scheme. Accepting `Basic` too would
	 * mean base64 that looks like a password to every proxy and log scrubber in
	 * between, and accepting no scheme at all would make a bare secret
	 * indistinguishable from a malformed header.
	 */
	public const SCHEME = 'Bearer';

	/**
	 * Bytes of entropy behind one credential.
	 *
	 * Thirty-two, matching the organization invitation token this is modelled
	 * on. The digest is a unique index, so a collision would be a refused
	 * issue rather than a security failure — but 256 bits also puts brute force
	 * against a rate-limited endpoint out of reach, which is the property that
	 * actually matters for a bearer secret.
	 */
	public const TOKEN_BYTES = 32;

	/**
	 * The plaintext is URL-safe base64 of TOKEN_BYTES, unpadded.
	 *
	 * Forty-three characters. Checked exactly rather than as a range: this is
	 * the only shape this plugin ever issues, so anything else is not a
	 * credential of ours and does not need a database round trip to find out.
	 */
	public const TOKEN_LENGTH = 43;

	/**
	 * `label` is `varchar(191)`.
	 *
	 * The same ceiling `name` carries on a definition, and for the same reason:
	 * MySQL outside strict mode truncates rather than refusing, and a label
	 * silently losing its tail is how two credentials come to look identical in
	 * the one list that is supposed to tell them apart.
	 */
	public const MAX_LABEL_LENGTH = 191;

	/**
	 * A label has to say something, or the list it appears in says nothing.
	 */
	public const MIN_LABEL_LENGTH = 1;

	/**
	 * Whether a presented secret could be one of ours at all.
	 *
	 * Deliberately not a database question. This runs before any lookup, so a
	 * stranger posting arbitrary bytes at the endpoint is answered by a regular
	 * expression rather than by a query.
	 *
	 * @param string $token Presented plaintext.
	 */
	public static function is_valid_token( string $token ): bool {
		if ( strlen( $token ) !== self::TOKEN_LENGTH ) {
			return false;
		}

		/*
		 * `\z`, not `$`, for the reason recorded on
		 * `Conversion_Rules::is_valid_idempotency_key()`: PCRE's `$` matches
		 * before a trailing newline, so `$` here would accept a token with one
		 * appended — which then digests to something else entirely and fails to
		 * verify, turning a working credential into an unexplained 401.
		 */
		return 1 === preg_match( '/^[A-Za-z0-9\-_]+\z/', $token );
	}

	/**
	 * Pulls the secret out of an Authorization header.
	 *
	 * Returns an empty string for anything that is not exactly one `Bearer`
	 * credential of ours, so a caller cannot tell a malformed header from an
	 * unknown credential — the header is parsed and the value shape-checked
	 * before either becomes a query.
	 *
	 * @param string $header Raw Authorization header value.
	 */
	public static function token_from_header( string $header ): string {
		$parts = explode( ' ', trim( $header ), 3 );

		if ( 2 !== count( $parts ) ) {
			return '';
		}

		// Case-insensitive, because RFC 7235 says the scheme is. A client
		// sending `bearer` is correct and must not be refused for it.
		if ( 0 !== strcasecmp( $parts[0], self::SCHEME ) ) {
			return '';
		}

		return self::is_valid_token( $parts[1] ) ? $parts[1] : '';
	}

	/**
	 * Whether a label is usable as one.
	 *
	 * @param string $label Staff-supplied label.
	 */
	public static function is_valid_label( string $label ): bool {
		$length = strlen( $label );

		if ( $length < self::MIN_LABEL_LENGTH || $length > self::MAX_LABEL_LENGTH ) {
			return false;
		}

		// Control characters checked on the raw string, before any trim.
		// `trim()` strips `\0`, so trimming first would swallow a null byte and
		// report the result clean — the defect this class's sibling
		// `Conversion_Definition` was fixed for.
		return 1 !== preg_match( '/[\x00-\x1F\x7F]/', $label );
	}

	/**
	 * Whether a stored credential may still authenticate a report.
	 *
	 * @param int $revoked_at_ts Revocation time, or 0 while live.
	 */
	public static function is_live( int $revoked_at_ts ): bool {
		return 0 === $revoked_at_ts;
	}
}
