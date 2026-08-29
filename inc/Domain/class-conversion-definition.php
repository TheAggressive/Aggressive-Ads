<?php
/**
 * What a publisher declares a conversion to be.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Pure validation for one conversion definition.
 *
 * A definition is trusted server configuration, and that is the whole reason it
 * exists as a record rather than as parameters on a request. Value, currency and
 * attribution window all come from here, so an anonymous browser reporting an
 * outcome cannot state what the outcome was worth or how long the click that
 * earned it stays creditable.
 */
final class Conversion_Definition {

	/** Accepting reports. */
	public const STATUS_ACTIVE = 'active';

	/**
	 * Retired. Reports are refused, and its history stays readable.
	 *
	 * Archived rather than deleted, because the ledger points at definitions by
	 * id: deleting one would leave every conversion it ever recorded pointing at
	 * nothing, and reporting would silently lose them.
	 */
	public const STATUS_ARCHIVED = 'archived';

	/**
	 * `name` is `varchar(191)`, the ordinary utf8mb4 index-safe width.
	 */
	public const MAX_NAME_LENGTH = 191;

	/**
	 * The public identifier a page sends, in hex.
	 *
	 * Random rather than the row id, because the reporting endpoint is public
	 * and a sequential id is an invitation to walk the table — reporting
	 * conversions against every definition on the site to see which ones exist.
	 * 128 bits is not guessable, and it means a refusal for an unknown key and a
	 * refusal for another tenant's key can be identical without either being a
	 * lie.
	 */
	public const PUBLIC_KEY_LENGTH = 32;

	/**
	 * Every valid status.
	 *
	 * @return list<string>
	 */
	public static function statuses(): array {
		return array( self::STATUS_ACTIVE, self::STATUS_ARCHIVED );
	}

	/**
	 * Whether a status is one this plugin stores.
	 *
	 * @param string $status Candidate status.
	 */
	public static function is_valid_status( string $status ): bool {
		return in_array( $status, self::statuses(), true );
	}

	/**
	 * Whether a public key has the shape this plugin mints.
	 *
	 * `\z` rather than `$`, for the reason recorded on
	 * `Conversion_Rules::is_valid_idempotency_key()`: `$` matches before a
	 * trailing newline, and this value indexes a `char(32)` column.
	 *
	 * @param string $key Candidate key.
	 */
	public static function is_valid_public_key( string $key ): bool {
		return 1 === preg_match( '/^[a-f0-9]{32}\z/', $key );
	}

	/**
	 * Whether a name is storable and meaningful.
	 *
	 * Control characters are refused rather than stripped. A name is shown to
	 * staff and travels into an audit context; silently rewriting what somebody
	 * typed is how a name in the log stops matching the name on the screen.
	 *
	 * **The control-character check runs before the trim, and that ordering is
	 * the whole rule.** PHP's `trim()` strips `\0`, `\t`, `\n`, `\r` and
	 * `\x0B` by default, so trimming first quietly deletes a null byte and
	 * hands back a name that looks clean — which is exactly the silent rewrite
	 * this refuses to do, and a null byte is the one control character most
	 * worth refusing loudly. This test caught the first draft doing it.
	 *
	 * @param string $name Candidate name, exactly as submitted.
	 */
	public static function is_valid_name( string $name ): bool {
		if ( 1 === preg_match( '/[\x00-\x1f\x7f]/', $name ) ) {
			return false;
		}

		$trimmed = trim( $name );

		return '' !== $trimmed && strlen( $trimmed ) <= self::MAX_NAME_LENGTH;
	}

	/**
	 * Normalizes a submitted definition, or names what is wrong with it.
	 *
	 * Returns the same shape as the settings validator: either the value to
	 * store or the list of problems, never a partially-applied record. A
	 * definition that half-saved would accept reports under a window nobody
	 * chose.
	 *
	 * @param array<string, mixed> $input Raw, already-allowlisted fields.
	 * @return array{ok: true, value: array{name: string, org_id: int, window_seconds: int, default_value_micros: int, currency: string, allow_s2s: bool, status: string}}|array{ok: false, errors: list<string>}
	 */
	public static function validate( array $input ): array {
		$errors = array();

		$raw_name = is_string( $input['name'] ?? null ) ? $input['name'] : '';

		// Checked raw and stored trimmed. See is_valid_name(): trimming first
		// would delete a null byte instead of refusing it.
		if ( ! self::is_valid_name( $raw_name ) ) {
			$errors[] = 'name';
		}

		$name = trim( $raw_name );

		$org_id = is_numeric( $input['org_id'] ?? null ) ? (int) $input['org_id'] : 0;

		if ( $org_id < 0 ) {
			$errors[] = 'org_id';
		}

		/*
		 * The window is clamped rather than refused, matching every other
		 * stored setting: a value outside the range must still produce a
		 * working window rather than a definition that attributes nothing.
		 * A missing window is the thirty-day default, not zero.
		 */
		$window = Conversion_Rules::window_seconds( $input['window_seconds'] ?? null );

		$value = is_numeric( $input['default_value_micros'] ?? null ) ? (int) $input['default_value_micros'] : 0;

		if ( ! Conversion_Rules::is_valid_value_micros( $value ) ) {
			$errors[] = 'default_value_micros';
		}

		// Empty is how a valueless definition — a signup — is stored, and it
		// must stay distinguishable from a currency somebody typed wrong.
		$currency = is_string( $input['currency'] ?? null ) ? strtoupper( trim( $input['currency'] ) ) : '';

		if ( '' !== $currency && ! Conversion_Rules::is_valid_currency( $currency ) ) {
			$errors[] = 'currency';
		}

		// A value with no currency is a number nobody can add up, and a
		// currency with no value is harmless. Only the first is refused.
		if ( $value > 0 && '' === $currency ) {
			$errors[] = 'currency';
		}

		$status = is_string( $input['status'] ?? null ) && '' !== $input['status']
			? $input['status']
			: self::STATUS_ACTIVE;

		if ( ! self::is_valid_status( $status ) ) {
			$errors[] = 'status';
		}

		if ( array() !== $errors ) {
			return array(
				'ok'     => false,
				'errors' => array_values( array_unique( $errors ) ),
			);
		}

		return array(
			'ok'    => true,
			'value' => array(
				'name'                 => $name,
				'org_id'               => $org_id,
				'window_seconds'       => $window,
				'default_value_micros' => $value,
				'currency'             => $currency,
				'allow_s2s'            => ! empty( $input['allow_s2s'] ),
				'status'               => $status,
			),
		);
	}

	/**
	 * Whether a definition in this state may accept a report.
	 *
	 * Separate from the status constant so the reason lives in one place: an
	 * archived definition is refused at ingestion, not filtered out of a query
	 * and left to look like a definition that never existed.
	 *
	 * @param string $status Stored status.
	 */
	public static function accepts_reports( string $status ): bool {
		return self::STATUS_ACTIVE === $status;
	}
}
