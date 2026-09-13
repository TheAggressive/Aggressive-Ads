<?php
/**
 * Shared creative workflow and progressive forms.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Creative_Actions;
use Aggressive\Ads\Portal\Creative_Feedback;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Attachment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Workflow\Creative_Manager;
use WP_Error;
use WP_UnitTestCase;

/**
 * Creative_Manager against real ownership, metadata, storage, and audit data.
 */
final class CreativeEditsTest extends WP_UnitTestCase {


	/**
	 * Owning advertiser user id.
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * Unrelated advertiser user id.
	 *
	 * @var int
	 */
	private int $stranger;

	/**
	 * Owning organization id.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * Editable campaign id.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * Active campaign placement id.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Shared workflow.
	 *
	 * @var Creative_Manager
	 */
	private Creative_Manager $manager;

	/**
	 * Progressive form delivery.
	 *
	 * @var Creative_Actions
	 */
	private Creative_Actions $actions;

	/**
	 * Private file storage.
	 *
	 * @var Private_Storage
	 */
	private Private_Storage $storage;

	/**
	 * Temporary source files.
	 *
	 * @var array<int, string>
	 */
	private array $temporary = array();

	/**
	 * Stored relative paths to clean up.
	 *
	 * @var array<int, string>
	 */
	private array $stored = array();

	/**
	 * Builds one editable tenant campaign and placement.
	 *
	 * @return void
	 */

	/**
	 * Builds one editable tenant campaign and placement.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->owner    = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->stranger = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->org_id   = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->owner );

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage Leaderboard',
			)
		);
		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );

		$this->campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
				'post_author' => $this->owner,
			)
		);
		update_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, $this->org_id );
		add_post_meta( $this->campaign_id, Campaign_Repository::META_PLACEMENT_ID, $this->placement_id );

		$this->manager = Plugin::instance()->container()->get( Creative_Manager::class );
		$this->actions = Plugin::instance()->container()->get( Creative_Actions::class );
		$this->storage = Plugin::instance()->container()->get( Private_Storage::class );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();
	}

	/**
	 * Removes filesystem fixtures and request globals.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->stored as $relative ) {
			$this->storage->delete( $relative );
		}

		foreach ( $this->temporary as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}

		$_FILES = array();
		$_POST  = array();

		parent::tear_down();
	}

	/**
	 * Makes a temporary PNG upload entry.
	 *
	 * @param int $width  Image width.
	 * @param int $height Image height.
	 * @return array<string, mixed>
	 */
	/**
	 * **Artwork is swapped in place before review, and never after.**
	 *
	 * Replacing a banner used to mean removing the creative and uploading
	 * again, which drops the placement's coverage and throws away the
	 * destination and the share. The record has to survive: that is the whole
	 * point, so the destination is asserted to be untouched afterwards.
	 *
	 * @return void
	 */
	public function test_artwork_is_swapped_in_place_and_frozen_after_approval(): void {
		wp_set_current_user( $this->owner );

		$creative = $this->manager->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->image_file( 728, 90 ),
			'https://example.com/keep-me',
			'Swapped creative'
		);

		$this->assertIsArray( $creative );

		$creative_id = (int) $creative['id'];
		$creatives   = Plugin::instance()->container()->get( Creative_Repository::class );
		$first       = $creatives->storage_details( $creative_id );

		$this->assertIsArray( $first );
		$this->stored[] = $first['path'];

		$swapped = $this->manager->replace_artwork( $creative_id, $this->image_file( 728, 90 ) );

		$this->assertIsArray( $swapped, 'The artwork was refused.' );
		$this->assertSame( $creative_id, (int) $swapped['id'], 'A new creative was made instead of swapping this one.' );

		$second = $creatives->storage_details( $creative_id );

		$this->assertIsArray( $second );
		$this->stored[] = $second['path'];

		$this->assertNotSame( $first['path'], $second['path'], 'The stored file did not change.' );

