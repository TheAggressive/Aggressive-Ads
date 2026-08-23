<?php
/**
 * Organizations for the staff Organizations screen spec.
 *
 * The base seed creates exactly one organization, which is enough to prove the
 * table mounts and nothing else: one row cannot demonstrate sorting, cannot be
 * filtered down to a subset, and cannot collide with another organization's
 * name. So this adds two more, on either side of it alphabetically, one of them
 * suspended.
 *
 * Built through `create_for_owner()` rather than by inserting posts, because
 * that is the path production uses and the only one that also writes the
 * canonical-name meta and registers the identity row. An organization seeded as
 * a bare post has no registry entry, and the duplicate-name refusal the spec
 * asserts would not happen — the test would pass against a fixture that could
 * never behave like a real site.
 *
 * **Each one gets its own owner, and none of them is the advertiser.** A portal
 * account belongs to exactly one organization: `Campaign_Editor::create()`
 * resolves the acting organization as `org_ids_for_user()[0]` and has no way to
 * choose between several. Hanging these off the base seed's advertiser gave
 * that account three, and whichever the query returned first became the one
 * every new campaign belonged to — a suspended fixture organization, in CI,
 * which stopped the campaign wizard dead. It passed locally purely because the
 * pre-existing rows happened to sort first.
 *
 * Idempotent: running it twice reuses whatever already exists.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Repository\Org_Access_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Roles;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Organizations this fixture guarantees.
 *
 * Each entry is name => [ state, owner email, owner display name ]. The owners
 * are dedicated to this fixture and belong to nothing else, so no account ends
 * up in more than one organization.
 */
const AGGR_E2E_ORGS = array(
	// Sorts before "Bright Angle Media", so ascending order puts it first.
	'Apex Analytics Group' => array(
		Org_Repository::STATE_SUSPENDED,
		'apex-owner@example.test',
		'Rae from Apex',
	),
	// Sorts after it, so descending order puts it first.
	'Zephyr Outdoor Co'    => array(
		Org_Repository::STATE_ACTIVE,
		'zephyr-owner@example.test',
		'Ira from Zephyr',
	),
);

$aggr_e2e_access = new Org_Access_Repository();
$aggr_e2e_orgs   = new Org_Repository( $aggr_e2e_access );

foreach ( AGGR_E2E_ORGS as $aggr_e2e_name => $aggr_e2e_spec ) {
	list( $aggr_e2e_state, $aggr_e2e_email, $aggr_e2e_display ) = $aggr_e2e_spec;

	$aggr_e2e_owner = get_user_by( 'email', $aggr_e2e_email );

	if ( ! $aggr_e2e_owner instanceof WP_User ) {
		$aggr_e2e_new = wp_insert_user(
			array(
				'user_login'   => (string) strstr( $aggr_e2e_email, '@', true ),
				'user_pass'    => wp_generate_password( 24, true, true ),
				'user_email'   => $aggr_e2e_email,
				'display_name' => $aggr_e2e_display,
				'role'         => Roles::ADVERTISER,
			)
		);

		if ( is_wp_error( $aggr_e2e_new ) ) {
			WP_CLI::error(
				'seed-organizations: could not create the owner for "' . $aggr_e2e_name
					. '": ' . $aggr_e2e_new->get_error_message()
			);
		}

		$aggr_e2e_owner = get_user_by( 'id', (int) $aggr_e2e_new );
	}

	if ( ! $aggr_e2e_owner instanceof WP_User ) {
		WP_CLI::error( 'seed-organizations: no owner account for "' . $aggr_e2e_name . '".' );
	}

	$aggr_e2e_canonical = Org_Repository::canonical_name( $aggr_e2e_name );
	$aggr_e2e_id        = $aggr_e2e_access->org_id_for_canonical( $aggr_e2e_canonical );

	if ( $aggr_e2e_id <= 0 ) {
		$aggr_e2e_created = $aggr_e2e_orgs->create_for_owner(
			$aggr_e2e_name,
			(int) $aggr_e2e_owner->ID
		);

		if ( is_wp_error( $aggr_e2e_created ) ) {
			WP_CLI::error(
				'seed-organizations: could not create "' . $aggr_e2e_name . '": '
					. $aggr_e2e_created->get_error_message()
			);
		}

		$aggr_e2e_id = (int) $aggr_e2e_created;
	}

	if ( ! $aggr_e2e_orgs->set_state( $aggr_e2e_id, $aggr_e2e_state ) ) {
		WP_CLI::error( 'seed-organizations: could not set the state of "' . $aggr_e2e_name . '".' );
	}
}

/*
 * The base organization needs a registry entry too.
 *
 * `bin/dev/seed.php` inserts it as a plain post, so it has no canonical-name
 * meta and no identity row. The spec renames another organization onto its name
 * and expects a refusal, and without a registration there is nothing to collide
 * with — the write would succeed and the assertion would be testing the absence
 * of a fixture rather than the presence of a rule.
 */
$aggr_e2e_base = get_page_by_path( 'bright-angle-media', OBJECT, Post_Types::ORGANIZATION );

if ( $aggr_e2e_base instanceof WP_Post ) {
	$aggr_e2e_base_canonical = Org_Repository::canonical_name( (string) $aggr_e2e_base->post_title );

	update_post_meta(
		(int) $aggr_e2e_base->ID,
		Org_Repository::META_CANONICAL_NAME,
		$aggr_e2e_base_canonical
	);

	if ( $aggr_e2e_access->org_id_for_canonical( $aggr_e2e_base_canonical ) <= 0 ) {
		$aggr_e2e_access->register_identity( (int) $aggr_e2e_base->ID, $aggr_e2e_base_canonical );
	}
}

/*
 * The invariant this fixture broke once, asserted rather than assumed.
 *
 * `Campaign_Editor::create()` resolves the acting organization as
 * `org_ids_for_user()[0]` and cannot choose between several, so an account in
 * two organizations silently files new campaigns under whichever the query
 * returns first. When that happened here the campaign wizard stopped working
 * and the failure surfaced three specs away, in CI only, because the local
 * ordering happened to be benign.
 */
foreach ( array( 'advertiser@example.test', 'admin@example.test' ) as $aggr_e2e_check ) {
	$aggr_e2e_account = get_user_by( 'email', $aggr_e2e_check );

	if ( ! $aggr_e2e_account instanceof WP_User ) {
		continue;
	}

	$aggr_e2e_owned = $aggr_e2e_orgs->org_ids_for_user( (int) $aggr_e2e_account->ID );

	if ( count( $aggr_e2e_owned ) > 1 ) {
		WP_CLI::error(
			'seed-organizations: ' . $aggr_e2e_check . ' belongs to '
				. count( $aggr_e2e_owned ) . ' organizations. A portal account '
				. 'belongs to exactly one; fixture organizations need their own '
				. 'owners.'
		);
	}
}

// Roles, so the screen's capability gate resolves the way the spec assumes.
( new Roles() )->install();

WP_CLI::success( 'seed-organizations: fixture organizations are in place.' );
