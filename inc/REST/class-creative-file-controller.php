<?php
/**
 * Serving private creative bytes.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

use Aggressive\Ads\Domain\Preview_Frame;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Repository\Creative_Attachment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Storage\Private_Storage;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The authorized read path for unapproved creative.
 *
 * The highest-value endpoint in the system, so its contract is explicit:
 *
 * **It streams bytes and never redirects.** A redirect hands the caller a URL
 * that outlives their session and can be pasted anywhere, which turns
 * authorization into a one-time check on a permanent capability. Streaming
 * keeps every single request authorized.
 *
 * **Unauthorized reads return 404, not 403.** A 403 on a real id and a 404 on
 * a fake one is a working object-id oracle: an attacker enumerates the id
 * space, learns which creatives exist, and from that how many customers you
 * have and when they onboarded. Both answers here are identical.
 *
 * **The Content-Type comes from an allowlist, never from stored data.** The
 * stored value was derived from the file at upload, but serving a stored
 * string as a header means one bad write becomes a content-type the browser
 * will execute.
 *
 * See docs/rest-api.md and docs/threat-model.md.
 */
final class Creative_File_Controller implements Service {


	/**
	 * Registers a route on the plugin namespace.
	 *
	 * @param non-falsy-string         $route Route pattern.
	 * @param array<int|string, mixed> $args  register_rest_route() arguments.
	 * @return void
	 */
	public static function register_route( string $route, array $args ): void {
		register_rest_route( Api::NAMESPACE, $route, $args );
	}

	/**
	 * Stored relative path to serve once the response has been approved.
	 *
	 * Held on the instance rather than passed through the response, because a
	 * response header is sent to the client and the private root is the one
	 * secret protecting unapproved artwork.
	 *
	 * Relative rather than absolute so that the containment check in
	 * `Private_Storage::resolve()` runs again at the moment the bytes are
	 * read, instead of the streaming half trusting a path the authorizing
	 * half resolved on an earlier hook.
	 *
	 * @var string
	 */
	private string $pending_path = '';

	/**
	 * The preview document waiting to be emitted, if any.
	 *
	 * Held the way `$pending_path` is, and for the same reason: the response
	 * body is not JSON, so it is written by the serve hook rather than
	 * returned.
	 *
	 * @var string
	 */
	private string $pending_document = '';

	/**
	 * Constructor.
	 *
	 * @param Creative_Repository            $creatives Creative persistence.
	 * @param Private_Storage                $storage   Private file storage.
	 * @param Creative_Attachment_Repository $attachments Where an approved creative's bytes live.
	 */
	public function __construct(
		private readonly Creative_Repository $creatives,
		private readonly Private_Storage $storage,
		private readonly Creative_Attachment_Repository $attachments
	) {
	}

	/**
	 * Attaches the route and the streaming hook.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 10, 2 );
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		self::register_route(
			'/creatives/(?P<id>\d+)/preview',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),
				),
			)
		);

		self::register_route(
			'/creatives/(?P<id>\d+)/file',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),
				),
			)
		);
	}

	/**
	 * A document holding one creative, for a frame to render.
	 *
	 * **Ours, not the browser's.** Handed an image to render on its own, a
	 * browser builds a viewer document around it, with inline styles and a
	 * script of its own; the policy on those bytes correctly refuses both, and
	 * says so in the console sixty-seven times per preview. This is the
	 * document that frame loads instead: one `img`, no script, and the same
	 * authorization as the bytes it points at — checked here so a document is
	 * never produced for a creative the caller may not see.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function preview( WP_REST_Request $request ) {
		$creative_id = (int) $request->get_param( 'id' );

		/*
		 * The same one answer every failure gets below: not a creative, no
		 * such id, not yours. Distinguishing them is what builds the oracle.
		 */
		if ( ! current_user_can( 'read_aggr_creative', $creative_id ) ) {
			return new WP_Error( 'aggr_not_found', __( 'Not found.', 'aggressive-ads' ), array( 'status' => 404 ) );
		}

