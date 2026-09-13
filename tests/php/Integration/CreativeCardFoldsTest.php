<?php
/**
 * What a creative card shows once it has a creative.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Portal\Creative_Actions;
use Aggressive\Ads\Portal\Creative_Feedback;
use WP_UnitTestCase;

/**
 * The card stops asking for what it already has.
 *
 * It used to render both states at once: the finished creative and, below it,
 * a permanent upload form with five lines of instructions for a second one
 * almost nobody adds. Every edit is a dialog now, and a dialog is safe only
 * while two things hold — it opens itself when something inside needs acting
 * on, and its trigger says whether there is anything behind it. Hiding is the
 * easy half; both of those are the halves that go wrong.
 */
final class CreativeCardFoldsTest extends WP_UnitTestCase {

	/**
	 * Renders the creative step for one placement.
	 *
	 * @param array<int, array<string, mixed>> $creatives Creatives on the placement.
	 * @param string                           $error_for Which field owns the current error.
	 * @return string
	 */
	private function render( array $creatives, string $error_for = '' ): string {
		$aggr_campaign = array(
			'id'         => 7,
			'start_date' => '2026-06-01',
			'end_date'   => '2026-06-30',
		);

		$aggr_slots = array(
			array(
				'id'        => 5,
				'name'      => '728x90 Header',
				'size'      => '728x90',
				'max_bytes' => 153600,
				'max_size'  => '150 KB',
				'active'    => true,
				'creatives' => $creatives,
			),
		);

		$aggr_overlays           = array();
		$aggr_campaign_url       = 'https://example.test/campaign/7';
		$aggr_creative_ready     = array() !== $creatives;
		$aggr_creative_error_for = $error_for;

		ob_start();
		require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-creative-step.php';

		$this->overlays = $aggr_overlays;

		return (string) ob_get_clean();
	}

	/**
	 * The dialog specs the last render queued.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $overlays = array();

	/**
	 * One queued dialog spec, by kind.
	 *
	 * @param string $kind Dialog kind.
	 * @return array<string, mixed>
	 */
	private function dialog( string $kind ): array {
		foreach ( $this->overlays as $spec ) {
			if ( is_array( $spec ) && ( $spec['kind'] ?? '' ) === $kind ) {
				return $spec;
			}
		}

		$this->fail( sprintf( 'No "%s" dialog was queued, so the card cannot open one.', $kind ) );
	}

	/**
	 * One uploaded creative, as the card renders it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function one_creative(): array {
		return array(
			array(
				'id'            => 11,
				'assignment_id' => 42,
				'revision'      => 3,
				'name'          => 'LA27banner(728x90).jpg',
				'dimensions'    => '728×90',
				'bytes'         => 55296,
				'click_url'     => 'https://example.com/show',
				'alt_text'      => 'LA Art Show, January 2027',
				'preview'       => 'https://example.test/preview.png',
				'rejected'      => false,
				'state_text'    => '',
				'notes'         => '',
				'starts_on'     => '',
				'ends_on'       => '',
				'status'        => 'active',
			),
		);
	}

	/**
	 * An empty placement leads with the upload form and asks for a creative.
	 *
	 * @return void
	 */
	public function test_an_empty_placement_shows_the_form_unfolded(): void {
		$html = $this->render( array() );

		$this->assertStringContainsString( 'Creative needed', $html );
		$this->assertStringContainsString( 'Required ad creative size: 728x90 pixels', $html );

		// Not behind a dialog: uploading is the whole point of an empty card.
		$this->assertStringNotContainsString( 'Add a rotating creative', $html );
		$this->assertStringContainsString( 'class="aggr-upload-form"', $html );
	}

	/**
	 * Once a creative exists, the instructions and the badge go with it.
	 *
	 * @return void
	 */
	public function test_a_filled_placement_drops_what_it_no_longer_asks(): void {
		$html = $this->render( $this->one_creative() );

		$this->assertStringNotContainsString(
			'Required ad creative size',
			$html,
			'Instructions for a task that is already done.'
		);
		$this->assertStringNotContainsString(
			'Creative needed',
			$html,
			'The card is not asking for a creative it has.'
		);
		$this->assertStringNotContainsString(
			'>Uploaded<',
			$html,
			'A badge beside a visible thumbnail of the thing it reports.'
		);

		// The destination leads and the filename follows it.
		$this->assertStringContainsString( 'aggr-uploaded__destination', $html );
		$this->assertLessThan(
			strpos( $html, 'LA27banner(728x90).jpg' ),
			(int) strpos( $html, 'https://example.com/show' ),
			'The filename is metadata and reads after the destination, not before it.'
		);
	}

