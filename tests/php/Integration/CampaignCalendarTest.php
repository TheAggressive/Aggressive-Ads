<?php
/**
 * The schedule calendar as the server draws it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use DOMDocument;
use DOMXPath;
use WP_UnitTestCase;

/**
 * What the calendar module is handed, and what a browser without it sees.
 *
 * The module reads every value here from the markup, so a missing attribute
 * does not fail loudly: the calendar simply never attaches and the date
 * fields carry on alone. These pin the contract from the server's side;
 * `calendar.test.ts` pins the reader.
 */
final class CampaignCalendarTest extends WP_UnitTestCase {

	/**
	 * Restores the options a test changed.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( 'start_of_week' );
		update_option( 'timezone_string', '' );
		parent::tear_down();
	}

	/**
	 * Renders the partial.
	 *
	 * @param array<string, mixed> $args Overrides for the partial's inputs.
	 * @return DOMXPath
	 */
	private function render( array $args = array() ): DOMXPath {
		$aggr_cal_start_id = 'aggr-start-date';
		$aggr_cal_end_id   = 'aggr-end-date';
		$aggr_cal_start    = (string) ( $args['start'] ?? '2030-06-10' );
		$aggr_cal_end      = (string) ( $args['end'] ?? '2030-06-23' );
		$aggr_cal_min      = (string) ( $args['min'] ?? '' );
		$aggr_cal_fixed    = (bool) ( $args['fixed'] ?? false );
		$aggr_cal_locked   = (bool) ( $args['locked'] ?? false );

		ob_start();
		require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-calendar.php';
		$html = (string) ob_get_clean();

		$document = new DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();

		return new DOMXPath( $document );
	}

	/**
	 * The one element carrying `data-aggr-calendar`.
	 *
	 * @param DOMXPath $xpath Rendered partial.
	 * @return \DOMElement
	 */
	private function root( DOMXPath $xpath ): \DOMElement {
		$nodes = $xpath->query( '//*[@data-aggr-calendar]' );

		$this->assertInstanceOf( \DOMNodeList::class, $nodes );
		$this->assertSame( 1, $nodes->length );

		$root = $nodes->item( 0 );
		$this->assertInstanceOf( \DOMElement::class, $root );

		return $root;
	}

	/**
	 * Visible quick picks, by key.
	 *
	 * @param DOMXPath $xpath Rendered partial.
	 * @return array<int, string>
	 */
	private function offered( DOMXPath $xpath ): array {
		$keys  = array();
		$nodes = $xpath->query( '//button[@data-aggr-preset][not(@hidden)]' );

		foreach ( false === $nodes ? array() : $nodes as $node ) {
			$this->assertInstanceOf( \DOMElement::class, $node );
			$keys[] = $node->getAttribute( 'data-aggr-preset' );
		}

		return $keys;
	}

	public function test_it_hands_the_module_every_value_it_reads(): void {
		update_option( 'timezone_string', 'Pacific/Auckland' );

		$root = $this->root( $this->render() );

		$this->assertSame( 'aggr-start-date', $root->getAttribute( 'data-aggr-start' ) );
		$this->assertSame( 'aggr-end-date', $root->getAttribute( 'data-aggr-end' ) );
		// The site's today, not the server's: Auckland is a day ahead of UTC for half of every day.
		$this->assertSame( wp_date( 'Y-m-d', null, new \DateTimeZone( 'Pacific/Auckland' ) ), $root->getAttribute( 'data-aggr-today' ) );
		$this->assertSame( $root->getAttribute( 'data-aggr-today' ), $root->getAttribute( 'data-aggr-min' ), 'An empty minimum means today.' );
		$this->assertSame( '0', $root->getAttribute( 'data-aggr-start-locked' ) );

		foreach ( array( 'start', 'end', 'unavailable', 'range', 'open', 'fixed', 'days-one', 'days-other' ) as $label ) {
			$this->assertNotSame( '', $root->getAttribute( 'data-aggr-label-' . $label ), $label );
		}
	}

	public function test_day_names_never_contain_the_field_labels(): void {
		$root = $this->root( $this->render() );

		// Playwright's getByLabel matches substrings, case-insensitively; a day named "…, start date" collided with the field.
		foreach ( array( 'start', 'end' ) as $label ) {
			$this->assertStringNotContainsStringIgnoringCase( 'date', $root->getAttribute( 'data-aggr-label-' . $label ) );
		}
	}

	public function test_without_script_it_is_a_hidden_picture_with_inert_controls(): void {
		$xpath = $this->render();

		$this->assertSame( 2, $xpath->query( '//*[@data-aggr-calendar-grids][@aria-hidden="true"]//table' )->length );
		$this->assertSame( 0, $xpath->query( '//*[@data-aggr-calendar-grids]//button' )->length );
		$this->assertSame( 5, $xpath->query( '//button[@data-aggr-preset][@disabled]' )->length );
		$this->assertSame( 2, $xpath->query( '//*[contains(@class,"aggr-calendar__nav")]//button[@disabled]' )->length );
		$this->assertSame( 0, $xpath->query( '//button[@data-aggr-preset][not(@disabled)]' )->length );
	}

	public function test_it_draws_the_range_it_is_given(): void {
		$xpath = $this->render();

		$this->assertSame( 2, $xpath->query( '//span[contains(@class,"aggr-calendar__day--edge")]' )->length );
		$this->assertSame( 12, $xpath->query( '//td[contains(@class,"aggr-calendar__cell--in")]' )->length );
		$this->assertSame( 1, $xpath->query( '//td[@class="aggr-calendar__cell--from"]/span[.="10"]' )->length );
		$this->assertSame( 1, $xpath->query( '//td[@class="aggr-calendar__cell--to"]/span[.="23"]' )->length );
		$this->assertSame( '14 days selected', trim( (string) $xpath->query( '//*[@data-aggr-calendar-span]' )->item( 0 )?->textContent ) );
	}

	public function test_a_fixed_package_offers_only_start_picks(): void {
		$this->assertSame( array( 'today', 'monday', 'two-weeks', 'month', 'open' ), $this->offered( $this->render() ) );
		$this->assertSame( array( 'today', 'monday' ), $this->offered( $this->render( array( 'fixed' => true ) ) ) );
	}

	public function test_a_started_campaign_offers_only_end_picks(): void {
		$xpath = $this->render( array( 'locked' => true ) );

		$this->assertSame( array( 'two-weeks', 'month', 'open' ), $this->offered( $xpath ) );
		$this->assertSame( '1', $this->root( $xpath )->getAttribute( 'data-aggr-start-locked' ) );
	}

	public function test_weeks_start_on_the_sites_first_weekday(): void {
		// June 1, 2030 is a Saturday.
		update_option( 'start_of_week', 1 );
		$monday = $this->render();
		update_option( 'start_of_week', 0 );
		$sunday = $this->render();

		$this->assertSame( '1', $this->root( $monday )->getAttribute( 'data-aggr-week-start' ) );
		$this->assertSame( 5, $monday->query( '(//table)[1]/tbody/tr[1]/td[not(node())]' )->length );
		$this->assertSame( 6, $sunday->query( '(//table)[1]/tbody/tr[1]/td[not(node())]' )->length );
	}
}
