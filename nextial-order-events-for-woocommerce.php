<?php
/**
 * Plugin Name:       Nextial Order Events – WooCommerce MCP Server for ChatGPT Order Alerts
 * Description:       MCP Events for WooCommerce. Lets ChatGPT and other MCP clients get a signed notification for every new WooCommerce order through MCP Events and react automatically.
 * Version:           1.0.5
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Nextial
 * Author URI:        https://nextial.it/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nextial-order-events-for-woocommerce
 *
 * @package NextialOrderEvents
 */

defined( 'ABSPATH' ) || exit;

define( 'NEXTIAL_OE_VERSION', '1.0.5' );
define( 'NEXTIAL_OE_FILE', __FILE__ );
define( 'NEXTIAL_OE_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEXTIAL_OE_DB_VERSION', '2' );

require_once NEXTIAL_OE_DIR . 'includes/class-input.php';
require_once NEXTIAL_OE_DIR . 'includes/class-signer.php';
require_once NEXTIAL_OE_DIR . 'includes/class-store.php';
require_once NEXTIAL_OE_DIR . 'includes/class-http.php';
require_once NEXTIAL_OE_DIR . 'includes/class-events.php';
require_once NEXTIAL_OE_DIR . 'includes/class-tools.php';
require_once NEXTIAL_OE_DIR . 'includes/class-mcp-server.php';
require_once NEXTIAL_OE_DIR . 'includes/class-oauth.php';
require_once NEXTIAL_OE_DIR . 'includes/class-dispatcher.php';
require_once NEXTIAL_OE_DIR . 'includes/class-endpoints.php';
require_once NEXTIAL_OE_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'Nextial_OE_Store', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Nextial_OE_Store', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Nextial_OE_Store::maybe_upgrade();
		Nextial_OE_Endpoints::init();
		Nextial_OE_Dispatcher::init();
		Nextial_OE_Events::init();
		if ( is_admin() ) {
			Nextial_OE_Admin::init();
		}
	}
);
