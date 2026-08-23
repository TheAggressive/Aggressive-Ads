<?php
/**
 * The db-10 reindex of organization lookup keys.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Upgrade;

use Aggressive\Ads\Repository\Org_Access_Repository;
use WP_UnitTestCase;

/**
 * `active_key` used to be an HMAC over `wp_salt( 'auth' )`.
 *
 * Auth salts rotate — deliberately, as security hygiene, and accidentally, when
 * a database is restored into a site with different AUTH_KEY/AUTH_SALT values.
 * Every stored key then became unreproducible, so lookups missed rows that were
 * sitting right there: an organization could never be renamed again, and
 * duplicate-name detection silently stopped detecting anything.
 *
 * These assert the repair, and that it repairs the thing it claims to.
 */
final class OrgAccessReindexTest extends WP_UnitTestCase {

	/**
	 * Registry under test.
	 *
	 * @var Org_Access_Repository
	 */
	private Org_Access_Repository $access;

	/**
	 * Organization the identity row belongs to.
	 *
	 * @var int
	 */
	private int $org_id = 0;

	/**
	 * Starts from a genuinely new table.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->access = new Org_Access_Repository();
		$this->access->drop_table();
		$this->access->install_table();

		$this->org_id = (int) self::factory()->post->create(
			array(
				'post_type'  => 'aggr_org',
				'post_title' => 'REINDEX SUBJECT',
			)
		);
	}

	/**
	 * Replaces a row's key with one this site can never recompute.
	 *
	 * This is what a salt rotation leaves behind: the plaintext columns are
	 * intact and the key beside them is meaningless.
	 *
	 * @param int $row_id Registry row id.
	 * @return void
	 */
	private function orphan_key( int $row_id ): void {
		global $wpdb;

		$wpdb->update(
			$this->access->table_name(),
			array( 'active_key' => str_repeat( 'f', 64 ) ),
			array( 'id' => $row_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * The id of the single identity row.
	 *
	 * @return int
	 */
	private function identity_row_id(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE kind = %s LIMIT 1',
				$this->access->table_name(),
				Org_Access_Repository::KIND_IDENTITY
			)
		);
	}

	/**
	 * One row's stored columns.
	 *
	 * @param int $row_id Registry row id.
	 * @return array<string, string>
	 */
	private function row( int $row_id ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT active_key, token_hash, canonical_name FROM %i WHERE id = %d',
				$this->access->table_name(),
				$row_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? array_map( 'strval', $row ) : array();
	}

	/** An orphaned identity key is repaired, and the lookup works again. */
	public function test_reindex_repairs_an_orphaned_identity_key(): void {
		$this->assertTrue( true === $this->access->register_identity( $this->org_id, 'REINDEX SUBJECT' ) );

		$row_id = $this->identity_row_id();
		$this->assertGreaterThan( 0, $row_id );

		// Assert the fixture is real before asserting on it: the lookup must
		// work now, or "it works after the migration" proves nothing.
		$this->assertSame( $this->org_id, $this->access->org_id_for_canonical( 'REINDEX SUBJECT' ) );

		$before = $this->row( $row_id );
		$this->orphan_key( $row_id );

		// And the orphaning must actually break it, or the repair is untested.
		$this->assertSame( 0, $this->access->org_id_for_canonical( 'REINDEX SUBJECT' ) );

		$rewritten = $this->access->reindex_active_keys();

		// A count, not just "it works now": a reindex that silently skipped
		// every row would leave a passing lookup if the key happened to match.
		$this->assertSame( 1, $rewritten );
		$this->assertSame( $this->org_id, $this->access->org_id_for_canonical( 'REINDEX SUBJECT' ) );

		$after = $this->row( $row_id );

		$this->assertSame( $before['active_key'], $after['active_key'] );
		$this->assertSame( 'REINDEX SUBJECT', $after['canonical_name'] );
	}

	/**
	 * The bearer-token verifier is left alone.
	 *
	 * The negative half, and the more valuable one. `token_hash` is a secret
	 * verifier rather than an index, so a salt rotation invalidating it is
	 * correct behaviour. A reindex that "helpfully" rewrote it would be
	 * rewriting a hash of a token nobody holds any more.
	 */
	public function test_reindex_does_not_touch_token_hashes(): void {
		$this->assertTrue( true === $this->access->register_identity( $this->org_id, 'REINDEX SUBJECT' ) );

		$row_id = $this->identity_row_id();
		$before = $this->row( $row_id );

		$this->assertNotSame( '', $before['token_hash'] );

		$this->access->reindex_active_keys();

		$this->assertSame( $before['token_hash'], $this->row( $row_id )['token_hash'] );
	}

	/** Running it twice changes nothing, because a migration can be re-run. */
	public function test_reindex_is_idempotent(): void {
		$this->assertTrue( true === $this->access->register_identity( $this->org_id, 'REINDEX SUBJECT' ) );

		$row_id = $this->identity_row_id();

		$this->assertSame( 1, $this->access->reindex_active_keys() );
		$first = $this->row( $row_id );

		$this->assertSame( 1, $this->access->reindex_active_keys() );

		$this->assertSame( $first['active_key'], $this->row( $row_id )['active_key'] );
		$this->assertSame( $this->org_id, $this->access->org_id_for_canonical( 'REINDEX SUBJECT' ) );
	}

	/**
	 * A resolved invitation keeps its sentinel key.
	 *
	 * `resolve()` deliberately replaces `active_key` with a random value so the
	 * unique index stops reserving that address — which is what lets somebody
	 * who declined an invitation be invited again. Recomputing it would
	 * re-reserve every address ever invited, and the second invitation would
	 * come back as "already pending" forever.
	 */
	public function test_reindex_leaves_resolved_rows_alone(): void {
		$invite = $this->access->create_invite( $this->org_id, 'resolved@example.test', 1 );

		$this->assertIsArray( $invite );

		$row_id = (int) $invite['id'];

		// resolve() only moves a row out of `processing`, which claim() is what
		// puts it into. Calling resolve() directly returns false and would have
		// left this test asserting against a still-pending row.
		$this->assertTrue( $this->access->claim( $row_id ) );
		$this->assertTrue( $this->access->resolve( $row_id, 'accepted', 1 ) );

		$sentinel = $this->active_key_of( $row_id );

		// Assert the fixture is what the test is about: a resolved row whose
		// key is a sentinel, not the lookup key for its address.
		$this->assertNotSame( '', $sentinel );

		$this->access->reindex_active_keys();

		$this->assertSame(
			$sentinel,
			$this->active_key_of( $row_id ),
			'A resolved row must keep the sentinel that frees its address.'
		);

		// The point of the sentinel: the address can be invited again.
		$again = $this->access->create_invite( $this->org_id, 'resolved@example.test', 1 );

		$this->assertIsArray( $again );
	}

	/**
	 * A pending invitation is reindexed, because its key is a real lookup key.
	 *
	 * The positive half of the status filter. Excluding too much would leave
	 * pending invitations unresolvable after a salt rotation.
	 */
	public function test_reindex_repairs_a_pending_invitation(): void {
		$invite = $this->access->create_invite( $this->org_id, 'pending@example.test', 1 );

		$this->assertIsArray( $invite );

		$row_id = (int) $invite['id'];
		$before = $this->active_key_of( $row_id );

		$this->orphan_key( $row_id );
		$this->assertNotSame( $before, $this->active_key_of( $row_id ) );

		$this->access->reindex_active_keys();

		$this->assertSame( $before, $this->active_key_of( $row_id ) );
	}

	/**
	 * One row's active_key.
	 *
	 * @param int $row_id Registry row id.
	 * @return string
	 */
	private function active_key_of( int $row_id ): string {
		global $wpdb;

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT active_key FROM %i WHERE id = %d',
				$this->access->table_name(),
				$row_id
			)
		);
	}

	/**
	 * An organization holding no identity row can still be renamed.
	 *
	 * Rows predating the registry, imported sites and seeded fixtures all
	 * produce this state, and refusing it made those organizations permanently
	 * unrenameable under an error blaming an entry that was never written.
	 */
	public function test_rename_registers_an_identity_that_was_never_written(): void {
		// Assert the fixture is the state under test before asserting on it.
		$this->assertSame( 0, $this->identity_row_id() );

		$result = $this->access->rename_identity( $this->org_id, 'REINDEX SUBJECT', 'RENAMED SUBJECT' );

		$this->assertTrue( true === $result );
		$this->assertSame( $this->org_id, $this->access->org_id_for_canonical( 'RENAMED SUBJECT' ) );
	}

	/**
	 * A name another organization holds is still refused.
	 *
	 * The negative half. Self-healing a missing registration must not become a
	 * way to take a name that is already taken.
	 */
	public function test_self_heal_cannot_claim_a_name_another_organization_holds(): void {
		$other = (int) self::factory()->post->create(
			array(
				'post_type'  => 'aggr_org',
				'post_title' => 'ALREADY TAKEN',
			)
		);

		$this->assertTrue( true === $this->access->register_identity( $other, 'ALREADY TAKEN' ) );
		$this->assertSame( 0, $this->identity_row_count_for( $this->org_id ) );

		$result = $this->access->rename_identity( $this->org_id, 'REINDEX SUBJECT', 'ALREADY TAKEN' );

		$this->assertInstanceOf( \WP_Error::class, $result );

		// And the name must still belong to whoever held it.
		$this->assertSame( $other, $this->access->org_id_for_canonical( 'ALREADY TAKEN' ) );
	}

	/**
	 * An organization registered under some other name is still refused.
	 *
	 * The guard must keep meaning something: only a *missing* registration is
	 * repaired, never a contradictory one.
	 */
	public function test_rename_refuses_when_registered_under_another_name(): void {
		$this->assertTrue( true === $this->access->register_identity( $this->org_id, 'REGISTERED NAME' ) );

		$result = $this->access->rename_identity( $this->org_id, 'SOME OTHER NAME', 'THIRD NAME' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aggr_org_identity_mismatch', $result->get_error_code() );
		$this->assertSame( 0, $this->access->org_id_for_canonical( 'THIRD NAME' ) );
	}

	/**
	 * Active identity rows held by one organization.
	 *
	 * @param int $org_id Organization id.
	 * @return int
	 */
	private function identity_row_count_for( int $org_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE org_id = %d AND kind = %s',
				$this->access->table_name(),
				$org_id,
				Org_Access_Repository::KIND_IDENTITY
			)
		);
	}

	/**
	 * Lookup keys survive an auth-salt rotation, which is the entire point.
	 *
	 * Simulated the way a real rotation presents: `wp_salt( 'auth' )` returns a
	 * different value, and nothing else about the row changes.
	 */
	public function test_lookup_survives_an_auth_salt_rotation(): void {
		$this->assertTrue( true === $this->access->register_identity( $this->org_id, 'REINDEX SUBJECT' ) );

		$rotate = static fn (): string => 'a-completely-different-auth-salt';
		add_filter( 'salt', $rotate, 10, 0 );

		try {
			$this->assertSame(
				$this->org_id,
				$this->access->org_id_for_canonical( 'REINDEX SUBJECT' ),
				'A rotated auth salt must not orphan the lookup index.'
			);
		} finally {
			remove_filter( 'salt', $rotate, 10 );
		}
	}
}
