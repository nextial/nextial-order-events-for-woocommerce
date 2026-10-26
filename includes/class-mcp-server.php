<?php
/**
 * Transport-independent MCP JSON-RPC handler.
 *
 * Dual-era server:
 *  - Modern (2026-07-28): stateless, per-request _meta, header/body checks,
 *    server/discover, MCP Events (events/list|subscribe|unsubscribe).
 *  - Legacy (2025-03-26 .. 2025-11-25): initialize handshake, no sessions
 *    issued (allowed by those revisions), same tools.
 *
 * handle() returns [ http_status, body_array|null ].
 *
 * @package NextialOrderEvents
 */

defined( 'ABSPATH' ) || exit;

class Nextial_OE_MCP_Server {

	const MODERN_VERSIONS = array( '2026-07-28' );
	const LEGACY_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26' );

	const ERR_PARSE            = -32700;
	const ERR_INVALID_REQUEST  = -32600;
	const ERR_METHOD_NOT_FOUND = -32601;
	const ERR_INVALID_PARAMS   = -32602;
	const ERR_INTERNAL         = -32603;
	const ERR_CALLBACK         = -32015; // CallbackEndpointError (OpenAI MCP Events).
	const ERR_HEADER_MISMATCH  = -32020;
	const ERR_UNSUPPORTED_VER  = -32022;

	/** @var string Identity of the connected account (access key or OAuth grant). */
	private $principal;

	/** @var bool Whether the caller authenticated with OAuth (adds securitySchemes). */
	private $oauth;

	public function __construct( $principal, $oauth = false ) {
		$this->principal = $principal;
		$this->oauth     = (bool) $oauth;
	}

	/**
	 * @param string $raw     Raw request body.
	 * @param array  $headers Request headers, keys lower-cased.
	 * @return array{0:int,1:array|null}
	 */
	public function handle( $raw, array $headers ) {
		$msg = json_decode( $raw, true );
		if ( null === $msg && JSON_ERROR_NONE !== json_last_error() ) {
			return array( 400, self::error( null, self::ERR_PARSE, 'Parse error' ) );
		}
		$msg = Nextial_OE_Input::clean( $msg );
		if ( ! is_array( $msg ) || isset( $msg[0] ) ) {
			return array( 400, self::error( null, self::ERR_INVALID_REQUEST, 'Expected a single JSON-RPC message (batches are not supported).' ) );
		}
		if ( ( $msg['jsonrpc'] ?? null ) !== '2.0' || ! isset( $msg['method'] ) || ! is_string( $msg['method'] ) ) {
			// A JSON-RPC response or garbage: clients must not send responses.
			return array( 400, self::error( $msg['id'] ?? null, self::ERR_INVALID_REQUEST, 'Invalid JSON-RPC request.' ) );
		}

		$method = $msg['method'];
		$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();

		// Notifications (no id): accept and ignore.
		if ( ! array_key_exists( 'id', $msg ) ) {
			return array( 202, null );
		}
		$id = $msg['id'];
		if ( null === $id || ( ! is_string( $id ) && ! is_int( $id ) ) ) {
			return array( 400, self::error( null, self::ERR_INVALID_REQUEST, 'Request id must be a string or integer.' ) );
		}

		if ( 'initialize' === $method ) {
			return array( 200, self::result( $id, $this->initialize( $params ), false ) );
		}

		$meta    = isset( $params['_meta'] ) && is_array( $params['_meta'] ) ? $params['_meta'] : array();
		$version = $meta['io.modelcontextprotocol/protocolVersion'] ?? null;
		$modern  = null !== $version;

		// A modern header with no version in the body is a header/body mismatch.
		if ( ! $modern && isset( $headers['mcp-protocol-version'] ) && in_array( $headers['mcp-protocol-version'], self::MODERN_VERSIONS, true ) ) {
			return array( 400, self::error( $id, self::ERR_HEADER_MISMATCH, 'Header mismatch: MCP-Protocol-Version header is set but the request body has no _meta protocol version.' ) );
		}

		if ( $modern ) {
			if ( ! is_string( $version ) || ! in_array( $version, self::MODERN_VERSIONS, true ) ) {
				$supported = array_merge( self::MODERN_VERSIONS, self::LEGACY_VERSIONS );
				return array(
					400,
					self::error(
						$id,
						self::ERR_UNSUPPORTED_VER,
						'Unsupported protocol version',
						array(
							'supported' => $supported,
							'requested' => $version,
						)
					),
				);
			}
			$mismatch = self::check_headers( $method, $params, $version, $headers );
			if ( $mismatch ) {
				return array( 400, self::error( $id, self::ERR_HEADER_MISMATCH, $mismatch ) );
			}
		}

		try {
			switch ( $method ) {
				case 'server/discover':
					$out = $this->discover();
					break;
				case 'ping':
					$out = array();
					break;
				case 'tools/list':
					$out = self::cacheable( array( 'tools' => $this->tool_list() ) );
					break;
				case 'tools/call':
					$out = $this->tools_call( $params );
					break;
				case 'events/list':
					$out = array( 'events' => array_values( Nextial_OE_Events::definitions() ) );
					break;
				case 'events/subscribe':
					$out = $this->events_subscribe( $params );
					break;
				case 'events/unsubscribe':
					$out = $this->events_unsubscribe( $params );
					break;
				default:
					return array( $modern ? 404 : 200, self::error( $id, self::ERR_METHOD_NOT_FOUND, 'Method not found: ' . $method ) );
			}
		} catch ( Nextial_OE_RPC_Error $e ) {
			return array( 200, self::error( $id, $e->getCode(), $e->getMessage(), $e->data ) );
		} catch ( Throwable $e ) {
			return array( 200, self::error( $id, self::ERR_INTERNAL, 'Internal error' ) );
		}

		return array( 200, self::result( $id, $out, $modern ) );
	}

