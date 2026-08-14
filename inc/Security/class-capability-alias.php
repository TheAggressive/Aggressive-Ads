<?php
/**
 * One-release aliases for renamed capabilities.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Security;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Identity_Maps;

/**
 * Grants the new capability when a role still holds the old string, and the
 * reverse, for one release.
 *
 * The publish primitive's previous name is the one named in ADR-0022; the
 * rest of the map is the same problem at the same moment.
 */
final class Capability_Alias implements Service {

	/**
	 * Attaches the alias.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'user_has_cap', array( $this, 'alias' ), 10, 4 );
	}

	/**
	 * Mirrors each renamed capability onto its partner.
	 *
	 * @param array<string, bool> $allcaps Caps the user already holds.
	 * @param array<int, string>  $caps    Caps being asked about. Unused.
	 * @param array<int, mixed>   $args    current_user_can() arguments. Unused.
	 * @param object              $user    The user under consideration. Unused.
	 * @return array<string, bool>
	 */
	public function alias( array $allcaps, array $caps, array $args, object $user ): array {
		unset( $caps, $args, $user );

		foreach ( Identity_Maps::capabilities() as $legacy => $current ) {
			if ( ! empty( $allcaps[ $legacy ] ) ) {
				$allcaps[ $current ] = true;
			}

			if ( ! empty( $allcaps[ $current ] ) ) {
				$allcaps[ $legacy ] = true;
			}
		}

		return $allcaps;
	}
}
