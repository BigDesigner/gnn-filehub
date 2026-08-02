<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once GNN_FILEHUB_PATH . 'inc/class-filehub-attachment.php';

/**
 * Class FileHub_Admin
 * Generates WP Native Admin Dashboard & Settings screens.
 */
class FileHub_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    /**
     * Add Plugin Admin Menus
     */
    public function add_admin_menu() {
        add_menu_page(
            __( 'FileHub - Genel Bakış', 'gnn-filehub' ),
            'FileHub',
            'manage_options',
            'filehub',
            array( $this, 'render_dashboard_page' ),
            'dashicons-cloud-upload',
            30
        );

        add_submenu_page(
            'filehub',
            __( 'FileHub - Ayarlar', 'gnn-filehub' ),
            __( 'Ayarlar', 'gnn-filehub' ),
            'manage_options',
            'filehub-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Enqueue Admin Assets
     */
    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'filehub' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'filehub-admin-css',
            GNN_FILEHUB_URL . 'assets/css/filehub-admin.css',
            array(),
            GNN_FILEHUB_VERSION
        );
    }

    /**
     * Register Plugin Options
     */
    public function register_settings() {
        register_setting( 'filehub_settings_group', 'filehub_guest_upload' );
        register_setting( 'filehub_settings_group', 'filehub_strict_mime' );
        register_setting( 'filehub_settings_group', 'filehub_auto_rename' );
        register_setting( 'filehub_settings_group', 'filehub_storage_driver' );
        register_setting( 'filehub_settings_group', 'filehub_allowed_extensions' );
        register_setting( 'filehub_settings_group', 'filehub_r2_account_id' );
        register_setting( 'filehub_settings_group', 'filehub_r2_access_key' );
        register_setting( 'filehub_settings_group', 'filehub_r2_secret_key' );
        register_setting( 'filehub_settings_group', 'filehub_r2_bucket' );
        register_setting( 'filehub_settings_group', 'filehub_gdrive_client_id' );
        register_setting( 'filehub_settings_group', 'filehub_gdrive_client_secret' );
        register_setting( 'filehub_settings_group', 'filehub_gdrive_refresh_token' );
        register_setting( 'filehub_settings_group', 'filehub_gdrive_folder_id' );
    }

    /**
     * Render Dashboard Page
     */
    public function render_dashboard_page() {
        $stats = FileHub_Attachment::get_system_stats();
        $r2_free_gb   = 10;
        $r2_used_gb   = round( $stats['driver_bytes']['r2'] / ( 1024 * 1024 * 1024 ), 2 );
        $r2_pct       = min( 100, round( ( $r2_used_gb / $r2_free_gb ) * 100, 1 ) );

        $gdrive_free_gb = 15;
        $gdrive_used_gb = round( $stats['driver_bytes']['gdrive'] / ( 1024 * 1024 * 1024 ), 2 );
        $gdrive_pct     = min( 100, round( ( $gdrive_used_gb / $gdrive_free_gb ) * 100, 1 ) );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'GNN FileHub NextGen - Genel Bakış', 'gnn-filehub' ); ?></h1>
            <hr class="wp-header-end">

            <div class="filehub-dashboard-grid">
                <div class="filehub-card">
                    <h3><?php esc_html_e( 'Toplam Dosya Sayısı', 'gnn-filehub' ); ?></h3>
                    <div class="filehub-stat-number"><?php echo esc_html( number_format_i18n( $stats['total_files'] ) ); ?></div>
                </div>
                <div class="filehub-card">
                    <h3><?php esc_html_e( 'Toplam İndirme', 'gnn-filehub' ); ?></h3>
                    <div class="filehub-stat-number"><?php echo esc_html( number_format_i18n( $stats['total_downloads'] ) ); ?></div>
                </div>
                <div class="filehub-card">
                    <h3><?php esc_html_e( 'Kullanılan Toplam Alan', 'gnn-filehub' ); ?></h3>
                    <div class="filehub-stat-number"><?php echo esc_html( size_format( $stats['total_bytes'] ) ); ?></div>
                </div>
                <div class="filehub-card">
                    <h3><?php esc_html_e( 'Aktif Depolama Sürücüsü', 'gnn-filehub' ); ?></h3>
                    <div class="filehub-stat-number" style="text-transform:uppercase;"><?php echo esc_html( get_option( 'filehub_storage_driver', 'local' ) ); ?></div>
                </div>
            </div>

            <h2 style="margin-top: 30px;"><?php esc_html_e( 'Bulut Depolama Ücretsiz Kota Takibi', 'gnn-filehub' ); ?></h2>
            <div class="filehub-dashboard-grid">
                <div class="filehub-card">
                    <h3>Cloudflare R2 (10 GB Free Tier)</h3>
                    <p><?php printf( esc_html__( '%s GB / 10 GB (%%%s)', 'gnn-filehub' ), $r2_used_gb, $r2_pct ); ?></p>
                    <div class="filehub-progress-bar">
                        <div class="filehub-progress-fill" style="width: <?php echo esc_attr( $r2_pct ); ?>%;"></div>
                    </div>
                </div>

                <div class="filehub-card">
                    <h3>Google Drive (15 GB Free Tier)</h3>
                    <p><?php printf( esc_html__( '%s GB / 15 GB (%%%s)', 'gnn-filehub' ), $gdrive_used_gb, $gdrive_pct ); ?></p>
                    <div class="filehub-progress-bar">
                        <div class="filehub-progress-fill" style="width: <?php echo esc_attr( $gdrive_pct ); ?>%;"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Settings Page with Pure CSS Toggles
     */
    public function render_settings_page() {
        $guest_upload = get_option( 'filehub_guest_upload', '0' );
        $strict_mime  = get_option( 'filehub_strict_mime', '1' );
        $auto_rename  = get_option( 'filehub_auto_rename', '1' );
        $driver       = get_option( 'filehub_storage_driver', 'local' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'GNN FileHub NextGen - Ayarlar', 'gnn-filehub' ); ?></h1>
            <hr class="wp-header-end">

            <form method="post" action="options.php">
                <?php
                settings_fields( 'filehub_settings_group' );
                do_settings_sections( 'filehub_settings_group' );
                ?>

                <div class="filehub-card" style="margin-top: 20px;">
                    <h3><?php esc_html_e( 'Genel & Güvenlik Ayarları', 'gnn-filehub' ); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Misafir Yükleme İzni', 'gnn-filehub' ); ?></th>
                            <td>
                                <label class="filehub-switch">
                                    <input type="checkbox" name="filehub_guest_upload" value="1" <?php checked( '1', $guest_upload ); ?>>
                                    <span class="filehub-slider"></span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Katı MIME Doğrulaması', 'gnn-filehub' ); ?></th>
                            <td>
                                <label class="filehub-switch">
                                    <input type="checkbox" name="filehub_strict_mime" value="1" <?php checked( '1', $strict_mime ); ?>>
                                    <span class="filehub-slider"></span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Otomatik Çakışma Önleme', 'gnn-filehub' ); ?></th>
                            <td>
                                <label class="filehub-switch">
                                    <input type="checkbox" name="filehub_auto_rename" value="1" <?php checked( '1', $auto_rename ); ?>>
                                    <span class="filehub-slider"></span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'İzin Verilen Uzantılar', 'gnn-filehub' ); ?></th>
                            <td>
                                <input type="text" name="filehub_allowed_extensions" class="regular-text" value="<?php echo esc_attr( get_option( 'filehub_allowed_extensions', 'jpg,jpeg,png,gif,pdf,zip,doc,docx,xlsx' ) ); ?>">
                                <p class="description"><?php esc_html_e( 'Virgülle ayrılmış liste.', 'gnn-filehub' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Depolama Sürücüsü', 'gnn-filehub' ); ?></th>
                            <td>
                                <select name="filehub_storage_driver">
                                    <option value="local" <?php selected( 'local', $driver ); ?>>Yerel Korumalı Depolama (Local Protected)</option>
                                    <option value="r2" <?php selected( 'r2', $driver ); ?>>Cloudflare R2</option>
                                    <option value="gdrive" <?php selected( 'gdrive', $driver ); ?>>Google Drive</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="filehub-card" style="margin-top: 20px;">
                    <h3>Cloudflare R2 API Ayarları</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Account ID</th>
                            <td><input type="text" name="filehub_r2_account_id" class="regular-text" value="<?php echo esc_attr( get_option( 'filehub_r2_account_id' ) ); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row">Access Key ID</th>
                            <td><input type="text" name="filehub_r2_access_key" class="regular-text" value="<?php echo esc_attr( get_option( 'filehub_r2_access_key' ) ); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row">Secret Access Key</th>
                            <td><input type="password" name="filehub_r2_secret_key" class="regular-text" value="<?php echo esc_attr( get_option( 'filehub_r2_secret_key' ) ); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row">Bucket Name</th>
                            <td><input type="text" name="filehub_r2_bucket" class="regular-text" value="<?php echo esc_attr( get_option( 'filehub_r2_bucket' ) ); ?>"></td>
                        </tr>
                    </table>
                </div>

                <div class="filehub-card" style="margin-top: 20px;">
                    <h3>Google Drive API v3 Ayarları</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Client ID</th>
                            <td><input type="text" name="filehub_gdrive_client_id" class="regular-text" value="<?php echo esc_attr( get_option( 'filehub_gdrive_client_id' ) ); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row">Client Secret</th>
                            <td><input type="password" name="filehub_gdrive_client_secret" class="regular-text" value="<?php echo esc_attr( get_option( 'filehub_gdrive_client_secret' ) ); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row">Refresh Token</th>
                            <td><input type="text" name="filehub_gdrive_refresh_token" class="regular-text" value="<?php echo esc_attr( get_option( 'filehub_gdrive_refresh_token' ) ); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row">Target Folder ID</th>
                            <td><input type="text" name="filehub_gdrive_folder_id" class="regular-text" value="<?php echo esc_attr( get_option( 'filehub_gdrive_folder_id' ) ); ?>"></td>
                        </tr>
                    </table>
                </div>

                <?php submit_button( __( 'Ayarları Kaydet', 'gnn-filehub' ) ); ?>
            </form>
        </div>
        <?php
    }
}