	/**
	 * The upload form moves into a dialog once something is uploaded.
	 *
	 * @return void
	 */
	public function test_the_upload_form_moves_into_a_dialog_once_something_is_uploaded(): void {
		$html = $this->render( $this->one_creative() );

		$this->assertStringContainsString( 'Add a rotating creative', $html );
		$this->assertStringContainsString( 'aria-controls="aggr-add-5"', $html );

		/*
		 * "Rotating", not "another" and not "alternating". A second creative
		 * competes with the first by weight, so somebody who read the label as
		 * "replace" would pay for both to run.
		 */
		$this->assertStringNotContainsString( 'Add another creative', $html );

		/*
		 * The form itself is no longer on the card; the dialog carries it.
		 * Matched on the attribute rather than the bare class name, because
		 * the card still prints a `<noscript>` rule that names the selector.
		 */
		$this->assertStringNotContainsString( 'class="aggr-upload-form"', $html );

		$this->assertSame( 'aggr-add-5', $this->dialog( 'add' )['id'] );
	}

	/**
	 * A refused save names the dialog that has to reopen.
	 *
	 * **The fragment is the mechanism, and asserting the spec is not enough.**
	 * The first version of this seeded the dialog module's `isOpen` state and
	 * tested that the spec carried the flag. The flag was carried and nothing
	 * opened: `bootDialog()` only calls `openDialog()` while the state is
	 * still closed, so the dialog got no `.is-open` class, no focus trap and
	 * no `inert`. The test passed over a feature that did not exist.
	 *
	 * @return void
	 */
	public function test_a_refused_save_names_the_dialog_to_reopen(): void {
		// A destination refusal opens that creative's destination dialog.
		$this->assertSame(
			'aggr-destination-dialog-11',
			Creative_Feedback::error_fragment( 'aggr_click_url_invalid', 0, 11 )
		);

		// Artwork refusals open the replace dialog, not the destination one.
		$this->assertSame(
			'aggr-swap-11',
			Creative_Feedback::error_fragment( 'aggr_creative_size_mismatch', 0, 11 )
		);

		// An upload refusal opens the placement's add dialog.
		$this->assertSame(
			'aggr-add-5',
			Creative_Feedback::error_fragment( 'aggr_upload_too_large', 5, 0 )
		);

		/*
		 * And the fragment has to name a dialog this page actually renders,
		 * or it reopens nothing. Rendering the card is what proves the ids
		 * agree; two halves naming each other differently is the whole
		 * failure mode here.
		 */
		$html = $this->render( $this->one_creative() );

		$this->assertStringContainsString( 'id="aggr-destination-dialog-11"', $this->overlay_ids() );
		$this->assertStringContainsString( 'aggr-swap-11', $this->overlay_ids() );
		$this->assertStringContainsString( 'aria-controls="aggr-add-5"', $html );
	}

	/**
	 * The dialog ids this render queued, as attribute strings.
	 *
	 * @return string
	 */
	private function overlay_ids(): string {
		$ids = '';

		foreach ( $this->overlays as $spec ) {
			if ( is_array( $spec ) ) {
				$ids .= 'id="' . (string) ( $spec['id'] ?? '' ) . '" ';
			}
		}

		return $ids;
	}

	/**
	 * The run-dates trigger says whether any dates are set.
	 *
	 * The fold this replaces showed a saved window without being opened. A
	 * dialog cannot, so losing this makes dates somebody set look identical to
	 * dates nobody set — which is exactly what the fold's open-on-load rule
	 * existed to prevent.
	 *
	 * @return void
	 */
	public function test_the_run_dates_trigger_says_whether_dates_are_set(): void {
		$html = $this->render( $this->one_creative() );

		$this->assertStringContainsString( 'Add custom run dates', $html );
		$this->assertStringNotContainsString( 'Custom run dates set', $html );

		$scheduled = $this->one_creative();

		$scheduled[0]['starts_on'] = '2026-06-10';

		$html = $this->render( $scheduled );

		$this->assertStringContainsString( 'Custom run dates set', $html );
		$this->assertStringNotContainsString( 'Add custom run dates', $html );

		// Either end alone counts: an end date with no start is still a window.
		$scheduled               = $this->one_creative();
		$scheduled[0]['ends_on'] = '2026-06-20';

		$this->assertStringContainsString( 'Custom run dates set', $this->render( $scheduled ) );
	}

