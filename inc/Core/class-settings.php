<?php
/**
 * The only reader and writer of `aggr_settings`.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Core;

use Aggressive\Ads\Domain\Settings_Schema;
use WP_Error;

/**
 * Lazy option access. Constructing this class reads nothing.
 */
final class Settings {

	public const OPTION = 'aggr_settings';

	/**
	 * Merged settings document.
	 *
	 * @return array{modules: array<string, bool>, brand: array<string, string>, delivery: array{fill_ttl: int, house_policy: string}, tracking: array{retention_days: int}}
	 */
	public function get(): array {
		return Settings_Schema::merge( get_option( self::OPTION, null ) );
	}

	/**
	 * Whether a module is on.
	 *
	 * @param string $module Schema module key.
	 */
	public function module_enabled( string $module ): bool {
		$document = $this->get();

		return ! empty( $document['modules'][ $module ] );
	}

	/**
	 * Advertiser-facing product name.
	 */
	public function product_name(): string {
		return $this->get()['brand']['product_name'];
	}

	/**
	 * Optional tagline. Empty means omit the subtitle.
	 */
	public function tagline(): string {
		return $this->get()['brand']['tagline'];
	}

	/**
	 * Optional logo URL. Empty means render the product name as the mark.
	 */
	public function logo_url(): string {
		return $this->get()['brand']['logo_url'];
	}

	/**
	 * Fill object-cache TTL in seconds.
	 */
	public function fill_ttl(): int {
		return $this->get()['delivery']['fill_ttl'];
	}

	/**
	 * When to serve house creative.
	 */
	public function house_policy(): string {
		return $this->get()['delivery']['house_policy'];
	}

	/**
	 * Event retention in days.
	 */
	public function retention_days(): int {
		return $this->get()['tracking']['retention_days'];
	}

	/**
	 * Whether staff have saved a document (so inline tokens should print).
	 */
	public function has_store(): bool {
		$stored = get_option( self::OPTION, null );

		return is_array( $stored ) && array() !== $stored;
	}

	/**
	 * Replace the document. Rejects the whole payload on any schema error.
	 *
	 * @param array<string, mixed> $input Raw modules/brand/delivery/tracking fields.
	 * @return true|WP_Error
	 */
	public function save( array $input ): true|WP_Error {
		$validated = Settings_Schema::validate( $input );

		if ( isset( $validated['value'] ) ) {
			update_option( self::OPTION, $validated['value'], true );

			return true;
		}

		return new WP_Error(
			'aggr_settings_invalid',
			__( 'Those settings cannot be saved.', 'aggressive-ads' ),
			array(
				'status' => 400,
				'errors' => isset( $validated['errors'] ) && is_array( $validated['errors'] ) ? $validated['errors'] : array(),
			)
		);
	}
}
