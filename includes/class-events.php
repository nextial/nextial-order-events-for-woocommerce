<?php
/**
 * Event catalogue and WooCommerce hooks.
 *
 * Each event has a descriptor (what events/list returns), a filter matcher
 * (does this occurrence match a subscription's arguments?) and a payload
 * builder. Hooks only queue work; payloads are built at shutdown so the
 * order is fully saved (line items, totals) by then.
 *
 * @package NextialOrderEvents
 */

defined( 'ABSPATH' ) || exit;

class Nextial_OE_Events {

	/** @var array<string,array> queued occurrences, keyed for de-duplication */
	private static $queue = array();

	public static function init() {
		// New orders: classic checkout, block checkout, admin/REST-created orders.
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_store_api_order' ), 20, 1 );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 5 );
	}

	/* ------------------------------------------------------------------ */
	/* Catalogue                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Event descriptors, as returned by events/list.
	 *
	 * @return array<string,array>
	 */
	public static function definitions() {
		$order_payload = array(
			'type'                 => 'object',
			'properties'           => array(
				'order_id'      => array( 'type' => 'integer' ),
				'order_number'  => array( 'type' => 'string' ),
				'status'        => array( 'type' => 'string' ),
				'total'         => array( 'type' => 'number' ),
				'currency'      => array( 'type' => 'string' ),
				'item_count'    => array( 'type' => 'integer' ),
				'items'         => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'     => array( 'type' => 'string' ),
							'quantity' => array( 'type' => 'integer' ),
							'total'    => array( 'type' => 'number' ),
						),
					),
				),
				'customer_name' => array( 'type' => 'string' ),
				'payment_method'=> array( 'type' => 'string' ),
				'created_at'    => array( 'type' => 'string', 'format' => 'date-time' ),
				'admin_url'     => array( 'type' => 'string' ),
			),
			'required'             => array( 'order_id', 'order_number', 'status', 'total', 'currency', 'created_at' ),
			'additionalProperties' => false,
		);

		$defs = array(
			'order.created' => array(
				'name'          => 'order.created',
				'description'   => 'A new WooCommerce order was placed in the store.',
				'delivery'      => array( 'webhook' ),
				'inputSchema'   => array(
					'type'                 => 'object',
					'properties'           => new stdClass(),
					'additionalProperties' => false,
				),
				'payloadSchema' => $order_payload,
			),
		);

		/**
		 * Filter the event catalogue (used by add-ons to register more events).
		 * Add-ons queue occurrences with Nextial_OE_Events::enqueue().
		 *
		 * @param array $defs  Event descriptors keyed by name.
		 * @param array $order_payload JSON Schema of the standard order payload.
		 */
		return apply_filters( 'nextial_oe_event_definitions', $defs, $order_payload );
	}

	/**
	 * Validate subscribe arguments against the (small) input schema.
	 *
	 * @param string $name Event name.
	 * @param mixed  $args Arguments.
	 * @return true|string True or an error message.
	 */
	public static function validate_arguments( $name, $args ) {
		$defs = self::definitions();
		if ( ! isset( $defs[ $name ] ) ) {
			return 'Unknown event: ' . $name;
		}
		if ( null === $args ) {
			$args = array();
		}
		if ( ! is_array( $args ) ) {
			return 'arguments must be an object.';
		}
		$props = $defs[ $name ]['inputSchema']['properties'];
		$props = is_object( $props ) ? (array) $props : $props;
		foreach ( $args as $key => $value ) {
			if ( ! isset( $props[ $key ] ) ) {
				return 'Unknown argument: ' . $key;
			}
			$type = $props[ $key ]['type'];
			$ok   = ( 'number' === $type && is_numeric( $value ) && ! is_string( $value ) )
				|| ( 'boolean' === $type && is_bool( $value ) )
				|| ( 'string' === $type && is_string( $value ) )
				|| ( 'integer' === $type && is_int( $value ) );
			if ( ! $ok ) {
				return sprintf( 'Argument %s must be of type %s.', $key, $type );
			}
			if ( isset( $props[ $key ]['minimum'] ) && $value < $props[ $key ]['minimum'] ) {
				return sprintf( 'Argument %s must be >= %s.', $key, $props[ $key ]['minimum'] );
			}
		}
		return true;
	}

	/**
	 * Does an occurrence match a subscription's arguments?
	 */
	public static function matches( $name, array $args, array $data ) {
		$match = true;
		return (bool) apply_filters( 'nextial_oe_event_matches', $match, $name, $args, $data );
	}

	/* ------------------------------------------------------------------ */
	/* WooCommerce hooks: queue only                                       */
	/* ------------------------------------------------------------------ */

	public static function on_new_order( $order_id ) {
		if ( is_object( $order_id ) && method_exists( $order_id, 'get_id' ) ) {
			$order_id = $order_id->get_id();
		}
		self::enqueue( 'order.created', 'order', (int) $order_id );
	}

	public static function on_store_api_order( $order ) {
		self::on_new_order( $order );
	}

	/**
	 * Queue an occurrence; the payload is built at shutdown.
	 *
	 * @param string $name  Event name (must be in the catalogue).
	 * @param string $kind  'order', 'product', or any other kind handled by the
	 *                      nextial_oe_event_payload filter.
	 * @param int    $id    Object ID.
	 * @param mixed  $extra Extra data passed to the payload builder.
	 */
	public static function enqueue( $name, $kind, $id, $extra = null ) {
		self::$queue[ $name . ':' . $id ] = array( (string) $name, (string) $kind, (int) $id, $extra );
	}

	/**
	 * At shutdown, build payloads and hand them to the dispatcher.
	 */
	public static function flush() {
		if ( empty( self::$queue ) ) {
			return;
		}
		$queue       = self::$queue;
		self::$queue = array();
		foreach ( $queue as $item ) {
			list( $name, $kind, $id ) = $item;
			if ( empty( Nextial_OE_Store::active_subscriptions( $name ) ) ) {
				continue;
			}
			if ( 'order.created' === $name && ! self::claim_new_order( $id ) ) {
				continue;
			}
			$extra = isset( $item[3] ) ? $item[3] : null;
			if ( 'order' === $kind ) {
				$data = self::order_payload( $id );
			} elseif ( 'product' === $kind ) {
				$data = self::product_payload( $id, ! empty( $extra ) );
			} else {
				$data = null;
			}
			/**
			 * Filter the payload of a queued occurrence (add-ons can enrich it or
			 * build payloads for their own kinds). Return null to skip it.
			 */
			$data = apply_filters( 'nextial_oe_event_payload', $data, $name, $kind, $id, $extra );
			if ( $data ) {
				Nextial_OE_Dispatcher::emit( $name, $data );
			}
		}
	}

	/**
	 * order.created must fire once per order, and never for block-checkout
	 * drafts. Marks the order so later hooks/requests skip it.
	 */
	private static function claim_new_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->has_status( array( 'checkout-draft', 'auto-draft', 'trash' ) ) ) {
			return false;
		}
		if ( $order->get_meta( '_nextial_oe_created_sent' ) ) {
			return false;
		}
		$order->update_meta_data( '_nextial_oe_created_sent', time() );
		$order->save_meta_data();
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Payloads                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Order summary. Deliberately excludes e-mail, phone and addresses:
	 * the event says what happened; ChatGPT can fetch details with a tool.
	 */
	public static function order_payload( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return null;
		}
		$items = array();
		$count = 0;
		foreach ( $order->get_items() as $item ) {
			$qty     = (int) $item->get_quantity();
			$count  += $qty;
			$items[] = array(
				'name'     => (string) $item->get_name(),
				'quantity' => $qty,
				'total'    => (float) $item->get_total(),
			);
		}
		$created = $order->get_date_created();
		return array(
			'order_id'       => (int) $order->get_id(),
			'order_number'   => (string) $order->get_order_number(),
			'status'         => (string) $order->get_status(),
			'total'          => (float) $order->get_total(),
			'currency'       => (string) $order->get_currency(),
			'item_count'     => $count,
			'items'          => array_slice( $items, 0, 50 ),
			'customer_name'  => trim( $order->get_billing_first_name() ),
			'payment_method' => (string) $order->get_payment_method_title(),
			'created_at'     => $created ? $created->format( DATE_ATOM ) : gmdate( DATE_ATOM ),
			'admin_url'      => (string) $order->get_edit_order_url(),
		);
	}

	public static function product_payload( $product_id, $out_of_stock ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return null;
		}
		$qty = $product->get_stock_quantity();
		return array(
			'product_id'     => (int) $product->get_id(),
			'name'           => (string) $product->get_name(),
			'sku'            => (string) $product->get_sku(),
			'stock_quantity' => null === $qty ? null : (int) $qty,
			'out_of_stock'   => (bool) $out_of_stock,
			'admin_url'      => (string) admin_url( 'post.php?post=' . $product->get_id() . '&action=edit' ),
		);
	}

	/**
	 * Sample payloads for the "send test event" button.
	 */
	public static function sample_payload( $name ) {
		/**
		 * Filter the sample payload used by the "Send test" buttons, so add-ons
		 * can provide samples that match their own payload schemas.
		 */
		return apply_filters( 'nextial_oe_sample_payload', self::default_sample_payload( $name ), $name );
	}

	private static function default_sample_payload( $name ) {
		if ( 'product.low_stock' === $name ) {
			return array(
				'product_id'     => 0,
				'name'           => 'Test product (Nextial Order Events)',
				'sku'            => 'TEST-SKU',
				'stock_quantity' => 2,
				'out_of_stock'   => false,
				'admin_url'      => admin_url( 'edit.php?post_type=product' ),
			);
		}
		return array(
			'order_id'       => 0,
			'order_number'   => 'TEST-' . wp_rand( 1000, 9999 ),
			'status'         => 'order.payment_failed' === $name ? 'failed' : 'processing',
			'total'          => 249.90,
			'currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR',
			'item_count'     => 2,
			'items'          => array(
				array(
					'name'     => 'Test product (Nextial Order Events)',
					'quantity' => 2,
					'total'    => 249.90,
				),
			),
			'customer_name'  => 'Test',
			'payment_method' => 'Test',
			'created_at'     => gmdate( DATE_ATOM ),
			'admin_url'      => admin_url( 'admin.php?page=wc-orders' ),
		);
	}
}