	/**
	 * Every creative on a placement gets its own edits, not just the first.
	 *
	 * A rotating pair is the case the card was not built for: one set of
	 * dialog ids shared between two creatives would point both cards' edits
	 * at whichever rendered last, which looks exactly like "the second one
	 * cannot be edited".
	 *
	 * @return void
	 */
	public function test_a_second_creative_gets_its_own_edits(): void {
		$pair = $this->one_creative();

		$second                  = $pair[0];
		$second['id']            = 12;
		$second['assignment_id'] = 43;
		$second['click_url']     = 'https://example.com/second';
		$pair[]                  = $second;

		$html = $this->render( $pair );

		foreach ( array( 11, 12 ) as $id ) {
			$this->assertStringContainsString( 'aria-controls="aggr-destination-dialog-' . $id . '"', $html );
			$this->assertStringContainsString( 'aria-controls="aggr-swap-' . $id . '"', $html );
			$this->assertStringContainsString( 'aria-controls="aggr-remove-' . $id . '"', $html );
		}

		// Eleven: five kinds on each creative, plus the placement's own add.
		$ids = array();

		foreach ( $this->overlays as $spec ) {
			$ids[] = (string) ( $spec['id'] ?? '' );
		}

		$this->assertCount( 11, $ids );
		$this->assertSame( $ids, array_unique( $ids ), 'Two creatives share a dialog id, so one card edits the other.' );
	}

	/**
	 * The creative's actions sit below the artwork, not on it.
	 *
	 * They were white text over a gradient, which is a bet on what the
	 * advertiser uploaded: legible over a dark banner, unreadable over a light
	 * one. Beneath the image there is no scrim to tune and no reveal to get
	 * right, so the absence of the overlay is asserted alongside the presence
	 * of the row — reintroducing one would pass a test that only looked for
	 * the links.
	 *
	 * @return void
	 */
	public function test_the_creative_actions_sit_below_the_artwork(): void {
		$html = $this->render( $this->one_creative() );

		$this->assertStringContainsString( 'aggr-creative-actions', $html );
		$this->assertStringNotContainsString(
			'aggr-thumb-actions',
			$html,
			'The overlay is back, and with it the contrast it cannot control.'
		);

		// Below, not inside: the actions are a sibling of the thumb.
		$thumb = (int) strpos( $html, 'aggr-uploaded__thumb' );
		$row   = (int) strpos( $html, 'aggr-creative-actions' );

		$this->assertGreaterThan( $thumb, $row );
		$this->assertStringContainsString( 'aggr-uploaded__figure', $html );

		// All three, and removal still behind its confirmation dialog.
		$this->assertStringContainsString( 'aria-controls="aggr-preview-11"', $html );
		$this->assertStringContainsString( 'aria-controls="aggr-swap-11"', $html );
		$this->assertStringContainsString( 'aria-controls="aggr-remove-11"', $html );
		$this->assertStringContainsString( 'aggr-card-action--danger', $html );
		$this->assertStringContainsString( 'aria-haspopup="dialog"', $html );
	}

	/**
	 * **The rendered dialog carries the attributes the module binds on.**
	 *
	 * Every other test of this reads the template source, which is not the
	 * same question: the destination form is rendered by the overlay host in
	 * `wp_footer`, and an attribute present in a partial that the host never
	 * reaches is an attribute the browser never sees. That is the difference
	 * between a form that saves quietly and one that posts the whole page.
	 *
	 * @return void
	 */
	public function test_the_rendered_dialog_carries_the_save_attributes(): void {
		$this->render( $this->one_creative() );

		$aggr_campaign      = array(
			'id'         => 7,
			'start_date' => '2026-06-01',
			'end_date'   => '2026-06-30',
		);
		$aggr_overlays      = $this->overlays;
		$aggr_overlay_print = true;

		ob_start();
		require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-overlays.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString(
			'data-aggr-save="aggr-save-destination-11"',
			$html,
			'The destination form reaches the page without the attribute the module binds on.'
		);

		/*
		 * The target the server will name has to exist on the card, or the
		 * save succeeds and updates nothing — which reads as the save not
		 * working. The selector is built in `Creative_Actions`; this is the
		 * other end of it.
		 */

		/*
		 * And the target it names has to exist on the card, or the save
		 * succeeds and updates nothing — which reads as the save not working.
		 */
		$card = $this->render( $this->one_creative() );

		$this->assertStringContainsString( 'id="aggr-destination-value-11"', $card );
	}
}
