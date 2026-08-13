<?php
/**
 * One-release hook aliases.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Hook_Aliases;
use WP_UnitTestCase;

/**
 * Sites that hooked the previous names must still observe the new ones.
 */
final class HookAliasesTest extends WP_UnitTestCase {

	/**
	 * A filter on the previous name still runs.
	 *
	 * @return void
	 */
	public function test_apply_runs_the_legacy_filter_after_the_current_one(): void {
		add_filter(
			'aggr_portal_base',
			static function ( mixed $value ): string {
				return is_string( $value ) ? $value . '-new' : 'new';
			}
		);
		add_filter(
			'laao_ads_portal_base',
			static function ( mixed $value ): string {
				return is_string( $value ) ? $value . '-legacy' : 'legacy';
			}
		);

		$this->assertSame( 'advertiser-new-legacy', Hook_Aliases::apply( 'aggr_portal_base', 'advertiser' ) );
	}

	/**
	 * An action on the previous name still fires.
	 *
	 * @return void
	 */
	public function test_fire_runs_the_legacy_action_after_the_current_one(): void {
		$hit = array();

		add_action(
			'aggr_campaign_transitioned',
			static function () use ( &$hit ): void {
				$hit[] = 'new';
			}
		);
		add_action(
			'laao_ads_campaign_transitioned',
			static function () use ( &$hit ): void {
				$hit[] = 'legacy';
			}
		);

		Hook_Aliases::fire( 'aggr_campaign_transitioned', 1, 'aggr_draft', 'aggr_submitted', array() );

		$this->assertSame( array( 'new', 'legacy' ), $hit );
	}
}
