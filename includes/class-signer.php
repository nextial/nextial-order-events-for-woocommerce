<?php
/**
 * Standard Webhooks signing (https://www.standardwebhooks.com/).
 *
 * Signature = base64( HMAC-SHA256( key, "{msg_id}.{timestamp}.{body}" ) ), sent as "v1,<sig>".
 * The key is the base64-decoded part of a "whsec_" secret.
 *
 * Kept free of WordPress calls so it can be unit-tested on its own.
 *
 * @package NextialOrderEvents
 */

defined( 'ABSPATH' ) || exit;

class Nextial_OE_Signer {

	const PREFIX = 'whsec_';

	/**
	 * Decode a whsec_ secret into raw key bytes, or null if invalid.
	 * MCP Events requires the decoded key to be 24-64 bytes.
	 *
	 * @param mixed $secret Secret as sent by the client.
	 * @return string|null
	 */
	public static function decode_secret( $secret ) {
		if ( ! is_string( $secret ) || 0 !== strpos( $secret, self::PREFIX ) ) {
			return null;
		}
		$key = base64_decode( substr( $secret, strlen( self::PREFIX ) ), true );
		if ( false === $key ) {
			return null;
		}
		$len = strlen( $key );
		if ( $len < 24 || $len > 64 ) {
			return null;
		}
		return $key;
	}

	/**
	 * Build the webhook-signature header value.
	 *
	 * @param string $secret    whsec_ secret.
	 * @param string $msg_id    webhook-id value.
	 * @param int    $timestamp Unix seconds (same value sent in webhook-timestamp).
	 * @param string $body      Exact body bytes that will be sent.
	 * @return string
	 */
	public static function sign( $secret, $msg_id, $timestamp, $body ) {
		$key = self::decode_secret( $secret );
		if ( null === $key ) {
			throw new InvalidArgumentException( 'Invalid webhook secret.' );
		}
		$payload = $msg_id . '.' . (int) $timestamp . '.' . $body;
		return 'v1,' . base64_encode( hash_hmac( 'sha256', $payload, $key, true ) );
	}

	/**
	 * Verify a webhook-signature header (used by the built-in test receiver).
	 *
	 * @param string $secret     whsec_ secret.
	 * @param string $msg_id     webhook-id header.
	 * @param string $timestamp  webhook-timestamp header.
	 * @param string $body       Raw body.
	 * @param string $signatures webhook-signature header (space-separated list).
	 * @param int    $tolerance  Allowed clock skew in seconds.
	 * @return bool
	 */
	public static function verify( $secret, $msg_id, $timestamp, $body, $signatures, $tolerance = 300 ) {
		if ( ! ctype_digit( (string) $timestamp ) || abs( time() - (int) $timestamp ) > $tolerance ) {
			return false;
		}
		$expected = self::sign( $secret, $msg_id, (int) $timestamp, $body );
		foreach ( preg_split( '/\s+/', trim( (string) $signatures ) ) as $candidate ) {
			if ( hash_equals( $expected, $candidate ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Generate a fresh whsec_ secret (32 random bytes).
	 *
	 * @return string
	 */
	public static function generate_secret() {
		return self::PREFIX . base64_encode( random_bytes( 32 ) );
	}
}
