<?php
/**
 * Settings document schema.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Domain\Viewability_Rules;
use PHPUnit\Framework\TestCase;

/**
 * Defaults, merge, and the rejections Brand save depends on. No WordPress:
 * a drifted default here is a drifted first-read on every fresh install.
 */
final class SettingsSchemaTest extends TestCase {

	/**
	 * First read matches the compiled token defaults and leaves public signup on.
	 *
	 * @return void
	 */
	public function test_defaults_match_the_shipped_palette(): void {
		$defaults = Settings_Schema::defaults();

		$this->assertTrue( $defaults['modules'][ Settings_Schema::MODULE_PUBLIC_SIGNUP ] );
		$this->assertFalse( $defaults['modules'][ Settings_Schema::MODULE_BILLING ] );
		$this->assertTrue( $defaults['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] );
		$this->assertFalse( $defaults['modules'][ Settings_Schema::MODULE_REPORTING ] );
		$this->assertSame( 'Advertising', $defaults['brand']['product_name'] );
		$this->assertSame( '', $defaults['brand']['tagline'] );
		$this->assertSame( '', $defaults['brand']['logo_url'] );
		$this->assertSame( '#ff3b2f', $defaults['brand']['accent'] );
		$this->assertSame( '#8e1f1f', $defaults['brand']['accent_strong'] );
		$this->assertSame( '#f7f4ee', $defaults['brand']['canvas'] );
		$this->assertSame( '#ffffff', $defaults['brand']['surface'] );
		$this->assertSame( '#111214', $defaults['brand']['text'] );
		$this->assertSame( 30, $defaults['delivery']['fill_ttl'] );
		$this->assertSame( Settings_Schema::HOUSE_WHEN_EMPTY, $defaults['delivery']['house_policy'] );
		$this->assertSame( 90, $defaults['tracking']['retention_days'] );
		$this->assertSame( 30, $defaults['creative']['retention_days'] );
		$this->assertSame( 0, $defaults['audit']['retention_days'], 'Keep forever is the shipped audit window.' );
	}

	/**
	 * The current defaults are a valid document, so a first save of an
	 * untouched form cannot fail contrast.
	 *
	 * @return void
	 */
	public function test_defaults_validate(): void {
		$result = Settings_Schema::validate( Settings_Schema::defaults() );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( Settings_Schema::defaults(), $result['value'] );
	}

	/**
	 * Native delivery cannot be merged or saved off. There is no fallback publisher.
	 *
	 * @return void
	 */
	public function test_native_delivery_cannot_be_turned_off(): void {
		$merged = Settings_Schema::merge(
			array(
				'modules' => array(
					Settings_Schema::MODULE_NATIVE_DELIVERY => false,
				),
			)
		);

		$this->assertTrue( $merged['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] );

		$input = Settings_Schema::defaults();
		$input['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] = false;
		$result = Settings_Schema::validate( $input );

		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['value']['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] );

		unset( $input['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] );
		$absent = Settings_Schema::validate( $input );

		$this->assertTrue( $absent['ok'] );
		$this->assertTrue( $absent['value']['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] );
	}

	/**
	 * Unknown keys are dropped so a future schema cannot be poisoned by junk.
	 *
	 * @return void
	 */
	public function test_merge_drops_unknown_keys_and_coerces_modules(): void {
		$merged = Settings_Schema::merge(
			array(
				'modules' => array(
					Settings_Schema::MODULE_BILLING => 1,
					'nope'                          => true,
				),
				'brand'   => array(
					'product_name' => 'Museum Ads',
					'extra'        => 'x',
				),
				'other'   => 1,
			)
		);

		$this->assertTrue( $merged['modules'][ Settings_Schema::MODULE_BILLING ] );
		$this->assertArrayNotHasKey( 'nope', $merged['modules'] );
		$this->assertSame( 'Museum Ads', $merged['brand']['product_name'] );
		$this->assertArrayNotHasKey( 'extra', $merged['brand'] );
		$this->assertSame( '#ff3b2f', $merged['brand']['accent'] );
	}

	/**
	 * Garbage storage falls back to defaults rather than fatalling a request.
	 *
	 * @return void
	 */
	public function test_merge_rejects_non_arrays(): void {
		$this->assertSame( Settings_Schema::defaults(), Settings_Schema::merge( null ) );
		$this->assertSame( Settings_Schema::defaults(), Settings_Schema::merge( 'nope' ) );
	}

	/**
	 * An empty product name is not a white-label, it is a missing mark.
	 *
	 * @return void
	 */
	public function test_empty_product_name_is_rejected(): void {
		$input                          = Settings_Schema::defaults();
		$input['brand']['product_name'] = '   ';

		$result = Settings_Schema::validate( $input );

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'product_name', $result['errors'] );
	}

	/**
	 * Short hex, named colours, and missing hashes never reach inline CSS.
	 *
	 * @return void
	 */
	public function test_invalid_hex_is_rejected(): void {
		$input                    = Settings_Schema::defaults();
		$input['brand']['accent'] = '#fff';

		$result = Settings_Schema::validate( $input );

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'accent', $result['errors'] );
	}

	/**
	 * White on white buttons fail AA. Save must refuse, not warn.
	 *
	 * @return void
	 */
	public function test_low_contrast_accent_strong_is_rejected(): void {
		$input                           = Settings_Schema::defaults();
		$input['brand']['accent_strong'] = '#ffffff';

		$result = Settings_Schema::validate( $input );

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'contrast_button', $result['errors'] );
	}

	/**
	 * A javascript: logo is not an image URL.
	 *
	 * @return void
	 */
	public function test_non_http_logo_url_is_rejected(): void {
		$input                      = Settings_Schema::defaults();
		$input['brand']['logo_url'] = 'javascript:alert(1)';

		$result = Settings_Schema::validate( $input );

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'logo_url', $result['errors'] );
	}

	/**
	 * An https logo with a host is accepted.
	 *
	 * @return void
	 */
	public function test_https_logo_url_is_accepted(): void {
		$input                      = Settings_Schema::defaults();
		$input['brand']['logo_url'] = 'https://example.test/logo.svg';

		$result = Settings_Schema::validate( $input );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'https://example.test/logo.svg', $result['value']['brand']['logo_url'] );
	}

	/**
	 * Fill TTL outside 5–300 is not a cache setting, it is a mistake.
	 *
	 * @return void
	 */
	public function test_fill_ttl_out_of_bounds_is_rejected(): void {
		$input                         = Settings_Schema::defaults();
		$input['delivery']['fill_ttl'] = 4;

		$result = Settings_Schema::validate( $input );

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'fill_ttl', $result['errors'] );
	}

	/**
	 * An unknown house policy must not reach fill.
	 *
	 * @return void
	 */
	public function test_unknown_house_policy_is_rejected(): void {
		$input                             = Settings_Schema::defaults();
		$input['delivery']['house_policy'] = 'always';

		$result = Settings_Schema::validate( $input );

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'house_policy', $result['errors'] );
	}

	/**
	 * Retention below a month is not a retention policy.
	 *
	 * @return void
	 */
	public function test_retention_out_of_bounds_is_rejected(): void {
		$input                               = Settings_Schema::defaults();
		$input['tracking']['retention_days'] = 7;

		$result = Settings_Schema::validate( $input );

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'retention_days', $result['errors'] );
	}

	/**
	 * Creative retention has its own bounds, and its own error key.
	 *
	 * Seven days is legal here and rejected for tracking, which is the point of
	 * two windows rather than one: what they delete differs in kind, so their
	 * floors do too. A shared error key would also make the settings screen
	 * mark the wrong field.
	 *
	 * @return void
	 */
	public function test_creative_retention_bounds_differ_from_tracking(): void {
		$input                               = Settings_Schema::defaults();
		$input['creative']['retention_days'] = Settings_Schema::MIN_CREATIVE_RETENTION_DAYS;

		$result = Settings_Schema::validate( $input );

		$this->assertTrue( $result['ok'], 'Seven days is inside the creative bounds.' );
		$this->assertSame( 7, $result['value']['creative']['retention_days'] );
	}

	/**
	 * Below the creative floor is refused under its own key.
	 *
	 * @return void
	 */
	public function test_creative_retention_below_the_floor_is_rejected(): void {
		$input                               = Settings_Schema::defaults();
		$input['creative']['retention_days'] = Settings_Schema::MIN_CREATIVE_RETENTION_DAYS - 1;

		$result = Settings_Schema::validate( $input );

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'creative_retention_days', $result['errors'] );
		$this->assertNotContains( 'retention_days', $result['errors'], 'The tracking field is untouched and must not be blamed.' );
	}

	/**
	 * Above the creative ceiling is refused too.
	 *
	 * @return void
	 */
	public function test_creative_retention_above_the_ceiling_is_rejected(): void {
		$input                               = Settings_Schema::defaults();
		$input['creative']['retention_days'] = Settings_Schema::MAX_CREATIVE_RETENTION_DAYS + 1;

		$result = Settings_Schema::validate( $input );

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'creative_retention_days', $result['errors'] );
	}

