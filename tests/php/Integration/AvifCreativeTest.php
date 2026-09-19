<?php
/**
 * AVIF creatives, end to end.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Attachment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Workflow\Creative_Manager;
use Aggressive\Ads\Workflow\Creative_Promoter;
use WP_UnitTestCase;

/**
 * A real AVIF file through upload and approval.
 *
 * Adding a type to `Upload_Rules` is one line; whether the rest of the path
 * agrees is not. WordPress's own type check, PHP's reading of the header and
 * the Media Library copy made at approval each have their own idea of what an
 * image is, and any one of them refusing would make the type allowed on paper
 * and impossible in practice. The fixture is a real encoded file, not bytes
 * built by the test, so what is read is what an encoder produces.
 */
final class AvifCreativeTest extends WP_UnitTestCase {

	/**
	 * Owning advertiser user id.
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * Draft campaign id.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * A 728×90 placement on it.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Temporary copies of the fixture, which the upload consumes.
	 *
	 * @var array<int, string>
	 */
	private array $temporary = array();

	/**
	 * Media Library files made by promotion.
	 *
	 * @var array<int, int>
	 */
	private array $attachments = array();

	/**
	 * A draft campaign with one 728×90 placement.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->owner = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$org_id      = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $org_id, Org_Repository::META_OWNER_USER, $this->owner );

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Header',
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
		update_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, $org_id );
		add_post_meta( $this->campaign_id, Campaign_Repository::META_PLACEMENT_ID, $this->placement_id );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();
	}

	/**
	 * Removes stored files, Media Library copies and temporary files.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$creatives = Plugin::instance()->container()->get( Creative_Repository::class );
		$storage   = Plugin::instance()->container()->get( Private_Storage::class );

		foreach ( $creatives->ids_for_campaign( $this->campaign_id ) as $creative_id ) {
			$stored = $creatives->storage_details( $creative_id );

			if ( null !== $stored && '' !== $stored['path'] ) {
				$storage->delete( $stored['path'] );
			}
		}

		foreach ( $this->attachments as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		foreach ( $this->temporary as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}

		parent::tear_down();
	}

	/**
	 * An AVIF file is accepted, stored as AVIF and published as AVIF.
	 *
	 * @return void
	 */
	public function test_an_avif_creative_is_uploaded_and_published(): void {
		wp_set_current_user( $this->owner );

		$result = Plugin::instance()->container()->get( Creative_Manager::class )->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->fixture( 'banner.avif' ),
			'https://example.com/avif',
			''
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		$this->assertSame( 'image/avif', $result['mime'] );
		$this->assertSame( 728, $result['width'] );
		$this->assertSame( 90, $result['height'] );

		$stored = Plugin::instance()->container()->get( Creative_Repository::class )->storage_details( (int) $result['id'] );
		$this->assertIsArray( $stored );
		$this->assertStringEndsWith( '.avif', $stored['path'], 'The stored file was not named from its type.' );

		$attachment_id = Plugin::instance()->container()->get( Creative_Promoter::class )->promote( (int) $result['id'] );

		$this->assertIsInt( $attachment_id, is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : '' );
		$this->attachments[] = $attachment_id;
		$this->assertSame( 'image/avif', get_post_mime_type( $attachment_id ) );
		$this->assertStringEndsWith(
			'.avif',
			Plugin::instance()->container()->get( Creative_Attachment_Repository::class )->attachment_file( (int) $result['id'] )
		);
	}

	/**
	 * A PNG named `.avif` is refused, and nothing is stored.
	 *
	 * The new type must not become a way past the check that the name and
	 * the bytes agree.
	 *
	 * @return void
	 */
	public function test_a_png_named_avif_is_refused(): void {
		wp_set_current_user( $this->owner );

		$image = imagecreatetruecolor( 728, 90 );
		$path  = wp_tempnam( 'aggr-avif' );
		imagepng( $image, $path );
		$this->temporary[] = $path;

		$result = Plugin::instance()->container()->get( Creative_Manager::class )->upload(
			$this->campaign_id,
			$this->placement_id,
			array(
				'name'     => 'banner.avif',
				'tmp_name' => $path,
				'error'    => UPLOAD_ERR_OK,
				'size'     => (int) filesize( $path ),
			),
			'https://example.com/avif',
			''
		);

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_' . Upload_Rules::ERROR_TYPE_MISMATCH, $result->get_error_code() );
		$this->assertSame( array(), Plugin::instance()->container()->get( Creative_Repository::class )->for_campaign( $this->campaign_id ) );
	}

	/**
	 * A temporary copy of the AVIF fixture, as an upload would present it.
	 *
	 * @param string $name The name the browser would claim.
	 * @return array<string, mixed>
	 */
	private function fixture( string $name ): array {
		$path = wp_tempnam( 'aggr-avif' );
		copy( AGGR_PLUGIN_DIR . 'tests/fixtures/creative-728x90.avif', $path );
		$this->temporary[] = $path;

		return array(
			'name'     => $name,
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => (int) filesize( $path ),
		);
	}
}
