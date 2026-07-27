<?php
/**
 * Plugin Name:       	Variants display
 * Description:       	Change the way variants are displayed on Woocommerce product detail. 
 * Author:            	Ondřej Hruboš
 * Author URI:        	https://hrubos.dev
 * Version:           	1.0.0
 * Text Domain:       	variants_display
 * Domain Path: /languages
 * Requires at least: 	6.0
 * Requires Plugins:	woocommerce
 */

defined('ABSPATH') || exit;
define( 'VARIANTS_DISPLAY_VERSION',  '1.0.0' );
define( 'VARIANTS_DISPLAY_PATH',     plugin_dir_path( __FILE__ ) );
define( 'VARIANTS_DISPLAY_URL',      plugin_dir_url( __FILE__ ) );
define( 'VARIANTS_DISPLAY_META_KEY', '_variants_display_mode' );

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', true );
@ini_set( 'display_errors', 1 );

require_once VARIANTS_DISPLAY_PATH . 'include/admin.php';
require_once VARIANTS_DISPLAY_PATH . 'include/frontend.php';

add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'Variants display requires WooCommerce to be active.', 'variants_display' )
                . '</p></div>';
        } );
        return;
    }

    VARIANTS_DISPLAY_Admin::init();
    VARIANTS_DISPLAY_Frontend::init();
} );

add_action( 'init', 'variants_display_load_textdomain' );
function variants_display_load_textdomain() {
	load_plugin_textdomain(
		'variants_display',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
