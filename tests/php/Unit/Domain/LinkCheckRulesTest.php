<?php
/**
 * What the link check may fetch.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Link_Check_Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The negatives are the point.
 *
 * This gate exists to stop one request reaching the inside of the host's
 * network, so the refusals below are the test: an allowed URL that should have
 * been refused is a vulnerability, while a refused URL that should have been
 * allowed is an advertiser pressing a button and being told to check by hand.
 */
final class LinkCheckRulesTest extends TestCase {

	/**
	 * URLs that must never be fetched, and which rule stops each one.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function refused(): array {
		return array(
			'loopback name'        => array( 'http://localhost/health', Link_Check_Rules::REFUSE_HOST ),
			'loopback address'     => array( 'http://127.0.0.1/', Link_Check_Rules::REFUSE_ADDRESS ),
			'loopback decimal'     => array( 'http://2130706433/', Link_Check_Rules::REFUSE_HOST ),
			'private address'      => array( 'https://192.168.1.1/admin', Link_Check_Rules::REFUSE_ADDRESS ),
			'link-local metadata'  => array( 'http://169.254.169.254/latest/meta-data/', Link_Check_Rules::REFUSE_ADDRESS ),
			'ipv6 loopback'        => array( 'http://[::1]/', Link_Check_Rules::REFUSE_ADDRESS ),
			'ipv6 unique local'    => array( 'http://[fd00::1]/', Link_Check_Rules::REFUSE_ADDRESS ),
			'public address'       => array( 'https://93.184.216.34/', Link_Check_Rules::REFUSE_ADDRESS ),
			'internal suffix'      => array( 'https://wiki.internal/page', Link_Check_Rules::REFUSE_HOST ),
			'mdns suffix'          => array( 'https://printer.local/', Link_Check_Rules::REFUSE_HOST ),
			'localhost suffix'     => array( 'https://app.localhost/', Link_Check_Rules::REFUSE_HOST ),
			'single label'         => array( 'http://intranet/', Link_Check_Rules::REFUSE_HOST ),
			'trailing dot on name' => array( 'http://localhost./', Link_Check_Rules::REFUSE_HOST ),
			'credentials'          => array( 'https://user:pass@example.com/', Link_Check_Rules::REFUSE_CREDENTIALS ),
			'user only'            => array( 'https://admin@example.com/', Link_Check_Rules::REFUSE_CREDENTIALS ),
			'other port'           => array( 'https://example.com:8080/', Link_Check_Rules::REFUSE_PORT ),
			'redis port'           => array( 'https://example.com:6379/', Link_Check_Rules::REFUSE_PORT ),
			'file scheme'          => array( 'file:///etc/passwd', Link_Check_Rules::REFUSE_MALFORMED ),
			'gopher scheme'        => array( 'gopher://example.com/', Link_Check_Rules::REFUSE_SCHEME ),
			'javascript scheme'    => array( 'javascript:alert(1)', Link_Check_Rules::REFUSE_MALFORMED ),
			'data scheme'          => array( 'data:text/html,<b>x</b>', Link_Check_Rules::REFUSE_MALFORMED ),
			'newline injection'    => array( "https://example.com/\r\nHost: internal", Link_Check_Rules::REFUSE_MALFORMED ),
			'null byte'            => array( "https://example.com/\0", Link_Check_Rules::REFUSE_MALFORMED ),
			'empty'                => array( '', Link_Check_Rules::REFUSE_MALFORMED ),
			'no host'              => array( 'https:///path', Link_Check_Rules::REFUSE_MALFORMED ),
			'relative'             => array( '/campaigns/1', Link_Check_Rules::REFUSE_MALFORMED ),
		);
	}

	#[DataProvider( 'refused' )]
	public function test_it_refuses_what_must_not_be_fetched( string $url, string $reason ): void {
		$this->assertSame( $reason, Link_Check_Rules::refuse( $url ), $url );
	}

	/**
	 * Ordinary destinations, which have to keep working.
	 *
	 * @return array<string, array{string}>
	 */
	public static function allowed(): array {
		return array(
			'plain https'      => array( 'https://example.com/' ),
			'no path'          => array( 'https://example.com' ),
			'http'             => array( 'http://example.com/offer' ),
			'explicit 443'     => array( 'https://example.com:443/offer' ),
			'explicit 80'      => array( 'http://example.com:80/offer' ),
			'query and hash'   => array( 'https://shop.example.co.uk/a?utm_source=x#top' ),
			'subdomain'        => array( 'https://www.example.com/landing/page' ),
			'uppercase host'   => array( 'HTTPS://Example.COM/Landing' ),
			'trailing dot'     => array( 'https://example.com./' ),
			'internal in path' => array( 'https://example.com/internal/local' ),
		);
	}

