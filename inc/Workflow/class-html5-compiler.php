<?php
/**
 * Compile the static profile into a self-contained document with no URL authority.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Html5_Profile;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP defines the native DOM property names used throughout this tree walker.
/** Rebuild an allowlisted tree; never serialize the hostile input tree as output. */
final class Html5_Compiler {

	private const ELEMENTS   = array( 'html', 'head', 'body', 'title', 'div', 'span', 'p', 'br', 'strong', 'em', 'b', 'i', 'small', 'h1', 'h2', 'h3', 'ul', 'ol', 'li', 'img', 'style', 'link', 'meta' );
	private const PROPERTIES = array( 'display', 'position', 'top', 'right', 'bottom', 'left', 'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'box-sizing', 'overflow', 'color', 'background-color', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-align', 'text-decoration', 'text-transform', 'white-space', 'border', 'border-width', 'border-style', 'border-color', 'border-radius', 'opacity', 'vertical-align', 'flex', 'flex-direction', 'flex-wrap', 'align-items', 'align-self', 'justify-content', 'gap', 'order' );

	/**
	 * All local raster members have already passed the shared raster inspector.
	 *
	 * @param array<string,string> $files Inspected archive members.
	 * @param array<string,string> $images Local member => verified data image URI.
	 * @param Html5_Budget         $budget Entire ingest budget.
	 * @throws \RuntimeException Unsupported input or exceeded work budget.
	 */
	public function compile( array $files, array $images, Html5_Budget $budget ): string {
		if ( ! class_exists( \Dom\HTMLDocument::class ) ) {
			throw new \RuntimeException( 'aggr_html5_unavailable' );
		}
		foreach ( $files as $name => $bytes ) {
			$budget->check();
			if ( str_ends_with( $name, '.css' ) ) {
				$this->stylesheet( $bytes );
			}
		}
		$source = $files['index.html'] ?? '';
		if ( '' === $source || substr_count( $source, '<' ) > Html5_Profile::MAX_NODES || ! mb_check_encoding( $source, 'UTF-8' ) || 1 === preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $source ) || str_contains( $source, '<?' ) ) {
			throw new \RuntimeException( 'aggr_html5_document' );
		}
		$previous = libxml_use_internal_errors( true );
		try {
			// The HTML5 string parser does not resolve external resources and accepts no NONET flag.
			$document = \Dom\HTMLDocument::createFromString( $source, LIBXML_COMPACT, 'UTF-8' );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		if ( null !== $document->doctype && ( 'html' !== strtolower( $document->doctype->name ) || '' !== $document->doctype->publicId || '' !== $document->doctype->systemId ) ) {
			throw new \RuntimeException( 'aggr_html5_document' );
		}
		$count = 0;
		$body  = $this->node( $document->documentElement, $files, $images, $budget, $count, 0 );
		$budget->check();
		if ( strlen( $body ) + 15 > Html5_Profile::MAX_BYTES ) {
			throw new \RuntimeException( 'aggr_html5_document' );
		}
		return '<!doctype html>' . $body;
	}