	/* ------------------------------------------------------------------ */
	/* Methods                                                             */
	/* ------------------------------------------------------------------ */

	private function capabilities() {
		return array(
			'tools'  => new stdClass(),
			'events' => new stdClass(),
		);
	}

	private function instructions() {
		$events = implode( ', ', array_keys( Nextial_OE_Events::definitions() ) );
		return 'WooCommerce store "' . get_bloginfo( 'name' ) . '". Subscribe to these events to be notified when they happen: ' . $events . '. Use get_order and list_recent_orders to look up details.';
	}

	private function initialize( array $params ) {
		$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$version   = in_array( $requested, self::LEGACY_VERSIONS, true ) ? $requested : self::LEGACY_VERSIONS[0];
		return array(
			'protocolVersion' => $version,
			'capabilities'    => $this->capabilities(),
			'serverInfo'      => self::server_info(),
			'instructions'    => $this->instructions(),
		);
	}

	private function discover() {
		return self::cacheable(
			array(
				'supportedVersions' => array_merge( self::MODERN_VERSIONS, self::LEGACY_VERSIONS ),
				'capabilities'      => $this->capabilities(),
				'instructions'      => $this->instructions(),
			)
		);
	}

	/**
	 * CacheableResult fields, required on server/discover and tools/list
	 * since 2026-07-28. "private": the store name is per-site, and the
	 * catalogue can change when add-ons register events or tools.
	 */
	private static function cacheable( array $result ) {
		$result['ttlMs']      = 300000;
		$result['cacheScope'] = 'private';
		return $result;
	}

	/**
	 * Tool list; with OAuth each tool declares the scope it needs, which is
	 * how ChatGPT knows to offer the sign-in step.
	 */
	private function tool_list() {
		$tools = Nextial_OE_Tools::definitions();
		if ( $this->oauth ) {
			foreach ( $tools as $i => $tool ) {
				$tools[ $i ]['securitySchemes'] = array(
					array(
						'type'   => 'oauth2',
						'scopes' => array( Nextial_OE_OAuth::SCOPE_READ ),
					),
				);
			}
		}
		return $tools;
	}

