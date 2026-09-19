<?php
/**
 * The edit flow for a running campaign, as it renders.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Nonces;
use DOMDocument;
use DOMXPath;
use WP_UnitTestCase;

/**
 * The redesign moved every control; none of them may have changed what it
 * posts. The server handlers were not touched, so these pin the contract
 * between the markup and them: action names, nonces, field names, and the
 * step each form moves to next.
 */
final class CampaignChangesScreenTest extends WP_UnitTestCase {

	/**
	 * Restores the request.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $_GET['step'], $_GET['edit'] );
		parent::tear_down();
	}

	/**
	 * A running campaign with every edit enabled.
	 *
	 * @param array<string, mixed> $overrides Values to replace.
	 * @return array<string, mixed>
	 */
	private function campaign( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                     => 4242,
				'title'                  => 'Spring season launch',
				'start_date'             => '2030-06-01',
				'end_date'               => '2030-06-30',
				'live_edit_fields'       => array( 'title', 'placement_ids', 'start_ts', 'end_ts', 'click_urls' ),
				'draft_edits'            => array(),
				'edit_values'            => array(
					'title'         => 'Spring season launch',
					'placement_ids' => array( 7 ),
					'click_urls'    => array( 11 => 'https://example.com/a' ),
				),
				// What View_Data offers an edit: the package's placements, with their shapes.
				'edit_placement_options' => array(
					array(
						'id'       => 7,
						'name'     => 'Homepage leaderboard',
						'size'     => '728x90',
						'max_size' => '150 KB',
						'shape'    => array(
							'label'  => '728×90',
							'width'  => 66,
							'height' => 8,
						),
					),
					array(
						'id'       => 8,
						'name'     => 'Article sidebar',
						'size'     => '300x250',
						'max_size' => '150 KB',
						'shape'    => array(
							'label'  => '300×250',
							'width'  => 27,
							'height' => 23,
						),
					),
				),
				'creatives'              => array(
					array(
						'id'        => 11,
						'placement' => 'Homepage leaderboard',
						'click_url' => 'https://example.com/a',
					),
				),
			),
			$overrides
		);
	}

	/**
	 * Renders a partial for a step.
	 *
	 * @param string               $partial  File under templates/portal/partials.
	 * @param string               $step     The step asked for.
	 * @param array<string, mixed> $campaign The campaign.
	 * @return DOMXPath
	 */
	private function render( string $partial, string $step, array $campaign ): DOMXPath {
		$_GET['step'] = $step; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture for a read-only display preference.

		$aggr_campaign = $campaign;

		ob_start();
		require AGGR_PLUGIN_DIR . 'templates/portal/partials/' . $partial;
		$html = (string) ob_get_clean();

		$document = new DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();

		return new DOMXPath( $document );
	}

	/**
	 * Values of every input with a name, keyed by it.
	 *
	 * @param DOMXPath $xpath Rendered markup.
	 * @param string   $form  XPath to the form.
	 * @return array<string, string>
	 */
	private function fields( DOMXPath $xpath, string $form ): array {
		$fields = array();

		$inputs = $xpath->query( $form . '//input[@name]' );

		foreach ( false === $inputs ? array() : $inputs as $input ) {
			$this->assertInstanceOf( \DOMElement::class, $input );
			$fields[ $input->getAttribute( 'name' ) ] = $input->getAttribute( 'value' );
		}

		return $fields;
	}

	public function test_the_head_lists_only_the_steps_the_site_allows(): void {
		$all  = $this->render( 'campaign-changes-steps.php', 'details', $this->campaign() );
		$some = $this->render( 'campaign-changes-steps.php', 'details', $this->campaign( array( 'live_edit_fields' => array( 'click_urls' ) ) ) );

		$labels = static function ( DOMXPath $xpath ): array {
			$found = array();

			$items = $xpath->query( '//ol[@class="aggr-steps"]/li' );

			foreach ( false === $items ? array() : $items as $item ) {
				$found[] = trim( (string) $xpath->evaluate( 'string(.)', $item ) );
			}

			return $found;
		};

		// The same three steps as creation, with the same names.
		$this->assertSame( array( 'Package & dates', 'Ads', 'Review & submit' ), $labels( $all ) );
		$this->assertSame( array( 'Ads', 'Review & submit' ), $labels( $some ), 'A switched-off step was still offered.' );
		$this->assertSame( 1, $all->query( '//li[@aria-current="step"]' )->length );
	}

	public function test_the_details_form_posts_what_the_handler_reads(): void {
		$xpath  = $this->render( 'campaign-changes.php', 'details', $this->campaign() );
		$fields = $this->fields( $xpath, '//form[@id="aggr-changes-form"]' );

		$this->assertSame( Campaign_Actions::CHANGES_ACTION, $fields['action'] );
		$this->assertSame( '4242', $fields['campaign_id'] );
		$this->assertSame( 'destination', $fields['next_step'] );

		// Name, placements and dates in one post, to the handler that reads them all.
		$this->assertSame( '2030-06-01', $fields['start_date'] );
		$this->assertSame( '2030-06-30', $fields['end_date'] );
		$this->assertTrue( wp_verify_nonce( $fields['_wpnonce'], Campaign_Nonces::changes_nonce_action( 4242 ) ) > 0, 'The form lost its nonce.' );
		$this->assertSame( 'Spring season launch', $fields['title'] );

		// Both package placements offered as package cards, and only the campaign's own one ticked.
		$this->assertSame( 2, $xpath->query( '//label[contains(@class,"aggr-choice--package")]//span[@class="aggr-package__shape"]' )->length, 'A placement card lost its silhouette.' );
		$this->assertSame( 2, $xpath->query( '//input[@name="placement_ids[]"]' )->length );
		$this->assertSame( 1, $xpath->query( '//input[@name="placement_ids[]"][@checked]' )->length );
		$this->assertSame( '7', $xpath->query( '//input[@name="placement_ids[]"][@checked]' )->item( 0 )?->getAttribute( 'value' ) );
	}

	public function test_the_last_step_before_review_moves_to_review(): void {
		$xpath  = $this->render( 'campaign-changes.php', 'destination', $this->campaign() );
		$fields = $this->fields( $xpath, '//form[@id="aggr-changes-form"]' );

		$this->assertSame( 'review', $fields['next_step'] );
		$this->assertSame( Campaign_Actions::CHANGES_ACTION, $fields['action'] );
		$this->assertTrue( wp_verify_nonce( $fields['_wpnonce'], Campaign_Nonces::changes_nonce_action( 4242 ) ) > 0, 'The Destination card lost its nonce.' );
	}

	/**
	 * Creation's Destination card, posting a proposal: the same field, check
	 * and tags, but staged rather than autosaved, and checked as staged.
	 *
	 * @return void
	 */
	public function test_the_destination_is_creations_card_staging_a_proposal(): void {
		$xpath = $this->render(
			'campaign-changes.php',
			'destination',
			$this->campaign(
				array(
					'edit_link'       => 'https://example.com/a',
					'edit_link_used'  => 1,
					'edit_link_total' => 2,
				)
			)
		);
		$form  = '//form[@id="aggr-changes-form"]';

		$this->assertSame( 1, $xpath->query( $form )->length );
		$this->assertSame( 'https://example.com/a', $this->fields( $xpath, $form )['default_click_url'] ?? null );
		$this->assertSame( '1', (string) $xpath->evaluate( 'string(' . $form . '/@data-aggr-stage)' ), 'The check would read a link nobody staged.' );
		$this->assertStringContainsString( 'proposed=1', (string) $xpath->evaluate( 'string(' . $form . '/@data-aggr-link-check)' ) );

		// Autosave writes drafts; a running campaign's link is a proposal.
		$this->assertSame( 0, $xpath->query( $form . '[@data-aggr-autosave]' )->length );
		$this->assertSame( 0, $xpath->query( $form . '//input[@name="autosave_rev"]' )->length );

		$this->assertSame( 1, $xpath->query( $form . '//button[@data-aggr-link-check-button]' )->length, 'The check is missing.' );
		$this->assertSame( 1, $xpath->query( $form . '//*[@data-aggr-tags]' )->length, 'The tracking tags are missing.' );
		$this->assertStringContainsString( 'Used by 1 of 2 ads', (string) $xpath->evaluate( 'string(' . $form . ')' ) );
	}

	/**
	 * Creation's size cards: an ad has Update rather than Replace and Remove;
	 * an empty size takes an upload that comes back here; a size the proposal
	 * adds is described and not offered an upload the server would refuse.
	 *
	 * @return void
	 */
	public function test_the_ads_are_creations_size_cards_with_an_update(): void {
		$creative = array(
			'id'         => 11,
			'placement'  => 'Homepage leaderboard',
			'click_url'  => 'https://example.com/a',
			'preview'    => 'https://example.com/a.png',
			'alt_text'   => '',
			'name'       => 'leaderboard.png',
			'bytes'      => 2048,
			'approved'   => true,
			'rejected'   => false,
			'state_text' => 'Approved',
			'notes'      => '',
		);
		$slot     = static fn ( int $id, string $name, string $size, array $creatives, bool $proposed ): array => array(
			'id'        => $id,
			'name'      => $name,
			'size'      => $size,
			'max_bytes' => 153600,
			'max_size'  => '150 KB',
			'active'    => true,
			'creatives' => $creatives,
			'proposed'  => $proposed,
			'leaving'   => false,
		);

		$xpath = $this->render(
			'campaign-changes.php',
			'destination',
			$this->campaign(
				array(
					'can_request_updates' => true,
					'edit_slots'          => array(
						$slot( 7, 'Homepage leaderboard', '728x90', array( $creative ), false ),
						$slot( 8, 'Article sidebar', '300x250', array(), false ),
						$slot( 9, 'Footer banner', '468x60', array(), true ),
					),
				)
			)
		);

		$this->assertSame( 3, $xpath->query( '//section[contains(@class,"aggr-upload-card")]' )->length );
		$this->assertSame( 'Update', trim( (string) $xpath->evaluate( 'string(//section[@aria-labelledby="aggr-slot-7"]//a[@aria-controls="aggr-replace-11"])' ) ) );
		$this->assertSame( 0, $xpath->query( '//section[@aria-labelledby="aggr-slot-7"]//form[contains(@class,"aggr-upload-form")]' )->length, 'A serving ad was offered a second upload.' );

		$upload = $this->fields( $xpath, '//section[@aria-labelledby="aggr-slot-8"]//form[contains(@class,"aggr-upload-form")]' );

		$this->assertSame( '8', $upload['placement_id'] ?? null );
		$this->assertSame( 'edit', $upload['aggr_return'] ?? null, 'An upload from the edit flow would land in the wizard.' );

		$this->assertSame( 0, $xpath->query( '//section[@aria-labelledby="aggr-slot-9"]//form' )->length, 'A size the change has not added yet was offered an upload.' );
		$this->assertStringContainsString( 'Added by your change', (string) $xpath->evaluate( 'string(//section[@aria-labelledby="aggr-slot-9"])' ) );
	}

	/**
	 * Creation's package grid, with the package running now marked, and
	 * creation's schedule: a fixed package states its end rather than asking.
	 *
	 * @return void
	 */
	public function test_the_package_is_creations_grid_and_the_schedule_follows_it(): void {
		$package = static fn ( int $id, string $name, int $days ): array => array(
			'id'            => $id,
			'name'          => $name,
			'price'         => 'USD 450.00',
			'duration'      => $days . ' days',
			'duration_days' => $days,
			'is_default'    => false,
			'sizes'         => array(
				array(
					'label'  => '728×90',
					'width'  => 66,
					'height' => 8,
				),
			),
			'placements'    => array( 'Homepage leaderboard' ),
		);

		$xpath = $this->render(
			'campaign-changes.php',
			'details',
			$this->campaign(
				array(
					'live_edit_fields' => array( 'package_id', 'start_ts', 'end_ts' ),
					'package_id'       => 5,
					'package_options'  => array( $package( 5, 'Launch', 30 ), $package( 6, 'Premium', 14 ) ),
					'edit_values'      => array(
						'package_id' => 6,
						'start_ts'   => (int) strtotime( '2030-06-01 00:00:00' ),
						'start_date' => '2030-06-01',
						'end_date'   => '2030-06-30',
					),
				)
			)
		);
		$form  = '//form[@id="aggr-changes-form"]';

		$this->assertSame( 2, $xpath->query( $form . '//input[@type="radio"][@name="package_id"]' )->length );
		$this->assertSame( '6', (string) $xpath->evaluate( 'string(' . $form . '//input[@name="package_id"][@checked]/@value)' ), 'The staged package is not the one selected.' );
		$this->assertStringContainsString( 'Your package', (string) $xpath->evaluate( 'string(//label[.//input[@value="5"]])' ), 'The running package is not marked.' );
		$this->assertStringContainsString( 'Choose a package', (string) $xpath->evaluate( 'string(' . $form . ')' ) );

		// A fixed package: the end is derived, so it is not posted.
		$this->assertSame( 1, $xpath->query( $form . '//input[@name="end_date"][@disabled]' )->length );
		$this->assertStringContainsString( 'Runs through', (string) $xpath->evaluate( 'string(//*[@id="aggr-run-through"])' ) );
		$this->assertSame( 1, $xpath->query( $form . '//*[@data-aggr-calendar]' )->length, 'The calendar is missing.' );
	}

	public function test_review_lists_each_change_and_offers_submit_and_discard(): void {
		$xpath = $this->render(
			'campaign-changes.php',
			'review',
			$this->campaign(
				array(
					'draft_edits' => array(
						array(
							'label' => 'Campaign name',
							'from'  => 'Spring season launch',
							'to'    => 'Summer season launch',
						),
					),
				)
			)
		);

		$this->assertSame( 1, $xpath->query( '//li[@class="aggr-changes__item"]' )->length );
		$this->assertStringContainsString( 'Summer season launch', (string) $xpath->query( '//li[@class="aggr-changes__item"]' )->item( 0 )?->textContent );

		$submit  = $this->fields( $xpath, '//form[.//input[@value="' . Campaign_Actions::CHANGES_SUBMIT . '"]]' );
		$discard = $this->fields( $xpath, '//form[.//input[@value="' . Campaign_Actions::CHANGES_CANCEL . '"]]' );

		$this->assertTrue( wp_verify_nonce( $submit['_wpnonce'] ?? '', Campaign_Nonces::submit_changes_nonce_action( 4242 ) ) > 0 );
		$this->assertTrue( wp_verify_nonce( $discard['_wpnonce'] ?? '', Campaign_Nonces::cancel_changes_nonce_action( 4242 ) ) > 0 );
	}

	public function test_nothing_to_submit_offers_no_submit(): void {
		$xpath = $this->render( 'campaign-changes.php', 'review', $this->campaign() );

		$this->assertSame( 0, $xpath->query( '//input[@value="' . Campaign_Actions::CHANGES_SUBMIT . '"]' )->length );
		$this->assertSame( 0, $xpath->query( '//input[@value="' . Campaign_Actions::CHANGES_CANCEL . '"]' )->length );
		$this->assertStringContainsString( 'Nothing changed yet', (string) $xpath->document->textContent );
	}

	public function test_a_link_to_the_old_schedule_step_lands_on_the_combined_one(): void {
		$head = $this->render( 'campaign-changes-steps.php', 'schedule', $this->campaign() );
		$flow = $this->render( 'campaign-changes.php', 'schedule', $this->campaign() );

		$this->assertSame( 'Package & dates', trim( (string) $head->evaluate( 'string(//li[@aria-current="step"])' ) ) );
		$this->assertSame( 1, $flow->query( '//input[@name="start_date"]' )->length );
		$this->assertSame( 1, $flow->query( '//input[@name="title"]' )->length );
	}

	/**
	 * Replacing an ad's artwork is why editing needs an Ads step, so it is
	 * there whenever the ads can be replaced, even where links cannot change.
	 *
	 * @return void
	 */
	public function test_the_ads_step_is_there_whenever_ads_can_be_replaced(): void {
		$without_links = $this->campaign( array( 'live_edit_fields' => array( 'title' ) ) );
		$replaceable   = $this->campaign(
			array(
				'live_edit_fields'    => array( 'title' ),
				'can_request_updates' => true,
			)
		);

		$labels = static function ( DOMXPath $xpath ): string {
			return trim( (string) preg_replace( '/\s+/', ' ', (string) $xpath->evaluate( 'string(//ol[@class="aggr-steps"])' ) ) );
		};

		$this->assertStringNotContainsString( 'Ads', $labels( $this->render( 'campaign-changes-steps.php', 'details', $without_links ) ) );
		$this->assertStringContainsString( 'Ads', $labels( $this->render( 'campaign-changes-steps.php', 'details', $replaceable ) ) );
	}
}