	/**
	 * Build output only from known HTML-namespace nodes and individual safe attributes.
	 *
	 * @param \Dom\Node|null       $node Parsed input node.
	 * @param array<string,string> $files Inspected members.
	 * @param array<string,string> $images Verified raster URIs.
	 * @param Html5_Budget         $budget Entire ingest budget.
	 * @param int                  $count Visited nodes.
	 * @param int                  $depth Current nesting.
	 * @throws \RuntimeException Unsupported input or exceeded work budget.
	 */
	private function node( ?\Dom\Node $node, array $files, array $images, Html5_Budget $budget, int &$count, int $depth ): string {
		$budget->check();
		if ( null === $node || ++$count > Html5_Profile::MAX_NODES || $depth > Html5_Profile::MAX_DEPTH ) {
			throw new \RuntimeException( 'aggr_html5_document' );
		}
		if ( $node instanceof \Dom\Text ) {
			return self::escape( $node->textContent ?? '' );
		}
		if ( $node instanceof \Dom\Comment ) {
			return '';
		}
		if ( ! $node instanceof \Dom\Element || 'http://www.w3.org/1999/xhtml' !== $node->namespaceURI || ! in_array( $node->localName, self::ELEMENTS, true ) ) {
			throw new \RuntimeException( 'aggr_html5_document' );
		}
		$tag        = $node->localName;
		$attributes = array();
		foreach ( $node->attributes as $attribute ) {
			if ( null !== $attribute->namespaceURI || strlen( $attribute->value ) > 4096 ) {
				throw new \RuntimeException( 'aggr_html5_document' );
			}
			$attributes[ $attribute->name ] = $attribute->value;
		}
		if ( 'meta' === $tag ) {
			if ( array( 'charset' => 'utf-8' ) !== array_map( 'strtolower', $attributes ) ) {
				throw new \RuntimeException( 'aggr_html5_document' );
			}
			return '';
		}
		if ( 'link' === $tag ) {
			if ( count( $attributes ) !== 2 || ( $attributes['rel'] ?? '' ) !== 'stylesheet' || ! isset( $attributes['href'] ) || ! Html5_Profile::path( $attributes['href'] ) || ! str_ends_with( $attributes['href'], '.css' ) || ! isset( $files[ $attributes['href'] ] ) ) {
				throw new \RuntimeException( 'aggr_html5_document' );
			}
			return '<style>' . $this->stylesheet( $files[ $attributes['href'] ] ) . '</style>';
		}
		if ( 'style' === $tag ) {
			if ( array() !== $attributes ) {
				throw new \RuntimeException( 'aggr_html5_document' );
			}
			return '<style>' . $this->stylesheet( $node->textContent ?? '' ) . '</style>';
		}
		$out = '<' . $tag;
		foreach ( $attributes as $key => $value ) {
			$clean = match ( $key ) {
				'class', 'id' => ( 1 === preg_match( '/\A[a-zA-Z0-9_-]+(?: [a-zA-Z0-9_-]+)*\z/', $value ) ? $value : null ),
				'lang' => ( 'html' === $tag && 1 === preg_match( '/\A[a-zA-Z-]{2,35}\z/', $value ) ? $value : null ),
				'style' => $this->declarations( $value ),
				'alt' => ( 'img' === $tag ? $value : null ),
				'width', 'height' => ( 'img' === $tag && 1 === preg_match( '/\A[1-9][0-9]{0,3}\z/', $value ) ? $value : null ),
				'src' => ( 'img' === $tag && Html5_Profile::path( $value ) ? ( $images[ $value ] ?? null ) : null ),
				default => null,
			};
			if ( null === $clean ) {
				throw new \RuntimeException( 'aggr_html5_document' );
			}
			$out .= ' ' . $key . '="' . self::escape( $clean ) . '"';
		}
		if ( 'img' === $tag && ( ! isset( $attributes['src'], $attributes['alt'] ) ) ) {
			throw new \RuntimeException( 'aggr_html5_document' );
		}
		$out .= '>';
		if ( in_array( $tag, array( 'img', 'br' ), true ) ) {
			return $out;
		}
		if ( 'head' === $tag ) {
			$out .= '<meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="' . self::escape( Html5_Profile::POLICY ) . '"><meta name="referrer" content="no-referrer"><style>html,body{margin:0;width:100%;height:100%;overflow:hidden}*{box-sizing:border-box}</style>';
		}
		foreach ( $node->childNodes as $child ) {
			$out .= $this->node( $child, $files, $images, $budget, $count, $depth + 1 );
			if ( strlen( $out ) > Html5_Profile::MAX_BYTES ) {
				throw new \RuntimeException( 'aggr_html5_document' );
			}
		}
		return $out . '</' . $tag . '>';
	}

	/**
	 * A deliberately small CSS grammar: no at-rules, functions, escapes or URL-bearing properties.
	 *
	 * @param string $css Local stylesheet.
	 * @throws \RuntimeException Unsupported input or exceeded work budget.
	 */
	private function stylesheet( string $css ): string {
		if ( strlen( $css ) > 65536 || 1 === preg_match( '/[^a-zA-Z0-9_\s.#%,:+;{}-]/', $css ) ) {
			throw new \RuntimeException( 'aggr_html5_style' );
		}
		$out = '';
		foreach ( explode( '}', trim( $css ) ) as $rule ) {
			if ( '' === trim( $rule ) ) {
				continue;
			}
			$parts = explode( '{', $rule );
			if ( count( $parts ) !== 2 || 1 !== preg_match( '/\A\s*[.#]?[a-zA-Z][a-zA-Z0-9_-]*(?:\s*,\s*[.#]?[a-zA-Z][a-zA-Z0-9_-]*)*\s*\z/', $parts[0] ) ) {
				throw new \RuntimeException( 'aggr_html5_style' );
			}
			$out .= trim( $parts[0] ) . '{' . $this->declarations( $parts[1] ) . '}';
		}
		return $out;
	}

	/**
	 * Property allowlist plus values without string, function or URL syntax.
	 *
	 * @param string $style Inline declarations.
	 * @throws \RuntimeException Unsupported input or exceeded work budget.
	 */
	private function declarations( string $style ): string {
		if ( strlen( $style ) > 4096 ) {
			throw new \RuntimeException( 'aggr_html5_style' );
		}
		$out = '';
		foreach ( explode( ';', $style ) as $declaration ) {
			if ( '' === trim( $declaration ) ) {
				continue;
			}
			$parts = explode( ':', $declaration );
			if ( count( $parts ) !== 2 || ! in_array( trim( $parts[0] ), self::PROPERTIES, true ) || strlen( $parts[1] ) > 250
				|| 1 !== preg_match( '/\A\s*[a-zA-Z0-9.#%+-]+(?:\s+[a-zA-Z0-9.#%+-]+){0,3}\s*\z/', $parts[1] ) ) {
				throw new \RuntimeException( 'aggr_html5_style' );
			}
			$out .= trim( $parts[0] ) . ':' . trim( $parts[1] ) . ';';
		}
		return $out;
	}

	/**
	 * Both text and attribute values are rebuilt, including quotes and raw-text delimiters.
	 *
	 * @param string $value Parsed UTF-8 value.
	 */
	private static function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
