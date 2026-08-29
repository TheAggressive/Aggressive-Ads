<?php
/**
 * Signed fill tokens.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

/**
 * HMAC tokens minted at fill and consumed once per event type.
 *
 * The secret is wp_salt( 'aggr_fill' ), which is network-wide, and post ids
 * restart on every site. blog_id is therefore part of the signed payload —
 * authenticity without tenancy would credit the wrong publisher. See
 * docs/data-schema.md.
 *
 * The payload is not encrypted; authenticity is the HMAC and replay is the
 * unique (token_hash, event) row.
 */
final class Fill_Token {

	public const TTL_SECONDS = 300;
	public const MAX_LENGTH  = 200;
	public const PARTS       = 7;

	/**
	 * Mints a token bound to one fill on the current site.
	 *
	 * @param int $placement_id Placement post id.
	 * @param int $campaign_id  Campaign post id, or 0 for house.
	 * @param int $creative_id  Creative post id, or 0 for house.
	 * @return array{blog_id: int, placement_id: int, campaign_id: int, creative_id: int, exp: int, nonce: string, token: string}
	 */
	public function mint( int $placement_id, int $campaign_id, int $creative_id ): array {
		return $this->mint_on_site( $this->current_blog_id(), $placement_id, $campaign_id, $creative_id );
	}

	/**
	 * Mints a token bound to an explicit site.
	 *
	 * Production fill always uses mint(), which reads get_current_blog_id().
	 * This exists so tests can mint a foreign-site token without
	 * switch_to_blog() — a no-op on single-site PHPUnit. Never pass a
	 * client-supplied blog id.
	 *
	 * @param int $blog_id      Site id to bind. Must be > 0.
	 * @param int $placement_id Placement post id.
	 * @param int $campaign_id  Campaign post id, or 0 for house.
	 * @param int $creative_id  Creative post id, or 0 for house.
	 * @param int $ttl          Lifetime in seconds. Explicit so expiry
	 *                          behaviour is reachable from a test; every
	 *                          production caller takes the default.
	 * @return array{blog_id: int, placement_id: int, campaign_id: int, creative_id: int, exp: int, nonce: string, token: string}
	 */
	public function mint_on_site( int $blog_id, int $placement_id, int $campaign_id, int $creative_id, int $ttl = self::TTL_SECONDS ): array {
		$nonce = bin2hex( random_bytes( 8 ) );
		// Explicit rather than fixed, so expiry behaviour is reachable from a
		// test. Every caller uses the default.
		$exp = time() + $ttl;

		return array(
			'blog_id'      => $blog_id,
			'placement_id' => $placement_id,
			'campaign_id'  => $campaign_id,
			'creative_id'  => $creative_id,
			'exp'          => $exp,
			'nonce'        => $nonce,
			'token'        => $this->encode( $blog_id, $placement_id, $campaign_id, $creative_id, $exp, $nonce ),
		);
	}

