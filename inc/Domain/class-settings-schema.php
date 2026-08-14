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

	public const MAX_PRODUCT_NAME = 60;
	public const MAX_TAGLINE      = 80;
	public const MAX_LOGO_URL     = 500;

	public const ACCENT_CONTRAST = '#ffffff';

	public const HOUSE_WHEN_EMPTY = 'when_empty';
	public const HOUSE_NEVER      = 'never';

	public const MIN_FILL_TTL       = 5;
	public const MAX_FILL_TTL       = 300;
	public const MIN_RETENTION_DAYS = 30;
	public const MAX_RETENTION_DAYS = 730;

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
	 * First-read document. Public signup stays on so existing WP registration
	 * policy is unchanged until staff turn the module off.
	 *
	 * @return array{modules: array<string, bool>, brand: array<string, string>, delivery: array{fill_ttl: int, house_policy: string}, tracking: array{retention_days: int}}
	 */
	public static function defaults(): array {
		return array(
			'modules'  => array(
				self::MODULE_PUBLIC_SIGNUP   => true,
				self::MODULE_BILLING         => false,
				self::MODULE_NATIVE_DELIVERY => true,
				self::MODULE_REPORTING       => false,
			),
			'brand'    => array(
				'product_name'  => 'Advertising',
				'tagline'       => '',
				'logo_url'      => '',
				'accent'        => '#ff3b2f',
				'accent_strong' => '#e90d00',
				'canvas'        => '#f7f4ee',
				'surface'       => '#ffffff',
				'text'          => '#111214',
			),
			'delivery' => array(
				'fill_ttl'     => 30,
				'house_policy' => self::HOUSE_WHEN_EMPTY,
			),
			'tracking' => array(
				'retention_days' => 90,
			),
		);
	}

	/**
	 * Merge stored values onto defaults. Unknown keys are dropped.
	 *
	 * @param mixed $stored Raw option value.
	 * @return array{modules: array<string, bool>, brand: array<string, string>, delivery: array{fill_ttl: int, house_policy: string}, tracking: array{retention_days: int}}
	 */
	public static function merge( mixed $stored ): array {
		$defaults = self::defaults();

		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		$modules  = is_array( $stored['modules'] ?? null ) ? $stored['modules'] : array();
		$brand    = is_array( $stored['brand'] ?? null ) ? $stored['brand'] : array();
		$delivery = is_array( $stored['delivery'] ?? null ) ? $stored['delivery'] : array();
		$tracking = is_array( $stored['tracking'] ?? null ) ? $stored['tracking'] : array();

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

		return $defaults;
	}

	/**
	 * Validate and normalise a submitted document.
	 *
	 * @param array<string, mixed> $input Raw modules/brand/delivery/tracking fields.
	 * @return array{ok: true, value: array{modules: array<string, bool>, brand: array<string, string>, delivery: array{fill_ttl: int, house_policy: string}, tracking: array{retention_days: int}}}|array{ok: false, errors: list<string>}
	 */
	public static function validate( array $input ): array {
		$defaults = self::defaults();
		$modules  = is_array( $input['modules'] ?? null ) ? $input['modules'] : array();
		$brand    = is_array( $input['brand'] ?? null ) ? $input['brand'] : array();
		$delivery = is_array( $input['delivery'] ?? null ) ? $input['delivery'] : array();
		$tracking = is_array( $input['tracking'] ?? null ) ? $input['tracking'] : array();
		$errors   = array();

		$out_modules = array();

		foreach ( self::module_keys() as $key ) {
			$out_modules[ $key ] = ! empty( $modules[ $key ] );
		}

		// HTML checkboxes do not POST when off. Forcing true here is what stops
		// "remove the Modules row" from turning delivery off on the next save.
		$out_modules[ self::MODULE_NATIVE_DELIVERY ] = true;

		$product_name = self::plain_text( $brand['product_name'] ?? $defaults['brand']['product_name'] );
		$tagline      = self::plain_text( $brand['tagline'] ?? '' );
		$logo_url     = trim( is_string( $brand['logo_url'] ?? null ) ? $brand['logo_url'] : '' );

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

		if ( array() !== $errors ) {
			return array(
				'ok'     => false,
				'errors' => array_values( array_unique( $errors ) ),
			);
		}

		return array(
			'ok'    => true,
			'value' => array(
				'modules'  => $out_modules,
				'brand'    => array(
					'product_name'  => $product_name,
					'tagline'       => $tagline,
					'logo_url'      => $logo_url,
					'accent'        => $colours['accent'],
					'accent_strong' => $colours['accent_strong'],
					'canvas'        => $colours['canvas'],
					'surface'       => $colours['surface'],
					'text'          => $colours['text'],
				),
				'delivery' => array(
					'fill_ttl'     => $fill_ttl,
					'house_policy' => $house_policy,
				),
				'tracking' => array(
					'retention_days' => $retention,
				),
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
