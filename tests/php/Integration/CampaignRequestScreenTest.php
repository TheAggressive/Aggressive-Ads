<?php
/**
 * The pause, restart and cancel request, as the page renders it.
 *
 * Asking is a dialog opened from More actions. A request already waiting
 * stays on the page. A refused send comes back to the dialog, and the
 * banner says what was wrong rather than that the campaign could not be saved.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Nonces;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Router;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Workflow\Campaign_Action_Requests;
use DOMDocument;
use DOMElement;
use DOMXPath;
use WP_UnitTestCase;

/**
 * Pins the menu, the dialog and the waiting card to the handler they post to.
 */
final class CampaignRequestScreenTest extends WP_UnitTestCase {

	use RunningCampaignFixtures;

	/**
	 * Builds the running-campaign fixture.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_up_running_campaign();
	}

	/**
	 * Drops the request the handler reads.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * A live campaign page contains the dialog the menu points at.
	 *
	 * The ad cards used to replace the overlay list after the request had
	 * been queued. The link still hashed to `#aggr-request-…` and nothing
	 * opened, and a test that rendered the dialog on its own stayed green.
	 *
	 * @return void
	 */
	public function test_a_live_campaign_page_contains_the_dialog_its_menu_opens(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();
		$dialog_id   = Campaign_Action_Requests::dialog_id( $campaign_id );

		$this->set_permalink_structure( '/%postname%/' );
		$router = Plugin::instance()->container()->get( Router::class );
		$router->register_rules();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Test setup: the rules have to exist before go_to() can resolve one.
		flush_rewrite_rules( false );

		try {
			$this->go_to( Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id ) );

			ob_start();
			require AGGR_PLUGIN_DIR . 'templates/portal/campaigns-detail.php';
			$html = (string) ob_get_clean();
		} finally {
			$this->set_permalink_structure( '' );
		}

		$xpath = $this->document( $html );
		$link  = $xpath->query( '//a[@aria-controls="' . $dialog_id . '"]' )->item( 0 );
		$shell = $xpath->query( '//*[@id="' . $dialog_id . '"]' )->item( 0 );

