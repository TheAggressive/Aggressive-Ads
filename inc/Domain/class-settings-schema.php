<?php
/**
 * The settings document: defaults, merge, and validation.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Pure schema for `aggr_settings`. Core\Settings is the only reader/writer.
 */
final class Settings_Schema {

	public const MODULE_BILLING         = 'billing';
	public const MODULE_NATIVE_DELIVERY = 'native_delivery';
	public const MODULE_PUBLIC_SIGNUP   = 'public_signup';
	public const MODULE_REPORTING       = 'reporting';

	/**
	 * Fields an advertiser may propose changing on a campaign that is already
	 * scheduled, live or paused. Every one still goes to staff for approval.
	 *
	 * Per-field rather than one switch because "can advertisers edit a running
	 * campaign" is not one question. Letting somebody extend an end date is a
	 * different risk from letting them repoint the destination URL, and a site
	 * that wants the first does not necessarily want the second.
	 */
	public const EDIT_TITLE       = 'title';
	public const EDIT_NOTES       = 'notes';
	public const EDIT_SCHEDULE    = 'schedule';
	public const EDIT_DESTINATION = 'destination';
	public const EDIT_PLACEMENTS  = 'placements';

	public const MAX_PRODUCT_NAME  = 60;
	public const MAX_TAGLINE       = 80;
	public const MAX_LOGO_URL      = 500;
	public const MAX_SUPPORT_EMAIL = 254;

	public const ACCENT_CONTRAST = '#ffffff';

	public const HOUSE_WHEN_EMPTY = 'when_empty';
	public const HOUSE_NEVER      = 'never';

	public const MIN_FILL_TTL       = 5;
	public const MAX_FILL_TTL       = 300;
	public const MIN_RETENTION_DAYS = 30;

	/**
	 * Bounds for the private creative window.
	 *
	 * Narrower at the bottom than tracking's, because what this deletes is the
	 * only remaining copy of an advertiser's artwork rather than a row of
	 * telemetry. A week is the shortest span in which somebody can notice a
	 * campaign was cancelled by mistake and duplicate it.
	 */
	/**
	 * Audit retention is a choice, not a number.
	 *
	 * Zero means never delete, and is the default: today nothing prunes the audit
	 * log, so any positive default would have the first cron run after an upgrade
	 * silently delete history somebody may be required to hold. The rest are the
	 * spans a records policy is actually written in. A free-text field would let
	 * somebody type 3 and shred three years of evidence for who approved what.
	 *
	 * @var array<int, int>
	 */
	public const AUDIT_RETENTION_CHOICES = array( 0, 365, 730, 1095, 2555 );

	public const MIN_CREATIVE_RETENTION_DAYS = 7;

	public const MAX_CREATIVE_RETENTION_DAYS = 365;
	public const MAX_RETENTION_DAYS          = 730;

	/**
	 * Module keys in display order.
	 *
	 * @return list<string>
	 */
	public static function module_keys(): array {
		return array(
			self::MODULE_PUBLIC_SIGNUP,
			self::MODULE_BILLING,
			self::MODULE_NATIVE_DELIVERY,
			self::MODULE_REPORTING,
		);
	}

	/**
	 * Editable-field keys in display order.
	 *
	 * `EDIT_PLACEMENTS` is last and deliberately separated in the UI: it is not
	 * a peer of the others. Changing placements changes the required creative
	 * size, so an approved placement change leaves the campaign unable to serve
	 * until a correctly sized creative is uploaded and reviewed. It is a
	 * re-submission, not a field edit.
	 *
	 * @return list<string>
	 */
	public static function edit_keys(): array {
		return array(
			self::EDIT_TITLE,
			self::EDIT_NOTES,
			self::EDIT_SCHEDULE,
			self::EDIT_DESTINATION,
			self::EDIT_PLACEMENTS,
		);
	}

	/**
	 * Editable fields whose approval invalidates the existing creative.
	 *
	 * @return list<string>
	 */
	public static function structural_edit_keys(): array {
		return array( self::EDIT_PLACEMENTS );
	}

