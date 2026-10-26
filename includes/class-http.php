<?php
/**
 * Outbound HTTP for callback verification and event delivery.
 *
 * Follows the MCP Events network rules: HTTPS only, resolve and validate the
 * IP at connect time (no private/reserved ranges), connect to the validated
 * IP while keeping the hostname for TLS, and never follow redirects.
 *
 * @package NextialOrderEvents
 */

defined( 'ABSPATH' ) || exit;

class Nextial_OE_Http {

	/** @var array|null host => ip pin for the request in flight */
	private static $pin = null;

	/**
	 * Validate a callback URL and resolve it to a public IP.
	 *
	 * @param string $url URL.
	 * @return array{host:string,port:int,ip:string}|WP_Error
	 */
	public static function resolve_public( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'nextial_oe_url', 'Callback URL must be an absolute https:// URL.' );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'nextial_oe_url', 'Callback URL must not contain credentials.' );
		}
		$host = strtolower( $parts['host'] );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 443;

		/**
		 * Allows tests on a private network to skip the public-IP check.
		 * Never enable this on a production site.
		 */
		$allow_private = (bool) apply_filters( 'nextial_oe_allow_private_callbacks', false, $url );

		$ips = array();
		if ( filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
			$ips[] = trim( $host, '[]' );
		} else {
			$v4 = gethostbynamel( $host );
			if ( is_array( $v4 ) ) {
				$ips = array_merge( $ips, $v4 );
			}
			if ( function_exists( 'dns_get_record' ) ) {
				$v6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( is_array( $v6 ) ) {
					foreach ( $v6 as $rec ) {
						if ( ! empty( $rec['ipv6'] ) ) {
							$ips[] = $rec['ipv6'];
						}
					}
				}
			}
		}
		if ( empty( $ips ) ) {
			return new WP_Error( 'nextial_oe_dns', 'Callback host could not be resolved.' );
		}
		foreach ( $ips as $ip ) {
			if ( ! $allow_private && ! self::is_public_ip( $ip ) ) {
				return new WP_Error( 'nextial_oe_ssrf', 'Callback host resolves to a non-public address.' );
			}
		}
		return array(
			'host' => $host,
			'port' => $port,
			'ip'   => $ips[0],
		);
	}

	public static function is_public_ip( $ip ) {
		$bin = @inet_pton( trim( (string) $ip, '[]' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $bin ) {
			return false;
		}
		if ( 16 === strlen( $bin ) ) {
			// IPv4-mapped (::ffff:a.b.c.d) and IPv4-compatible (::a.b.c.d): judge the embedded IPv4.
			$prefix = substr( $bin, 0, 12 );
			if ( str_repeat( "\0", 10 ) . "\xff\xff" === $prefix || str_repeat( "\0", 12 ) === $prefix ) {
				return self::is_public_ip( inet_ntop( substr( $bin, 12 ) ) );
			}
			$blocked6 = array(
				array( '::', 128 ),          // Unspecified.
				array( '::1', 128 ),         // Loopback.
				array( '64:ff9b::', 96 ),    // NAT64.
				array( '64:ff9b:1::', 48 ),  // Local-use NAT64.
				array( '100::', 64 ),        // Discard-only.
				array( '2001:db8::', 32 ),   // Documentation.
				array( '2002::', 16 ),       // 6to4 (embeds IPv4).
				array( 'fc00::', 7 ),        // Unique local.
				array( 'fe80::', 10 ),       // Link local.
				array( 'fec0::', 10 ),       // Site local (deprecated).
				array( 'ff00::', 8 ),        // Multicast.
			);
			foreach ( $blocked6 as $r ) {
				if ( self::in_prefix( $bin, inet_pton( $r[0] ), $r[1] ) ) {
					return false;
				}
			}
			return true;
		}
		$ip4 = inet_ntop( $bin );
		if ( false === filter_var( $ip4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		$blocked4 = array(
			array( '0.0.0.0', 8 ),
			array( '100.64.0.0', 10 ),   // CGNAT.
			array( '127.0.0.0', 8 ),
			array( '169.254.0.0', 16 ),
			array( '192.0.0.0', 24 ),
			array( '198.18.0.0', 15 ),   // Benchmarking.
			array( '224.0.0.0', 4 ),     // Multicast.
			array( '240.0.0.0', 4 ),
		);
		foreach ( $blocked4 as $r ) {
			if ( self::in_prefix( $bin, inet_pton( $r[0] ), $r[1] ) ) {
				return false;
			}
		}
		return true;
	}

	/** Binary prefix match. */
	private static function in_prefix( $addr, $net, $bits ) {
		if ( strlen( $addr ) !== strlen( $net ) ) {
			return false;
		}
		$bytes = intdiv( $bits, 8 );
		if ( substr( $addr, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $addr[ $bytes ] ) & $mask ) === ( ord( $net[ $bytes ] ) & $mask );
	}

	/**
	 * GET a public HTTPS URL with the same protections as webhook delivery
	 * (public IP only, connection pinned to the checked IP, no redirects).
	 *
	 * @return array{code:int,body:string}|WP_Error
	 */
	public static function safe_get( $url ) {
		$target = self::resolve_public( $url );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		self::$pin = $target;
		add_action( 'http_api_curl', array( __CLASS__, 'pin_curl' ), 10, 3 );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => 65536,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		remove_action( 'http_api_curl', array( __CLASS__, 'pin_curl' ), 10 );
		self::$pin = null;
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * POST a JSON body with Standard Webhooks headers.
	 *
	 * @param string $url             Callback URL.
	 * @param string $body            Exact body bytes.
	 * @param string $msg_id          webhook-id.
	 * @param string $secret          whsec_ secret.
	 * @param string $subscription_id X-MCP-Subscription-Id.
	 * @return array{code:int,body:string}|WP_Error
	 */
	public static function signed_post( $url, $body, $msg_id, $secret, $subscription_id ) {
		$target = self::resolve_public( $url );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$ts      = time();
		$headers = array(
			'Content-Type'          => 'application/json',
			'webhook-id'            => $msg_id,
			'webhook-timestamp'     => (string) $ts,
			'webhook-signature'     => Nextial_OE_Signer::sign( $secret, $msg_id, $ts, $body ),
			'X-MCP-Subscription-Id' => $subscription_id,
			'User-Agent'            => 'Nextial Order Events/' . NEXTIAL_OE_VERSION . '; ' . home_url(),
		);

		self::$pin = $target;
		add_action( 'http_api_curl', array( __CLASS__, 'pin_curl' ), 10, 3 );
		$response = wp_remote_post(
			$url,
			array(
				'body'        => $body,
				'headers'     => $headers,
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => true,
				'data_format' => 'body',
				'limit_response_size' => 65536,
			)
		);
		remove_action( 'http_api_curl', array( __CLASS__, 'pin_curl' ), 10 );
		self::$pin = null;

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Make cURL connect to the IP we validated (prevents DNS rebinding
	 * between the check and the connection) while keeping SNI/Host.
	 *
	 * @param resource|CurlHandle $handle cURL handle.
	 */
	public static function pin_curl( $handle ) {
		if ( ! self::$pin || ! defined( 'CURLOPT_RESOLVE' ) ) {
			return;
		}
		$ip = self::$pin['ip'];
		if ( false !== strpos( $ip, ':' ) ) {
			$ip = '[' . $ip . ']';
		}
		curl_setopt( $handle, CURLOPT_RESOLVE, array( self::$pin['host'] . ':' . self::$pin['port'] . ':' . $ip ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
