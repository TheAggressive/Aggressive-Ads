<?php
/**
 * What a saved campaign title looks like, and what a failed save costs.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Campaign_Editor;
use WP_UnitTestCase;

/**
 * Title fidelity through the shared draft workflow.
 *
 * Split from `CampaignEditorTest` by responsibility rather than by line
 * count alone: that file proves who may edit a draft and when, and this one
 * proves that what an advertiser types is what the campaign keeps. The defect
 * behind it was reported as "This campaign changed in another window" on a
 * campaign nobody else had open, which is not a sentence that points at title
 * handling — keeping the evidence in a file named for the cause is the point.
 */
final class CampaignTitleSaveTest extends WP_UnitTestCase {

	/**
	 * Advertiser user id, who owns the organization.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * Shared draft workflow.
	 *
	 * @var Campaign_Editor
	 */
	private Campaign_Editor $editor;

	/**
	 * Stored campaign reads.
	 *
	 * @var Campaign_Repository
	 */
	private Campaign_Repository $campaigns;

	/**
	 * One advertiser owning one organization, which is all a draft needs.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Bright Angle Media',
			)
		);

		update_post_meta( $org_id, Org_Repository::META_OWNER_USER, $this->advertiser );

		$this->editor    = Plugin::instance()->container()->get( Campaign_Editor::class );
		$this->campaigns = Plugin::instance()->container()->get( Campaign_Repository::class );

		Plugin::instance()->container()->get( Org_Repository::class )->flush_cache();
		Plugin::instance()->container()->get( Ownership::class )->flush_cache();
	}

	/**
	 * **A title containing markup characters saves as typed.**
	 *
	 * Every one of these was refused. The draft read-back compared the typed
	 * title with `get_the_title()`, which curls quotes and encodes `&`, and the
	 * write passed plain text to `wp_update_post()`, which unslashes it — so
	 * `&`, `'`, `"` and a backslash all read back different from what was sent.
	 * The save rolled back, but only after claiming a revision, and the
	 * advertiser was told the campaign had changed in another window.
	 *
	 * Run as an advertiser because the account matters: without
	 * `unfiltered_html`, WordPress stores `&` as `&amp;`. An administrator
	 * would have passed half of these cases for a reason that is not the fix.
	 *
	 * @dataProvider provide_titles_with_markup_characters
	 *
	 * @param string $typed What the advertiser typed.
	 * @param string $shown What the campaign must show afterwards.
	 * @return void
	 */
	public function test_a_title_with_markup_characters_saves_as_typed( string $typed, string $shown ): void {
		wp_set_current_user( $this->advertiser );
		$this->assertFalse(
			current_user_can( 'unfiltered_html' ),
			'This test needs an account that WordPress filters titles for.'
		);

		$campaign_id = $this->editor->create();
		$this->assertIsInt( $campaign_id );

		$saved = $this->editor->save( $campaign_id, array( 'title' => $typed ), 0 );

		$this->assertSame( 1, $saved, 'The save was refused: ' . ( is_wp_error( $saved ) ? $saved->get_error_code() : '' ) );
		$this->assertSame( $shown, $this->campaigns->title( $campaign_id ) );
		$this->assertFalse( $this->campaigns->title_is_placeholder( $campaign_id ) );
	}

	/**
	 * Titles that each broke the read-back in a different way.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provide_titles_with_markup_characters(): array {
		return array(
			'ampersand, stored as &amp;'        => array( 'Arts & Culture', 'Arts & Culture' ),
			'apostrophe, curled by display'     => array( "Shawn's campaign", "Shawn's campaign" ),
			'double quotes, curled by display'  => array( 'The "Big" Show', 'The "Big" Show' ),
			'backslash, stripped by unslashing' => array( 'Path \\ test', 'Path \\ test' ),
			'less-than, encoded on input'       => array( 'Under 5 < 10', 'Under 5 < 10' ),
			'accents, which always worked'      => array( 'Café Noël', 'Café Noël' ),
		);
	}

	/**
	 * **A save that fails does not use up the revision.**
	 *
	 * The revision is claimed before the write, which is what makes a
	 * concurrent editor lose cleanly. A write that then fails saved nothing,
	 * though, and holding the claim left the open page one behind: its next
	 * save — sent with the revision it still believed current — was refused
	 * as a conflict, and so was every one after it. Asserting the retry, not
	 * only the stored counter, is what shows the page can recover.
	 *
	 * @return void
	 */
	public function test_a_failed_write_gives_the_revision_back(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->editor->create();
		$this->assertIsInt( $campaign_id );

		$refuse = static fn (): bool => true;
		add_filter( 'wp_insert_post_empty_content', $refuse );

		try {
			$failed = $this->editor->save( $campaign_id, array( 'title' => 'Never stored' ), 0 );
		} finally {
			remove_filter( 'wp_insert_post_empty_content', $refuse );
		}

		$this->assertWPError( $failed );
		$this->assertSame( 'aggr_campaign_not_saved', $failed->get_error_code() );
		$this->assertSame( 0, $this->campaigns->autosave_revision( $campaign_id ) );

		$retry = $this->editor->save( $campaign_id, array( 'title' => 'Stored on retry' ), 0 );

		$this->assertSame( 1, $retry, 'The retry was refused as a conflict, so the failed write kept its revision.' );
		$this->assertSame( 'Stored on retry', $this->campaigns->title( $campaign_id ) );
	}
}
