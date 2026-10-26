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
				'description' => 'List the most recent WooCommerce orders, optionally filtered by status.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'status' => array(
							'type'        => 'string',
							'description' => 'Order status without the wc- prefix, e.g. processing, completed, on-hold, failed.',
						),
						'limit'  => array(
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
				$limit = isset( $args['limit'] ) ? max( 1, min( 20, (int) $args['limit'] ) ) : 10;
				$query = array(
					'limit'   => $limit,
					'orderby' => 'date',
					'order'   => 'DESC',
					'return'  => 'ids',
					'type'    => 'shop_order',
				);
				if ( ! empty( $args['status'] ) ) {
					$query['status'] = array( 'wc-' . sanitize_key( preg_replace( '/^wc-/', '', $args['status'] ) ) );
				}
				$orders = array();
				foreach ( wc_get_orders( $query ) as $id ) {
					$p = Nextial_OE_Events::order_payload( $id );
					if ( $p ) {
						unset( $p['items'] );
						$orders[] = $p;
					}
				}
				return array( 'orders' => $orders );

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
}