		/*
		 * The old bytes are gone. A replace that left them behind would grow
		 * private storage without bound, one abandoned banner at a time.
		 *
		 * Asked through `resolve()`, which is the only thing that answers it:
		 * the stored path is relative to the private root, so `is_file()` on
		 * it is false whether the file exists or not — an assertion that
		 * passed just as happily with the deletion removed.
		 */
		$storage = Plugin::instance()->container()->get( Private_Storage::class );

		$this->assertNull( $storage->resolve( $first['path'] ), 'The replaced file was left on disk.' );
		$this->assertNotNull( $storage->resolve( $second['path'] ), 'The new file is not readable, so this proves nothing.' );

		// Everything that is not the artwork survives it.
		$details = $creatives->details( $creative_id );

		$this->assertIsArray( $details );
		$this->assertSame( 'https://example.com/keep-me', (string) $details['click_url'] );

		// The wrong size is refused, and leaves the current artwork alone.
		$wrong = $this->manager->replace_artwork( $creative_id, $this->image_file( 300, 250 ) );

		$this->assertInstanceOf( \WP_Error::class, $wrong );
		$this->assertSame( 'aggr_creative_size_mismatch', $wrong->get_error_code() );
		$this->assertSame(
			$second['path'],
			$creatives->storage_details( $creative_id )['path'] ?? '',
			'A refused replacement changed the stored file anyway.'
		);

		// Approved artwork is not swappable; it goes through review instead.
		$attachment = (int) self::factory()->attachment->create_object(
			array(
				'file'           => 'approved.png',
				'post_mime_type' => 'image/png',
			)
		);

		Plugin::instance()->container()->get( Creative_Attachment_Repository::class )
			->set_attachment_id( $creative_id, $attachment );

		$frozen = $this->manager->replace_artwork( $creative_id, $this->image_file( 728, 90 ) );

		$this->assertInstanceOf( \WP_Error::class, $frozen );
		$this->assertSame( 'aggr_artwork_frozen', $frozen->get_error_code() );

		wp_set_current_user( 0 );

		$stranger = $this->manager->replace_artwork( $creative_id, $this->image_file( 728, 90 ) );

