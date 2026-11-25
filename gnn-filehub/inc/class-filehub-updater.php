<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class FileHub_Updater
 * Checks GitHub Releases for plugin updates and integrates with WordPress Core Update System.
 */
class FileHub_Updater {

    private $repo            = 'BigDesigner/gnn-filehub';
    private $slug            = 'gnn-filehub';
    private $main_file       = '';
    private $plugin_basename = 'gnn-filehub/gnn-filehub-nextgen.php';
    private $transient_key   = 'filehub_github_update_check';
    private $cache_duration  = 43200; // 12 * HOUR_IN_SECONDS
    private $requires_wp     = '6.0';
    private $requires_php    = '8.0';
    private $tested_up_to    = '7.1';
    private $was_active      = false;

    public function __construct() {
        if ( defined( 'GNN_FILEHUB_FILE' ) ) {
            $this->main_file       = GNN_FILEHUB_FILE;
            $this->plugin_basename = plugin_basename( GNN_FILEHUB_FILE );
        } else {
            $this->main_file = dirname( __DIR__ ) . '/gnn-filehub-nextgen.php';
        }

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_pre_install', array( $this, 'capture_pre_install_state' ), 10, 2 );
        add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );

        add_action( 'admin_init', array( $this, 'handle_manual_check' ) );
        add_action( 'load-update-core.php', array( $this, 'clear_cache' ) );
    }

    private function get_local_version(): string {
        $main_file = WP_PLUGIN_DIR . '/' . $this->plugin_basename;
        if ( file_exists( $main_file ) ) {
            if ( function_exists( 'clearstatcache' ) ) {
                clearstatcache( true, $main_file );
            }
            $headers = get_file_data( $main_file, array( 'Version' => 'Version' ), 'plugin' );
            if ( ! empty( $headers['Version'] ) ) {
                return trim( (string) $headers['Version'] );
            }
        }

        if ( defined( 'GNN_FILEHUB_VERSION' ) ) {
            return (string) GNN_FILEHUB_VERSION;
        }

        return '0.0.0';
    }

    private function sanitize_github_url( string $url ): string {
        $clean = esc_url_raw( $url, array( 'https' ) );
        if ( '' === $clean ) {
            return '';
        }

        $parts = wp_parse_url( $clean );
        if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) ) {
            return '';
        }

        $host          = strtolower( $parts['host'] );
        $allowed_hosts = array(
            'github.com',
            'api.github.com',
            'codeload.github.com',
            'objects.githubusercontent.com',
            'release-assets.githubusercontent.com',
        );

        $is_allowed = in_array( $host, $allowed_hosts, true )
            || (bool) preg_match( '/^(?:[a-z0-9-]+\.)+githubusercontent\.com$/', $host );

        return $is_allowed ? $clean : '';
    }

    private function get_package_url_from_api( $release_body ): string {
        if ( ! empty( $release_body->assets ) && is_array( $release_body->assets ) ) {
            foreach ( $release_body->assets as $asset ) {
                if ( isset( $asset->browser_download_url, $asset->name ) && '.zip' === strtolower( substr( (string) $asset->name, -4 ) ) ) {
                    $url = $this->sanitize_github_url( (string) $asset->browser_download_url );
                    if ( '' !== $url ) {
                        return $url;
                    }
                }
            }
        }

        if ( ! empty( $release_body->zipball_url ) ) {
            return $this->sanitize_github_url( (string) $release_body->zipball_url );
        }

        return '';
    }

    private function get_fallback_release() {
        $latest_url = 'https://github.com/' . $this->repo . '/releases/latest';
        $user_agent = 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url();

        $head = wp_remote_head(
            $latest_url,
            array(
                'redirection' => 0,
                'timeout'     => 10,
                'headers'     => array(
                    'User-Agent' => $user_agent,
                ),
            )
        );

        if ( is_wp_error( $head ) ) {
            return false;
        }

        $location = wp_remote_retrieve_header( $head, 'location' );
        if ( ! is_string( $location ) || ! preg_match( '#/releases/tag/([^/?#]+)$#', $location, $matches ) ) {
            return false;
        }

        $tag            = rawurldecode( $matches[1] );
        $remote_version = ltrim( $tag, 'vV' );

        if ( ! preg_match( '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $remote_version ) ) {
            return false;
        }

        $download_url    = '';
        $expanded_url    = 'https://github.com/' . $this->repo . '/releases/expanded_assets/' . rawurlencode( $tag );
        $assets_response = wp_remote_get(
            $expanded_url,
            array(
                'timeout' => 10,
                'headers' => array(
                    'User-Agent' => $user_agent,
                ),
            )
        );

        if ( ! is_wp_error( $assets_response ) && 200 === wp_remote_retrieve_response_code( $assets_response ) ) {
            $html = wp_remote_retrieve_body( $assets_response );
            if ( preg_match_all( '/href=["\']([^"\']+\.zip)["\']/i', $html, $asset_matches ) ) {
                foreach ( $asset_matches[1] as $href ) {
                    $candidate = 0 === strpos( $href, '/' ) ? 'https://github.com' . $href : $href;
                    $sanitized = $this->sanitize_github_url( $candidate );
                    if ( '' !== $sanitized ) {
                        $download_url = $sanitized;
                        break;
                    }
                }
            }
        }

        if ( '' === $download_url ) {
            $download_url = $this->sanitize_github_url( 'https://github.com/' . $this->repo . '/archive/refs/tags/' . rawurlencode( $tag ) . '.zip' );
        }

        if ( '' === $download_url ) {
            return false;
        }

        $html_url = $this->sanitize_github_url( 'https://github.com/' . $this->repo . '/releases/tag/' . rawurlencode( $tag ) );

        return (object) array(
            'version'      => $remote_version,
            'download_url' => $download_url,
            'changelog'    => '',
            'published_at' => '',
            'html_url'     => $html_url ? $html_url : 'https://github.com/' . $this->repo,
        );
    }

    private function get_remote_release() {
        $cached = get_transient( $this->transient_key );
        if ( false !== $cached ) {
            if ( ! is_object( $cached ) || ! empty( $cached->failed ) ) {
                return false;
            }
            return $cached;
        }

        $api_url      = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
        $response     = wp_remote_get(
            $api_url,
            array(
                'timeout' => 10,
                'headers' => array(
                    'Accept'     => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
                ),
            )
        );
        $release_data = false;

        if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ) );
            if ( is_object( $body ) && ! empty( $body->tag_name ) ) {
                $remote_version = ltrim( (string) $body->tag_name, 'vV' );
                if ( preg_match( '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $remote_version ) ) {
                    $download_url = $this->get_package_url_from_api( $body );
                    if ( '' !== $download_url ) {
                        $repo_url     = 'https://github.com/' . $this->repo;
                        $html_url     = isset( $body->html_url ) ? $this->sanitize_github_url( (string) $body->html_url ) : '';
                        $release_data = (object) array(
                            'version'      => $remote_version,
                            'download_url' => $download_url,
                            'changelog'    => isset( $body->body ) && is_string( $body->body ) ? $body->body : '',
                            'published_at' => isset( $body->published_at ) && is_string( $body->published_at ) ? $body->published_at : '',
                            'html_url'     => $html_url ? $html_url : $repo_url,
                        );
                    }
                }
            }
        }

        if ( false === $release_data ) {
            $release_data = $this->get_fallback_release();
        }

        if ( false === $release_data ) {
            set_transient( $this->transient_key, (object) array( 'failed' => true ), 300 );
            return false;
        }

        set_transient( $this->transient_key, $release_data, $this->cache_duration );
        return $release_data;
    }

    public function check_for_update( $transient ) {
        if ( empty( $transient ) || ! is_object( $transient ) || empty( $transient->checked ) ) {
            return $transient;
        }

        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = array();
        }
        if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
            $transient->no_update = array();
        }

        $release = $this->get_remote_release();
        if ( false === $release ) {
            return $transient;
        }

        $local_version = $this->get_local_version();

        $obj               = new stdClass();
        $obj->id           = $this->plugin_basename;
        $obj->slug         = $this->slug;
        $obj->plugin       = $this->plugin_basename;
        $obj->url          = $release->html_url;
        $obj->requires     = $this->requires_wp;
        $obj->tested       = $this->tested_up_to;
        $obj->requires_php = $this->requires_php;

        if ( version_compare( $release->version, $local_version, '>' ) ) {
            $obj->new_version                              = $release->version;
            $obj->package                                  = $release->download_url;
            $transient->response[ $this->plugin_basename ] = $obj;
            unset( $transient->no_update[ $this->plugin_basename ] );
        } else {
            $obj->new_version                               = $local_version;
            $obj->package                                   = '';
            $transient->no_update[ $this->plugin_basename ] = $obj;
            unset( $transient->response[ $this->plugin_basename ] );
        }

        return $transient;
    }

    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $this->slug !== $args->slug ) {
            return $result;
        }

        $release = $this->get_remote_release();
        if ( false === $release ) {
            return $result;
        }

        return (object) array(
            'name'          => 'GNN Filehub',
            'slug'          => $this->slug,
            'version'       => $release->version,
            'author'        => 'BigDesigner',
            'homepage'      => 'https://gnn.com.tr',
            'requires'      => $this->requires_wp,
            'requires_php'  => $this->requires_php,
            'tested'        => $this->tested_up_to,
            'last_updated'  => $release->published_at,
            'download_link' => $release->download_url,
            'sections'      => array(
                'description' => '100% WordPress Core Native, zero-dependency file upload and multi-cloud storage management plugin.',
                'changelog'   => nl2br( esc_html( $release->changelog ) ),
            ),
        );
    }

    public function capture_pre_install_state( $response, $hook_extra ) {
        if ( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->plugin_basename ) {
            if ( ! function_exists( 'is_plugin_active' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $this->was_active = is_plugin_active( $this->plugin_basename );
        }

        return $response;
    }

    public function after_install( $response, $hook_extra, $result ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( is_wp_error( $result ) || ! is_array( $result ) ) {
            return $result;
        }

        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
            return $result;
        }

        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $plugin_dir  = wp_normalize_path( untrailingslashit( WP_PLUGIN_DIR . '/' . $this->slug ) );
        $destination = ! empty( $result['destination'] ) ? wp_normalize_path( untrailingslashit( $result['destination'] ) ) : '';

        if ( '' !== $destination && $destination !== $plugin_dir && $wp_filesystem && $wp_filesystem->exists( $destination ) ) {
            if ( $wp_filesystem->move( $destination, $plugin_dir, true ) ) {
                $result['destination']      = trailingslashit( $plugin_dir );
                $result['destination_name'] = $this->slug;
            }
        } elseif ( $destination === $plugin_dir ) {
            $result['destination']      = trailingslashit( $plugin_dir );
            $result['destination_name'] = $this->slug;
        }

        wp_clean_plugins_cache( true );

        if ( $this->was_active ) {
            activate_plugin( $this->plugin_basename );
            $this->was_active = false;
        }

        delete_transient( $this->transient_key );

        return $result;
    }

    public function handle_manual_check() {
        if ( isset( $_GET['filehub_check_update'] ) && '1' === $_GET['filehub_check_update'] ) {
            $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'filehub_manual_update' ) || ! current_user_can( 'update_plugins' ) ) {
                wp_die( esc_html__( 'Güvenlik doğrulaması başarısız.', 'gnn-filehub' ), '', array( 'response' => 403 ) );
            }

            delete_transient( $this->transient_key );
            delete_site_transient( 'update_plugins' );

            wp_safe_redirect( admin_url( 'update-core.php?force-check=1' ) );
            exit;
        }
    }

    public function clear_cache() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        delete_transient( $this->transient_key );
    }
}
