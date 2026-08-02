<?php
/**
 * Plugin Name:       GNN FileHub NextGen
 * Plugin URI:        https://gnn.com.tr
 * Description:       100% WordPress Core Native, zero-dependency file upload and multi-cloud storage management plugin.
 * Version:           1.0.0
 * Author:            GNN Team
 * Text Domain:       gnn-filehub
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Define Plugin Constants
define( 'GNN_FILEHUB_VERSION', '1.0.0' );
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
