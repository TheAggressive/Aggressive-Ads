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

/** Organizations this fixture guarantees, and the state each must end in. */
const AGGR_E2E_ORGS = array(
	// Sorts before "Bright Angle Media", so ascending order puts it first.
	'Apex Analytics Group' => Org_Repository::STATE_SUSPENDED,
	// Sorts after it, so descending order puts it first.
	'Zephyr Outdoor Co'    => Org_Repository::STATE_ACTIVE,
);

$aggr_e2e_access = new Org_Access_Repository();
$aggr_e2e_orgs   = new Org_Repository( $aggr_e2e_access );

/**
 * The owner every fixture organization hangs off.
 *
 * The base seed's advertiser, so no new account appears in the users list and
 * the spec's assertions about member counts stay predictable.
 */
$aggr_e2e_owner = get_user_by( 'email', 'advertiser@example.test' );

if ( ! $aggr_e2e_owner instanceof WP_User ) {
	WP_CLI::error( 'seed-organizations: the base seed has not run; no advertiser account.' );
}

foreach ( AGGR_E2E_ORGS as $aggr_e2e_name => $aggr_e2e_state ) {
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

// Roles, so the screen's capability gate resolves the way the spec assumes.
( new Roles() )->install();

WP_CLI::success( 'seed-organizations: fixture organizations are in place.' );
