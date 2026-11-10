<?php
/**
 * Whether a request looks like a member already in the organization.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Member_Name_Match;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A wrong hint is worse than none, so the refusals outnumber the matches.
 */
final class MemberNameMatchTest extends TestCase {

	private const MEMBERS = array( 'Morgan Reyes', 'Sam Okafor', 'Dana' );

	/**
	 * Requesters that are Morgan Reyes.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function members_who_match(): array {
		return array(
			'the same display name'  => array( 'Morgan Reyes', 'someone@gmail.com' ),
			'in another case'        => array( 'MORGAN REYES', 'someone@gmail.com' ),
			'the other way round'    => array( 'Reyes, Morgan', 'someone@gmail.com' ),
			'a dotted address'       => array( 'mr', 'morgan.reyes@gmail.com' ),
			'an underscored address' => array( 'mr', 'reyes_morgan@gmail.com' ),
			'run together'           => array( 'mr', 'morganreyes@gmail.com' ),
			'initial and surname'    => array( 'mr', 'mreyes@gmail.com' ),
			'with a plus tag'        => array( 'mr', 'morgan.reyes+ads@gmail.com' ),
		);
	}

	/**
	 * Requesters that are nobody the organization has.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function strangers(): array {
		return array(
			'a first name alone'      => array( 'Morgan', 'morgan@gmail.com' ),
			'a surname alone'         => array( 'Reyes', 'reyes@gmail.com' ),
			'a different person'      => array( 'Morgan Reyna', 'mreyna@gmail.com' ),
			'a partial run together'  => array( 'x', 'morganr@gmail.com' ),
			'nothing at all'          => array( '', '' ),
			'an address with no name' => array( '', '12345@gmail.com' ),
			'a single-name member'    => array( 'Dana', 'dana@gmail.com' ),
		);
	}

	#[DataProvider( 'members_who_match' )]
	public function test_a_requester_who_looks_like_a_member_is_matched( string $name, string $email ): void {
		$this->assertSame( 'Morgan Reyes', Member_Name_Match::find( $name, $email, self::MEMBERS ) );
	}

	#[DataProvider( 'strangers' )]
	public function test_anyone_else_is_not( string $name, string $email ): void {
		$this->assertNull( Member_Name_Match::find( $name, $email, self::MEMBERS ) );
	}

	public function test_the_cases_are_all_there(): void {
		$this->assertCount( 8, self::members_who_match() );
		$this->assertCount( 7, self::strangers() );
	}

	public function test_two_members_who_fit_make_no_suggestion(): void {
		$this->assertNull(
			Member_Name_Match::find( 'Morgan Reyes', 'x@gmail.com', array( 'Morgan Reyes', 'reyes morgan' ) ),
			'Naming one of two would be wrong half the time.'
		);
	}

	public function test_the_member_is_returned_exactly_as_the_organization_spells_it(): void {
		$this->assertSame( 'Morgán Reyes-Ortiz', Member_Name_Match::find( 'morgán reyes ortiz', 'x@gmail.com', array( 'Morgán Reyes-Ortiz' ) ) );
	}

	public function test_an_empty_organization_matches_nobody(): void {
		$this->assertNull( Member_Name_Match::find( 'Morgan Reyes', 'morgan.reyes@gmail.com', array() ) );
	}
}