	/**
	 * First-read document. Public signup stays on so existing WP registration
	 * policy is unchanged until staff turn the module off.
	 *
	 * Every live-edit field starts off. A running campaign is one staff already
	 * approved, and silently widening what an advertiser may change underneath
	 * an approval is not a default anybody chose — it is one they inherited on
	 * upgrade.
	 *
	 * @return array{modules: array<string, bool>, brand: array<string, string>, delivery: array{fill_ttl: int, house_policy: string}, tracking: array{retention_days: int}, creative: array{retention_days: int}, audit: array{retention_days: int}, live_edits: array<string, bool>}
	 */
	public static function defaults(): array {
		return array(
			'modules'    => array(
				self::MODULE_PUBLIC_SIGNUP   => true,
				self::MODULE_BILLING         => false,
				self::MODULE_NATIVE_DELIVERY => true,
				self::MODULE_REPORTING       => false,
			),
			'brand'      => array(
				'product_name'  => 'Advertising',
				'tagline'       => '',
				'support_email' => '',
				'logo_url'      => '',
				'accent'        => '#ff3b2f',
				'accent_strong' => '#8e1f1f',
				'canvas'        => '#f7f4ee',
				'surface'       => '#ffffff',
				'text'          => '#111214',
			),
			'delivery'   => array(
				'fill_ttl'     => 30,
				'house_policy' => self::HOUSE_WHEN_EMPTY,
			),
			'tracking'   => array(
				'retention_days' => 90,
			),
			'creative'   => array(
				'retention_days' => 30,
			),
			'audit'      => array(
				'retention_days' => 0,
			),
			'live_edits' => array(
				self::EDIT_TITLE       => false,
				self::EDIT_NOTES       => false,
				self::EDIT_SCHEDULE    => false,
				self::EDIT_DESTINATION => false,
				self::EDIT_PLACEMENTS  => false,
			),
		);
	}

	/**
	 * Merge stored values onto defaults. Unknown keys are dropped.
	 *
	 * @param mixed $stored Raw option value.
	 * @return array{modules: array<string, bool>, brand: array<string, string>, delivery: array{fill_ttl: int, house_policy: string}, tracking: array{retention_days: int}, creative: array{retention_days: int}, audit: array{retention_days: int}, live_edits: array<string, bool>}
	 */
	public static function merge( mixed $stored ): array {
		$defaults = self::defaults();

		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		$modules    = is_array( $stored['modules'] ?? null ) ? $stored['modules'] : array();
		$brand      = is_array( $stored['brand'] ?? null ) ? $stored['brand'] : array();
		$delivery   = is_array( $stored['delivery'] ?? null ) ? $stored['delivery'] : array();
		$tracking   = is_array( $stored['tracking'] ?? null ) ? $stored['tracking'] : array();
		$creative   = is_array( $stored['creative'] ?? null ) ? $stored['creative'] : array();
		$audit      = is_array( $stored['audit'] ?? null ) ? $stored['audit'] : array();
		$live_edits = is_array( $stored['live_edits'] ?? null ) ? $stored['live_edits'] : array();

		foreach ( self::edit_keys() as $key ) {
			if ( array_key_exists( $key, $live_edits ) ) {
				$defaults['live_edits'][ $key ] = (bool) $live_edits[ $key ];
			}
		}

		foreach ( self::module_keys() as $key ) {
			if ( array_key_exists( $key, $modules ) ) {
				$defaults['modules'][ $key ] = (bool) $modules[ $key ];
			}
		}

		// There is no fallback publisher. A stored false from the old Modules
		// checkbox must not keep the public site dark.
		$defaults['modules'][ self::MODULE_NATIVE_DELIVERY ] = true;

		foreach ( array_keys( $defaults['brand'] ) as $key ) {
			if ( array_key_exists( $key, $brand ) && is_string( $brand[ $key ] ) ) {
				$defaults['brand'][ $key ] = $brand[ $key ];
			}
		}

		if ( isset( $delivery['fill_ttl'] ) && is_numeric( $delivery['fill_ttl'] ) ) {
			$defaults['delivery']['fill_ttl'] = (int) $delivery['fill_ttl'];
		}

		if ( isset( $delivery['house_policy'] ) && is_string( $delivery['house_policy'] ) ) {
			$defaults['delivery']['house_policy'] = $delivery['house_policy'];
		}

		if ( isset( $tracking['retention_days'] ) && is_numeric( $tracking['retention_days'] ) ) {
			$defaults['tracking']['retention_days'] = (int) $tracking['retention_days'];
		}

		if ( isset( $creative['retention_days'] ) && is_numeric( $creative['retention_days'] ) ) {
			$defaults['creative']['retention_days'] = (int) $creative['retention_days'];
		}

		if ( isset( $audit['retention_days'] ) && is_numeric( $audit['retention_days'] ) ) {
			$defaults['audit']['retention_days'] = (int) $audit['retention_days'];
		}

		return $defaults;
	}

