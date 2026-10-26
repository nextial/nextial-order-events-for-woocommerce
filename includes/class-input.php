<?php
/**
 * Input sanitization helpers for data that arrives as a raw request body
 * (JSON-RPC from MCP clients, OAuth client registration, test webhooks).
 *
 * @package NextialOrderEvents
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizes decoded JSON and HTTP headers before they are used or stored.
 */
class Nextial_OE_Input {

	/** Maximum nesting depth kept from decoded JSON. */
	const MAX_DEPTH = 8;

	/**
	 * Decode a raw JSON request body and sanitize every key and string value.
	 *
	 * Strings keep their exact characters (URLs, PKCE values and signatures are
	 * compared byte-for-byte later), but invalid UTF-8, control characters and
	 * HTML tags are removed. Each field is then validated again where it is used.
	 *
	 * @param string $raw Raw request body.
	 * @return mixed Sanitized array/scalar, or null when the body is not valid JSON.
	 */
	public static function json( $raw ) {
		$data = json_decode( (string) $raw, true );
		if ( null === $data ) {
			return null;
		}
		return self::clean( $data );
	}

	/**
	 * Recursively sanitize decoded JSON data.
	 *
	 * @param mixed $value Decoded value.
	 * @param int   $depth Current depth.
	 * @return mixed
	 */
	public static function clean( $value, $depth = 0 ) {
		if ( is_array( $value ) ) {
			if ( $depth >= self::MAX_DEPTH ) {
				return array();
			}
			$out = array();
			foreach ( $value as $k => $v ) {
				$key         = is_int( $k ) ? $k : self::text( (string) $k );
				$out[ $key ] = self::clean( $v, $depth + 1 );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return self::text( $value );
		}
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}
		return null;
	}

	/**
	 * Sanitize one string without altering legitimate characters.
	 *
	 * @param string $s Input.
	 * @return string
	 */
	public static function text( $s ) {
		$s = wp_check_invalid_utf8( (string) $s, true );
		$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s );
		if ( false !== strpos( $s, '<' ) ) {
			$s = wp_strip_all_tags( $s );
		}
		return $s;
	}

	/**
	 * Sanitize a list of request headers (name => value).
	 *
	 * @param array $headers Raw headers.
	 * @return array Lower-cased names, sanitized single-line values.
	 */
	public static function headers( array $headers ) {
		$out = array();
		foreach ( $headers as $name => $value ) {
			$name = strtolower( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $name ) );
			if ( '' === $name || ! is_scalar( $value ) ) {
				continue;
			}
			$out[ $name ] = sanitize_text_field( (string) $value );
		}
		return $out;
	}
}