	/**
	 * Parses a token. Null if expired, truncated, bound to another site, or the HMAC does not match.
	 *
	 * @param string $token         Serialized token.
	 * @param bool   $allow_expired Accept a token past its expiry, for the one
	 *                              event that legitimately arrives late.
	 * @return array{blog_id: int, placement_id: int, campaign_id: int, creative_id: int, exp: int, nonce: string}|null
	 */
	public function parse( string $token, bool $allow_expired = false ): ?array {
		if ( strlen( $token ) > self::MAX_LENGTH ) {
			return null;
		}

		$parts = explode( '.', $token );

		if ( self::PARTS !== count( $parts ) ) {
			return null;
		}

		$blog_id      = (int) $parts[0];
		$placement_id = (int) $parts[1];
		$campaign_id  = (int) $parts[2];
		$creative_id  = (int) $parts[3];
		$exp          = (int) $parts[4];
		$nonce        = $parts[5];
		$hmac         = $parts[6];

		if ( $blog_id <= 0 || $placement_id <= 0 || $exp <= 0 || 1 !== preg_match( '/^[a-f0-9]{16}\z/', $nonce ) || 1 !== preg_match( '/^[a-f0-9]{32}\z/', $hmac ) ) {
			return null;
		}

		/*
		 * The signature must cover the bytes presented, not the bytes we chose
		 * to read.
		 *
		 * `sign()` re-serializes the *cast* integers, so without this every
		 * numeric segment is malleable: `(int) '1<img src=x>'` is 1, and
		 * `1<img src=x>.42.7.9.<exp>.<nonce>.<hmac>` therefore produces the same
		 * digest as the canonical token and passes `hash_equals()` untouched.
		 * Only the nonce and the HMAC were ever charset-constrained.
		 *
		 * That was not exploitable — the REST routes gate on `[0-9a-f.]`, and
		 * the click hop percent-encodes what it forwards — but the click hop is
		 * now the thing that hands this token to an advertiser's page, and a
		 * signed value whose signature does not cover its own serialization is
		 * not something to publish. It also meant one fill could produce
		 * unlimited distinct `token_hash` values, since the hash is taken over
		 * the string rather than the payload.
		 *
		 * Comparing the round trip rather than running another regex, because
		 * this rejects exactly what `encode()` would never emit — leading
		 * zeros, a leading `+`, surrounding whitespace, trailing text — without
		 * a second definition of "numeric" that could drift from the first.
		 */
		$canonical = array( $blog_id, $placement_id, $campaign_id, $creative_id, $exp );

		foreach ( $canonical as $index => $value ) {
			if ( (string) $value !== $parts[ $index ] ) {
				return null;
			}
		}

		if ( ( 0 === $campaign_id ) !== ( 0 === $creative_id ) ) {
			return null;
		}

		if ( ! hash_equals( $this->sign( $blog_id, $placement_id, $campaign_id, $creative_id, $exp, $nonce ), $hmac ) ) {
			return null;
		}

		/*
		 * Expiry bounds how long a token may *start* reporting, and a view is
		 * the one event that legitimately arrives late: an ad below the fold is
		 * delivered at load and becomes viewable when somebody scrolls to it,
		 * which can be well past the five-minute window. Refusing those drops
		 * exactly the inventory viewability exists to measure while the
		 * impression still counts in the denominator.
		 *
		 * Authenticity is unaffected — the HMAC above is what proves the token
		 * is ours — and replay is bounded by the unique `(token_hash, event)`
		 * key rather than by the clock.
		 */
		if ( ! $allow_expired && $exp < time() ) {
			return null;
		}

		// HMAC already covers blog_id; this check is what stops a valid token
		// from site A incrementing colliding post ids on site B.
		if ( $blog_id !== $this->current_blog_id() ) {
			return null;
		}

		return array(
			'blog_id'      => $blog_id,
			'placement_id' => $placement_id,
			'campaign_id'  => $campaign_id,
			'creative_id'  => $creative_id,
			'exp'          => $exp,
			'nonce'        => $nonce,
		);
	}

	/**
	 * Stable digest used as the unique event key.
	 *
	 * @param string $token Full token string.
	 */
	public function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, $this->secret() );
	}

	/**
	 * Daily IP digest. Not comparable across days or installs.
	 *
	 * @param string $ip Client address.
	 */
	public function ip_hash( string $ip ): string {
		$valid = filter_var( $ip, FILTER_VALIDATE_IP );
		$value = is_string( $valid ) ? $valid : '';
		$salt  = wp_salt( 'aggr_fill' ) . gmdate( 'Y-m-d' );

		return hash_hmac( 'sha256', $value, $salt );
	}

	/**
	 * Serializes one token.
	 *
	 * @param int    $blog_id      Site id.
	 * @param int    $placement_id Placement post id.
	 * @param int    $campaign_id  Campaign post id, or 0 for house.
	 * @param int    $creative_id  Creative post id, or 0 for house.
	 * @param int    $exp          Unix expiry.
	 * @param string $nonce        16-hex nonce.
	 */
	private function encode( int $blog_id, int $placement_id, int $campaign_id, int $creative_id, int $exp, string $nonce ): string {
		return implode(
			'.',
			array(
				(string) $blog_id,
				(string) $placement_id,
				(string) $campaign_id,
				(string) $creative_id,
				(string) $exp,
				$nonce,
				$this->sign( $blog_id, $placement_id, $campaign_id, $creative_id, $exp, $nonce ),
			)
		);
	}

	/**
	 * 32-hex HMAC of the payload.
	 *
	 * @param int    $blog_id      Site id.
	 * @param int    $placement_id Placement post id.
	 * @param int    $campaign_id  Campaign post id, or 0 for house.
	 * @param int    $creative_id  Creative post id, or 0 for house.
	 * @param int    $exp          Unix expiry.
	 * @param string $nonce        16-hex nonce.
	 */
	private function sign( int $blog_id, int $placement_id, int $campaign_id, int $creative_id, int $exp, string $nonce ): string {
		$payload = implode(
			'.',
			array(
				(string) $blog_id,
				(string) $placement_id,
				(string) $campaign_id,
				(string) $creative_id,
				(string) $exp,
				$nonce,
			)
		);

		return substr( hash_hmac( 'sha256', $payload, $this->secret() ), 0, 32 );
	}

	/**
	 * Per-install HMAC secret.
	 */
	private function secret(): string {
		return wp_salt( 'aggr_fill' );
	}

	/**
	 * Current site. Never 0: a token bound to blog 0 would parse on every site
	 * that failed to boot $wpdb->blogid.
	 */
	private function current_blog_id(): int {
		$id = get_current_blog_id();

		return $id > 0 ? $id : 1;
	}
}
