<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class FileHub_Core
 * Main plugin engine and singleton orchestrator.
 */
class FileHub_Core {

    /**
     * Singleton Instance
     * @var FileHub_Core|null
     */
    private static $instance = null;

    /**
     * Get Singleton Instance
     * @return FileHub_Core
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load Plugin Dependencies
     */
    private function load_dependencies() {
        require_once GNN_FILEHUB_PATH . 'inc/storage/class-storage-interface.php';
        require_once GNN_FILEHUB_PATH . 'inc/storage/class-storage-local.php';
        require_once GNN_FILEHUB_PATH . 'inc/storage/class-storage-r2.php';
        require_once GNN_FILEHUB_PATH . 'inc/storage/class-storage-gdrive.php';
        require_once GNN_FILEHUB_PATH . 'inc/class-filehub-attachment.php';
        require_once GNN_FILEHUB_PATH . 'inc/class-filehub-rest-api.php';
        require_once GNN_FILEHUB_PATH . 'inc/class-filehub-admin.php';
        require_once GNN_FILEHUB_PATH . 'inc/class-filehub-shortcodes.php';
        require_once GNN_FILEHUB_PATH . 'inc/class-filehub-updater.php';
    }

    /**
     * Register Core Hooks & Subsystems
     */
    private function init_hooks() {
        add_action( 'init', array( $this, 'ensure_protected_storage_dir' ) );

        // Instantiate Subsystems
        new FileHub_REST_API();
        new FileHub_Shortcodes();

        if ( is_admin() ) {
            new FileHub_Admin();
            new FileHub_Updater();
        }
    }

    /**
     * Ensure Local Protected Storage directory exists with .htaccess protection
     */
    public function ensure_protected_storage_dir() {
        $upload_dir    = wp_upload_dir();
        $protected_dir = $upload_dir['basedir'] . '/filehub-protected';

        if ( ! file_exists( $protected_dir ) ) {
            wp_mkdir_p( $protected_dir );
        }

        $htaccess_file = $protected_dir . '/.htaccess';
        if ( ! file_exists( $htaccess_file ) ) {
            @file_put_contents( $htaccess_file, "Deny from all\n" );
        }

        $index_file = $protected_dir . '/index.html';
        if ( ! file_exists( $index_file ) ) {
            @file_put_contents( $index_file, '' );
        }

        $this->ensure_chunk_storage_dir();
    }

    /**
     * Ensure Temporary Chunk Storage directory exists with .htaccess protection
     */
    public function ensure_chunk_storage_dir() {
        $upload_dir = wp_upload_dir();
        $chunks_dir = $upload_dir['basedir'] . '/filehub-protected/chunks';

        if ( ! file_exists( $chunks_dir ) ) {
            wp_mkdir_p( $chunks_dir );
        }

        $htaccess_file = $chunks_dir . '/.htaccess';
        if ( ! file_exists( $htaccess_file ) ) {
            @file_put_contents( $htaccess_file, "Deny from all\n" );
        }
    }

    /**
     * Plugin Activation Handler
     */
    public static function activate() {
        $upload_dir    = wp_upload_dir();
        $protected_dir = $upload_dir['basedir'] . '/filehub-protected';

        if ( ! file_exists( $protected_dir ) ) {
            wp_mkdir_p( $protected_dir );
        }

        $htaccess_file = $protected_dir . '/.htaccess';
        if ( ! file_exists( $htaccess_file ) ) {
            @file_put_contents( $htaccess_file, "Deny from all\n" );
        }

        flush_rewrite_rules();
    }

    /**
     * Plugin Deactivation Handler
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }
}
