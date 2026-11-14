<?php
/**
 * Hostile HTML and CSS cannot acquire URL, script or browsing-context authority.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Workflow;

use Aggressive\Ads\Workflow\Html5_Budget;
use Aggressive\Ads\Workflow\Html5_Compiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Html5CompilerTest extends TestCase {

	public function test_compiles_local_styles_and_escapes_text_without_importing_input_policy(): void {
		$files    = array(
			'index.html' => '<!doctype html><html lang="en"><head><link rel="stylesheet" href="local.css"></head><body><div class="banner">A &amp; B &lt;script&gt;</div></body></html>',
			'local.css'  => '.banner { color: red; width: 100%; display: flex; }',
		);
		$document = ( new Html5_Compiler() )->compile( $files, array(), new Html5_Budget() );
		$this->assertStringContainsString( 'Content-Security-Policy', $document );
		$this->assertStringContainsString( 'A &amp; B &lt;script&gt;', $document );
		$this->assertStringContainsString( '.banner{color:red;width:100%;display:flex;}', $document );
		$this->assertStringNotContainsString( '<link', $document );
		$this->assertStringNotContainsString( '<script', $document );
	}

	#[DataProvider( 'hostile_documents' )]
	public function test_refuses_unsupported_authority_instead_of_silently_stripping_it( string $html ): void {
		$this->expectException( \RuntimeException::class );
		( new Html5_Compiler() )->compile( array( 'index.html' => $html ), array(), new Html5_Budget() );
	}

	/**
	 * Concrete browser attack syntax rather than a copy of the compiler's allowlists.
	 *
	 * @return array<string,array{string}>
	 */
	public static function hostile_documents(): array {
		return array(
			'script and STUN'        => array( '<script>new RTCPeerConnection()</script>' ),
			'event handler'          => array( '<div onclick="fetch(\'https://example.com\')">Ad</div>' ),
			'refresh'                => array( '<meta http-equiv="refresh" content="0;url=https://example.com">' ),
			'input CSP'              => array( '<meta http-equiv="Content-Security-Policy" content="default-src *">' ),
			'svg namespace'          => array( '<svg><foreignObject><div>Ad</div></foreignObject></svg>' ),
			'frame'                  => array( '<iframe srcdoc="<script>alert(1)</script>"></iframe>' ),
			'form'                   => array( '<form action="https://example.com"><input name="token"></form>' ),
			'remote image'           => array( '<img src="https://example.com/tracker" alt="">' ),
			'encoded traversal'      => array( '<img src="%2e%2e/a.png" alt="">' ),
			'inline data image'      => array( '<img src="data:image/svg+xml,test" alt="">' ),
			'candidate sources'      => array( '<img src="a.png" srcset="https://example.com/track 2x" alt="">' ),
			'DNS hint'               => array( '<link rel="dns-prefetch" href="//example.com">' ),
			'CSS network'            => array( '<style>div{background-image:url(https://example.com/track)}</style>' ),
			'CSS import'             => array( '<style>@import "https://example.com/track";</style>' ),
			'CSS escape'             => array( '<div style="background:u\\72l(https://example.com)">Ad</div>' ),
			'CSS animation'          => array( '<style>@keyframes ping{from{opacity:0}to{opacity:1}}</style>' ),
			'style breakout'         => array( '<style>div{color:red}</style><script>alert(1)</script>' ),
			'raw text parser'        => array( '<xmp><img src="https://example.com"></xmp>' ),
			'HTML template'          => array( '<template><img src="https://example.com"></template>' ),
			'public doctype'         => array( '<!DOCTYPE html SYSTEM "https://example.com/entities"><div>Ad</div>' ),
			'processing instruction' => array( '<?php echo 1; ?><div>Ad</div>' ),
			'download'               => array( '<a download href="https://example.com">Ad</a>' ),
			'deep tree'              => array( str_repeat( '<div>', 40 ) . 'Ad' . str_repeat( '</div>', 40 ) ),
			'node budget'            => array( str_repeat( '<br>', 4097 ) ),
			'invalid UTF-8'          => array( "<div>\xc3\x28</div>" ),
		);
	}
}
