<?php
/**
 * Built-in OAuth 2.1 authorization server, so ChatGPT can connect with
 * "OAuth" instead of a secret URL.
 *
 *  - Protected resource metadata (RFC 9728) and authorization server
 *    metadata (RFC 8414), discovered from the 401 WWW-Authenticate header.
 *  - Clients: Client ID Metadata Documents (client_id is an HTTPS URL, the
 *    way ChatGPT prefers) and Dynamic Client Registration (RFC 7591).
 *  - Authorization code + PKCE S256 only, public clients ("none").
 *  - Issuer identification in every authorization response (RFC 9207).
 *  - The shop owner signs in to WordPress and approves on a consent screen.
 *  - Opaque tokens, stored hashed: access 1 h, refresh 30 days, rotated.
 *
 * The protocol logic (authorize/token/register/validate) is plain PHP that
 * returns values, so it is unit-tested outside WordPress; the endpoints at
 * the bottom only do I/O.
 *
 * @package NextialOrderEvents
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own tables; names come from $wpdb->prefix and every value is passed through $wpdb->prepare().

class Nextial_OE_OAuth {

	const SCOPE_READ   = 'order-events:read';
	const SCOPE_EVENTS = 'order-events:events';

	const ACCESS_TTL  = 3600;
	const REFRESH_TTL = 2592000; // 30 days.
	const CODE_TTL    = 600;

	const OPT_CLIENTS = 'nextial_oe_oauth_clients';
	const MAX_CLIENTS = 100;

	/** Who may connect the store to an assistant. */
	const CAP = 'manage_woocommerce';

	/* ------------------------------------------------------------------ */
	/* Identifiers                                                         */
	/* ------------------------------------------------------------------ */

	public static function scopes() {
		return array( self::SCOPE_READ, self::SCOPE_EVENTS );
	}

	/** Issuer: has a path of its own so it cannot clash with other plugins. */
	public static function issuer() {
		return untrailingslashit( home_url( '/nextial-oe-oauth' ) );
	}

	/** Canonical resource identifier = the OAuth MCP endpoint. */
	public static function resource() {
		return untrailingslashit( rest_url( 'nextial-order-events/v1/mcp' ) );
	}

	/** RFC 9728 well-known URL with the resource path inserted. */
	public static function resource_metadata_url() {
		$path = (string) wp_parse_url( self::resource(), PHP_URL_PATH );
		return self::origin( self::resource() ) . '/.well-known/oauth-protected-resource' . $path;
	}

	/** RFC 8414 well-known URL for our issuer. */
	public static function as_metadata_url() {
		$path = (string) wp_parse_url( self::issuer(), PHP_URL_PATH );
		return self::origin( self::issuer() ) . '/.well-known/oauth-authorization-server' . $path;
	}

	private static function origin( $url ) {
		$p = wp_parse_url( $url );
		return $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
	}

	/** OAuth needs real paths (well-known URLs), i.e. pretty permalinks. */
	public static function available() {
		return '' !== (string) get_option( 'permalink_structure' );
	}

	/* ------------------------------------------------------------------ */
	/* Metadata documents                                                  */
	/* ------------------------------------------------------------------ */

	public static function resource_metadata() {
		return array(
			'resource'                 => self::resource(),
			'authorization_servers'    => array( self::issuer() ),
			'scopes_supported'         => self::scopes(),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => 'Nextial Order Events – ' . get_bloginfo( 'name' ),
		);
	}

	public static function server_metadata() {
		$i = self::issuer();
		return array(
			'issuer'                                => $i,
			'authorization_endpoint'                => $i . '/authorize',
			'token_endpoint'                        => $i . '/token',
			'registration_endpoint'                 => $i . '/register',
			'revocation_endpoint'                   => $i . '/revoke',
			'response_types_supported'              => array( 'code' ),
			'response_modes_supported'              => array( 'query' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                      => self::scopes(),
			'authorization_response_iss_parameter_supported' => true,
			'client_id_metadata_document_supported' => true,
		);
	}

	/**
	 * WWW-Authenticate value for a 401/403 from the MCP endpoint.
	 *
	 * @param string $error Optional RFC 6750 error code.
	 */
	public static function www_authenticate( $error = '' ) {
		$v = 'Bearer resource_metadata="' . self::resource_metadata_url() . '", scope="' . implode( ' ', self::scopes() ) . '"';
		if ( '' !== $error ) {
			$v .= ', error="' . $error . '"';
		}
		return $v;
	}

	/* ------------------------------------------------------------------ */
	/* Clients                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Look a client up: an HTTPS client_id is a metadata document (CIMD),
	 * anything else must have been registered through DCR.
	 *
	 * @return array{client_id:string,client_name:string,redirect_uris:string[]}|WP_Error
	 */
	public static function get_client( $client_id ) {
		if ( ! is_string( $client_id ) || '' === $client_id || strlen( $client_id ) > 255 ) {
			return new WP_Error( 'invalid_client', 'Unknown client.' );
		}
		if ( 0 === strpos( $client_id, 'https://' ) ) {
			return self::fetch_client_document( $client_id );
		}
		$clients = get_option( self::OPT_CLIENTS, array() );
		if ( is_array( $clients ) && isset( $clients[ $client_id ] ) ) {
			return $clients[ $client_id ];
		}
		return new WP_Error( 'invalid_client', 'Unknown client.' );
	}

	/**
	 * Fetch and validate a Client ID Metadata Document. Cached for an hour.
	 */
	/** ChatGPT's published client, used only if its document cannot be fetched and no copy is stored. */
	const CHATGPT_CLIENT_ID = 'https://chatgpt.com/oauth/client.json';

	private static function fetch_client_document( $url ) {
		$cache = 'nextial_oe_cimd_' . md5( $url );
		$hit   = get_transient( $cache );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$store = get_option( 'nextial_oe_cimd_store', array() );
		$store = is_array( $store ) ? $store : array();
		$copy  = $store[ md5( $url ) ] ?? null; // Last copy that validated, kept as a fallback.

		$fail_key  = 'nextial_oe_cimdx_' . md5( $url );
		$host_fail = 'nextial_oe_cimdh_' . md5( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
		$blocked   = get_transient( $fail_key ) || ( ! $copy && (int) get_transient( $host_fail ) >= 3 );

		if ( ! $blocked ) {
			$res    = Nextial_OE_Http::safe_get( $url ); // Public IP only, pinned, no redirects, 64 KiB max.
			$client = ( is_wp_error( $res ) || 200 !== $res['code'] )
				? new WP_Error( 'invalid_client', 'Client metadata document could not be fetched (' . ( is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $res['code'] ) . ').' )
				: self::validate_client_document( $url, Nextial_OE_Input::json( $res['body'] ) );
			if ( ! is_wp_error( $client ) ) {
				set_transient( $cache, $client, HOUR_IN_SECONDS );
				$store[ md5( $url ) ] = $client + array( 'fetched_at' => time() );
				update_option( 'nextial_oe_cimd_store', array_slice( $store, -20, null, true ), false );
				return $client;
			}
			self::log( 'client_document', $client->get_error_message(), $url );
			set_transient( $fail_key, 1, 300 ); // Unauthenticated callers cannot make us fetch in a loop.
			set_transient( $host_fail, (int) get_transient( $host_fail ) + 1, 300 );
		}

		// A temporary outage (or a bot filter in front of the client's site) must not
		// break a working connection: fall back to the last copy that validated.
		if ( is_array( $copy ) && (int) ( $copy['fetched_at'] ?? 0 ) > time() - 30 * DAY_IN_SECONDS ) {
			unset( $copy['fetched_at'] );
			return $copy;
		}
		if ( self::CHATGPT_CLIENT_ID === $url ) {
			return array(
				'client_id'     => self::CHATGPT_CLIENT_ID,
				'client_name'   => 'ChatGPT',
				'redirect_uris' => array( 'https://chatgpt.com/connector_platform_oauth_redirect' ),
			);
		}
		return isset( $client ) && is_wp_error( $client ) ? $client : new WP_Error( 'invalid_client', 'Client metadata document could not be fetched.' );
	}

	/* ------------------------------------------------------------------ */
	/* Diagnostics: the last sign-in problems, shown on the settings page   */
	/* ------------------------------------------------------------------ */

	const OPT_LOG = 'nextial_oe_oauth_log';

	/**
	 * Record a sign-in problem (never tokens or codes).
	 *
	 * @param string $kind   Short type, e.g. token, mcp_auth, client_document.
	 * @param string $detail Error code / description.
	 * @param string $client Client id (shown as host only).
	 */
	public static function log( $kind, $detail, $client = '' ) {
		$log = get_option( self::OPT_LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'at'     => time(),
				'kind'   => substr( (string) $kind, 0, 30 ),
				'detail' => substr( (string) $detail, 0, 200 ),
				'client' => (string) ( wp_parse_url( (string) $client, PHP_URL_HOST ) ?: substr( (string) $client, 0, 40 ) ),
			)
		);
		update_option( self::OPT_LOG, array_slice( $log, 0, 30 ), false );
	}

	public static function recent_log() {
		$log = get_option( self::OPT_LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * @param string $url Document URL (= client_id).
	 * @param mixed  $doc Decoded JSON.
	 */
	public static function validate_client_document( $url, $doc ) {
		if ( ! is_array( $doc ) || ( $doc['client_id'] ?? null ) !== $url ) {
			return new WP_Error( 'invalid_client', 'Client metadata document does not match its URL.' );
		}
		$uris = self::clean_redirect_uris( $doc['redirect_uris'] ?? null );
		if ( is_wp_error( $uris ) ) {
			return new WP_Error( 'invalid_client', $uris->get_error_message() );
		}
		$methods = array();
		if ( isset( $doc['token_endpoint_auth_methods_supported'] ) && is_array( $doc['token_endpoint_auth_methods_supported'] ) ) {
			$methods = $doc['token_endpoint_auth_methods_supported'];
		}
		if ( isset( $doc['token_endpoint_auth_method'] ) && is_string( $doc['token_endpoint_auth_method'] ) ) {
			$methods[] = $doc['token_endpoint_auth_method'];
		}
		if ( ! in_array( 'none', $methods, true ) ) {
			return new WP_Error( 'invalid_client', 'This store only supports public clients (token_endpoint_auth_method "none").' );
		}
		return array(
			'client_id'     => $url,
			'client_name'   => self::clean_name( $doc['client_name'] ?? '', $url ),
			'redirect_uris' => $uris,
		);
	}

	/**
	 * Dynamic Client Registration (RFC 7591).
	 *
	 * @param mixed $req Decoded JSON request.
	 * @return array{0:int,1:array}
	 */
	public static function register( $req, $ip = '' ) {
		$rate_key = 'nextial_oe_dcr_' . md5( self::ip_bucket( $ip ) );
		$count    = (int) get_transient( $rate_key );
		if ( $count >= 10 ) {
			return array( 429, self::err( 'temporarily_unavailable', 'Too many registrations from this address. Try again later.' ) );
		}
		set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );
		if ( ! is_array( $req ) ) {
			return array( 400, self::err( 'invalid_client_metadata', 'Expected a JSON object.' ) );
		}
		$req = Nextial_OE_Input::clean( $req );
		$uris = self::clean_redirect_uris( $req['redirect_uris'] ?? null );
		if ( is_wp_error( $uris ) ) {
			return array( 400, self::err( 'invalid_redirect_uri', $uris->get_error_message() ) );
		}
		$method = $req['token_endpoint_auth_method'] ?? 'none';
		if ( 'none' !== $method ) {
			return array( 400, self::err( 'invalid_client_metadata', 'Only token_endpoint_auth_method "none" is supported (PKCE public client).' ) );
		}
		foreach ( (array) ( $req['grant_types'] ?? array() ) as $g ) {
			if ( ! in_array( $g, array( 'authorization_code', 'refresh_token' ), true ) ) {
				return array( 400, self::err( 'invalid_client_metadata', 'Unsupported grant type: ' . (string) $g ) );
			}
		}
		$id     = 'ssc_' . Nextial_OE_Store::random_token( 32 );
		$client = array(
			'client_id'     => $id,
			'client_name'   => self::clean_name( $req['client_name'] ?? '', $uris[0] ),
			'redirect_uris' => $uris,
			'created_at'    => time(),
		);
		$clients = get_option( self::OPT_CLIENTS, array() );
		$clients = is_array( $clients ) ? $clients : array();
		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			// Only forget clients that never obtained a token (a real client gets one within minutes).
			$in_use = self::clients_in_use();
			foreach ( $clients as $cid => $c ) {
				if ( ! isset( $in_use[ $cid ] ) && (int) ( $c['created_at'] ?? 0 ) < time() - 600 ) {
					unset( $clients[ $cid ] );
				}
			}
			if ( count( $clients ) >= self::MAX_CLIENTS ) {
				return array( 429, self::err( 'temporarily_unavailable', 'Client registration is temporarily unavailable.' ) );
			}
		}
		$clients[ $id ] = $client;
		update_option( self::OPT_CLIENTS, $clients, false );

		return array(
			201,
			array(
				'client_id'                  => $id,
				'client_id_issued_at'        => $client['created_at'],
				'client_name'                => $client['client_name'],
				'redirect_uris'              => $uris,
				'token_endpoint_auth_method' => 'none',
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
			),
		);
	}

	/** Rate-limit bucket: IPv4 address, or the /64 of an IPv6 address. */
	private static function ip_bucket( $ip ) {
		$bin = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false !== $bin && 16 === strlen( $bin ) ) {
			return bin2hex( substr( $bin, 0, 8 ) ) . '::/64';
		}
		return (string) $ip;
	}

	/** client_id => true for clients holding any unexpired token. */
	private static function clients_in_use() {
		global $wpdb;
		$t   = Nextial_OE_Store::tokens_table();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT client_id FROM {$t} WHERE expires_at > %d", time() ) );
		return array_fill_keys( $ids ? $ids : array(), true );
	}

	private static function clean_redirect_uris( $uris ) {
		if ( ! is_array( $uris ) || empty( $uris ) || count( $uris ) > 10 ) {
			return new WP_Error( 'bad', 'redirect_uris must be a non-empty array.' );
		}
		$out = array();
		foreach ( $uris as $u ) {
			if ( ! is_string( $u ) || strlen( $u ) > 500 ) {
				return new WP_Error( 'bad', 'Invalid redirect URI.' );
			}
			$p = wp_parse_url( $u );
			if ( ! is_array( $p ) || empty( $p['host'] ) || isset( $p['fragment'] ) ) {
				return new WP_Error( 'bad', 'Invalid redirect URI: ' . $u );
			}
			$scheme   = strtolower( $p['scheme'] ?? '' );
			$loopback = in_array( strtolower( $p['host'] ), array( 'localhost', '127.0.0.1', '[::1]' ), true );
			if ( 'https' !== $scheme && ! ( 'http' === $scheme && $loopback ) ) {
				return new WP_Error( 'bad', 'Redirect URIs must use https: ' . $u );
			}
			$out[] = $u;
		}
		return array_values( array_unique( $out ) );
	}

	private static function clean_name( $name, $fallback_url ) {
		$name = is_string( $name ) ? trim( wp_strip_all_tags( $name ) ) : '';
		if ( '' === $name ) {
			$name = (string) wp_parse_url( $fallback_url, PHP_URL_HOST );
		}
		return substr( $name, 0, 80 );
	}

	/* ------------------------------------------------------------------ */
	/* Authorization endpoint                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Validate an authorization request.
	 *
	 * Returns one of:
	 *  - array( 'fatal' => message )      : show an error page, never redirect
	 *  - array( 'redirect' => url )       : error that goes back to the client
	 *  - array( 'ok' => true, 'client' => ..., 'scope' => [...], 'params' => [...] )
	 */
	public static function check_authorize_request( array $q ) {
		$client = self::get_client( $q['client_id'] ?? '' );
		if ( is_wp_error( $client ) ) {
			return array( 'fatal' => $client->get_error_message() );
		}
		$redirect = $q['redirect_uri'] ?? '';
		if ( ! is_string( $redirect ) || ! in_array( $redirect, $client['redirect_uris'], true ) ) {
			return array( 'fatal' => 'The redirect URI is not registered for this client.' );
		}
		$state = isset( $q['state'] ) && is_string( $q['state'] ) ? $q['state'] : null;
		if ( null !== $state && ! preg_match( '/^[\x20-\x7E]{1,512}$/', $state ) ) {
			return array( 'fatal' => 'Invalid state parameter.' );
		}
		// Errors are shown here instead of being redirected: anyone can register
		// a client, so redirecting before the owner has seen the consent screen
		// would turn this site into an open redirector (RFC 9700 4.11).
		$back = static function ( $code, $desc ) {
			return array(
				'fatal' => $desc,
				'error' => $code,
			);
		};
		if ( ( $q['response_type'] ?? '' ) !== 'code' ) {
			return $back( 'unsupported_response_type', 'Only response_type=code is supported.' );
		}
		$challenge = $q['code_challenge'] ?? '';
		if ( ! is_string( $challenge ) || ! preg_match( '/^[A-Za-z0-9_-]{43,128}$/', $challenge ) ) {
			return $back( 'invalid_request', 'PKCE code_challenge is required.' );
		}
		if ( ( $q['code_challenge_method'] ?? '' ) !== 'S256' ) {
			return $back( 'invalid_request', 'code_challenge_method must be S256.' );
		}
		if ( isset( $q['resource'] ) && ! self::same_resource( $q['resource'] ) ) {
			return $back( 'invalid_target', 'Unknown resource.' );
		}
		$scope = self::parse_scope( $q['scope'] ?? '' );
		if ( empty( $scope ) ) {
			return $back( 'invalid_scope', 'None of the requested scopes is supported.' );
		}
		return array(
			'ok'     => true,
			'client' => $client,
			'scope'  => $scope,
			'params' => array(
				'client_id'             => $client['client_id'],
				'redirect_uri'          => $redirect,
				'response_type'         => 'code',
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
				'scope'                 => implode( ' ', $scope ),
				'state'                 => $state,
				'resource'              => self::resource(),
			),
		);
	}

	/**
	 * The owner answered the consent screen.
	 *
	 * @param array $params  'params' from check_authorize_request().
	 * @param int   $user_id Approving user.
	 * @param bool  $allow   Decision.
	 * @return string Redirect URL back to the client.
	 */
	public static function decide( array $params, $user_id, $allow ) {
		if ( ! $allow ) {
			return self::redirect_with( $params['redirect_uri'], array( 'error' => 'access_denied', 'error_description' => 'The store owner declined.' ), $params['state'] );
		}
		$code = Nextial_OE_Store::random_token( 48 );
		set_transient(
			'nextial_oe_code_' . hash( 'sha256', $code ),
			array(
				'client_id'      => $params['client_id'],
				'redirect_uri'   => $params['redirect_uri'],
				'code_challenge' => $params['code_challenge'],
				'scope'          => $params['scope'],
				'resource'       => $params['resource'],
				'user_id'        => (int) $user_id,
				'expires'        => time() + self::CODE_TTL,
			),
			self::CODE_TTL
		);
		return self::redirect_with( $params['redirect_uri'], array( 'code' => $code ), $params['state'] );
	}

	/** Append response parameters, always including iss (RFC 9207). */
	public static function redirect_with( $uri, array $args, $state ) {
		if ( null !== $state && '' !== $state ) {
			$args['state'] = $state;
		}
		$args['iss'] = self::issuer();
		$sep         = false === strpos( $uri, '?' ) ? '?' : '&';
		return $uri . $sep . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/* ------------------------------------------------------------------ */
	/* Token endpoint                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array       $post        Form fields.
	 * @param string|null $auth_header Authorization header, if any.
	 * @return array{0:int,1:array}
	 */
	public static function token( array $post, $auth_header = null ) {
		if ( ! empty( $post['client_secret'] ) || ! empty( $post['client_assertion'] ) || ( is_string( $auth_header ) && 0 === stripos( $auth_header, 'basic ' ) ) ) {
			return array( 401, self::err( 'invalid_client', 'Only public clients (no client authentication) are supported.' ) );
		}
		$client = self::get_client( $post['client_id'] ?? '' );
		if ( is_wp_error( $client ) ) {
			return array( 401, self::err( 'invalid_client', $client->get_error_message() ) );
		}
		if ( isset( $post['resource'] ) && ! self::same_resource( $post['resource'] ) ) {
			return array( 400, self::err( 'invalid_target', 'Unknown resource.' ) );
		}
		$grant = $post['grant_type'] ?? '';

		if ( 'authorization_code' === $grant ) {
			$code = is_string( $post['code'] ?? null ) ? $post['code'] : '';
			$key  = 'nextial_oe_code_' . hash( 'sha256', $code );
			$data = '' === $code ? false : get_transient( $key );
			// Single use: only the request that actually deletes the code may redeem it.
			if ( is_array( $data ) && ! delete_transient( $key ) ) {
				$data = false;
			}
			if ( ! is_array( $data ) || $data['expires'] < time() || $data['client_id'] !== $client['client_id'] ) {
				return array( 400, self::err( 'invalid_grant', 'Invalid or expired authorization code.' ) );
			}
			if ( ( $post['redirect_uri'] ?? '' ) !== $data['redirect_uri'] ) {
				return array( 400, self::err( 'invalid_grant', 'redirect_uri does not match.' ) );
			}
			$verifier = is_string( $post['code_verifier'] ?? null ) ? $post['code_verifier'] : '';
			if ( ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) || ! hash_equals( $data['code_challenge'], self::pkce( $verifier ) ) ) {
				return array( 400, self::err( 'invalid_grant', 'PKCE verification failed.' ) );
			}
			if ( ! self::user_may_connect( $data['user_id'] ) ) {
				return array( 400, self::err( 'invalid_grant', 'The approving user can no longer manage this store.' ) );
			}
			return array( 200, self::issue( $client['client_id'], $data['user_id'], $data['scope'] ) );
		}

		if ( 'refresh_token' === $grant ) {
			$row = self::find_token( $post['refresh_token'] ?? '', 'refresh' );
			if ( ! $row || $row['client_id'] !== $client['client_id'] ) {
				return array( 400, self::err( 'invalid_grant', 'Invalid or expired refresh token.' ) );
			}
			if ( ! self::user_may_connect( (int) $row['user_id'] ) ) {
				self::revoke_grant( $row['client_id'], (int) $row['user_id'] );
				return array( 400, self::err( 'invalid_grant', 'The approving user can no longer manage this store.' ) );
			}
			$scope = $row['scope'];
			if ( ! empty( $post['scope'] ) ) {
				$asked = self::parse_scope( $post['scope'] );
				$scope = implode( ' ', array_intersect( $asked, explode( ' ', $row['scope'] ) ) );
				if ( '' === $scope ) {
					return array( 400, self::err( 'invalid_scope', 'Scope exceeds the original grant.' ) );
				}
			}
			if ( ! self::delete_token_hash( $row['token_hash'] ) ) { // Rotation; a parallel request already used it.
				return array( 400, self::err( 'invalid_grant', 'Invalid or expired refresh token.' ) );
			}
			return array( 200, self::issue( $row['client_id'], (int) $row['user_id'], $scope ) );
		}

		return array( 400, self::err( 'unsupported_grant_type', 'Use authorization_code or refresh_token.' ) );
	}

	/** RFC 7009: always 200, even for unknown tokens. */
	public static function revoke( array $post ) {
		$token = is_string( $post['token'] ?? null ) ? $post['token'] : '';
		if ( '' !== $token ) {
			self::delete_token_hash( hash( 'sha256', $token ) );
		}
		return array( 200, array() );
	}

	public static function pkce( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	private static function issue( $client_id, $user_id, $scope ) {
		$access  = Nextial_OE_Store::random_token( 48 );
		$refresh = Nextial_OE_Store::random_token( 48 );
		self::store_token( $access, 'access', $client_id, $user_id, $scope, self::ACCESS_TTL );
		self::store_token( $refresh, 'refresh', $client_id, $user_id, $scope, self::REFRESH_TTL );
		return array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TTL,
			'refresh_token' => $refresh,
			'scope'         => $scope,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Resource server side                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Validate an Authorization header.
	 *
	 * @return array{principal:string,scopes:string[],user_id:int,client_id:string}|null
	 */
	public static function authenticate( $auth_header ) {
		if ( ! is_string( $auth_header ) || ! preg_match( '/^Bearer\s+([A-Za-z0-9._~+\/-]+=*)\s*$/i', $auth_header, $m ) ) {
			return null;
		}
		$row = self::find_token( $m[1], 'access' );
		if ( ! $row || ! self::same_resource( $row['resource'] ) || ! self::user_may_connect( (int) $row['user_id'] ) ) {
			return null;
		}
		return array(
			'principal' => self::principal( $row['client_id'], (int) $row['user_id'] ),
			'scopes'    => explode( ' ', $row['scope'] ),
			'user_id'   => (int) $row['user_id'],
			'client_id' => $row['client_id'],
		);
	}

	/** Scope a JSON-RPC method needs (null = none beyond a valid token). */
	public static function scope_for_method( $method ) {
		if ( 'tools/call' === $method ) {
			return self::SCOPE_READ;
		}
		if ( 'events/subscribe' === $method || 'events/unsubscribe' === $method ) {
			return self::SCOPE_EVENTS;
		}
		return null;
	}

	public static function principal( $client_id, $user_id ) {
		return 'oauth:' . substr( hash( 'sha256', $client_id . '|' . (int) $user_id ), 0, 24 );
	}

	/** A grant is live while it holds an unexpired token and its user may still connect. */
	public static function principal_is_live( $principal ) {
		global $wpdb;
		$t    = Nextial_OE_Store::tokens_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT DISTINCT client_id, user_id FROM {$t} WHERE expires_at > %d", time() ),
			ARRAY_A
		);
		foreach ( $rows ? $rows : array() as $r ) {
			if ( hash_equals( self::principal( $r['client_id'], (int) $r['user_id'] ), (string) $principal ) ) {
				return self::user_may_connect( (int) $r['user_id'] );
			}
		}
		return false;
	}

	public static function user_may_connect( $user_id ) {
		return $user_id > 0 && user_can( (int) $user_id, self::CAP );
	}

	/* ------------------------------------------------------------------ */
	/* Connections (for the admin screen)                                  */
	/* ------------------------------------------------------------------ */

	/** Live grants: one row per (client, user) that still has a refresh token. */
	public static function connections() {
		global $wpdb;
		$t    = Nextial_OE_Store::tokens_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT client_id, user_id, MAX(created_at) AS last_seen FROM {$t} WHERE expires_at > %d GROUP BY client_id, user_id ORDER BY last_seen DESC",
				time()
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows ? $rows : array() as $r ) {
			$client             = self::get_client( $r['client_id'] );
			$r['client_name']   = is_wp_error( $client ) ? (string) wp_parse_url( $r['client_id'], PHP_URL_HOST ) : $client['client_name'];
			$r['user_id']       = (int) $r['user_id'];
			$r['last_seen']     = (int) $r['last_seen'];
			$out[]              = $r;
		}
		return $out;
	}

	/** Disconnect: drop tokens and every subscription that grant created. */
	public static function revoke_grant( $client_id, $user_id ) {
		global $wpdb;
		$wpdb->delete(
			Nextial_OE_Store::tokens_table(),
			array(
				'client_id' => (string) $client_id,
				'user_id'   => (int) $user_id,
			)
		);
		Nextial_OE_Store::delete_subscriptions_of( self::principal( $client_id, $user_id ) );
	}

	/* ------------------------------------------------------------------ */
	/* Storage helpers                                                     */
	/* ------------------------------------------------------------------ */

	private static function store_token( $token, $kind, $client_id, $user_id, $scope, $ttl ) {
		global $wpdb;
		$now = time();
		$wpdb->insert(
			Nextial_OE_Store::tokens_table(),
			array(
				'token_hash' => hash( 'sha256', $token ),
				'kind'       => $kind,
				'client_id'  => $client_id,
				'user_id'    => (int) $user_id,
				'scope'      => $scope,
				'resource'   => self::resource(),
				'expires_at' => $now + $ttl,
				'created_at' => $now,
			)
		);
	}

	private static function find_token( $token, $kind ) {
		global $wpdb;
		if ( ! is_string( $token ) || strlen( $token ) < 20 || strlen( $token ) > 200 ) {
			return null;
		}
		$t   = Nextial_OE_Store::tokens_table();
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE token_hash = %s AND kind = %s", hash( 'sha256', $token ), $kind ),
			ARRAY_A
		);
		if ( ! $row || (int) $row['expires_at'] < time() ) {
			return null;
		}
		return $row;
	}

	/** @return int Rows deleted. */
	private static function delete_token_hash( $hash ) {
		global $wpdb;
		return (int) $wpdb->delete( Nextial_OE_Store::tokens_table(), array( 'token_hash' => $hash ) );
	}

	private static function same_resource( $value ) {
		return is_string( $value ) && untrailingslashit( $value ) === self::resource();
	}

	/** Supported subset of a space-separated scope string; empty input = all. */
	public static function parse_scope( $scope ) {
		$asked = is_string( $scope ) ? preg_split( '/\s+/', trim( $scope ) ) : array();
		$asked = array_filter( (array) $asked );
		if ( empty( $asked ) ) {
			return self::scopes();
		}
		// Unknown scopes (e.g. "openid" added by a client by default) are ignored.
		return array_values( array_intersect( self::scopes(), $asked ) );
	}

	public static function err( $code, $desc ) {
		return array(
			'error'             => $code,
			'error_description' => $desc,
		);
	}

	/* ================================================================== */
	/* HTTP glue (WordPress only)                                          */
	/* ================================================================== */

	/**
	 * Route a request path to an OAuth handler. Called from parse_request.
	 *
	 * @param string $path Absolute URL path of the request.
	 * @return string|null Handler name.
	 */
	public static function route( $path ) {
		$path     = '/' . ltrim( (string) $path, '/' );
		$path     = untrailingslashit( $path );
		$res_path = untrailingslashit( (string) wp_parse_url( self::resource(), PHP_URL_PATH ) );
		$iss_path = untrailingslashit( (string) wp_parse_url( self::issuer(), PHP_URL_PATH ) );

		$map = array(
			'/.well-known/oauth-protected-resource' . $res_path => 'prm',
			'/.well-known/oauth-protected-resource'             => 'prm',
			'/.well-known/oauth-authorization-server' . $iss_path => 'asm',
			$iss_path . '/.well-known/oauth-authorization-server' => 'asm',
			$iss_path . '/authorize'                              => 'authorize',
			$iss_path . '/token'                                  => 'token',
			$iss_path . '/register'                               => 'register',
			$iss_path . '/revoke'                                 => 'revoke',
		);
		return $map[ $path ] ?? null;
	}

	public static function serve( $handler ) {
		if ( ! self::available() ) {
			self::json( 404, self::err( 'not_available', 'Enable pretty permalinks to use OAuth with Nextial Order Events.' ) );
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'OPTIONS' === $method ) {
			self::cors();
			status_header( 204 );
			exit;
		}
		switch ( $handler ) {
			case 'prm':
				self::cors();
				self::json( 200, self::resource_metadata(), false );
				break;
			case 'asm':
				self::cors();
				self::json( 200, self::server_metadata(), false );
				break;
			case 'register':
				self::cors();
				if ( 'POST' !== $method ) {
					self::json( 405, self::err( 'invalid_request', 'POST required.' ) );
				}
				$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
				/** Sites behind a reverse proxy can supply the real client IP here. */
				$ip = (string) apply_filters( 'nextial_oe_client_ip', $ip );
				list( $st, $body ) = self::register( Nextial_OE_Input::json( (string) file_get_contents( 'php://input' ) ), $ip ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- raw JSON body, sanitized by Nextial_OE_Input::json().
				self::json( $st, $body );
				break;
			case 'token':
			case 'revoke':
				self::cors();
				if ( 'POST' !== $method ) {
					self::json( 405, self::err( 'invalid_request', 'POST required.' ) );
				}
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- OAuth token endpoint, PKCE-protected.
				$post = wp_unslash( $_POST );
				if ( empty( $post ) ) {
					parse_str( (string) file_get_contents( 'php://input' ), $post ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
				$post = self::clean_params( (array) $post );
				$auth = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : null;
				list( $st, $body ) = 'token' === $handler ? self::token( (array) $post, $auth ) : self::revoke( (array) $post );
				if ( 200 !== $st ) {
					self::log( $handler . ' (' . sanitize_key( (string) ( $post['grant_type'] ?? '' ) ) . ')', ( $body['error'] ?? '' ) . ': ' . ( $body['error_description'] ?? '' ), (string) ( $post['client_id'] ?? '' ) );
				}
				self::json( $st, $body );
				break;
			case 'authorize':
				self::serve_authorize( $method );
				break;
		}
	}

	/**
	 * Sanitize request parameters. URL-valued and opaque OAuth values are
	 * kept byte-exact (they are compared exactly against registered values
	 * or validated by pattern later); everything else goes through
	 * sanitize_text_field(). Non-scalar values are dropped.
	 */
	private static function clean_params( array $in ) {
		$exact = array( 'client_id', 'redirect_uri', 'resource', 'state', 'code', 'code_verifier', 'code_challenge', 'refresh_token', 'token' );
		$out   = array();
		foreach ( $in as $k => $v ) {
			if ( ! is_string( $k ) || ! is_scalar( $v ) ) {
				continue;
			}
			$v         = (string) $v;
			$out[ $k ] = in_array( $k, $exact, true ) ? preg_replace( '/[\x00-\x1F\x7F]/', '', $v ) : sanitize_text_field( $v );
		}
		return $out;
	}

	private static function serve_authorize( $method ) {
		// phpcs:disable WordPress.Security.NonceVerification -- nonce checked below for the decision.
		$q = 'POST' === $method ? wp_unslash( $_POST ) : wp_unslash( $_GET );
		// phpcs:enable
		$q = self::clean_params( (array) $q );
		$check = self::check_authorize_request( $q );
		if ( isset( $check['fatal'] ) ) {
			self::page( __( 'Cannot connect', 'nextial-order-events-for-woocommerce' ), '<p>' . esc_html( $check['fatal'] ) . '</p>', 400 );
		}
		if ( ! is_user_logged_in() ) {
			$here = add_query_arg( array_filter( $check['params'], static fn( $v ) => null !== $v ), self::issuer() . '/authorize' );
			wp_safe_redirect( wp_login_url( $here ) );
			exit;
		}
		$user = wp_get_current_user();
		if ( ! self::user_may_connect( $user->ID ) ) {
			self::page( __( 'Not allowed', 'nextial-order-events-for-woocommerce' ), '<p>' . esc_html__( 'Only a shop manager or administrator of this store can connect it.', 'nextial-order-events-for-woocommerce' ) . '</p>', 403 );
		}
		if ( 'POST' === $method ) {
			if ( ! isset( $q['_wpnonce'] ) || ! wp_verify_nonce( $q['_wpnonce'], 'nextial_oe_oauth_consent' ) ) {
				self::page( __( 'Expired', 'nextial-order-events-for-woocommerce' ), '<p>' . esc_html__( 'This page expired. Start the connection again from ChatGPT.', 'nextial-order-events-for-woocommerce' ) . '</p>', 400 );
			}
			self::redirect_out( self::decide( $check['params'], $user->ID, isset( $q['decision'] ) && 'allow' === $q['decision'] ) );
		}
		self::consent_page( $check, $user );
	}

	private static function consent_page( array $check, $user ) {
		$labels = array(
			self::SCOPE_READ   => __( 'Read orders and low-stock products (no changes)', 'nextial-order-events-for-woocommerce' ),
			self::SCOPE_EVENTS => __( 'Be notified of store events, such as new orders', 'nextial-order-events-for-woocommerce' ),
		);
		$dest     = (string) wp_parse_url( $check['params']['redirect_uri'], PHP_URL_HOST );
		$official = 0 === strpos( $check['client']['client_id'], 'https://chatgpt.com/' ) && in_array( $dest, array( 'chatgpt.com', 'chat.openai.com' ), true );
		ob_start();
		?>
		<?php if ( $official ) : ?>
			<p><?php echo wp_kses( sprintf( /* translators: 1: app name, 2: store name */ __( '<strong>%1$s</strong> wants to connect to <strong>%2$s</strong>.', 'nextial-order-events-for-woocommerce' ), esc_html( $check['client']['client_name'] ), esc_html( get_bloginfo( 'name' ) ) ), array( 'strong' => array() ) ); ?></p>
		<?php else : ?>
			<p><?php echo wp_kses( sprintf( /* translators: 1: host name, 2: self-declared app name, 3: store name */ __( 'An app at <strong>%1$s</strong> (calling itself "%2$s", unverified) wants to connect to <strong>%3$s</strong>.', 'nextial-order-events-for-woocommerce' ), esc_html( $dest ), esc_html( $check['client']['client_name'] ), esc_html( get_bloginfo( 'name' ) ) ), array( 'strong' => array() ) ); ?></p>
		<?php endif; ?>
		<p><?php echo wp_kses( sprintf( /* translators: %s: host name */ __( 'Access will be sent to: <strong>%s</strong>', 'nextial-order-events-for-woocommerce' ), esc_html( $dest ) ), array( 'strong' => array() ) ); ?></p>
		<?php if ( ! $official ) : ?>
			<p class="warn"><?php esc_html_e( 'This is not the official ChatGPT app. Only continue if you started this connection yourself and recognise the address above.', 'nextial-order-events-for-woocommerce' ); ?></p>
		<?php endif; ?>
		<p><?php esc_html_e( 'It will be able to:', 'nextial-order-events-for-woocommerce' ); ?></p>
		<ul>
			<?php foreach ( $check['scope'] as $s ) : ?>
				<li><?php echo esc_html( $labels[ $s ] ?? $s ); ?></li>
			<?php endforeach; ?>
		</ul>
		<p class="muted">
			<?php
			/* translators: 1: user name, 2: host the answer is sent to */
			echo esc_html( sprintf( __( 'Signed in as %1$s. You will be sent back to %2$s. You can disconnect at any time in WooCommerce → Nextial Order Events.', 'nextial-order-events-for-woocommerce' ), $user->display_name, (string) wp_parse_url( $check['params']['redirect_uri'], PHP_URL_HOST ) ) );
			?>
		</p>
		<form method="post" action="<?php echo esc_url( self::issuer() . '/authorize' ); ?>">
			<?php wp_nonce_field( 'nextial_oe_oauth_consent' ); ?>
			<?php foreach ( $check['params'] as $k => $v ) : ?>
				<?php if ( null !== $v ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
				<?php endif; ?>
			<?php endforeach; ?>
			<button type="submit" name="decision" value="allow" class="primary"><?php esc_html_e( 'Allow', 'nextial-order-events-for-woocommerce' ); ?></button>
			<button type="submit" name="decision" value="deny"><?php esc_html_e( 'Deny', 'nextial-order-events-for-woocommerce' ); ?></button>
		</form>
		<?php
		self::page( __( 'Connect your store', 'nextial-order-events-for-woocommerce' ), (string) ob_get_clean(), 200 );
	}

	private static function page( $title, $html, $status ) {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		header( 'Referrer-Policy: no-referrer' );
		?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( $title ); ?> – Nextial Order Events</title>
<?php
		wp_register_style( 'nextial-oe-oauth', plugins_url( 'assets/css/oauth.css', NEXTIAL_OE_FILE ), array(), NEXTIAL_OE_VERSION );
		wp_print_styles( 'nextial-oe-oauth' );
		?>
</head>
<body><div class="box"><h1><?php echo esc_html( $title ); ?></h1><?php echo wp_kses( $html, self::page_allowed_html() ); ?></div></body>
</html>
		<?php
		exit;
	}

	/** HTML allowed in the consent and error pages. */
	private static function page_allowed_html() {
		return array(
			'p'      => array( 'class' => true ),
			'strong' => array(),
			'ul'     => array(),
			'li'     => array(),
			'br'     => array(),
			'form'   => array(
				'method' => true,
				'action' => true,
			),
			'input'  => array(
				'type'  => true,
				'name'  => true,
				'value' => true,
				'id'    => true,
			),
			'button' => array(
				'type'  => true,
				'name'  => true,
				'value' => true,
				'class' => true,
			),
		);
	}

	/** Redirect to the client's (already validated) redirect URI. */
	private static function redirect_out( $url ) {
		nocache_headers();
		header( 'Location: ' . $url, true, 302 );
		exit;
	}

	private static function cors() {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, Authorization, MCP-Protocol-Version' );
	}

	private static function json( $status, $body, $no_store = true ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		status_header( $status );
		if ( $no_store ) {
			header( 'Cache-Control: no-store' );
			header( 'Pragma: no-cache' );
		} else {
			header( 'Cache-Control: public, max-age=300' );
		}
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( empty( $body ) ? new stdClass() : $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}