		/*
		 * **Where the bytes are depends on whether they were approved.**
		 * Promotion copies the artwork into the Media Library and may clear
		 * the private stage, so an approved creative has no private file to
		 * stream — the same order the card's own thumbnail uses.
		 */
		$promoted = $this->attachments->attachment_url( $creative_id );
		$source   = '' !== $promoted
			? $promoted
			: add_query_arg(
				'_wpnonce',
				wp_create_nonce( 'wp_rest' ),
				rest_url( Api::NAMESPACE . '/creatives/' . $creative_id . '/file' )
			);

		if ( '' === $promoted && is_wp_error( $this->prepare( $creative_id ) ) ) {
			return new WP_Error( 'aggr_not_found', __( 'Not found.', 'aggressive-ads' ), array( 'status' => 404 ) );
		}

		$document = sprintf(
			'<!DOCTYPE html><html lang="%1$s"><head><meta charset="utf-8"><title>%2$s</title>'
			. '<style>html,body{margin:0;height:100%%;display:flex;align-items:center;justify-content:center;background:#fff}'
			. 'img{max-width:100%%;height:auto;display:block}</style></head>'

			/*
			 * `alt=""`: the frame carries the accessible name — "Preview of
			 * the ad for Header" — and the creative's own description is on
			 * the card beside it. A second name here would be read twice, and
			 * inventing one from a filename is worse than none.
			 */
			. '<body><img src="%3$s" alt=""></body></html>',
			esc_attr( str_replace( '_', '-', (string) get_locale() ) ),
			esc_html__( 'Advertisement preview', 'aggressive-ads' ),
			esc_url( $source )
		);

		$response = new WP_REST_Response( null, 200 );

		$response->header( 'Content-Type', 'text/html; charset=utf-8' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$response->header( 'Referrer-Policy', 'no-referrer' );
		$response->header( 'X-Frame-Options', 'SAMEORIGIN' );
		$response->header(
			'Content-Security-Policy',
			Preview_Frame::document_policy( (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( home_url(), PHP_URL_HOST ) )
		);

		$this->pending_document = $document;

		return $response;
	}

	/**
	 * Whether the caller may use this feature at all.
	 *
	 * Deliberately feature-level, not object-level. An object-level denial here
	 * would produce a 403 that distinguishes a real creative from an imaginary
	 * one; the object check happens in the handler, where both answers are 404.
	 *
	 * @return bool
	 */
	public function permission(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::ACCESS_PORTAL );
	}

	/**
	 * Authorizes the request and prepares the response.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function handle( WP_REST_Request $request ) {
		$creative_id = (int) $request->get_param( 'id' );

		$prepared = $this->prepare( $creative_id );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$this->pending_path = $prepared['path'];

		$response = new WP_REST_Response( null, 200 );

		$response->header( 'Content-Type', $prepared['mime'] );
		$response->header( 'Content-Length', (string) $prepared['bytes'] );
		$response->header( 'Content-Disposition', sprintf( 'inline; filename="%s"', $prepared['filename'] ) );

		// Sniffing is how a file served as one type gets executed as another.
		$response->header( 'X-Content-Type-Options', 'nosniff' );

		// Never shared, never stored: this is one organization's unpublished
		// artwork, and a cache between here and them does not know that.
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$response->header( 'Referrer-Policy', 'no-referrer' );

		/*
		 * **Untrusted rendering, and P17 says so.** A reviewer's browser is
		 * not a safer place to run a creative than a visitor's, so the bytes
		 * are served under a policy that permits the image and nothing else:
		 * no script, no style, no fetch, no frame of its own, no form, and no
		 * embedding by another site. `sandbox` covers the case where this
		 * response is the document — opened directly, or framed for a device
		 * preview — where the attribute alone would not be enough.
		 */
		$response->header( 'Content-Security-Policy', Preview_Frame::POLICY );

		// For the browsers that still read the older header instead.
		$response->header( 'X-Frame-Options', 'SAMEORIGIN' );

