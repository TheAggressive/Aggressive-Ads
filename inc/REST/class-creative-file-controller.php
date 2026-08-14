<?php
/**
 * Serving private creative bytes.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Upload_Rules;
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
	 * The REST namespace.
	 */
	public const NAMESPACE = 'aggr/v1';

	/**
	 * Registers a route on the plugin namespace.
	 *
	 * @param non-falsy-string         $route Route pattern.
	 * @param array<int|string, mixed> $args  register_rest_route() arguments.
	 * @return void
	 */
	public static function register_route( string $route, array $args ): void {
		register_rest_route( self::NAMESPACE, $route, $args );
	}

	/**
	 * Absolute path to serve once the response has been approved.
	 *
	 * Held on the instance rather than passed through the response, because a
	 * response header is sent to the client and the private root is the one
	 * secret protecting unapproved artwork.
	 *
	 * @var string
	 */
	private string $pending_path = '';

	/**
	 * Constructor.
	 *
	 * @param Creative_Repository $creatives Creative persistence.
	 * @param Private_Storage     $storage   Private file storage.
	 */
	public function __construct(
		private readonly Creative_Repository $creatives,
		private readonly Private_Storage $storage
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

		return $response;
	}

	/**
	 * Resolves and authorizes a creative's file.
	 *
	 * Separated from the response so the decision — which is the part with
	 * security consequences — is testable without emitting bytes.
	 *
	 * @param int $creative_id Creative post id.
	 * @return array{path: string, mime: string, filename: string, bytes: int}|WP_Error
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

		$bytes = filesize( $path );

		return array(
			'path'     => $path,
			'mime'     => $mime,
			'filename' => $this->safe_filename( $details['name'], $mime ),
			'bytes'    => false === $bytes ? 0 : (int) $bytes,
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
		if ( $served || '' === $this->pending_path ) {
			return $served;
		}

		$path = $this->pending_path;

		// Cleared before emitting, so a later response in the same request
		// cannot inherit a path somebody else was authorized for.
		$this->pending_path = '';

		if ( 200 !== $result->get_status() || ! is_file( $path ) ) {
			return $served;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming bytes to the client. WP_Filesystem reads the whole file into memory to hand it back, which is the opposite of what this endpoint is for.
		readfile( $path );

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