	/**
	 * A negative value is refused rather than read as "delete immediately".
	 *
	 * The settings screen offered negatives until its inputs were bounded; the
	 * server always refused them, and this is the assertion that says so.
	 *
	 * @return void
	 */
	public function test_negative_retention_is_rejected_on_both_windows(): void {
		$input                               = Settings_Schema::defaults();
		$input['creative']['retention_days'] = -5;
		$input['tracking']['retention_days'] = -5;

		$result = Settings_Schema::validate( $input );

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'creative_retention_days', $result['errors'] );
		$this->assertContains( 'retention_days', $result['errors'] );
	}

	/**
	 * Audit retention is one of the offered spans, not a range.
	 *
	 * @return void
	 */
	public function test_audit_retention_accepts_an_offered_span(): void {
		$input                            = Settings_Schema::defaults();
		$input['audit']['retention_days'] = 1095;

		$result = Settings_Schema::validate( $input );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1095, $result['value']['audit']['retention_days'] );
	}

	/**
	 * A number nobody offered is refused.
	 *
	 * Three days would shred three years of evidence for who approved what, and
	 * is far likelier to be a slip than an intention. Refusing it is the reason
	 * this is a choice rather than a free-text field.
	 *
	 * @return void
	 */
	public function test_audit_retention_refuses_a_span_that_was_not_offered(): void {
		$input                            = Settings_Schema::defaults();
		$input['audit']['retention_days'] = 3;

		$result = Settings_Schema::validate( $input );

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'audit_retention_days', $result['errors'] );
	}

	/**
	 * Zero is offered, because keep-forever is a real answer.
	 *
	 * @return void
	 */
	public function test_audit_retention_accepts_keep_forever(): void {
		$input                            = Settings_Schema::defaults();
		$input['audit']['retention_days'] = 0;

		$result = Settings_Schema::validate( $input );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 0, $result['value']['audit']['retention_days'] );
	}

	/**
	 * A negative span is refused rather than read as keep-forever.
	 *
	 * @return void
	 */
	public function test_audit_retention_refuses_a_negative_span(): void {
		$input                            = Settings_Schema::defaults();
		$input['audit']['retention_days'] = -365;

		$result = Settings_Schema::validate( $input );

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'audit_retention_days', $result['errors'] );
	}

	/**
	 * A stored viewability threshold survives being read back.
	 *
	 * `merge()` builds from defaults and copies only the keys it knows, so a
	 * setting absent from this class is dropped silently. That is how the
	 * threshold shipped configurable-in-name-only: the rules clamped it, the
	 * unit tests covered the clamping, and no stored value ever reached them.
	 */
	public function test_a_stored_viewability_threshold_is_read_back(): void {
		$merged = Settings_Schema::merge(
			array(
				'delivery' => array(
					'viewable_ratio'    => 30,
					'viewable_dwell_ms' => 2000,
				),
			)
		);

		$this->assertSame( 30, $merged['delivery']['viewable_ratio'] );
		$this->assertSame( 2000, $merged['delivery']['viewable_dwell_ms'] );
	}

	/** An unset threshold falls back to the standard rather than to nothing. */
	public function test_the_threshold_defaults_to_the_standard(): void {
		$merged = Settings_Schema::merge( array() );

		$this->assertSame(
			Viewability_Rules::DEFAULT_RATIO_PERCENT,
			$merged['delivery']['viewable_ratio']
		);
		$this->assertSame(
			Viewability_Rules::DEFAULT_DWELL_MS,
			$merged['delivery']['viewable_dwell_ms']
		);
	}

	/**
	 * A saved threshold survives validation too.
	 *
	 * `validate()` builds its own delivery array rather than reusing the merged
	 * one, so a key added to `merge()` alone is still dropped on the way in.
	 */
	public function test_a_submitted_threshold_survives_validation(): void {
		$result = Settings_Schema::validate(
			array(
				'delivery' => array(
					'viewable_ratio'    => 70,
					'viewable_dwell_ms' => 1500,
				),
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 70, $result['value']['delivery']['viewable_ratio'] );
		$this->assertSame( 1500, $result['value']['delivery']['viewable_dwell_ms'] );
	}

	/** An out-of-range threshold is clamped rather than refusing the save. */
	public function test_an_out_of_range_threshold_is_clamped_not_refused(): void {
		$result = Settings_Schema::validate(
			array(
				'delivery' => array(
					'viewable_ratio'    => 500,
					'viewable_dwell_ms' => -1,
				),
			)
		);

		$this->assertTrue( $result['ok'], 'An impossible threshold refused the whole save.' );
		$this->assertSame( 100, $result['value']['delivery']['viewable_ratio'] );
		$this->assertSame( Viewability_Rules::MIN_DWELL_MS, $result['value']['delivery']['viewable_dwell_ms'] );
	}
}
