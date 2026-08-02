<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class FileHub_Updater
 * Checks GitHub Releases API for plugin updates and integrates with WordPress Core Update System.
 */
class FileHub_Updater {

    /**
     * GitHub Repository (owner/repo)
     */
    private $repo = 'BigDesigner/gnn-filehub';

    /**
     * Plugin Slug (folder/file.php)
     */
    private $plugin_slug = '';

    /**
     * Transient Key for Caching GitHub API Response
     */
    private $transient_key = 'filehub_github_update_check';

    /**
     * Cache Duration (12 Hours)
     */
    private $cache_duration = 43200;

    /**
     * Constructor & Hook Initialization
     */
    public function __construct() {
        $this->plugin_slug = plugin_basename( GNN_FILEHUB_FILE );

        // WordPress Plugin Update Filters
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );

        // Admin Manual Check Hooks
        add_action( 'admin_init', array( $this, 'handle_manual_check' ) );
        add_action( 'load-update-core.php', array( $this, 'clear_cache' ) );
    }

    /**
     * Get Local Installed Version
     */
    private function get_local_version(): string {
        return defined( 'GNN_FILEHUB_VERSION' ) ? GNN_FILEHUB_VERSION : '1.0.0';
    }

    /**
     * Fetch Remote Release Info from GitHub API
     */
    private function get_remote_release() {
        $cached = get_transient( $this->transient_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $url  = sprintf( 'https://api.github.com/repos/%s/releases/latest', $this->repo );
        $args = array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ),
            'timeout' => 10,
        );

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            set_transient( $this->transient_key, false, 300 ); // Cache failure for 5 mins
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( empty( $body ) || ! isset( $body->tag_name ) ) {
            return false;
        }

        $remote_version = ltrim( $body->tag_name, 'v' );
        $download_url   = '';

        if ( ! empty( $body->assets ) ) {
            foreach ( $body->assets as $asset ) {
                if ( strpos( $asset->name, '.zip' ) !== false ) {
                    $download_url = $asset->browser_download_url;
                    break;
                }
            }
        }

        if ( empty( $download_url ) && isset( $body->zipball_url ) ) {
            $download_url = $body->zipball_url;
        }

        $release_data = (object) array(
            'version'      => $remote_version,
            'download_url' => $download_url,
            'changelog'    => isset( $body->body ) ? $body->body : '',
            'published_at' => isset( $body->published_at ) ? $body->published_at : '',
            'html_url'     => isset( $body->html_url ) ? $body->html_url : '',
        );

        set_transient( $this->transient_key, $release_data, $this->cache_duration );

        return $release_data;
    }

    /**
     * Inject Update Notice into WordPress Update Pipeline
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_remote_release();
        if ( false === $release ) {
            return $transient;
        }

        $local_version = $this->get_local_version();

        if ( version_compare( $release->version, $local_version, '>' ) ) {
            $obj              = new stdClass();
            $obj->slug        = dirname( $this->plugin_slug );
            $obj->plugin      = $this->plugin_slug;
            $obj->new_version = $release->version;
            $obj->url         = $release->html_url;
            $obj->package     = $release->download_url;

            $transient->response[ $this->plugin_slug ] = $obj;
        }

        return $transient;
    }

    /**
     * Provide Details Popup Info for Plugin Update Screen
     */
    public function plugin_info( $result, $action, $args ) {
        $plugin_folder = dirname( $this->plugin_slug );

        if ( 'plugin_information' !== $action || ( isset( $args->slug ) && $args->slug !== $plugin_folder ) ) {
            return $result;
        }

        $release = $this->get_remote_release();
        if ( false === $release ) {
            return $result;
        }

        return (object) array(
            'name'          => 'GNN FileHub NextGen',
            'slug'          => $plugin_folder,
            'version'       => $release->version,
            'author'        => 'GNN Team',
            'homepage'      => 'https://gnn.com.tr',
            'download_link' => $release->download_url,
            'sections'      => array(
                'description' => '100% WordPress Core Native, zero-dependency file upload and multi-cloud storage management plugin.',
                'changelog'   => nl2br( esc_html( $release->changelog ) ),
            ),
        );
    }

    /**
     * Ensure Target Directory Name Consistency After Automatic Update
     */
    public function after_install( $response, $hook_extra, $result ) {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
            return $result;
        }

        global $wp_filesystem;
        $target_dir = WP_PLUGIN_DIR . '/' . dirname( $this->plugin_slug );

        if ( isset( $result['destination'] ) && $result['destination'] !== $target_dir ) {
            $wp_filesystem->move( $result['destination'], $target_dir );
            $result['destination'] = $target_dir;
        }

        delete_transient( $this->transient_key );
        return $result;
    }

    /**
     * Handle Manual Update Check Action
     */
    public function handle_manual_check() {
        if ( isset( $_GET['filehub_check_update'] ) && '1' === $_GET['filehub_check_update'] ) {
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'filehub_manual_update' ) ) {
                wp_die( esc_html__( 'Güvenlik doğrulaması başarısız.', 'gnn-filehub' ) );
            }

            if ( current_user_can( 'update_plugins' ) ) {
                delete_transient( $this->transient_key );
                delete_site_transient( 'update_plugins' );

                wp_safe_redirect( admin_url( 'update-core.php?force-check=1' ) );
                exit;
            }
        }
    }

    /**
     * Clear Cache on Core Updates Screen
     */
    public function clear_cache() {
        delete_transient( $this->transient_key );
    }
}
