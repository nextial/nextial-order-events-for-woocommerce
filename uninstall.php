<?php
/**
 * Remove all plugin data on uninstall.
 *
 * @package NextialOrderEvents
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nextial_oe_subscriptions" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nextial_oe_deliveries" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nextial_oe_tokens" ); // phpcs:ignore WordPress.DB

foreach ( array( 'nextial_oe_access_key', 'nextial_oe_db_version', 'nextial_oe_test_receiver', 'nextial_oe_receiver_log', 'nextial_oe_oauth_clients', 'nextial_oe_cimd_store', 'nextial_oe_oauth_log' ) as $option ) {
	delete_option( $option );
}
wp_clear_scheduled_hook( 'nextial_oe_cleanup' );
wp_unschedule_hook( 'nextial_oe_deliver' ); // All argument variants.

// Transients (authorization codes, client metadata cache, notices, rate limits).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_nextial\\_oe\\_%' OR option_name LIKE '\\_transient\\_timeout\\_nextial\\_oe\\_%'" ); // phpcs:ignore WordPress.DB

// "Already notified" marker on orders (classic and HPOS storage).
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_nextial_oe_created_sent'" ); // phpcs:ignore WordPress.DB
$hpos_meta = $wpdb->prefix . 'wc_orders_meta';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_meta ) ) === $hpos_meta ) { // phpcs:ignore WordPress.DB
	$wpdb->query( "DELETE FROM {$hpos_meta} WHERE meta_key = '_nextial_oe_created_sent'" ); // phpcs:ignore WordPress.DB
}
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'nextial_oe_deliver' );
}
