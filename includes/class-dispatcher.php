<?php
/**
 * Event delivery: one signed POST per subscription, sent asynchronously
 * (Action Scheduler when WooCommerce provides it, WP-Cron otherwise) with
 * exponential backoff. The same eventId is kept across retries; each
 * attempt gets a fresh timestamp and signature.
 *
 * @package NextialOrderEvents
 */

defined( 'ABSPATH' ) || exit;

class Nextial_OE_Dispatcher {

	const HOOK         = 'nextial_oe_deliver';
	const CLEANUP_HOOK = 'nextial_oe_cleanup';
	const MAX_BODY     = 262144; // 256 KiB, MCP Events limit.

	/** Seconds to wait before attempt n+1 (index = attempts already made). */
	const BACKOFF = array( 30, 120, 600, 1800, 7200 );

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'deliver' ), 10, 1 );
		add_action( self::CLEANUP_HOOK, array( 'Nextial_OE_Store', 'purge_expired' ) );
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Fan an occurrence out to every matching, active subscription.
	 *
	 * @param string $name Event name.
	 * @param array  $data Payload (must match payloadSchema).
	 * @param bool   $now  Deliver synchronously (used by the admin test button).
	 * @return int Number of deliveries queued.
	 */
	public static function emit( $name, array $data, $now = false ) {
		$count = 0;
		foreach ( Nextial_OE_Store::active_subscriptions( $name ) as $sub ) {
			if ( ! Nextial_OE_Events::matches( $name, $sub['arguments'], $data ) ) {
				continue;
			}
			if ( self::emit_to( $sub, $name, $data, $now ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Queue (or send now) one event for one subscription.
	 *
	 * @return int|false Delivery id, or false if the body was too large.
	 */
	public static function emit_to( array $sub, $name, array $data, $now = false ) {
		$event_id = 'evt_' . Nextial_OE_Store::random_token( 24 );
		$body     = wp_json_encode(
			array(
				'eventId'   => $event_id,
				'name'      => $name,
				'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'data'      => $data,
				'cursor'    => null,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( strlen( $body ) > self::MAX_BODY ) {
			return false;
		}
		$delivery_id = Nextial_OE_Store::add_delivery( $sub['id'], $event_id, $name, $body );
		if ( $now ) {
			self::deliver( $delivery_id );
		} else {
			self::schedule( $delivery_id, 0 );
		}
		return $delivery_id;
	}

	private static function schedule( $delivery_id, $delay ) {
		$args = array( (int) $delivery_id );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::HOOK, $args, 'nextial-order-events-for-woocommerce' );
		} else {
			wp_schedule_single_event( time() + $delay, self::HOOK, $args );
		}
	}

	/**
	 * Attempt one delivery.
	 *
	 * @param int $delivery_id Row id.
	 */
	public static function deliver( $delivery_id ) {
		$row = Nextial_OE_Store::get_delivery( (int) $delivery_id );
		if ( ! $row || in_array( $row['status'], array( 'delivered', 'dropped' ), true ) ) {
			return;
		}
		$sub = Nextial_OE_Store::get_subscription( $row['subscription_id'] );
		if ( ! $sub ) {
			Nextial_OE_Store::update_delivery( $row['id'], array( 'status' => 'dropped', 'last_error' => 'Subscription no longer exists.' ) );
			return;
		}
		if ( null !== $sub['expires_at'] && $sub['expires_at'] < microtime( true ) * 1000 ) {
			Nextial_OE_Store::update_delivery( $row['id'], array( 'status' => 'dropped', 'last_error' => 'Subscription expired.' ) );
			return;
		}
		if ( ! Nextial_OE_Store::principal_is_live( $sub['principal'] ?? '' ) ) {
			Nextial_OE_Store::delete_subscription( $sub['id'] );
			Nextial_OE_Store::update_delivery( $row['id'], array( 'status' => 'dropped', 'last_error' => 'Access for this connection was revoked; subscription removed.' ) );
			return;
		}

		$attempts = (int) $row['attempts'] + 1;
		$result   = Nextial_OE_Http::signed_post( $sub['callback_url'], $row['body'], $row['event_id'], $sub['secret'], $sub['id'] );

		if ( is_wp_error( $result ) ) {
			$code  = null;
			$error = $result->get_error_message();
		} else {
			$code  = $result['code'];
			$error = $code >= 200 && $code < 300 ? null : substr( $result['body'], 0, 500 );
		}

		if ( null !== $code && $code >= 200 && $code < 300 ) {
			Nextial_OE_Store::update_delivery( $row['id'], array( 'status' => 'delivered', 'attempts' => $attempts, 'last_code' => $code, 'last_error' => null ) );
			return;
		}

		// 410 Gone: the receiver no longer wants this subscription.
		if ( 410 === $code ) {
			Nextial_OE_Store::delete_subscription( $sub['id'] );
			Nextial_OE_Store::update_delivery( $row['id'], array( 'status' => 'dropped', 'attempts' => $attempts, 'last_code' => $code, 'last_error' => 'Receiver returned 410; subscription removed.' ) );
			return;
		}
		// 413 and other non-retryable client errors.
		$permanent = 413 === $code || ( null !== $code && $code >= 400 && $code < 500 && ! in_array( $code, array( 408, 429 ), true ) );
		if ( $permanent || $attempts > count( self::BACKOFF ) ) {
			Nextial_OE_Store::update_delivery( $row['id'], array( 'status' => 'failed', 'attempts' => $attempts, 'last_code' => $code, 'last_error' => $error ) );
			return;
		}

		Nextial_OE_Store::update_delivery( $row['id'], array( 'status' => 'retrying', 'attempts' => $attempts, 'last_code' => $code, 'last_error' => $error ) );
		self::schedule( $row['id'], self::BACKOFF[ $attempts - 1 ] );
	}
}
