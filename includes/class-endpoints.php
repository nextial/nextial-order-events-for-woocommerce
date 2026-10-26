<?php
/**
 * HTTP entry points.
 *
 *  - MCP endpoint:  /wp-json/nextial-order-events/v1/mcp/{key}
 *                   fallback /?nextial_oe_mcp={key} (sites with a broken REST API)
 *  - Test receiver: /wp-json/nextial-order-events/v1/receiver/{key}
 *                   fallback /?nextial_oe_receiver={key}
 *    A built-in webhook receiver used by the admin self-test: it answers the
 *    verification challenge and checks signatures exactly like ChatGPT would.
 *
 * Responses are written directly (not through WP_REST_Response) so status
 * codes and empty 202 bodies match the MCP transport rules exactly.
 *
 * @package NextialOrderEvents
 */

defined( 'ABSPATH' ) || exit;

class Nextial_OE_Endpoints {

	const OPT_RECEIVER_LOG = 'nextial_oe_receiver_log';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'parse_request', array( __CLASS__, 'maybe_handle_query' ), 0 );
	}

	public static function mcp_url() {
		return rest_url( 'nextial-order-events/v1/mcp/' . Nextial_OE_Store::access_key() );
	}

	public static function mcp_fallback_url() {
		return add_query_arg( 'nextial_oe_mcp', Nextial_OE_Store::access_key(), home_url( '/' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'nextial-order-events/v1',
			'/mcp',
			array(
				'methods'             => array( 'GET', 'POST', 'DELETE' ),
				'callback'            => array( __CLASS__, 'rest_mcp_oauth' ),
				'permission_callback' => '__return_true', // Authenticated by the OAuth bearer token.
			)
		);
		register_rest_route(
			'nextial-order-events/v1',
			'/mcp/(?P<key>[A-Za-z0-9]+)',
			array(
				'methods'             => array( 'GET', 'POST', 'DELETE' ),
				'callback'            => array( __CLASS__, 'rest_mcp' ),
				'permission_callback' => '__return_true', // Authenticated by the key in the URL.
			)
		);
		register_rest_route(
			'nextial-order-events/v1',
			'/receiver/(?P<key>[A-Za-z0-9]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_receiver' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* MCP                                                                 */
	/* ------------------------------------------------------------------ */

	public static function rest_mcp( WP_REST_Request $request ) {
		$headers = array();
		foreach ( array( 'mcp-protocol-version', 'mcp-method', 'mcp-name', 'origin', 'authorization' ) as $h ) {
			$v = $request->get_header( $h );
			if ( null !== $v ) {
				$headers[ $h ] = $v;
			}
		}
		self::serve_mcp( (string) $request['key'], $request->get_method(), $request->get_body(), $headers );
	}

	/** OAuth-protected MCP endpoint: /wp-json/nextial-order-events/v1/mcp */
	public static function rest_mcp_oauth( WP_REST_Request $request ) {
		$headers = array();
		foreach ( array( 'mcp-protocol-version', 'mcp-method', 'mcp-name', 'origin' ) as $h ) {
			$v = $request->get_header( $h );
			if ( null !== $v ) {
				$headers[ $h ] = $v;
			}
		}
		self::serve_mcp_oauth( $request->get_method(), $request->get_body(), $headers, $request->get_header( 'authorization' ) );
	}

	private static function serve_mcp_oauth( $method, $body, array $headers, $auth ) {
		if ( ! Nextial_OE_OAuth::available() ) {
			self::send( 404, array( 'error' => 'oauth_unavailable' ) );
		}
		if ( 'POST' !== $method ) {
			self::send( 405, null, array( 'Allow' => 'POST' ) );
		}
		if ( isset( $headers['origin'] ) && ! self::origin_allowed( $headers['origin'] ) ) {
			self::send( 403, Nextial_OE_MCP_Server::error( null, Nextial_OE_MCP_Server::ERR_INVALID_REQUEST, 'Origin not allowed.' ) );
		}
		$who = Nextial_OE_OAuth::authenticate( $auth );
		if ( ! $who ) {
			if ( $auth ) { // A missing token is the normal first step; a rejected one is worth showing.
				Nextial_OE_OAuth::log( 'mcp_auth', 'Access token rejected (expired, revoked or for another site).' );
			}
			self::send(
				401,
				array( 'error' => 'invalid_token' ),
				array( 'WWW-Authenticate' => Nextial_OE_OAuth::www_authenticate( $auth ? 'invalid_token' : '' ) )
			);
		}
		$msg    = Nextial_OE_Input::json( $body );
		$needed = is_array( $msg ) && isset( $msg['method'] ) && is_string( $msg['method'] ) ? Nextial_OE_OAuth::scope_for_method( $msg['method'] ) : null;
		if ( $needed && ! in_array( $needed, $who['scopes'], true ) ) {
			self::send(
				403,
				Nextial_OE_MCP_Server::error( $msg['id'] ?? null, Nextial_OE_MCP_Server::ERR_INVALID_REQUEST, 'Insufficient scope: ' . $needed ),
				array( 'WWW-Authenticate' => Nextial_OE_OAuth::www_authenticate( 'insufficient_scope' ) )
			);
		}
		$server = new Nextial_OE_MCP_Server( $who['principal'], true );
		list( $status, $payload ) = $server->handle( $body, $headers );
		self::send( $status, $payload );
	}

	public static function maybe_handle_query( $wp ) {
		$path    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$handler = '' !== $path ? Nextial_OE_OAuth::route( $path ) : null;
		if ( $handler ) {
			Nextial_OE_OAuth::serve( $handler );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- machine-to-machine endpoints, authenticated by key.
		if ( isset( $_GET['nextial_oe_mcp'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_GET['nextial_oe_mcp'] ) );
			self::serve_mcp( $key, isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET', self::raw_body(), self::server_headers() );
		}
		if ( isset( $_GET['nextial_oe_receiver'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_GET['nextial_oe_receiver'] ) );
			self::serve_receiver( $key, self::raw_body(), self::server_headers() );
		}
		// phpcs:enable
	}

	private static function serve_mcp( $key, $method, $body, array $headers ) {
		if ( ! hash_equals( Nextial_OE_Store::access_key(), (string) $key ) ) {
			self::send( 404, array( 'error' => 'not_found' ) );
		}
		if ( 'POST' !== $method ) {
			self::send( 405, null, array( 'Allow' => 'POST' ) );
		}
		if ( isset( $headers['origin'] ) && ! self::origin_allowed( $headers['origin'] ) ) {
			self::send( 403, Nextial_OE_MCP_Server::error( null, Nextial_OE_MCP_Server::ERR_INVALID_REQUEST, 'Origin not allowed.' ) );
		}
		$principal = Nextial_OE_Store::key_principal( $key );
		$server    = new Nextial_OE_MCP_Server( $principal );
		list( $status, $payload ) = $server->handle( $body, $headers );
		self::send( $status, $payload );
	}

	/**
	 * Origin is only sent by browsers. Allow this site and ChatGPT; reject
	 * other browser origins (DNS-rebinding / cross-site protection).
	 */
	private static function origin_allowed( $origin ) {
		$host = wp_parse_url( $origin, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		$host    = strtolower( $host );
		$allowed = array( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), 'chatgpt.com', 'chat.openai.com' );
		$allowed = apply_filters( 'nextial_oe_allowed_origins', $allowed );
		foreach ( $allowed as $a ) {
			if ( $host === $a || substr( $host, -strlen( '.' . $a ) ) === '.' . $a ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Built-in test receiver                                              */
	/* ------------------------------------------------------------------ */

	public static function receiver_url( $key ) {
		return rest_url( 'nextial-order-events/v1/receiver/' . $key );
	}

	public static function receiver_fallback_url( $key ) {
		return add_query_arg( 'nextial_oe_receiver', $key, home_url( '/' ) );
	}

	public static function rest_receiver( WP_REST_Request $request ) {
		$headers = array();
		foreach ( array( 'webhook-id', 'webhook-timestamp', 'webhook-signature', 'x-mcp-subscription-id' ) as $h ) {
			$headers[ $h ] = sanitize_text_field( (string) $request->get_header( $h ) );
		}
		self::serve_receiver( (string) $request['key'], $request->get_body(), $headers );
	}

	private static function serve_receiver( $key, $body, array $headers ) {
		$cfg = get_option( Nextial_OE_Store::OPT_RECEIVER );
		if ( ! is_array( $cfg ) || empty( $cfg['key'] ) || ! hash_equals( $cfg['key'], (string) $key ) ) {
			self::send( 404, array( 'error' => 'not_found' ) );
		}
		$ok = Nextial_OE_Signer::verify(
			$cfg['secret'],
			$headers['webhook-id'] ?? '',
			$headers['webhook-timestamp'] ?? '',
			$body,
			$headers['webhook-signature'] ?? ''
		);
		// The raw body is only used to verify the signature; only sanitized data is stored.
		$msg = $ok ? Nextial_OE_Input::json( $body ) : null;
		self::log_receipt(
			array(
				'at'              => time(),
				'signature_ok'    => $ok,
				'webhook_id'      => sanitize_text_field( $headers['webhook-id'] ?? '' ),
				'subscription_id' => sanitize_text_field( $headers['x-mcp-subscription-id'] ?? '' ),
				'type'            => is_array( $msg ) && isset( $msg['type'] ) && is_string( $msg['type'] ) ? sanitize_key( $msg['type'] ) : 'event',
				'name'            => is_array( $msg ) && isset( $msg['name'] ) && is_string( $msg['name'] ) ? sanitize_text_field( $msg['name'] ) : '',
				'body'            => is_array( $msg ) ? substr( (string) wp_json_encode( $msg ), 0, 2000 ) : '',
			)
		);
		if ( ! $ok ) {
			self::send( 401, array( 'error' => 'bad_signature' ) );
		}
		if ( is_array( $msg ) && isset( $msg['type'] ) && 'verification' === $msg['type'] ) {
			self::send( 200, array( 'challenge' => (string) ( $msg['challenge'] ?? '' ) ) );
		}
		self::send( 200, array( 'ok' => true ) );
	}

	private static function log_receipt( array $entry ) {
		$log = get_option( self::OPT_RECEIVER_LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift( $log, $entry );
		update_option( self::OPT_RECEIVER_LOG, array_slice( $log, 0, 15 ), false );
	}

	/* ------------------------------------------------------------------ */
	/* Low-level I/O                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Raw request body. It is needed byte-exact to verify webhook signatures;
	 * it is decoded and sanitized with Nextial_OE_Input before any use or storage.
	 */
	private static function raw_body() {
		$body = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false === $body ? '' : $body;
	}

	private static function server_headers() {
		$raw = array();
		foreach ( array_keys( $_SERVER ) as $k ) {
			if ( is_string( $k ) && 0 === strpos( $k, 'HTTP_' ) && isset( $_SERVER[ $k ] ) && is_string( $_SERVER[ $k ] ) ) {
				$raw[ str_replace( '_', '-', substr( $k, 5 ) ) ] = sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) );
			}
		}
		return Nextial_OE_Input::headers( $raw );
	}

	/**
	 * Write the response and stop.
	 *
	 * @param int        $status  HTTP status.
	 * @param array|null $payload JSON body, or null for an empty body.
	 * @param array      $extra   Extra headers.
	 */
	private static function send( $status, $payload, array $extra = array() ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		status_header( $status );
		nocache_headers();
		foreach ( $extra as $k => $v ) {
			header( $k . ': ' . $v );
		}
		if ( null !== $payload ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		// Let shutdown hooks (event flush) still run.
		exit;
	}
}