		return $response;
	}

	/**
	 * Resolves and authorizes a creative's file.
	 *
	 * Separated from the response so the decision — which is the part with
	 * security consequences — is testable without emitting bytes.
	 *
	 * @param int $creative_id Creative post id.
	 * @return array{path: string, mime: string, filename: string, bytes: int}|WP_Error Path is relative to the private root.
	 */
	public function prepare( int $creative_id ) {
		/*
		 * One answer for every failure below: not a creative, no such id, not
		 * yours, no file on disk. Distinguishing them is what builds the
		 * oracle.
		 */
		$denied = new WP_Error(
			'aggr_not_found',
			__( 'Not found.', 'aggressive-ads' ),
			array( 'status' => 404 )
		);

		if ( ! current_user_can( 'read_aggr_creative', $creative_id ) ) {
			return $denied;
		}

		$details = $this->creatives->storage_details( $creative_id );

		if ( null === $details || '' === $details['path'] ) {
			return $denied;
		}

		$path = $this->storage->resolve( $details['path'] );

		if ( null === $path ) {
			return $denied;
		}

		$mime = $this->safe_content_type( $details['mime'] );

		if ( '' === $mime ) {
			return $denied;
		}

		// The plaintext length, not the file's. Stored creative is encrypted, so
		// filesize() describes the ciphertext and would send a Content-Length
		// the body cannot match.
		$bytes = $this->storage->plaintext_bytes( $details['path'] );

		return array(
			'path'     => $details['path'],
			'mime'     => $mime,
			'filename' => $this->safe_filename( $details['name'], $mime ),
			'bytes'    => null === $bytes ? 0 : $bytes,
		);
	}

	/**
	 * Streams the bytes, taking over from the JSON serializer.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_HTTP_Response $result  The response.
	 * @return bool
	 */
	public function serve( bool $served, WP_HTTP_Response $result ): bool {
		if ( $served ) {
			return $served;
		}

		if ( '' !== $this->pending_document ) {
			$document = $this->pending_document;

			// Cleared before emitting, as the path below is, so a later
			// response in the same request cannot inherit it.
			$this->pending_document = '';

			if ( 200 !== $result->get_status() ) {
				return $served;
			}

			echo $document; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built in preview() from escaped parts; it is the response body, not a fragment.

			return true;
		}

		if ( '' === $this->pending_path ) {
			return $served;
		}

		$path = $this->pending_path;

		// Cleared before emitting, so a later response in the same request
		// cannot inherit a path somebody else was authorized for.
		$this->pending_path = '';

		if ( 200 !== $result->get_status() ) {
			return $served;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The response body. Opened as a stream so the creative is decrypted a chunk at a time rather than assembled in memory.
		$out = fopen( 'php://output', 'wb' );

		if ( false === $out ) {
			return $served;
		}

		// A failure here is a file that could not be decrypted — a lost key, a
		// truncated file, a tampered one. The headers are already sent, so
		// there is nothing to turn it into: the body simply stops, which is the
		// honest outcome and is what a client sees as a broken transfer.
		$this->storage->copy_to( $path, $out );

		fclose( $out );

		return true;
	}

	/**
	 * The Content-Type to send, from our allowlist rather than from storage.
	 *
	 * @param string $stored Stored MIME type.
	 * @return string Empty when the stored type is not one we serve.
	 */
	private function safe_content_type( string $stored ): string {
		$stored = strtolower( trim( $stored ) );

		return in_array( $stored, Upload_Rules::ALLOWED_MIME, true ) ? $stored : '';
	}

	/**
	 * A filename safe to put in a Content-Disposition header.
	 *
	 * Quotes and control characters would let the stored name break out of the
	 * header value, and the extension comes from the type we are actually
	 * serving rather than from the name.
	 *
	 * @param string $name Stored display name.
	 * @param string $mime Content type being served.
	 * @return string
	 */
	private function safe_filename( string $name, string $mime ): string {
		$base = pathinfo( Upload_Rules::safe_display_name( $name ), PATHINFO_FILENAME );
		$base = preg_replace( '/[^A-Za-z0-9 _.-]/', '', is_string( $base ) ? $base : '' );

		if ( ! is_string( $base ) || '' === trim( $base ) ) {
			$base = 'creative';
		}

		return trim( $base ) . '.' . Upload_Rules::extension_for_mime( $mime );
	}
}
