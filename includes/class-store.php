<?php
/**
 * Persistence: options, subscriptions table, delivery log table.
 *
 * @package NextialOrderEvents
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own tables; names come from $wpdb->prefix and every value is passed through $wpdb->prepare().

class Nextial_OE_Store {

	const OPT_KEY        = 'nextial_oe_access_key';
	const OPT_DB_VERSION = 'nextial_oe_db_version';
	const OPT_RECEIVER   = 'nextial_oe_test_receiver';

	/** Default subscription lifetime when the client does not ask for one (24 h). */
	const DEFAULT_TTL_MS = 86400000;
	/** Longest finite lifetime we grant (7 days). */
	const MAX_TTL_MS = 604800000;
	/** Shortest lifetime we grant (5 minutes). */
	const MIN_TTL_MS = 300000;

	/* ------------------------------------------------------------------ */
	/* Install / upgrade                                                   */
	/* ------------------------------------------------------------------ */

	public static function activate() {
		self::create_tables();
		self::access_key(); // Generates one on first activation.
	}

	/** Stop background work; data is kept until uninstall. */
	public static function deactivate() {
		global $wpdb;
		// Queued deliveries would never run again: mark them dropped.
		$wpdb->query( "UPDATE " . self::deliveries_table() . " SET status = 'dropped', last_error = 'Plugin deactivated.' WHERE status IN ('pending','retrying')" ); // phpcs:ignore WordPress.DB
		wp_clear_scheduled_hook( 'nextial_oe_cleanup' );
		wp_unschedule_hook( 'nextial_oe_deliver' ); // All argument variants.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'nextial_oe_deliver' );
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( self::OPT_DB_VERSION ) !== NEXTIAL_OE_DB_VERSION ) {
			self::create_tables();
		}
	}

	public static function subs_table() {
		global $wpdb;
		return $wpdb->prefix . 'nextial_oe_subscriptions';
	}

	public static function deliveries_table() {
		global $wpdb;
		return $wpdb->prefix . 'nextial_oe_deliveries';
	}

	public static function tokens_table() {
		global $wpdb;
		return $wpdb->prefix . 'nextial_oe_tokens';
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$subs    = self::subs_table();
		$dels    = self::deliveries_table();

		dbDelta(
			"CREATE TABLE {$subs} (
				id varchar(64) NOT NULL,
				event_name varchar(100) NOT NULL,
				arguments longtext NOT NULL,
				callback_url text NOT NULL,
				secret text NOT NULL,
				expires_at bigint(20) unsigned NULL,
				principal varchar(100) NOT NULL DEFAULT '',
				created_at bigint(20) unsigned NOT NULL,
				updated_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				KEY event_name (event_name),
				KEY principal (principal)
			) {$charset};"
		);

		$tokens = self::tokens_table();
		dbDelta(
			"CREATE TABLE {$tokens} (
				token_hash char(64) NOT NULL,
				kind varchar(10) NOT NULL,
				client_id varchar(255) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				scope varchar(255) NOT NULL DEFAULT '',
				resource varchar(255) NOT NULL DEFAULT '',
				expires_at bigint(20) unsigned NOT NULL,
				created_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (token_hash),
				KEY user_id (user_id),
				KEY expires_at (expires_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$dels} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				subscription_id varchar(64) NOT NULL,
				event_id varchar(64) NOT NULL,
				event_name varchar(100) NOT NULL,
				body longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				last_code smallint(5) NULL,
				last_error text NULL,
				created_at bigint(20) unsigned NOT NULL,
				updated_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				KEY subscription_id (subscription_id),
				KEY status (status)
			) {$charset};"
		);

		update_option( self::OPT_DB_VERSION, NEXTIAL_OE_DB_VERSION );
	}

	/* ------------------------------------------------------------------ */
	/* Access key (the secret part of the MCP URL)                         */
	/* ------------------------------------------------------------------ */

	public static function access_key() {
		$key = get_option( self::OPT_KEY );
		if ( ! is_string( $key ) || strlen( $key ) < 32 ) {
			$key = self::random_token( 40 );
			update_option( self::OPT_KEY, $key, false );
		}
		return $key;
	}

	public static function regenerate_access_key() {
		// Subscriptions made through the old URL must stop with it.
		self::delete_subscriptions_of( self::key_principal( self::access_key() ) );
		delete_option( self::OPT_KEY );
		return self::access_key();
	}

	/** Principal recorded for subscriptions made through a secret URL. */
	public static function key_principal( $key ) {
		return 'key:' . substr( hash( 'sha256', (string) $key ), 0, 16 );
	}

	/**
	 * Is the connection that created a subscription still allowed to receive
	 * events? Checked before every delivery, so revoking access (new secret
	 * URL, Disconnect, lost shop-manager rights) also stops the webhooks.
	 */
	public static function principal_is_live( $principal ) {
		$principal = (string) $principal;
		if ( in_array( $principal, array( 'listen', 'selftest' ), true ) ) {
			$live = true; // The built-in test receiver (its subscriptions are replaced on every run).
		} elseif ( 0 === strpos( $principal, 'key:' ) ) {
			$live = hash_equals( self::key_principal( self::access_key() ), $principal );
		} elseif ( 0 === strpos( $principal, 'oauth:' ) ) {
			$live = class_exists( 'Nextial_OE_OAuth' ) && Nextial_OE_OAuth::principal_is_live( $principal );
		} else {
			$live = false;
		}
		return (bool) apply_filters( 'nextial_oe_principal_is_live', $live, $principal );
	}

	public static function random_token( $length ) {
		$alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$out      = '';
		$max      = strlen( $alphabet ) - 1;
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $alphabet[ random_int( 0, $max ) ];
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Subscriptions                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Canonical JSON (keys sorted recursively) so equal arguments hash equally.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function canonical_json( $value ) {
		$sort = static function ( $v ) use ( &$sort ) {
			if ( is_array( $v ) ) {
				$is_list = array_keys( $v ) === range( 0, count( $v ) - 1 );
				if ( ! $is_list ) {
					ksort( $v, SORT_STRING );
				}
				foreach ( $v as $k => $item ) {
					$v[ $k ] = $sort( $item );
				}
			}
			return $v;
		};
		$sorted = $sort( $value );
		if ( is_array( $sorted ) && empty( $sorted ) ) {
			return '{}';
		}
		return wp_json_encode( $sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Deterministic id from principal, callback URL, event name and arguments,
	 * as required by the MCP Events spec.
	 */
	public static function subscription_id( $principal, $url, $name, $arguments ) {
		$material = $principal . "\n" . $url . "\n" . $name . "\n" . self::canonical_json( $arguments );
		return 'sub_' . substr( hash( 'sha256', $material ), 0, 32 );
	}

	/**
	 * Work out the granted lifetime.
	 *
	 * @param bool     $has_ttl Whether the client sent ttlMs at all.
	 * @param int|null $ttl_ms  Requested value (null = no expiry).
	 * @return int|null Granted ms, or null for no expiry.
	 */
	public static function grant_ttl( $has_ttl, $ttl_ms ) {
		if ( ! $has_ttl ) {
			return self::DEFAULT_TTL_MS;
		}
		if ( null === $ttl_ms ) {
			return self::MAX_TTL_MS; // "No expiry" is capped: clients must refresh at least weekly.
		}
		$ttl = (int) $ttl_ms;
		$ttl = min( $ttl, self::MAX_TTL_MS );
		return max( $ttl, self::MIN_TTL_MS );
	}

	public static function upsert_subscription( array $sub ) {
		global $wpdb;
		$table    = self::subs_table();
		$now      = time();
		$existing = self::get_subscription( $sub['id'] );
		$row      = array(
			'id'           => $sub['id'],
			'event_name'   => $sub['event_name'],
			'arguments'    => self::canonical_json( $sub['arguments'] ),
			'callback_url' => $sub['callback_url'],
			'secret'       => $sub['secret'],
			'expires_at'   => $sub['expires_at'],
			'principal'    => isset( $sub['principal'] ) ? (string) $sub['principal'] : '',
			'updated_at'   => $now,
		);
		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => $sub['id'] ) );
		} else {
			$row['created_at'] = $now;
			$wpdb->insert( $table, $row );
		}
	}

	public static function get_subscription( $id ) {
		global $wpdb;
		$table = self::subs_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	public static function delete_subscription( $id ) {
		global $wpdb;
		$wpdb->delete( self::subs_table(), array( 'id' => $id ) );
	}

	/** Remove every subscription created by one connection (used on disconnect). */
	public static function delete_subscriptions_of( $principal ) {
		global $wpdb;
		$wpdb->delete( self::subs_table(), array( 'principal' => (string) $principal ) );
	}

	/**
	 * Active (not expired) subscriptions for an event.
	 */
	public static function active_subscriptions( $event_name ) {
		global $wpdb;
		$table = self::subs_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_name = %s AND ( expires_at IS NULL OR expires_at > %d )",
				$event_name,
				(int) ( microtime( true ) * 1000 )
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
	}

	public static function all_subscriptions( $active_only = false ) {
		global $wpdb;
		$table = self::subs_table();
		if ( $active_only ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE ( expires_at IS NULL OR expires_at > %d ) ORDER BY updated_at DESC",
					(int) ( microtime( true ) * 1000 )
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY updated_at DESC", ARRAY_A );
		}
		return array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
	}

	public static function purge_expired() {
		global $wpdb;
		$table = self::subs_table();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < %d",
				(int) ( microtime( true ) * 1000 ) - DAY_IN_SECONDS * 1000
			)
		);
		$dels = self::deliveries_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$dels} WHERE created_at < %d", time() - 30 * DAY_IN_SECONDS ) );
		$tokens = self::tokens_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tokens} WHERE expires_at < %d", time() ) );
	}

	private static function hydrate( array $row ) {
		$row['arguments']  = json_decode( $row['arguments'], true );
		$row['arguments']  = is_array( $row['arguments'] ) ? $row['arguments'] : array();
		$row['expires_at'] = null === $row['expires_at'] ? null : (int) $row['expires_at'];
		return $row;
	}

	/* ------------------------------------------------------------------ */
	/* Deliveries                                                          */
	/* ------------------------------------------------------------------ */

	public static function add_delivery( $subscription_id, $event_id, $event_name, $body ) {
		global $wpdb;
		$now = time();
		$wpdb->insert(
			self::deliveries_table(),
			array(
				'subscription_id' => $subscription_id,
				'event_id'        => $event_id,
				'event_name'      => $event_name,
				'body'            => $body,
				'status'          => 'pending',
				'attempts'        => 0,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function get_delivery( $id ) {
		global $wpdb;
		$table = self::deliveries_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
	}

	public static function update_delivery( $id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = time();
		$wpdb->update( self::deliveries_table(), $fields, array( 'id' => $id ) );
	}

	public static function recent_deliveries( $limit = 20 ) {
		global $wpdb;
		$table = self::deliveries_table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
	}
}
