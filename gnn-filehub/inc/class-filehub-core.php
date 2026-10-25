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
        require_once GNN_FILEHUB_PATH . 'inc/class-filehub-security.php';
        require_once GNN_FILEHUB_PATH . 'inc/class-filehub-uploads.php';
        require_once GNN_FILEHUB_PATH . 'inc/class-filehub-lifecycle.php';
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
        add_action('init',function(){load_plugin_textdomain('gnn-filehub',false,dirname(plugin_basename(GNN_FILEHUB_FILE)).'/languages');},0);
        new FileHub_Lifecycle();
        new FileHub_Updater();

        // Instantiate Subsystems
        new FileHub_REST_API();
        new FileHub_Shortcodes();

        if ( is_admin() ) {
            new FileHub_Admin();

        }

        // Keep regular front-end members (users without edit_posts) entirely out of wp-admin — they
        // should never need it and should never land there by accident (a stray bookmark, a
        // broken link, a plugin/theme calling login_url()/register_url() somewhere).
        add_filter( 'show_admin_bar', array( $this, 'maybe_hide_admin_bar' ) );
        add_action( 'admin_init', array( $this, 'maybe_block_wp_admin_access' ) );
        add_action( 'login_init', array( $this, 'maybe_redirect_wp_login' ) );
        add_filter( 'login_url', array( $this, 'filter_login_url' ), 10, 2 );
        add_filter( 'register_url', array( $this, 'filter_register_url' ) );
    }

    /**
     * Hide the Admin Bar on the Front End for Members Without edit_posts
     */
    public function maybe_hide_admin_bar( $show ) {
        if ( is_user_logged_in() && ! current_user_can( 'edit_posts' ) ) {
            return false;
        }
        return $show;
    }

    /**
     * Block wp-admin Dashboard Access for Members Without edit_posts
     * Sends them back to the front-end account page instead of ever showing them wp-admin.
     */
    public function maybe_block_wp_admin_access() {
        if ( wp_doing_ajax() ) {
            return; // admin-ajax.php must keep working for any plugin/theme relying on it
        }

        if ( is_user_logged_in() && ! current_user_can( 'edit_posts' ) ) {
            $account_page_id = (int) get_option( 'filehub_page_account', 0 );
            wp_safe_redirect( $account_page_id ? get_permalink( $account_page_id ) : home_url() );
            exit;
        }
    }

    /**
     * Redirect a Bare GET wp-login.php Visit to the Front-End Account Page
     * Only intercepts a plain, logged-out "show me the login screen" request — actual form
     * submissions, password-reset-with-token links, and logout all pass straight through
     * untouched, since those genuinely need the real page to work.
     */
    public function maybe_redirect_wp_login() {
        if ( is_user_logged_in() ) {
            return;
        }

        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
            return;
        }

        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'login';
        if ( 'login' !== $action ) {
            return;
        }

        $account_page_id = (int) get_option( 'filehub_page_account', 0 );
        if ( ! $account_page_id ) {
            return;
        }

        wp_safe_redirect( get_permalink( $account_page_id ) );
        exit;
    }

    /**
     * Point wp_login_url() at the Front-End Account Page (Logged-Out State) When Available
     */
    public function filter_login_url( $login_url, $redirect ) {
        $account_page_id = (int) get_option( 'filehub_page_account', 0 );
        if ( ! $account_page_id ) {
            return $login_url;
        }

        $url = get_permalink( $account_page_id );
        if ( $redirect ) {
            $url = add_query_arg( 'redirect_to', $redirect, $url );
        }

        return $url;
    }

    /**
     * Point wp_registration_url() at the Front-End Account Page When Available
     */
    public function filter_register_url( $register_url ) {
        $account_page_id = (int) get_option( 'filehub_page_account', 0 );
        return $account_page_id ? get_permalink( $account_page_id ) : $register_url;
    }

    /**
     * Ensure Local Protected Storage directory exists with .htaccess protection
     */
    public function ensure_protected_storage_dir() { return FileHub_Security::private_dir(); }
    public function ensure_chunk_storage_dir() { return FileHub_Security::private_dir(); }
    public static function activate() {
        self::get_instance();
        FileHub_Uploads::install();
        if(!wp_next_scheduled('filehub_cleanup'))wp_schedule_event(time()+60,'hourly','filehub_cleanup');
    }
    public static function deactivate() { wp_clear_scheduled_hook('filehub_cleanup'); }
}
