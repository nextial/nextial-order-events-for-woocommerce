<?php
/**
 * Read-only MCP tools, so ChatGPT can look up details after an event
 * ("an order arrived" -> get_order for the full picture).
 *
 * @package NextialOrderEvents
 */

defined( 'ABSPATH' ) || exit;

class Nextial_OE_Tools {

	public static function definitions() {
		$tools = array(
			array(
				'name'        => 'get_order',
				'title'       => 'Get order',
				'description' => 'Get one WooCommerce order: status, totals, line items, customer name, billing e-mail and shipping city.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'order_id' => array(
							'type'        => 'integer',
							'description' => 'The order ID (not the displayed order number if a numbering plugin is used).',
						),
					),
					'required'             => array( 'order_id' ),
					'additionalProperties' => false,
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
			array(
				'name'        => 'list_recent_orders',
				'title'       => 'List recent orders',
				'description' => 'List the most recent WooCommerce orders, newest first, optionally filtered by status. For "what is new since last time" (e.g. a scheduled task that runs every hour): pass since (orders created after it) and/or updated_since (orders changed after it, e.g. new status), then store checked_at from the result and pass it as since next time.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'since'         => array(
							'type'        => 'string',
							'description' => 'Only orders created at or after this moment. ISO 8601 date-time (e.g. 2026-11-13T09:00:00Z) or Unix timestamp.',
						),
						'updated_since' => array(
							'type'        => 'string',
							'description' => 'Only orders changed (status, payment, edits) at or after this moment. ISO 8601 date-time or Unix timestamp.',
						),
						'status'        => array(
							'type'        => 'string',
							'description' => 'Order status without the wc- prefix, e.g. processing, completed, on-hold, failed.',
						),
						'limit'         => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 20,
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
			array(
				'name'        => 'list_low_stock_products',
				'title'       => 'List low-stock products',
				'description' => 'List products whose stock is at or below the store low-stock threshold, including out-of-stock ones.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
		);
		return apply_filters( 'nextial_oe_tool_definitions', $tools );
	}

	/**
	 * Run a tool. Returns [structured data] or WP_Error for tool-level errors.
	 *
	 * @param string $name Tool name.
	 * @param array  $args Arguments.
	 * @return array|WP_Error
	 */
	public static function call( $name, array $args ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'nextial_oe_no_wc', 'WooCommerce is not active on this site.' );
		}
		/**
		 * Add-ons run their own (read-only) tools here: return data or WP_Error,
		 * or null to let Nextial Order Events handle the call.
		 */
		$pre = apply_filters( 'nextial_oe_tool_call', null, $name, $args );
		if ( null !== $pre ) {
			return $pre;
		}
		switch ( $name ) {
			case 'get_order':
				$order_id = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
				$order    = $order_id ? wc_get_order( $order_id ) : null;
				if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
					return new WP_Error( 'nextial_oe_not_found', 'Order not found.' );
				}
				$data                   = Nextial_OE_Events::order_payload( $order_id );
				$data['billing_email']  = (string) $order->get_billing_email();
				$data['shipping_city']  = (string) $order->get_shipping_city();
				$data['customer_note']  = (string) $order->get_customer_note();
				return $data;

			case 'list_recent_orders':
				$checked_at = time();
				$limit      = isset( $args['limit'] ) ? max( 1, min( 20, (int) $args['limit'] ) ) : 10;
				$query      = array(
					'limit'   => $limit + 1,
					'orderby' => 'date',
					'order'   => 'DESC',
					'return'  => 'ids',
					'type'    => 'shop_order',
				);
				if ( ! empty( $args['status'] ) ) {
					$query['status'] = array( 'wc-' . sanitize_key( preg_replace( '/^wc-/', '', $args['status'] ) ) );
				}
				foreach ( array( 'since' => 'date_created', 'updated_since' => 'date_modified' ) as $arg => $field ) {
					if ( ! isset( $args[ $arg ] ) || '' === $args[ $arg ] ) {
						continue;
					}
					$ts = self::parse_moment( $args[ $arg ] );
					if ( null === $ts ) {
						return new WP_Error( 'nextial_oe_bad_date', $arg . ' must be an ISO 8601 date-time (e.g. 2026-11-13T09:00:00Z) or a Unix timestamp.' );
					}
					$query[ $field ] = '>=' . $ts;
				}
				if ( isset( $query['date_modified'] ) && ! isset( $query['date_created'] ) ) {
					$query['orderby'] = 'modified';
				}
				$ids      = wc_get_orders( $query );
				$has_more = count( $ids ) > $limit;
				$orders   = array();
				foreach ( array_slice( $ids, 0, $limit ) as $id ) {
					$p = Nextial_OE_Events::order_payload( $id );
					if ( $p ) {
						unset( $p['items'] );
						$orders[] = $p;
					}
				}
				return array(
					'orders'     => $orders,
					'has_more'   => $has_more,
					'checked_at' => gmdate( 'Y-m-d\\TH:i:s\\Z', $checked_at ),
				);

			case 'list_low_stock_products':
				$limit     = isset( $args['limit'] ) ? max( 1, min( 50, (int) $args['limit'] ) ) : 20;
				$threshold = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
				$ids       = wc_get_products(
					array(
						'limit'        => 200,
						'manage_stock' => true,
						'return'       => 'ids',
						'status'       => 'publish',
					)
				);
				$items = array();
				foreach ( $ids as $id ) {
					$product = wc_get_product( $id );
					$qty     = $product ? $product->get_stock_quantity() : null;
					if ( null !== $qty && $qty <= $threshold ) {
						$items[] = Nextial_OE_Events::product_payload( $id, $qty <= 0 );
					}
					if ( count( $items ) >= $limit ) {
						break;
					}
				}
				return array(
					'threshold' => $threshold,
					'products'  => $items,
				);
		}
		return new WP_Error( 'nextial_oe_unknown_tool', 'Unknown tool: ' . $name );
	}

	/**
	 * Parse an ISO 8601 date-time or a Unix timestamp into a Unix timestamp.
	 * A date-time without a time zone is read in UTC. Returns null if invalid.
	 *
	 * @param mixed $value Input.
	 * @return int|null
	 */
	public static function parse_moment( $value ) {
		if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^\\d{9,11}$/', $value ) ) ) {
			return (int) $value;
		}
		if ( ! is_string( $value ) || ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}([T ]\\d{2}:\\d{2}(:\\d{2}(\\.\\d+)?)?)?(Z|[+-]\\d{2}:?\\d{2})?$/i', trim( $value ) ) ) {
			return null;
		}
		$ymd = array_map( 'intval', explode( '-', substr( trim( $value ), 0, 10 ) ) );
		if ( ! checkdate( $ymd[1], $ymd[2], $ymd[0] ) ) {
			return null;
		}
		try {
			$dt = new DateTimeImmutable( trim( $value ), new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return null;
		}
		return $dt->getTimestamp();
	}
}
