<?php
/**
 * The advertiser's click tracker, as the portal draws and saves it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Campaign_Editor;
use DOMDocument;
use DOMXPath;
use WP_UnitTestCase;

/**
 * The card, its form and the save behind it.
 *
 * The card is its own form so it cannot ride the destination's autosave, which
 * means the failure that matters is a form that posts a field it was not meant
 * to — a destination blanked by saving a tracker, or the reverse.
 */
final class CampaignTrackerScreenTest extends WP_UnitTestCase {

	private const TRACKER = 'https://track.example.com/c?u={lpurl}';

	/**
	 * Campaign service.
	 *
	 * @var Campaign_Repository
	 */
	private Campaign_Repository $campaigns;

	/**
	 * Portal form handlers.
	 *
	 * @var Campaign_Actions
	 */
	private Campaign_Actions $actions;

	/**
	 * The campaign under test.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * Builds an owner, an organization and a draft.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$org        = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $org, Org_Repository::META_OWNER_USER, $advertiser );

		$container = Plugin::instance()->container();
		$container->get( Org_Repository::class )->flush_cache();
		$container->get( Ownership::class )->flush_cache();

		wp_set_current_user( $advertiser );

		$this->campaigns   = $container->get( Campaign_Repository::class );
		$this->actions     = $container->get( Campaign_Actions::class );
		$campaign_id       = $container->get( Campaign_Editor::class )->create( 'Tracker campaign' );
		$this->campaign_id = (int) $campaign_id;
	}

	/**
	 * Renders the card.
	 *
	 * @param string $tracker Stored tracker.
	 * @param string $error   The field the last refusal belongs to.
	 * @return DOMXPath
	 */
	private function render( string $tracker, string $error = '' ): DOMXPath {
		$aggr_campaign  = array(
			'id'            => $this->campaign_id,
			'autosave_rev'  => 4,
			'click_tracker' => $tracker,
		);
		$aggr_error_for = $error;

		ob_start();
		require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-tracker-card.php';
		$html = (string) ob_get_clean();

		$document = new DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();

		return new DOMXPath( $document );
	}

	public function test_the_card_is_folded_until_there_is_something_in_it(): void {
		$this->assertSame( 0, $this->render( '' )->query( '//details[@open]' )->length );
		$this->assertSame( 1, $this->render( self::TRACKER )->query( '//details[@open]' )->length );
		$this->assertSame( 1, $this->render( '', 'aggr-campaign-tracker' )->query( '//details[@open]' )->length, 'A refused save must open the fold it refused.' );
	}

	public function test_the_card_shows_the_stored_tracker_and_marks_a_refused_one(): void {
		$xpath = $this->render( self::TRACKER, 'aggr-campaign-tracker' );
		$input = $xpath->query( '//input[@name="click_tracker"]' );

		$this->assertSame( 1, $input->length );
		$this->assertSame( self::TRACKER, $input->item( 0 )->attributes->getNamedItem( 'value' )->nodeValue );
		$this->assertSame( 'true', $input->item( 0 )->attributes->getNamedItem( 'aria-invalid' )->nodeValue );
		$this->assertSame( 0, $this->render( self::TRACKER )->query( '//input[@aria-invalid]' )->length );
	}

	public function test_the_form_posts_the_tracker_and_never_the_destination(): void {
		$xpath = $this->render( self::TRACKER );
		$names = array();

		foreach ( $xpath->query( '//form//input | //form//button' ) as $field ) {
			$names[] = $field->attributes->getNamedItem( 'name' )?->nodeValue ?? '';
		}

		// A destination in here would be sent empty and blank the saved link.
		$this->assertNotContains( 'default_click_url', $names );
		$this->assertContains( 'click_tracker', $names );
		$this->assertContains( 'autosave_rev', $names, 'The save needs the revision the autosave script keeps current.' );
		$this->assertContains( '_wpnonce', $names );
		$this->assertSame( 1, $xpath->query( '//input[@name="action"][@value="' . Campaign_Actions::SAVE_ACTION . '"]' )->length );
	}

	public function test_saving_the_tracker_stores_it_and_leaves_the_destination_alone(): void {
		$this->assertSame( 1, $this->actions->process_save( $this->campaign_id, array( 'default_click_url' => 'https://example.com/offer' ), 0 ) );
		$this->assertSame( 2, $this->actions->process_save( $this->campaign_id, array( 'click_tracker' => self::TRACKER ), 1 ) );

		$this->assertSame( self::TRACKER, $this->campaigns->click_tracker( $this->campaign_id ) );
		$this->assertSame( 'https://example.com/offer', $this->campaigns->default_click_url( $this->campaign_id ) );

		$row = Plugin::instance()->container()->get( View_Data::class )->campaign( $this->campaign_id );

		$this->assertIsArray( $row );
		$this->assertSame( self::TRACKER, $row['click_tracker'], 'The screens read the tracker from this row.' );
	}

	public function test_saving_the_destination_leaves_the_tracker_alone(): void {
		$this->assertSame( 1, $this->actions->process_save( $this->campaign_id, array( 'click_tracker' => self::TRACKER ), 0 ) );
		$this->assertSame( 2, $this->actions->process_save( $this->campaign_id, array( 'default_click_url' => 'https://example.com/offer' ), 1 ) );

		$this->assertSame( self::TRACKER, $this->campaigns->click_tracker( $this->campaign_id ) );
	}

	public function test_a_refused_tracker_keeps_the_old_one_and_names_its_field(): void {
		$this->assertSame( 1, $this->actions->process_save( $this->campaign_id, array( 'click_tracker' => self::TRACKER ), 0 ) );

		$result = $this->actions->process_save( $this->campaign_id, array( 'click_tracker' => 'https://track.example.com/c?no-landing-page' ), 1 );

		$this->assertWPError( $result );
		$this->assertSame( self::TRACKER, $this->campaigns->click_tracker( $this->campaign_id ) );
		$this->assertSame( 'aggr-campaign-tracker', Campaign_Actions::error_field( $result->get_error_code() ) );

		$message = Campaign_Actions::error_message( $result->get_error_code() );
		$this->assertStringContainsString( '{lpurl}', $message, 'The advertiser needs to be told what to fix, not that something failed.' );
	}
}
