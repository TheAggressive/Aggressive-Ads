<?php
/**
 * User lookup persistence.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Repository;

use WP_User;

/**
 * Resolves users for capability-driven application work.
 */
final class User_Repository {
	/** Number of users held in memory while resolving capability filters. */
	private const BATCH_SIZE = 200;

	/**
	 * Every current user who actually holds a capability.
	 *
	 * Resolution deliberately runs user_can() for every account, in bounded
	 * batches. WP_User_Query's capability argument sees stored roles and direct
	 * grants but cannot see a capability supplied by the `user_has_cap` filter;
	 * narrowing on it would silently omit a legitimate dynamically granted
	 * reviewer. Submission is not a hot read path, so correctness wins here.
	 *
	 * @param string $capability Primitive capability.
	 * @return array<int, array{id: int, email: string}>
	 */
	public function with_capability( string $capability ): array {
		if ( '' === trim( $capability ) ) {
			return array();
		}

		$rows   = array();
		$offset = 0;
		$count  = 0;

		do {
			$users = get_users(
				array(
					'number'  => self::BATCH_SIZE,
					'offset'  => $offset,
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);

			foreach ( $users as $user ) {
				if ( ! $user instanceof WP_User || $user->ID <= 0 || ! user_can( $user, $capability ) ) {
					continue;
				}

				$rows[] = array(
					'id'    => (int) $user->ID,
					'email' => (string) $user->user_email,
				);
			}

			$count   = count( $users );
			$offset += $count;
		} while ( self::BATCH_SIZE === $count );

		return $rows;
	}
}
