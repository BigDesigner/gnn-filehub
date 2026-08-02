<?php
/**
 * Plugin Name:       GNN Filehub
 * Plugin URI:        https://gnn.com.tr
 * Description:       100% WordPress Core Native, zero-dependency file upload and multi-cloud storage management plugin.
 * Version:           1.0.1
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