	#[DataProvider( 'allowed' )]
	public function test_it_allows_an_ordinary_destination( string $url ): void {
		$this->assertSame( '', Link_Check_Rules::refuse( $url ), $url );
	}

	public function test_resolved_addresses_are_judged_after_dns(): void {
		// A public name answering with a private address is the rebinding case.
		$this->assertFalse( Link_Check_Rules::is_public_address( '127.0.0.1' ) );
		$this->assertFalse( Link_Check_Rules::is_public_address( '10.0.0.5' ) );
		$this->assertFalse( Link_Check_Rules::is_public_address( '172.16.0.1' ) );
		$this->assertFalse( Link_Check_Rules::is_public_address( '192.168.0.1' ) );
		$this->assertFalse( Link_Check_Rules::is_public_address( '169.254.169.254' ) );
		$this->assertFalse( Link_Check_Rules::is_public_address( '::1' ) );
		$this->assertFalse( Link_Check_Rules::is_public_address( 'fd00::1' ) );
		$this->assertFalse( Link_Check_Rules::is_public_address( 'not-an-address' ) );
		$this->assertFalse( Link_Check_Rules::is_public_address( '' ) );

		$this->assertTrue( Link_Check_Rules::is_public_address( '93.184.216.34' ) );
		$this->assertTrue( Link_Check_Rules::is_public_address( '2606:2800:220:1:248:1893:25c8:1946' ) );
	}

	/**
	 * Status codes and what they mean to an advertiser.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function outcomes(): array {
		return array(
			'ok'           => array( 200, Link_Check_Rules::OUTCOME_WORKS ),
			'created'      => array( 201, Link_Check_Rules::OUTCOME_WORKS ),
			'moved'        => array( 301, Link_Check_Rules::OUTCOME_WORKS ),
			'not modified' => array( 304, Link_Check_Rules::OUTCOME_WORKS ),
			'unauthorized' => array( 401, Link_Check_Rules::OUTCOME_PRIVATE ),
			'forbidden'    => array( 403, Link_Check_Rules::OUTCOME_PRIVATE ),
			'method'       => array( 405, Link_Check_Rules::OUTCOME_PRIVATE ),
			'rate limited' => array( 429, Link_Check_Rules::OUTCOME_PRIVATE ),
			'not found'    => array( 404, Link_Check_Rules::OUTCOME_MISSING ),
			'gone'         => array( 410, Link_Check_Rules::OUTCOME_MISSING ),
			'teapot'       => array( 418, Link_Check_Rules::OUTCOME_BROKEN ),
			'server error' => array( 500, Link_Check_Rules::OUTCOME_BROKEN ),
			'bad gateway'  => array( 502, Link_Check_Rules::OUTCOME_BROKEN ),
			'no answer'    => array( 0, Link_Check_Rules::OUTCOME_UNREACHABLE ),
		);
	}

	#[DataProvider( 'outcomes' )]
	public function test_it_reads_a_status_code( int $status, string $outcome ): void {
		$this->assertSame( $outcome, Link_Check_Rules::outcome( $status ) );
	}
}
