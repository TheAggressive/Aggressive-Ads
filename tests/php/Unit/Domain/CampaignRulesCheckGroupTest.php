<?php
/**
 * Every validation problem appears under one readiness check.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Campaign_Rules;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The review screen lists what blocks approval under six checks. A code that
 * fell through to the default would still be shown — under "details" — but a
 * reviewer looking for the artwork problem would not find it there, so every
 * code is expected to be placed on purpose.
 */
final class CampaignRulesCheckGroupTest extends TestCase {

	/**
	 * Every ERROR_ constant, found by reflection so a new one cannot be missed.
	 *
	 * @return array<string, string>
	 */
	private static function codes(): array {
		$codes = array();

		foreach ( ( new ReflectionClass( Campaign_Rules::class ) )->getConstants() as $name => $value ) {
			if ( str_starts_with( $name, 'ERROR_' ) && is_string( $value ) ) {
				$codes[ $name ] = $value;
			}
		}

		return $codes;
	}

	public function test_every_problem_code_belongs_to_a_named_check(): void {
		$codes = self::codes();

		// A count, so a reflection that silently found nothing cannot pass.
		$this->assertCount( 21, $codes );

		foreach ( $codes as $name => $code ) {
			$this->assertContains( Campaign_Rules::check_group( $code ), Campaign_Rules::CHECK_GROUPS, $name );
		}
	}

	/**
	 * Only the title lands under details. Anything else there arrived by the
	 * default branch, which means a code nobody placed.
	 */
	public function test_only_the_title_is_a_details_problem(): void {
		$details = array_keys(
			array_filter(
				self::codes(),
				static fn ( string $code ): bool => 'details' === Campaign_Rules::check_group( $code )
			)
		);

		$this->assertSame( array( 'ERROR_TITLE_MISSING' ), $details );
	}

	public function test_artwork_and_its_link_are_one_check(): void {
		$this->assertSame( 'artwork', Campaign_Rules::check_group( Campaign_Rules::ERROR_NO_CREATIVES ) );
		$this->assertSame( 'artwork', Campaign_Rules::check_group( Campaign_Rules::ERROR_CLICK_URL_INVALID ) );
		$this->assertSame( 'details', Campaign_Rules::check_group( 'a_code_from_the_future' ) );
	}
}