		$this->assertInstanceOf( \WP_Error::class, $stranger );
		$this->assertSame( 'aggr_artwork_forbidden', $stranger->get_error_code() );
	}

	/**
	 * The portal entry points reach the manager they claim to.
	 *
	 * `set_weight()` and `set_destination()` are both tested directly above.
	 * Neither says anything about whether the form an advertiser posts ever
	 * arrives: the share control shipped complete and unreachable once
	 * already, and `Creative_Actions::process_weight()` had no test at all
	 * until this one. A handler that calls nothing passes every test written
	 * against the thing it should have called.
	 *
	 * @return void
	 */
	public function test_the_portal_entry_points_reach_the_manager(): void {
		wp_set_current_user( $this->owner );

		$creative = $this->manager->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->image_file( 728, 90 ),
			'https://example.com/posted',
			'Posted creative'
		);

		$this->assertIsArray( $creative );

		$creative_id = (int) $creative['id'];
		$creatives   = Plugin::instance()->container()->get( Creative_Repository::class );
		$stored      = $creatives->storage_details( $creative_id );

		$this->assertIsArray( $stored );
		$this->stored[] = $stored['path'];

		$assignments = Plugin::instance()->container()->get( Creative_Assignment_Repository::class );
		$assignments->install_table();
		$assignments->ensure(
			array(
				'line_item_id' => $this->campaign_id,
				'campaign_id'  => $this->campaign_id,
				'placement_id' => $this->placement_id,
				'revision_id'  => $creative_id,
			)
		);

		$this->assertTrue( $this->actions->process_destination( $creative_id, 'https://example.com/via-portal' ) );

		$details = $creatives->details( $creative_id );

		$this->assertIsArray( $details );
		$this->assertSame( 'https://example.com/via-portal', (string) $details['click_url'] );

		$this->assertTrue( $this->actions->process_weight( $creative_id, 5 ) );

		$saved = null;

		foreach ( $assignments->for_campaign( $this->campaign_id ) as $row ) {
			if ( (int) $row['revision_id'] === $creative_id ) {
				$saved = $row;
			}
		}

		$this->assertIsArray( $saved, 'The assignment disappeared.' );
		$this->assertSame( 5, (int) $saved['weight'] );

		// And each entry point passes a refusal back rather than swallowing it.
		$this->assertInstanceOf( \WP_Error::class, $this->actions->process_destination( $creative_id, 'javascript:alert(1)' ) );
		$this->assertInstanceOf( \WP_Error::class, $this->actions->process_weight( $creative_id, 0 ) );

		// And the artwork entry point reaches its manager too.
		$swapped = $this->actions->process_artwork( $creative_id, $this->image_file( 728, 90 ) );

		$this->assertIsArray( $swapped );

		$after = $creatives->storage_details( $creative_id );

		$this->assertIsArray( $after );
		$this->stored[] = $after['path'];
	}

	/**
	 * **A destination can be corrected before review, and never after.**
	 *
	 * There was no way to fix a mistyped destination at all: remove the
	 * creative and upload the file again, losing the placement's coverage in
	 * between for one character. The route exists now, and the half that
	 * matters is where it stops — once artwork is approved, repointing it in
	 * place is the mutation P2's immutability rule exists to prevent, and the
	 * advertiser's route becomes a reviewed update instead.
	 *
	 * Validation is asserted against the same values `upload()` refuses. The
	 * same URL arriving by a different door must not be judged by a different
	 * rule, and a second entry point with weaker checks is how one gets in.
	 *
	 * @return void
	 */
	public function test_a_destination_is_corrected_before_review_and_frozen_after(): void {
		wp_set_current_user( $this->owner );

		$creative = $this->manager->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->image_file( 728, 90 ),
			'https://example.com/typo',
			'Repointed creative'
		);

		$this->assertIsArray( $creative );

		$creative_id = (int) $creative['id'];
		$creatives   = Plugin::instance()->container()->get( Creative_Repository::class );
		$stored      = $creatives->storage_details( $creative_id );

		$this->assertIsArray( $stored );
		$this->stored[] = $stored['path'];

		$this->assertTrue( $this->manager->set_destination( $creative_id, 'https://example.com/fixed' ) );

		$details = $creatives->details( $creative_id );

		$this->assertIsArray( $details );
		$this->assertSame( 'https://example.com/fixed', (string) $details['click_url'] );

		// A save that changes nothing is a success and writes nothing.
		$this->assertTrue( $this->manager->set_destination( $creative_id, 'https://example.com/fixed' ) );

		foreach ( array( 'javascript:alert(1)', 'https://user:pass@example.com/', '', 'not a url' ) as $refused ) {
			$result = $this->manager->set_destination( $creative_id, $refused );

			$this->assertInstanceOf(
				\WP_Error::class,
				$result,
				sprintf( '"%s" was accepted as a destination.', $refused )
			);
		}

		// The refusals must not have written anything either.
		$details = $creatives->details( $creative_id );

		$this->assertIsArray( $details );
		$this->assertSame( 'https://example.com/fixed', (string) $details['click_url'] );

		/*
		 * Frozen means approved: `Revision_Policy` reads it from the Media
		 * Library copy existing, so giving the creative one is what a
		 * publisher's approval actually does to it.
		 */
		$attachment = (int) self::factory()->attachment->create_object(
			array(
				'file'           => 'approved.png',
				'post_mime_type' => 'image/png',
			)
		);

		Plugin::instance()->container()->get( Creative_Attachment_Repository::class )
			->set_attachment_id( $creative_id, $attachment );

		$frozen = $this->manager->set_destination( $creative_id, 'https://example.com/after-approval' );

		$this->assertInstanceOf( \WP_Error::class, $frozen );
		$this->assertSame( 'aggr_destination_frozen', $frozen->get_error_code() );

		$details = $creatives->details( $creative_id );

		$this->assertIsArray( $details );
		$this->assertSame(
			'https://example.com/fixed',
			(string) $details['click_url'],
			'An approved destination was repointed in place.'
		);

		/*
		 * The code matters, not just the refusal. A signed-out caller trips
		 * the campaign gate too, so an assertion that only says "some error"
		 * passes over a missing capability check.
		 */
		wp_set_current_user( 0 );

		$stranger = $this->manager->set_destination( $creative_id, 'https://example.com/stranger' );

		$this->assertInstanceOf( \WP_Error::class, $stranger );
		$this->assertSame( 'aggr_destination_forbidden', $stranger->get_error_code() );
	}

	private function image_file( int $width, int $height ): array {
		$image = imagecreatetruecolor( $width, $height );
		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();
		$path  = wp_tempnam( 'aggr-creative-manager' );
		file_put_contents( $path, $bytes );
		$this->temporary[] = $path;

		return array(
			'name'     => 'creative.png',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $bytes ),
		);
	}

	/**
	 * Current stored image paths, excluding server deny files.
	 *
	 * @return array<int, string>
	 */
	private function private_images(): array {
		$this->storage->ensure();
		$paths = glob( $this->storage->root() . '/*' );
		$paths = is_array( $paths ) ? $paths : array();
		$paths = array_values(
			array_filter(
				$paths,
				static fn ( string $path ): bool => 1 === preg_match( '/\.(?:jpe?g|png|gif|webp)$/i', $path )
			)
		);
		sort( $paths );

		return $paths;
	}

	/**
	 * **A scripted save passes exactly the gates a form post passes.**
	 *
	 * Asynchronous saving adds a response format, not an endpoint: the same
	 * `admin-post.php` action, the same nonce, the same handler, the same
	 * capability and validation checks. The risk in that design is a second
	 * path appearing later that skips one of them, so this drives the real
	 * handler with the async marker set and asserts the refusal, not just the
	 * success.
	 *
	 * @return void
	 */
	public function test_a_scripted_save_is_refused_like_a_form_post(): void {
		wp_set_current_user( $this->owner );

		$creative = $this->manager->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->image_file( 728, 90 ),
			'https://example.com/async',
			'Async creative'
		);

		$this->assertIsArray( $creative );

		$creative_id = (int) $creative['id'];
		$stored      = Plugin::instance()->container()->get( Creative_Repository::class )->storage_details( $creative_id );

		$this->assertIsArray( $stored );
		$this->stored[] = $stored['path'];

		/*
		 * The marker changes the response, never the answer. A signed-out
		 * caller is refused whether or not they ask for JSON, and asserting
		 * the code rather than "some error" is what makes this about the
		 * permission check rather than about any later gate catching it.
		 */
		$_POST[ Creative_Feedback::ASYNC_FIELD ] = '1';

		wp_set_current_user( 0 );

		$refused = $this->actions->process_destination( $creative_id, 'https://example.com/nope' );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'aggr_destination_forbidden', $refused->get_error_code() );

		unset( $_POST[ Creative_Feedback::ASYNC_FIELD ] );
	}

	/**
	 * The address a handler would have sent a browser to.
	 *
	 * `redirect()` ends in `exit`, so the only way to read what it built is to
	 * intercept the redirect itself. Throwing from the filter stops the exit
	 * as well, which is what makes the handler testable at all.
	 *
	 * @param callable $handler The handler to drive.
	 * @return string
	 */
	private function redirect_from( callable $handler ): string {
		/*
		 * The nonce is read from `$_REQUEST`, which only a real request
		 * builds. Copying it here is what lets these tests reach the handler
		 * at all — the sniff is right that this is unverified form data, and
		 * verifying it is the subject of the tests below rather than a
		 * precondition of them.
		 */
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

	/**
	 * A nonce for another action cannot authorize a destination change.
	 *
	 * The handlers were the untested layer: every test above drives the
	 * `process_*` seam beneath them, which says nothing about whether the
	 * nonce is checked before it is reached.
	 *
	 * @return void
	 */
	public function test_the_destination_handler_rejects_a_forged_nonce(): void {
		wp_set_current_user( $this->owner );

		$_POST = array(
			'creative_id' => '1',
			'campaign_id' => (string) $this->campaign_id,
			'click_url'   => 'https://example.com/forged',
			'_wpnonce'    => wp_create_nonce( Creative_Actions::artwork_nonce_action( 1 ) ),
		);

		$this->expectException( 'WPDieException' );
		$this->actions->handle_destination();
	}

	/**
	 * A nonce for another action cannot authorize an artwork swap.
	 *
	 * @return void
	 */
	public function test_the_artwork_handler_rejects_a_forged_nonce(): void {
		wp_set_current_user( $this->owner );

		$_POST = array(
			'creative_id' => '1',
			'campaign_id' => (string) $this->campaign_id,
			'_wpnonce'    => wp_create_nonce( Creative_Actions::destination_nonce_action( 1 ) ),
		);

		$this->expectException( 'WPDieException' );
		$this->actions->handle_artwork();
	}

	/**
	 * Every remaining creative handler checks a nonce before it does anything.
	 *
	 * The four below reached the review queue with no coverage at all: the
	 * tests above drive the `process_*` seam, which is beneath the check and
	 * therefore silent about whether the check exists. A handler that stopped
	 * calling `check_admin_referer` would keep every one of those tests green
	 * while accepting a cross-site post.
	 *
	 * Each is handed a nonce minted for a *different* action, which is the
	 * case a plain missing-nonce test misses — `check_admin_referer` with no
	 * nonce at all fails for a second reason, so a handler that verified the
	 * wrong action would still pass it.
	 *
	 * @dataProvider provide_guarded_handlers
	 *
	 * @param string               $method The handler to call.
	 * @param array<string,string> $post   The forged request, minus the nonce.
	 * @return void
	 */
	public function test_a_creative_handler_rejects_a_forged_nonce( string $method, array $post ): void {
		wp_set_current_user( $this->owner );

		$_POST = $post + array(
			'campaign_id' => (string) $this->campaign_id,
			// Minted for an action none of these handlers verifies.
			'_wpnonce'    => wp_create_nonce( Creative_Actions::remove_nonce_action( 4242 ) ),
		);

		$this->expectException( 'WPDieException' );
		$this->actions->{ $method }();
	}

	/**
	 * The handlers with no other nonce coverage, and the fields each reads.
	 *
	 * @return array<string, array{0: string, 1: array<string, string>}>
	 */
	public static function provide_guarded_handlers(): array {
		return array(
			'window'   => array(
				'handle_window',
				array(
					'assignment_id' => '1',
					'starts_on'     => '2026-01-01',
					'ends_on'       => '2026-02-01',
				),
			),
			'weight'   => array(
				'handle_weight',
				array(
					'creative_id' => '1',
					'weight'      => '50',
				),
			),
			'status'   => array(
				'handle_status',
				array(
					'assignment_id' => '1',
					'intent'        => 'pause',
				),
			),
			'withdraw' => array(
				'handle_withdraw',
				array( 'replacement_id' => '1' ),
			),
		);
	}

	/**
	 * **A refused destination sends the browser back to its own dialog.**
	 *
	 * `error_fragment()` is tested directly elsewhere, and that proves only
	 * that the right fragment can be computed. This proves the handler
	 * actually puts it on the URL — the half that decides whether an
	 * advertiser lands on a page with the failing field revealed or on one
	 * where the error names a control still folded away inside a shut dialog.
	 *
	 * @return void
	 */
	public function test_a_refused_destination_redirects_to_its_own_dialog(): void {
		wp_set_current_user( $this->owner );

		$creative = $this->manager->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->image_file( 728, 90 ),
			'https://example.com/start',
			'Redirect creative'
		);

		$this->assertIsArray( $creative );

		$creative_id = (int) $creative['id'];
		$stored      = Plugin::instance()->container()->get( Creative_Repository::class )->storage_details( $creative_id );

		$this->assertIsArray( $stored );
		$this->stored[] = $stored['path'];

		$_POST = array(
			'creative_id' => (string) $creative_id,
			'campaign_id' => (string) $this->campaign_id,
			'click_url'   => 'javascript:alert(1)',
			'_wpnonce'    => wp_create_nonce( Creative_Actions::destination_nonce_action( $creative_id ) ),
		);

		$url = $this->redirect_from( fn () => $this->actions->handle_destination() );

		$this->assertStringContainsString( 'aggr_error=aggr_click_url_invalid', $url );
		$this->assertStringContainsString( 'aggr_creative=' . $creative_id, $url );
		$this->assertStringEndsWith(
			'#aggr-destination-dialog-' . $creative_id,
			$url,
			'The refusal names a field inside a dialog and does not reopen it.'
		);
	}

	/**
	 * A successful destination change reports itself and reopens nothing.
	 *
	 * The negative is the half worth having: a success carrying an error
	 * fragment would reopen the dialog the advertiser has just finished with.
	 *
	 * @return void
	 */
	public function test_a_saved_destination_redirects_without_a_fragment(): void {
		wp_set_current_user( $this->owner );

		$creative = $this->manager->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->image_file( 728, 90 ),
			'https://example.com/before',
			'Redirect creative'
		);

		$this->assertIsArray( $creative );

		$creative_id = (int) $creative['id'];
		$stored      = Plugin::instance()->container()->get( Creative_Repository::class )->storage_details( $creative_id );

		$this->assertIsArray( $stored );
		$this->stored[] = $stored['path'];

		$_POST = array(
			'creative_id' => (string) $creative_id,
			'campaign_id' => (string) $this->campaign_id,
			'click_url'   => 'https://example.com/after',
			'_wpnonce'    => wp_create_nonce( Creative_Actions::destination_nonce_action( $creative_id ) ),
		);

		$url = $this->redirect_from( fn () => $this->actions->handle_destination() );

		$this->assertStringContainsString( 'aggr_notice=creative_destination_saved', $url );
		$this->assertStringNotContainsString( '#', $url );
		$this->assertStringNotContainsString( 'aggr_error', $url );
	}

	/**
	 * **The patch a saved destination sends back names this creative's field.**
	 *
	 * The browser suite showed the page reloading after a save that worked,
	 * and an empty patch map is what makes the client do that. The emission
	 * itself is one `wp_send_json()` call and cannot be driven from here —
	 * it ends in a bare `die` that takes PHPUnit with it, the failure
	 * `Redirect_Trap` was written for. What is worth asserting is the map,
	 * which is the part with a decision in it.
	 *
	 * @return void
	 */
	public function test_a_saved_destination_patches_its_own_field(): void {
		$this->assertSame(
			array( '#aggr-destination-value-42' => 'https://example.com/after' ),
			Creative_Feedback::destination_patch( 42, 'https://example.com/after' )
		);

		// The selector has to be the one the card actually renders.
		$html = 'id="aggr-destination-value-42"';

		$this->assertStringContainsString(
			'aggr-destination-value-',
			array_key_first( Creative_Feedback::destination_patch( 42, 'x' ) ) ?? '',
			'The patch names a selector the card does not carry.'
		);
		$this->assertStringContainsString( 'aggr-destination-value-42', $html );
	}
}