	private function tools_call( array $params ) {
		$name = $params['name'] ?? null;
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$known = wp_list_pluck( Nextial_OE_Tools::definitions(), 'name' );
		if ( ! is_string( $name ) || ! in_array( $name, $known, true ) ) {
			throw Nextial_OE_RPC_Error::make( esc_html( 'Unknown tool: ' . ( is_string( $name ) ? $name : '' ) ), (int) self::ERR_INVALID_PARAMS );
		}
		$data = Nextial_OE_Tools::call( $name, $args );
		if ( is_wp_error( $data ) ) {
			return array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => $data->get_error_message(),
					),
				),
				'isError' => true,
			);
		}
		return array(
			'content'           => array(
				array(
					'type' => 'text',
					'text' => wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ),
				),
			),
			'structuredContent' => $data,
			'isError'           => false,
		);
	}

	private function events_subscribe( array $params ) {
		list( $name, $args, $url ) = $this->subscription_identity( $params );

		$delivery = $params['delivery'];
		$secret   = $delivery['secret'] ?? null;
		if ( null === Nextial_OE_Signer::decode_secret( $secret ) ) {
			throw Nextial_OE_RPC_Error::make( esc_html( 'delivery.secret must be a whsec_ value whose base64 decodes to 24-64 bytes.' ), (int) self::ERR_INVALID_PARAMS );
		}

		// ttlMs may be top-level or inside delivery depending on the client.
		if ( array_key_exists( 'ttlMs', $params ) ) {
			$has_ttl = true;
			$ttl     = $params['ttlMs'];
		} elseif ( array_key_exists( 'ttlMs', $delivery ) ) {
			$has_ttl = true;
			$ttl     = $delivery['ttlMs'];
		} else {
			$has_ttl = false;
			$ttl     = null;
		}
		if ( $has_ttl && null !== $ttl && ( ! is_numeric( $ttl ) || $ttl <= 0 ) ) {
			throw Nextial_OE_RPC_Error::make( esc_html( 'ttlMs must be a positive number or null.' ), (int) self::ERR_INVALID_PARAMS );
		}

		$sub_id = Nextial_OE_Store::subscription_id( $this->principal, $url, $name, $args );

		$verified = $this->verify_callback( $url, $secret, $sub_id );
		if ( true !== $verified ) {
			throw Nextial_OE_RPC_Error::make( esc_html( 'Callback endpoint verification failed.' ), (int) self::ERR_CALLBACK, array( 'reason' => esc_html( (string) $verified ) ) );
		}

		$granted    = Nextial_OE_Store::grant_ttl( $has_ttl, $ttl );
		$expires_ms = null === $granted ? null : (int) ( microtime( true ) * 1000 ) + $granted;

		Nextial_OE_Store::upsert_subscription(
			array(
				'id'           => $sub_id,
				'event_name'   => $name,
				'arguments'    => $args,
				'callback_url' => $url,
				'secret'       => $secret,
				'expires_at'   => $expires_ms,
				'principal'    => $this->principal,
			)
		);

		return array(
			'id'            => $sub_id,
			'refreshBefore' => null === $expires_ms ? null : gmdate( 'Y-m-d\TH:i:s\Z', (int) floor( $expires_ms / 1000 ) ),
			'cursor'        => null,
			'truncated'     => false,
		);
	}

	private function events_unsubscribe( array $params ) {
		list( $name, $args, $url ) = $this->subscription_identity( $params, false );
		Nextial_OE_Store::delete_subscription( Nextial_OE_Store::subscription_id( $this->principal, $url, $name, $args ) );
		return array();
	}

	/**
	 * Common validation for subscribe/unsubscribe.
	 *
	 * @return array{0:string,1:array,2:string}
	 */
	private function subscription_identity( array $params, $validate_args = true ) {
		$name = $params['name'] ?? null;
		if ( ! is_string( $name ) || ! array_key_exists( $name, Nextial_OE_Events::definitions() ) ) {
			throw Nextial_OE_RPC_Error::make( esc_html( 'Unknown event: ' . ( is_string( $name ) ? $name : '' ) ), (int) self::ERR_INVALID_PARAMS );
		}
		$args = $params['arguments'] ?? array();
		if ( null === $args ) {
			$args = array();
		}
		if ( $validate_args ) {
			$valid = Nextial_OE_Events::validate_arguments( $name, $args );
			if ( true !== $valid ) {
				throw Nextial_OE_RPC_Error::make( esc_html( (string) $valid ), (int) self::ERR_INVALID_PARAMS );
			}
		} elseif ( ! is_array( $args ) ) {
			$args = array();
		}
		$delivery = $params['delivery'] ?? null;
		if ( ! is_array( $delivery ) || ( $delivery['mode'] ?? null ) !== 'webhook' || empty( $delivery['url'] ) || ! is_string( $delivery['url'] ) ) {
			throw Nextial_OE_RPC_Error::make( esc_html( 'delivery must be an object with mode webhook and an https url.' ), (int) self::ERR_INVALID_PARAMS );
		}
		return array( $name, $args, $delivery['url'] );
	}

	/**
	 * Send the signed verification challenge. Successful verifications are
	 * cached per (principal, url) for a day so refreshes stay cheap.
	 *
	 * @return true|string True, or a reason code for CallbackEndpointError.
	 */
	private function verify_callback( $url, $secret, $sub_id ) {
		$cache_key = 'nextial_oe_ok_' . md5( $this->principal . '|' . $url );
		if ( get_transient( $cache_key ) ) {
			return true;
		}
		$challenge = Nextial_OE_Store::random_token( 40 );
		$body      = wp_json_encode(
			array(
				'type'      => 'verification',
				'challenge' => $challenge,
			)
		);
		$res = Nextial_OE_Http::signed_post( $url, $body, 'msg_verification_' . Nextial_OE_Store::random_token( 20 ), $secret, $sub_id );
		if ( is_wp_error( $res ) ) {
			$code = $res->get_error_code();
			if ( in_array( $code, array( 'nextial_oe_url', 'nextial_oe_ssrf', 'nextial_oe_dns' ), true ) ) {
				return 'invalid_url';
			}
			return false !== stripos( $res->get_error_message(), 'timed out' ) ? 'timeout' : 'unreachable';
		}
		if ( $res['code'] < 200 || $res['code'] >= 300 ) {
			return 'http_' . $res['code'];
		}
		$reply = Nextial_OE_Input::json( $res['body'] );
		if ( ! is_array( $reply ) || ! isset( $reply['challenge'] ) || ! is_string( $reply['challenge'] ) || ! hash_equals( $challenge, $reply['challenge'] ) ) {
			return 'challenge_failed';
		}
		set_transient( $cache_key, 1, DAY_IN_SECONDS );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Streamable HTTP header/body consistency (modern requests only).
	 *
	 * @return string|null Error message, or null when fine.
	 */
	private static function check_headers( $method, array $params, $version, array $headers ) {
		$hv = $headers['mcp-protocol-version'] ?? null;
		if ( null === $hv ) {
			return 'Missing MCP-Protocol-Version header.';
		}
		if ( $hv !== $version ) {
			return sprintf( "Header mismatch: MCP-Protocol-Version header value '%s' does not match body value '%s'", $hv, $version );
		}
		$hm = $headers['mcp-method'] ?? null;
		if ( null === $hm ) {
			return 'Missing Mcp-Method header.';
		}
		if ( $hm !== $method ) {
			return sprintf( "Header mismatch: Mcp-Method header value '%s' does not match body value '%s'", $hm, $method );
		}
		if ( in_array( $method, array( 'tools/call', 'resources/read', 'prompts/get' ), true ) ) {
			$body_name = 'resources/read' === $method ? ( $params['uri'] ?? null ) : ( $params['name'] ?? null );
			$hn        = $headers['mcp-name'] ?? null;
			if ( null === $hn ) {
				return 'Missing Mcp-Name header.';
			}
			$hn = self::decode_header_value( $hn );
			if ( $hn !== $body_name ) {
				return sprintf( "Header mismatch: Mcp-Name header value '%s' does not match body value '%s'", $hn, (string) $body_name );
			}
		}
		return null;
	}

	/** Decode the =?base64?...?= sentinel used for non-ASCII header values. */
	public static function decode_header_value( $value ) {
		if ( preg_match( '/^=\?base64\?(.*)\?=$/', $value, $m ) ) {
			$decoded = base64_decode( $m[1], true );
			return false === $decoded ? $value : $decoded;
		}
		return $value;
	}

	public static function server_info() {
		return array(
			'name'    => 'nextial-order-events-for-woocommerce',
			'title'   => 'Order Events for WooCommerce',
			'version' => NEXTIAL_OE_VERSION,
		);
	}

	private static function result( $id, array $result, $modern ) {
		$result['resultType']                       = 'complete';
		$result['_meta']                            = isset( $result['_meta'] ) ? $result['_meta'] : array();
		$result['_meta']['io.modelcontextprotocol/serverInfo'] = self::server_info();
		if ( empty( $result ) ) {
			$result = new stdClass();
		}
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	public static function error( $id, $code, $message, $data = null ) {
		$err = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( null !== $data ) {
			$err['data'] = $data;
		}
		$out = array( 'jsonrpc' => '2.0' );
		if ( null !== $id ) {
			$out['id'] = $id;
		}
		$out['error'] = $err;
		return $out;
	}
}

/**
 * JSON-RPC error carrying a code and optional data.
 */
class Nextial_OE_RPC_Error extends Exception {
	/** @var mixed */
	public $data;

	public function __construct( $message, $code, $data = null ) {
		parent::__construct( $message, $code );
		$this->data = $data;
	}

	/**
	 * Build an error for a JSON-RPC response. The message is only ever
	 * JSON-encoded into an application/json body, never printed as HTML.
	 */
	public static function make( $message, $code, $data = null ) {
		return new self( (string) $message, (int) $code, $data );
	}
}