	/**
	 * Validate and normalise a submitted document.
	 *
	 * @param array<string, mixed> $input Raw modules/brand/delivery/tracking/live_edits fields.
	 * @return array{ok: true, value: array{modules: array<string, bool>, brand: array<string, string>, delivery: array{fill_ttl: int, house_policy: string}, tracking: array{retention_days: int}, creative: array{retention_days: int}, audit: array{retention_days: int}, live_edits: array<string, bool>}}|array{ok: false, errors: list<string>}
	 */
	public static function validate( array $input ): array {
		$defaults   = self::defaults();
		$modules    = is_array( $input['modules'] ?? null ) ? $input['modules'] : array();
		$brand      = is_array( $input['brand'] ?? null ) ? $input['brand'] : array();
		$delivery   = is_array( $input['delivery'] ?? null ) ? $input['delivery'] : array();
		$tracking   = is_array( $input['tracking'] ?? null ) ? $input['tracking'] : array();
		$creative   = is_array( $input['creative'] ?? null ) ? $input['creative'] : array();
		$audit      = is_array( $input['audit'] ?? null ) ? $input['audit'] : array();
		$live_edits = is_array( $input['live_edits'] ?? null ) ? $input['live_edits'] : array();
		$errors     = array();

		$out_modules = array();

		foreach ( self::module_keys() as $key ) {
			$out_modules[ $key ] = ! empty( $modules[ $key ] );
		}

		$out_live_edits = array();

		foreach ( self::edit_keys() as $key ) {
			$out_live_edits[ $key ] = ! empty( $live_edits[ $key ] );
		}

		// HTML checkboxes do not POST when off. Forcing true here is what stops
		// "remove the Modules row" from turning delivery off on the next save.
		$out_modules[ self::MODULE_NATIVE_DELIVERY ] = true;

		$product_name = self::plain_text( $brand['product_name'] ?? $defaults['brand']['product_name'] );
		$tagline      = self::plain_text( $brand['tagline'] ?? '' );
		$logo_url     = trim( is_string( $brand['logo_url'] ?? null ) ? $brand['logo_url'] : '' );
		$support      = trim( is_string( $brand['support_email'] ?? null ) ? $brand['support_email'] : '' );

		/*
		 * Optional, and empty is meaningful: it means "fall back to the site's
		 * admin address". Validated only when supplied, so a site that never
		 * sets one is never blocked from saving anything else.
		 */
		if ( '' !== $support && ( strlen( $support ) > self::MAX_SUPPORT_EMAIL || ! self::is_email( $support ) ) ) {
			$errors[] = 'support_email';
		}

		if ( '' === $product_name ) {
			$errors[] = 'product_name';
		} elseif ( strlen( $product_name ) > self::MAX_PRODUCT_NAME ) {
			$errors[] = 'product_name_length';
		}

		if ( strlen( $tagline ) > self::MAX_TAGLINE ) {
			$errors[] = 'tagline_length';
		}

		if ( '' !== $logo_url ) {
			if ( strlen( $logo_url ) > self::MAX_LOGO_URL || ! self::is_http_url( $logo_url ) ) {
				$errors[] = 'logo_url';
			}
		}

		$colours = array();

		foreach ( array( 'accent', 'accent_strong', 'canvas', 'surface', 'text' ) as $key ) {
			$hex = self::hex( is_string( $brand[ $key ] ?? null ) ? $brand[ $key ] : $defaults['brand'][ $key ] );

			if ( null === $hex ) {
				$errors[] = $key;
				continue;
			}

			$colours[ $key ] = $hex;
		}

		if (
			array() !== $errors
			|| ! isset( $colours['accent'], $colours['accent_strong'], $colours['canvas'], $colours['surface'], $colours['text'] )
		) {
			return array(
				'ok'     => false,
				'errors' => array_values( array_unique( $errors ) ),
			);
		}

		if ( ! Contrast::passes( $colours['text'], $colours['canvas'] ) ) {
			$errors[] = 'contrast_text_canvas';
		}

		if ( ! Contrast::passes( $colours['text'], $colours['surface'] ) ) {
			$errors[] = 'contrast_text_surface';
		}

		if ( ! Contrast::passes( self::ACCENT_CONTRAST, $colours['accent_strong'] ) ) {
			$errors[] = 'contrast_button';
		}

		if ( ! Contrast::passes( $colours['accent_strong'], $colours['surface'] ) ) {
			$errors[] = 'contrast_link';
		}

		if ( array() !== $errors ) {
			return array(
				'ok'     => false,
				'errors' => array_values( array_unique( $errors ) ),
			);
		}

		$fill_ttl = isset( $delivery['fill_ttl'] ) && is_numeric( $delivery['fill_ttl'] )
			? (int) $delivery['fill_ttl']
			: $defaults['delivery']['fill_ttl'];

		if ( $fill_ttl < self::MIN_FILL_TTL || $fill_ttl > self::MAX_FILL_TTL ) {
			$errors[] = 'fill_ttl';
		}

		$house_policy = is_string( $delivery['house_policy'] ?? null )
			? $delivery['house_policy']
			: $defaults['delivery']['house_policy'];

		if ( ! in_array( $house_policy, array( self::HOUSE_WHEN_EMPTY, self::HOUSE_NEVER ), true ) ) {
			$errors[] = 'house_policy';
		}

		$retention = isset( $tracking['retention_days'] ) && is_numeric( $tracking['retention_days'] )
			? (int) $tracking['retention_days']
			: $defaults['tracking']['retention_days'];

		if ( $retention < self::MIN_RETENTION_DAYS || $retention > self::MAX_RETENTION_DAYS ) {
			$errors[] = 'retention_days';
		}

		$creative_retention = isset( $creative['retention_days'] ) && is_numeric( $creative['retention_days'] )
			? (int) $creative['retention_days']
			: $defaults['creative']['retention_days'];

		if (
			$creative_retention < self::MIN_CREATIVE_RETENTION_DAYS
			|| $creative_retention > self::MAX_CREATIVE_RETENTION_DAYS
		) {
			$errors[] = 'creative_retention_days';
		}

		$audit_retention = isset( $audit['retention_days'] ) && is_numeric( $audit['retention_days'] )
			? (int) $audit['retention_days']
			: $defaults['audit']['retention_days'];

		// One of the offered spans, not a range. An audit window is a policy
		// decision with a small set of real answers, and a number nobody
		// offered is far more likely to be a mistake than an intention.
		if ( ! in_array( $audit_retention, self::AUDIT_RETENTION_CHOICES, true ) ) {
			$errors[] = 'audit_retention_days';
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
				'modules'    => $out_modules,
				'brand'      => array(
					'product_name'  => $product_name,
					'tagline'       => $tagline,
					'support_email' => $support,
					'logo_url'      => $logo_url,
					'accent'        => $colours['accent'],
					'accent_strong' => $colours['accent_strong'],
					'canvas'        => $colours['canvas'],
					'surface'       => $colours['surface'],
					'text'          => $colours['text'],
				),
				'delivery'   => array(
					'fill_ttl'     => $fill_ttl,
					'house_policy' => $house_policy,
				),
				'tracking'   => array(
					'retention_days' => $retention,
				),
				'creative'   => array(
					'retention_days' => $creative_retention,
				),
				'audit'      => array(
					'retention_days' => $audit_retention,
				),
				'live_edits' => $out_live_edits,
			),
		);
	}

	/**
	 * Collapse whitespace. Brand names are single-line labels.
	 *
	 * @param string $value Raw text.
	 */
	private static function plain_text( string $value ): string {
		$value = trim( $value );
		$value = str_replace( array( "\r", "\n", "\t" ), ' ', $value );

		return trim( preg_replace( '/ {2,}/', ' ', $value ) ?? $value );
	}

	/**
	 * A syntactically plausible email address.
	 *
	 * Deliberately not is_email(): that is a WordPress function, and this layer
	 * must not call one. The check is intentionally loose — an address that
	 * parses can still bounce, and the only real test is sending to it.
	 *
	 * @param string $value Candidate address.
	 */
	private static function is_email( string $value ): bool {
		return 1 === preg_match( '/^[^@\s]+@[^@\s.]+\.[^@\s]+$/', $value );
	}

	/**
	 * Six-digit hex with a leading hash, or null.
	 *
	 * @param string $value Candidate hex.
	 */
	private static function hex( string $value ): ?string {
		$value = strtolower( trim( $value ) );

		if ( 1 !== preg_match( '/^#[0-9a-f]{6}$/', $value ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * An HTTP or HTTPS URL with a host. Domain cannot call wp_parse_url().
	 *
	 * @param string $value Candidate URL.
	 */
	private static function is_http_url( string $value ): bool {
		if ( 1 !== preg_match( '/^https?:\/\//i', $value ) ) {
			return false;
		}

		$parts = parse_url( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() is a WordPress function, and this layer must not call one; see docs/architecture.md.

		return is_array( $parts ) && isset( $parts['host'] ) && is_string( $parts['host'] ) && '' !== $parts['host'];
	}
}
