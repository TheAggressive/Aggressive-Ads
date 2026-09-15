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
	 * @return array{modules: array<string, bool>, brand: array<string, string>, delivery: array{fill_ttl: int, house_policy: string}, tracking: array{retention_days: int}, creative: array{retention_days: int}, audit: array{retention_days: int}}
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
	 * Whether advertisers may propose changing one field on a running campaign.
	 *
	 * @param string $field One of Settings_Schema::edit_keys().
	 */
	public function live_edit_enabled( string $field ): bool {
		$document = $this->get();

		return ! empty( $document['live_edits'][ $field ] );
	}

	/**
	 * The fields advertisers may propose changing, in display order.
	 *
	 * An empty list is the whole feature switched off, and every surface treats
	 * it that way: no button, no form, and a refusal in the workflow. That is
	 * the shipped default.
	 *
	 * @return list<string>
	 */
	public function live_edit_fields(): array {
		$allowed = array();

		foreach ( Settings_Schema::edit_keys() as $field ) {
			if ( $this->live_edit_enabled( $field ) ) {
				$allowed[] = $field;
			}
		}

		return $allowed;
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
	 * Days a terminal campaign keeps private creative that was never approved.
	 *
	 * Only unapproved artwork is governed by this. An approved creative loses
	 * its private original the moment it is promoted to a Media Library
	 * attachment, because the attachment is then the copy everything reads.
	 * See Workflow\Creative_Retention.
	 */
	public function creative_retention_days(): int {
		return $this->get()['creative']['retention_days'];
	}

	/**
	 * Days the audit log is kept, or zero for never delete.
	 *
	 * Zero is the default and the shipped behaviour: an audit log is often
	 * required to be complete, so the plugin does not pick a compliance answer
	 * on a publisher's behalf.
	 */
	public function audit_retention_days(): int {
		return $this->get()['audit']['retention_days'];
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

		$errors = isset( $validated['errors'] ) && is_array( $validated['errors'] ) ? $validated['errors'] : array();

		return new WP_Error(
			'aggr_settings_invalid',
			self::refusal( $errors ),
			array(
				'status' => 400,
				'errors' => $errors,
			)
		);
	}

	/**
	 * The sentence a refused save shows, naming what to change.
	 *
	 * The settings screen shows the message a refused save returns, and it used
	 * to be "Those settings cannot be saved." for every cause — so a contrast
	 * refusal read as the save being broken, which is the one thing it was not.
	 *
	 * @param array<int, mixed> $codes Settings_Schema problem codes.
	 * @return string
	 */
	private static function refusal( array $codes ): string {
		$sentences = array();

		foreach ( $codes as $code ) {
			$sentence = match ( (string) $code ) {
				'contrast_button'       => __( 'The accent is not readable behind button text in either dark or white. Choose a darker or lighter accent.', 'aggressive-ads' ),
				'contrast_link'         => __( 'The accent for links is too light to read on the surface colour. Choose a darker one.', 'aggressive-ads' ),
				'contrast_text_canvas'  => __( 'The text colour is too close to the canvas colour to read.', 'aggressive-ads' ),
				'contrast_text_surface' => __( 'The text colour is too close to the surface colour to read.', 'aggressive-ads' ),
				'product_name'          => __( 'Enter a product name.', 'aggressive-ads' ),
				'product_name_length'   => sprintf(
					/* translators: %d: maximum characters. */
					__( 'Use %d characters or fewer for the product name.', 'aggressive-ads' ),
					Settings_Schema::MAX_PRODUCT_NAME
				),
				'tagline_length'        => sprintf(
					/* translators: %d: maximum characters. */
					__( 'Use %d characters or fewer for the tagline.', 'aggressive-ads' ),
					Settings_Schema::MAX_TAGLINE
				),
				'logo_url'              => __( 'The logo URL must be a complete http or https address.', 'aggressive-ads' ),
				'support_email'         => __( 'The support email is not a valid address.', 'aggressive-ads' ),
				'accent', 'accent_strong', 'canvas', 'surface', 'text' => __( 'Colours must be six-digit hex values, like #F05A28.', 'aggressive-ads' ),
				default                 => '',
			};

			if ( '' !== $sentence && ! in_array( $sentence, $sentences, true ) ) {
				$sentences[] = $sentence;
			}
		}

		return array() === $sentences ? __( 'Those settings cannot be saved.', 'aggressive-ads' ) : implode( ' ', $sentences );
	}
}
