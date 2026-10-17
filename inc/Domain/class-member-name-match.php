<?php
/**
 * Whether someone asking to join looks like a person already in the organization.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * A hint for the owner reading an access request, never a decision.
 *
 * Someone who signs up with `m.reyes@gmail.com` and asks to join "Bright Angle
 * Media" is often a colleague on a personal address. Telling the owner "this
 * looks like Morgan Reyes, who is already a member" saves them a message; it
 * must not become a reason to approve. A name is not a credential, anyone can
 * type one, and so this returns a *suggestion* for a screen to word as one.
 * Nothing in the workflow reads it, which is deliberate.
 *
 * Conservative on purpose, because a wrong suggestion is worse than none:
 *
 * - a member needs two or more name parts, so "Morgan" alone never matches;
 * - the requester must match all of them, in either order, or write them the
 *   two ways people write an address (`morganreyes`, `mreyes`);
 * - more than one member matching is no match, since the hint would name the
 *   wrong person half the time.
 *
 * No WordPress function is called here.
 */
final class Member_Name_Match {

	/**
	 * The member a requester most plausibly is, or null.
	 *
	 * @param string             $display_name The requester's display name.
	 * @param string             $email        The requester's email address.
	 * @param array<int, string> $members      Display names of the organization's members.
	 * @return string|null One member's display name, exactly as given, or null.
	 */
	public static function find( string $display_name, string $email, array $members ): ?string {
		$shapes = self::requester_shapes( $display_name, $email );

		if ( array() === $shapes ) {
			return null;
		}

		$found = null;

		foreach ( $members as $member ) {
			$parts = self::parts( $member );

			if ( count( $parts ) < 2 ) {
				continue;
			}

			if ( ! self::matches( $parts, $shapes ) ) {
				continue;
			}

			// A second match makes the hint a coin toss. Say nothing instead.
			if ( null !== $found ) {
				return null;
			}

			$found = $member;
		}

		return $found;
	}

	/**
	 * Every way the requester's own details could spell a name.
	 *
	 * @param string $display_name Display name.
	 * @param string $email        Address.
	 * @return array<int, array{parts: array<int, string>, joined: string}>
	 */
	private static function requester_shapes( string $display_name, string $email ): array {
		$at    = strpos( $email, '@' );
		$local = false === $at ? '' : substr( $email, 0, $at );
		$plus  = strpos( $local, '+' );
		// `morgan.reyes+ads` is `morgan.reyes`: the tag is the sender's, not a name.
		$local  = false === $plus ? $local : substr( $local, 0, $plus );
		$shapes = array();

		foreach ( array( $display_name, $local ) as $source ) {
			$parts = self::parts( $source );

			if ( array() !== $parts ) {
				$shapes[] = array(
					'parts'  => $parts,
					'joined' => implode( '', $parts ),
				);
			}
		}

		return $shapes;
	}

	/**
	 * Whether any of the requester's shapes is this member.
	 *
	 * @param array<int, string>                                           $member Member name parts, at least two.
	 * @param array<int, array{parts: array<int, string>, joined: string}> $shapes Requester shapes.
	 * @return bool
	 */
	private static function matches( array $member, array $shapes ): bool {
		$sorted  = $member;
		$joined  = implode( '', $member );
		$initial = mb_substr( $member[0], 0, 1 ) . $member[ count( $member ) - 1 ];

		sort( $sorted );

		foreach ( $shapes as $shape ) {
			$other = $shape['parts'];

			sort( $other );

			// The same parts in either order: Morgan Reyes, Reyes Morgan, morgan.reyes.
			if ( $other === $sorted ) {
				return true;
			}

			// `morganreyes`, `mreyes`: one run of letters, the way an address is written.
			if ( 1 === count( $shape['parts'] ) && in_array( $shape['joined'], array( $joined, $initial ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A name or address's letters and digits, lowercased, split where people split.
	 *
	 * @param string $value A display name or the part of an address before `@`.
	 * @return array<int, string>
	 */
	private static function parts( string $value ): array {
		$split = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $value ), -1, PREG_SPLIT_NO_EMPTY );

		return false === $split ? array() : $split;
	}
}
