<?php
/**
 * Plugin Name:       GNN Filehub
 * Plugin URI:        https://gnn.com.tr
 * Description:       100% WordPress Core Native, zero-dependency file upload and multi-cloud storage management plugin.
 * Version:           1.1.2
 * Author URI: 			https://github.com/BigDesigner
 * License: 			GPLv2 or later
 * License URI: 		https://www.gnu.org/licenses/gpl-2.0.html
 * Author:            BigDesigner
 * Text Domain:       gnn-filehub
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Read Version Dynamically from VERSION File
$filehub_version_path = plugin_dir_path( __FILE__ ) . 'VERSION';
$filehub_version      = file_exists( $filehub_version_path ) ? trim( file_get_contents( $filehub_version_path ) ) : '1.0.0';

// Define Plugin Constants
define( 'GNN_FILEHUB_VERSION', $filehub_version );
define( 'GNN_FILEHUB_FILE', __FILE__ );
define( 'GNN_FILEHUB_PATH', plugin_dir_path( __FILE__ ) );
define( 'GNN_FILEHUB_URL', plugin_dir_url( __FILE__ ) );

// Include Core Class
require_once GNN_FILEHUB_PATH . 'inc/class-filehub-core.php';

// Activation & Deactivation Hooks
register_activation_hook( __FILE__, array( 'FileHub_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FileHub_Core', 'deactivate' ) );

// Initialize Plugin Core
function gnn_filehub_init() {
    FileHub_Core::get_instance();
}
add_action( 'plugins_loaded', 'gnn_filehub_init' );

// Add Donate, Settings & Check Updates links to the Plugins list row
function gnn_filehub_plugin_action_links( $links, $file ) {
    if ( $file === plugin_basename( GNN_FILEHUB_FILE ) ) {
        $donate_link = '<a href="https://buymeacoffee.com/bigdesigner" target="_blank" style="font-weight:bold; color:#d63638;">' . esc_html__( 'Donate', 'gnn-filehub' ) . '</a>';

        $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=filehub-settings' ) ) . '">' . esc_html__( 'Settings', 'gnn-filehub' ) . '</a>';

        // Manual Update Check Link
        $update_url  = wp_nonce_url( admin_url( 'plugins.php?filehub_check_update=1' ), 'filehub_manual_update' );
        $update_link = '<a href="' . esc_url( $update_url ) . '">' . esc_html__( 'Check Updates', 'gnn-filehub' ) . '</a>';

        array_unshift( $links, $donate_link, $settings_link, $update_link );
    }
    return $links;
}
add_filter( 'plugin_action_links', 'gnn_filehub_plugin_action_links', 10, 2 );