		$this->assertInstanceOf( DOMElement::class, $link );
		$this->assertInstanceOf( DOMElement::class, $shell, 'The menu hashes to a dialog the page never prints.' );
		$this->assertSame( '#' . $dialog_id, $link->getAttribute( 'href' ) );
		$this->assertSame( 'dialog', $link->getAttribute( 'aria-haspopup' ) );
		$this->assertStringContainsString( 'aggr-overlay', $shell->getAttribute( 'class' ) );
		$this->assertSame( $dialog_id, $shell->getAttribute( 'data-dialog-id' ) );
		$this->assertSame( 1, $xpath->query( '//*[@id="' . $dialog_id . '"]//*[@role="dialog"]' )->length );
	}

	/**
	 * The menu names the ask this campaign can actually make, and opens the dialog.
	 *
	 * @return void
	 */
	public function test_more_actions_opens_the_request_dialog(): void {
		$xpath = $this->markup(
			$this->menu(
				array(
					array(
						'action' => Post_Statuses::PAUSED,
						'label'  => 'Pause this campaign',
					),
					array(
						'action' => Post_Statuses::CANCELLED,
						'label'  => 'Cancel this campaign',
					),
				)
			)
		);

		$link = $xpath->query( '//a[@aria-haspopup="dialog"]' )->item( 0 );

		$this->assertInstanceOf( DOMElement::class, $link );
		$this->assertSame( 'Pause or cancel', trim( $xpath->evaluate( 'string(.)', $link ) ) );
		$this->assertSame( '#aggr-request-7', $link->getAttribute( 'href' ) );
		$this->assertSame( 'aggr-request-7', $link->getAttribute( 'aria-controls' ) );
		$this->assertSame( 'aggr-request-7-open', $link->getAttribute( 'id' ) );
		$this->assertSame( 'false', $link->getAttribute( 'aria-expanded' ) );

		$items = $xpath->query( '//*[contains(@class, "aggr-menu__item")]' );

		$this->assertSame( 3, $items->length );
		$this->assertSame( 'Duplicate campaign', trim( $xpath->evaluate( 'string(.)', $items->item( 0 ) ) ) );
		$this->assertSame( 'Cancel campaign', trim( $xpath->evaluate( 'string(.)', $items->item( 2 ) ) ) );
		$this->assertStringContainsString( 'aggr-menu__item--danger', $items->item( 2 )->getAttribute( 'class' ) );
	}

	/**
	 * A scheduled campaign can already be cancelled, so the menu does not offer a second cancel.
	 *
	 * @return void
	 */
	public function test_a_single_ask_is_named_for_itself(): void {
		$this->assertSame(
			'Pause this campaign',
			Campaign_Action_Requests::prompt_label(
				array(
					array(
						'action' => Post_Statuses::PAUSED,
						'label'  => 'Pause this campaign',
					),
				)
			)
		);
		$this->assertSame(
			'Restart or cancel',
			Campaign_Action_Requests::prompt_label(
				array(
					array(
						'action' => Post_Statuses::LIVE,
						'label'  => 'Restart this campaign',
					),
					array(
						'action' => Post_Statuses::CANCELLED,
						'label'  => 'Cancel this campaign',
					),
				)
			)
		);
	}

	/**
	 * The dialog posts the same action, nonce and fields the handler already checks.
	 *
	 * Nothing is pre-selected: pause and cancel are different asks, and a
	 * checked radio would send the wrong one.
	 *
	 * @return void
	 */
	public function test_the_dialog_posts_the_request_the_handler_expects(): void {
		$xpath = $this->markup( $this->dialog( $this->options() ) );
		$form  = '//form[input[@name="action" and @value="' . Campaign_Actions::REQUEST_ACTION . '"]]';

		$this->assertSame( 1, $xpath->query( '//div[@role="dialog"][@aria-modal="true"]' )->length );
		$this->assertSame( 'Pause or cancel', trim( $xpath->evaluate( 'string(//h2)' ) ) );
		$this->assertSame( '7', $this->fields( $xpath, $form )['campaign_id'] );
		$this->assertArrayHasKey( '_wpnonce', $this->fields( $xpath, $form ) );

		$radios = $xpath->query( $form . '//input[@type="radio"][@name="requested_action"]' );

		$this->assertSame( 2, $radios->length );

		$values = array();

		foreach ( $radios as $radio ) {
			$this->assertInstanceOf( DOMElement::class, $radio );
			$this->assertFalse( $radio->hasAttribute( 'checked' ) );
			$this->assertTrue( $radio->hasAttribute( 'required' ) );
			$values[] = $radio->getAttribute( 'value' );
		}

		$this->assertSame( array( Post_Statuses::PAUSED, Post_Statuses::CANCELLED ), $values );

		$reason = $xpath->query( '//textarea[@name="reason"]' )->item( 0 );

		$this->assertInstanceOf( DOMElement::class, $reason );
		$this->assertTrue( $reason->hasAttribute( 'required' ) );
		$this->assertSame( (string) Campaign_Action_Requests::MAX_REASON_LENGTH, $reason->getAttribute( 'maxlength' ) );
		$this->assertSame( 0, $xpath->query( '//details' )->length );
	}

	/**
	 * One ask needs no radio. The title already says what is being asked.
	 *
	 * @return void
	 */
	public function test_a_single_ask_is_a_hidden_field(): void {
		$xpath  = $this->markup(
			$this->dialog(
				array(
					array(
						'action' => Post_Statuses::PAUSED,
						'label'  => 'Pause this campaign',
					),
				)
			)
		);
		$hidden = $xpath->query( '//input[@type="hidden"][@name="requested_action"]' )->item( 0 );

		$this->assertInstanceOf( DOMElement::class, $hidden );
		$this->assertSame( Post_Statuses::PAUSED, $hidden->getAttribute( 'value' ) );
		$this->assertSame( 0, $xpath->query( '//input[@type="radio"]' )->length );
		$this->assertSame( 'Pause this campaign', trim( $xpath->evaluate( 'string(//h2)' ) ) );
	}

	/**
	 * A refused reason comes back inside the dialog, on the field it belongs to.
	 *
	 * @return void
	 */
	public function test_a_refused_reason_is_shown_in_the_dialog(): void {
		$xpath  = $this->markup( $this->dialog( $this->options(), 'aggr_action_reason_required' ) );
		$reason = $xpath->query( '//textarea[@name="reason"]' )->item( 0 );
		$dialog = $xpath->query( '//div[@role="dialog"]' )->item( 0 );

		$this->assertInstanceOf( DOMElement::class, $reason );
		$this->assertInstanceOf( DOMElement::class, $dialog );
		$this->assertSame( 'true', $reason->getAttribute( 'aria-invalid' ) );
		$this->assertSame( 'aggr-request-7-error', $reason->getAttribute( 'aria-describedby' ) );
		$this->assertSame( 'aggr-request-7-error', $dialog->getAttribute( 'aria-describedby' ) );
		$this->assertSame( 'Tell the review team why.', trim( $xpath->evaluate( 'string(//*[@id="aggr-request-7-error"])' ) ) );
		$this->assertSame(
			'Tell the review team why.',
			Campaign_Actions::error_message( 'aggr_action_reason_required' )
		);
		$this->assertStringNotContainsString( 'could not be saved', Campaign_Actions::error_message( 'aggr_action_reason_required' ) );
		$this->assertSame( '', Campaign_Actions::error_field( 'aggr_action_reason_required' ) );
	}

	/**
	 * While a request is waiting, the page shows it. The form is not offered again.
	 *
	 * @return void
	 */
	public function test_a_waiting_request_stays_on_the_page(): void {
		$aggr_campaign = array(
			'id'                   => 7,
			'action_request'       => array(
				'action' => Post_Statuses::PAUSED,
				'reason' => 'The product sold out.',
			),
			'action_request_label' => 'Pause this campaign',
		);

		ob_start();
		require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-request.php';
		$xpath = $this->markup( (string) ob_get_clean() );

		$this->assertSame( 'Your request', trim( $xpath->evaluate( 'string(//h2)' ) ) );
		$this->assertStringContainsString( 'Pause this campaign', $xpath->evaluate( 'string(//section)' ) );
		$this->assertSame( 'The product sold out.', trim( $xpath->evaluate( 'string(//blockquote)' ) ) );
		$this->assertSame(
			Campaign_Actions::REQUEST_WITHDRAW,
			$xpath->evaluate( 'string(//input[@name="action"]/@value)' )
		);
		$this->assertSame( 0, $xpath->query( '//textarea' )->length );
		$this->assertSame( 0, $xpath->query( '//details' )->length );
	}

	/**
	 * An empty reason does not change the campaign, and the browser is sent back to the dialog.
	 *
	 * @return void
	 */
	public function test_a_refused_request_returns_to_its_dialog(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$_POST = array(
			'campaign_id'      => (string) $campaign_id,
			'requested_action' => Post_Statuses::PAUSED,
			'reason'           => '   ',
			'_wpnonce'         => wp_create_nonce( Campaign_Nonces::action_nonce_action( $campaign_id ) ),
		);

		$location = $this->redirect_from( array( $this->actions, 'handle_request_action' ) );

		$this->assertStringContainsString( 'aggr_notice=error', $location );
		$this->assertStringContainsString( 'aggr_error=aggr_action_reason_required', $location );
		$this->assertStringEndsWith( '#' . Campaign_Action_Requests::dialog_id( $campaign_id ), $location );
		$this->assertSame( Post_Statuses::LIVE, get_post_status( $campaign_id ) );
	}

	/**
	 * A request that is accepted does not reopen the dialog, and does not pause the campaign.
	 *
	 * @return void
	 */
	public function test_a_sent_request_does_not_reopen_the_dialog(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$_POST = array(
			'campaign_id'      => (string) $campaign_id,
			'requested_action' => Post_Statuses::PAUSED,
			'reason'           => 'The product sold out.',
			'_wpnonce'         => wp_create_nonce( Campaign_Nonces::action_nonce_action( $campaign_id ) ),
		);

		$location = $this->redirect_from( array( $this->actions, 'handle_request_action' ) );

		$this->assertStringContainsString( 'aggr_notice=action_requested', $location );
		$this->assertStringNotContainsString( '#', $location );
		$this->assertSame( Post_Statuses::LIVE, get_post_status( $campaign_id ) );
	}

	/**
	 * Pause and cancel, as the dialog offers them on a live campaign.
	 *
	 * @return array<int, array{action: string, label: string}>
	 */
	private function options(): array {
		return array(
			array(
				'action' => Post_Statuses::PAUSED,
				'label'  => 'Pause this campaign',
			),
			array(
				'action' => Post_Statuses::CANCELLED,
				'label'  => 'Cancel this campaign',
			),
		);
	}

	/**
	 * The More actions menu for a campaign that can also be copied and cancelled outright.
	 *
	 * @param array<int, array{action: string, label: string}> $options Requestable transitions.
	 * @return string
	 */
	private function menu( array $options ): string {
		$aggr_campaign          = array(
			'id'           => 7,
			'can_copy'     => true,
			'can_cancel'   => true,
			'copy_label'   => 'Duplicate campaign',
			'cancel_label' => 'Cancel campaign',
		);
		$aggr_campaign_url      = 'https://example.test/campaigns/7';
		$aggr_request_dialog    = true;
		$aggr_request_dialog_id = Campaign_Action_Requests::dialog_id( 7 );
		$aggr_request_prompt    = Campaign_Action_Requests::prompt_label( $options );

		ob_start();
		require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-more-actions.php';

		return (string) ob_get_clean();
	}

	/**
	 * The request dialog, printed the way the footer prints it.
	 *
	 * @param array<int, array{action: string, label: string}> $options    Requestable transitions.
	 * @param string                                           $error_code Redirect error, or empty.
	 * @return string
	 */
	private function dialog( array $options, string $error_code = '' ): string {
		$aggr_campaign      = array(
			'id'                  => 7,
			'requestable_actions' => $options,
		);
		$aggr_overlays      = array(
			array(
				'kind'       => 'request',
				'id'         => Campaign_Action_Requests::dialog_id( 7 ),
				'close_href' => Campaign_Action_Requests::dialog_id( 7 ) . '-open',
				'error_code' => $error_code,
			),
		);
		$aggr_overlay_print = true;

		ob_start();
		require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-overlays.php';

		return (string) ob_get_clean();
	}

	/**
	 * Loads a fragment into a document.
	 *
	 * @param string $html Fragment.
	 * @return DOMXPath
	 */
	private function markup( string $html ): DOMXPath {
		return $this->document( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
	}

	/**
	 * Loads a document, full page or fragment.
	 *
	 * @param string $html Markup.
	 * @return DOMXPath
	 */
	private function document( string $html ): DOMXPath {
		$document = new DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( $html );
		libxml_clear_errors();

		return new DOMXPath( $document );
	}

	/**
	 * Values of every input with a name, inside one form.
	 *
	 * @param DOMXPath $xpath Rendered markup.
	 * @param string   $form  XPath to the form.
	 * @return array<string, string>
	 */
	private function fields( DOMXPath $xpath, string $form ): array {
		$fields = array();
		$inputs = $xpath->query( $form . '//input[@name]' );

		foreach ( false === $inputs ? array() : $inputs as $input ) {
			$this->assertInstanceOf( DOMElement::class, $input );
			$fields[ $input->getAttribute( 'name' ) ] = $input->getAttribute( 'value' );
		}

		return $fields;
	}

	/**
	 * The address a handler would have sent a browser to.
	 *
	 * `redirect()` ends in `exit`. Throwing from the filter stops that, which
	 * is what makes the address readable.
	 *
	 * @param callable $handler The handler to drive.
	 * @return string
	 */
	private function redirect_from( callable $handler ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Assembles the request the handler under test is about to verify.
		$_REQUEST = $_POST;

		$captured = '';

		$catch = static function ( $location ) use ( &$captured ) {
			$captured = (string) $location;

			throw new \RuntimeException( 'redirected' );
		};

		add_filter( 'wp_redirect', $catch );

		try {
			$handler();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		} finally {
			remove_filter( 'wp_redirect', $catch );
		}

		return $captured;
	}
}
