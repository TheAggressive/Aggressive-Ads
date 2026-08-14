<?php
/**
 * Portal-owned email change security.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Security;

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Repository\User_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Email_Change;
use WP_UnitTestCase;

/**
 * Pins token issue, confirm, cancel and non-enumeration around email change.
 */
final class PortalEmailChangeTest extends WP_UnitTestCase {

	/**
	 * Email-change workflow.
	 *
	 * @var Email_Change
	 */
	private Email_Change $changes;

	/**
	 * User persistence.
	 *
	 * @var User_Repository
	 */
	private User_Repository $users;

	/**
	 * Portal view data.
	 *
	 * @var View_Data
	 */
	private View_Data $view;

	/**
	 * Captured mail.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mail = array();

	/** Capture transactional messages. */
	public function set_up(): void {
		parent::set_up();

		$container     = Plugin::instance()->container();
		$this->changes = $container->get( Email_Change::class );
		$this->users   = $container->get( User_Repository::class );
		$this->view    = $container->get( View_Data::class );
		$this->mail    = array();

		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/** Restore mail filter. */
	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Parameter.
	 *
	 * @param null|bool            $short_circuit Earlier short-circuit.
	 * @param array<string, mixed> $mail          Mail args.
	 */
	public function capture_mail( null|bool $short_circuit, array $mail ): bool {
		$this->mail[] = $mail;

		return true;
	}

	/** Create a portal-capable advertiser. */
	private function make_advertiser( string $email ): int {
		$user_id = self::factory()->user->create(
			array(
				'role'       => Roles::ADVERTISER,
				'user_email' => $email,
				'user_login' => 'aggr_' . wp_generate_password( 12, false, false ),
			)
		);

		return $user_id;
	}

	/** Extract the raw token from the confirmation mail. */
	private function token_from_mail(): string {
		$this->assertNotEmpty( $this->mail );
		$this->assertMatchesRegularExpression( '/[?&]key=([A-Za-z0-9_-]{43})/', (string) $this->mail[0]['message'] );
		preg_match( '/[?&]key=([A-Za-z0-9_-]{43})/', (string) $this->mail[0]['message'], $matches );

		return $matches[1];
	}

	/** A request stores only a hash and mails a portal confirmation URL. */
	public function test_request_stores_hash_and_mails_portal_url(): void {
		$user_id = $this->make_advertiser( 'current@example.test' );

		$this->assertTrue( $this->changes->request( $user_id, 'next@example.test' ) );

		$pending = $this->users->email_change( $user_id );
		$this->assertIsArray( $pending );
		$this->assertSame( 'next@example.test', $pending['new_email'] );
		$this->assertSame( 64, strlen( $pending['token_hash'] ) );
		$this->assertStringNotContainsString( $this->token_from_mail(), (string) wp_json_encode( $pending ) );
		$this->assertStringContainsString( '/advertiser/confirm-email/', (string) $this->mail[0]['message'] );
		$this->assertStringNotContainsString( 'profile.php', (string) $this->mail[0]['message'] );
	}

	/** Confirmation requires the owning session and is single-use. */
	public function test_confirm_requires_owner_session_and_is_single_use(): void {
		$user_id = $this->make_advertiser( 'owner@example.test' );
		$other   = $this->make_advertiser( 'other@example.test' );
		$this->assertTrue( $this->changes->request( $user_id, 'confirmed@example.test' ) );
		$token = $this->token_from_mail();
		$login = (string) get_userdata( $user_id )->user_login;

		$denied = $this->changes->confirm( $other, $login, $token );
		$this->assertWPError( $denied );
		$this->assertSame( 'aggr_invalid_email_change', $denied->get_error_code() );
		$this->assertSame( 'owner@example.test', strtolower( (string) get_userdata( $user_id )->user_email ) );

		$this->assertTrue( $this->changes->confirm( $user_id, $login, $token ) );
		$this->assertSame( 'confirmed@example.test', strtolower( (string) get_userdata( $user_id )->user_email ) );
		$this->assertNull( $this->users->email_change( $user_id ) );

		$replay = $this->changes->confirm( $user_id, $login, $token );
		$this->assertWPError( $replay );
		$this->assertSame( 'aggr_invalid_email_change', $replay->get_error_code() );
	}

	/** Taken addresses are suppressed without revealing the collision. */
	public function test_taken_address_is_suppressed_without_mail(): void {
		$this->make_advertiser( 'taken@example.test' );
		$user_id = $this->make_advertiser( 'seeker@example.test' );

		$this->assertTrue( $this->changes->request( $user_id, 'taken@example.test' ) );
		$this->assertSame( array(), $this->mail );
		$this->assertNull( $this->users->email_change( $user_id ) );
	}

	/** Cancel clears pending state; view data exposes it only while fresh. */
	public function test_cancel_and_pending_view_data(): void {
		$user_id = $this->make_advertiser( 'view@example.test' );
		$this->assertTrue( $this->changes->request( $user_id, 'pending@example.test' ) );

		wp_set_current_user( $user_id );
		$data = $this->view->account();
		$this->assertSame( 'pending@example.test', $data['pending_email'] );

		$this->assertTrue( $this->changes->cancel( $user_id ) );
		$this->assertNull( $this->users->email_change( $user_id ) );
		$this->assertSame( '', $this->view->account()['pending_email'] );
	}

	/** Details save still cannot set email even after email change exists. */
	public function test_account_save_still_cannot_set_email(): void {
		$user_id = $this->make_advertiser( 'locked@example.test' );
		$actions = Plugin::instance()->container()->get( \Aggressive\Ads\Portal\Account_Actions::class );

		$this->assertTrue(
			$actions->process_save(
				$user_id,
				array(
					'display_name' => 'Locked User',
					'first_name'   => 'Locked',
					'last_name'    => 'User',
					'user_email'   => 'hijack@example.test',
					'email'        => 'hijack@example.test',
					'role'         => 'administrator',
				)
			)
		);

		$user = get_userdata( $user_id );
		$this->assertSame( 'locked@example.test', strtolower( (string) $user->user_email ) );
		$this->assertContains( Roles::ADVERTISER, $user->roles );
		$this->assertNotContains( 'administrator', $user->roles );
	}
}
