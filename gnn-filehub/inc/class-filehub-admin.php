<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once GNN_FILEHUB_PATH . 'inc/class-filehub-attachment.php';

/**
 * Class FileHub_Admin
 * Generates Sleek Tabbed WP Native Admin Dashboard & Settings screens.
 */
class FileHub_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_init', array( $this, 'maybe_create_missing_pages' ) );
        add_action( 'admin_init', array( $this, 'handle_export_settings' ) );
        add_action( 'admin_init', array( $this, 'handle_import_settings' ) );
        add_action( 'admin_init', array( $this, 'handle_gdrive_oauth_start' ) );
        add_action( 'admin_init', array( $this, 'handle_gdrive_oauth_callback' ) );

        add_action( 'show_user_profile', array( $this, 'render_user_profile_fields' ) );
        add_action( 'edit_user_profile', array( $this, 'render_user_profile_fields' ) );
        add_action( 'personal_options_update', array( $this, 'save_user_profile_fields' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_user_profile_fields' ) );
    }

    /**
     * Add Plugin Admin Menus
     * Genel Bakış, Tüm Dosyalar & Ayarlar are three distinct pages — the settings tabs
     * (Genel & Güvenlik / Otomatik Sayfa Atamaları / Depolama Sürücüleri) only ever live
     * under "Ayarlar", since Overview and All Files aren't configuration screens.
     */
    public function add_admin_menu() {
        add_menu_page(
            __( 'FileHub - Genel Bakış', 'gnn-filehub' ),
            'FileHub',
            'manage_options',
            'filehub',
            array( $this, 'render_overview_page' ),
            'dashicons-cloud-upload',
            '79.103'
        );

        add_submenu_page(
            'filehub',
            __( 'FileHub - Genel Bakış', 'gnn-filehub' ),
            __( 'Genel Bakış', 'gnn-filehub' ),
            'manage_options',
            'filehub',
            array( $this, 'render_overview_page' )
        );

        add_submenu_page(
            'filehub',
            __( 'FileHub - Tüm Dosyalar', 'gnn-filehub' ),
            __( 'Tüm Dosyalar', 'gnn-filehub' ),
            'manage_options',
            'filehub-files',
            array( $this, 'render_files_page' )
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

        if ( isset( $_GET['page'] ) && 'filehub-files' === $_GET['page'] ) {
            wp_enqueue_style(
                'filehub-public-css',
                GNN_FILEHUB_URL . 'assets/css/filehub-public.css',
                array( 'filehub-admin-css' ),
                GNN_FILEHUB_VERSION
            );

            wp_enqueue_script(
                'filehub-public-js',
                GNN_FILEHUB_URL . 'assets/js/filehub-public.js',
                array(),
                GNN_FILEHUB_VERSION,
                true
            );

            wp_localize_script( 'filehub-public-js', 'filehub_vars', array(
                'rest_url'      => esc_url_raw( rest_url() ),
                'nonce'         => wp_create_nonce( 'wp_rest' ),
                'active_driver' => get_option( 'filehub_storage_driver', 'local' ),
            ) );
        }
    }

    /**
     * Register Plugin Options
     */
    public function register_settings() {
        // General & Security Options
        register_setting( 'filehub_general_group', 'filehub_guest_upload' );
        register_setting( 'filehub_general_group', 'filehub_strict_mime' );
        register_setting( 'filehub_general_group', 'filehub_auto_rename' );
        register_setting( 'filehub_general_group', 'filehub_allowed_extensions' );

        // Automatic Page Assignments
        register_setting( 'filehub_pages_group', 'filehub_page_account' );
        register_setting( 'filehub_pages_group', 'filehub_page_uploader' );
        register_setting( 'filehub_pages_group', 'filehub_page_manager' );
        register_setting( 'filehub_pages_group', 'filehub_page_admin_files' );

        // Storage Driver Options
        register_setting( 'filehub_storage_group', 'filehub_storage_driver' );
        register_setting( 'filehub_storage_group', 'filehub_r2_account_id' );
        register_setting( 'filehub_storage_group', 'filehub_r2_access_key' );
        register_setting( 'filehub_storage_group', 'filehub_r2_secret_key' );
        register_setting( 'filehub_storage_group', 'filehub_r2_bucket' );
        register_setting( 'filehub_storage_group', 'filehub_gdrive_client_id' );
        register_setting( 'filehub_storage_group', 'filehub_gdrive_client_secret' );
        register_setting( 'filehub_storage_group', 'filehub_gdrive_folder_id' );
        // filehub_gdrive_refresh_token is intentionally NOT registered here: it's no longer a
        // form field, it's written directly via update_option() by the OAuth "Connect" flow
        // (handle_gdrive_oauth_callback). Registering it to this group would make options.php
        // blank it out on every save of this form, since it's never present in the POST data.
    }

    /**
     * Page: Genel Bakış (Overview & Analytics) — standalone, no settings tabs
     */
    public function render_overview_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">GNN Filehub</h1>
            <hr class="wp-header-end">
            <?php $this->render_tab_overview(); ?>
        </div>
        <?php
    }

    /**
     * Page: Tüm Dosyalar (All Members' Files) — standalone, no settings tabs
     */
    public function render_files_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">GNN Filehub — <?php esc_html_e( 'Tüm Dosyalar', 'gnn-filehub' ); ?></h1>
            <hr class="wp-header-end">
            <?php $this->render_tab_files(); ?>
        </div>
        <?php
    }

    /**
     * Page: Ayarlar — the only page with a tab bar (Genel & Güvenlik / Otomatik Sayfa
     * Atamaları / Depolama Sürücüleri), since those three are the actual configuration screens.
     */
    public function render_settings_page() {
        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'general';
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">GNN Filehub — <?php esc_html_e( 'Ayarlar', 'gnn-filehub' ); ?></h1>
            <hr class="wp-header-end">

            <nav class="nav-tab-wrapper filehub-nav-tab-wrapper">
                <a href="?page=filehub-settings&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-settings" style="vertical-align: text-bottom;"></span> <?php esc_html_e( 'Genel & Güvenlik', 'gnn-filehub' ); ?>
                </a>
                <a href="?page=filehub-settings&tab=pages" class="nav-tab <?php echo $active_tab === 'pages' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-page" style="vertical-align: text-bottom;"></span> <?php esc_html_e( 'Otomatik Sayfa Atamaları', 'gnn-filehub' ); ?>
                </a>
                <a href="?page=filehub-settings&tab=storage" class="nav-tab <?php echo $active_tab === 'storage' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-cloud-upload" style="vertical-align: text-bottom;"></span> <?php esc_html_e( 'Depolama Sürücüleri', 'gnn-filehub' ); ?>
                </a>
                <a href="?page=filehub-settings&tab=maintenance" class="nav-tab <?php echo $active_tab === 'maintenance' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-tools" style="vertical-align: text-bottom;"></span> <?php esc_html_e( 'Bakım', 'gnn-filehub' ); ?>
                </a>
            </nav>

            <?php
            switch ( $active_tab ) {
                case 'pages':
                    $this->render_tab_pages();
                    break;
                case 'storage':
                    $this->render_tab_storage();
                    break;
                case 'maintenance':
                    $this->render_tab_maintenance();
                    break;
                case 'general':
                default:
                    $this->render_tab_general();
                    break;
            }
            ?>
        </div>
        <?php
    }

    /**
     * Overview & Analytics Content
     */
    private function render_tab_overview() {
        $stats = FileHub_Attachment::get_system_stats();

        $local_used_bytes = $stats['driver_bytes']['local'];

        $r2_configured = get_option( 'filehub_r2_account_id' ) && get_option( 'filehub_r2_access_key' ) && get_option( 'filehub_r2_secret_key' ) && get_option( 'filehub_r2_bucket' );
        $r2_free_gb    = 10;
        $r2_used_gb    = round( $stats['driver_bytes']['r2'] / ( 1024 * 1024 * 1024 ), 2 );
        $r2_pct        = min( 100, round( ( $r2_used_gb / $r2_free_gb ) * 100, 1 ) );

        $gdrive_configured = get_option( 'filehub_gdrive_client_id' ) && get_option( 'filehub_gdrive_client_secret' ) && get_option( 'filehub_gdrive_refresh_token' );
        $gdrive_free_gb    = 15;
        $gdrive_used_gb    = round( $stats['driver_bytes']['gdrive'] / ( 1024 * 1024 * 1024 ), 2 );
        $gdrive_pct        = min( 100, round( ( $gdrive_used_gb / $gdrive_free_gb ) * 100, 1 ) );
        ?>
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

        <h2 style="margin-top: 30px; font-weight: 600;"><?php esc_html_e( 'Depolama Kota Takibi', 'gnn-filehub' ); ?></h2>
        <div class="filehub-dashboard-grid">
            <div class="filehub-card">
                <h3><?php esc_html_e( 'Yerel Depolama (Sunucu Diski)', 'gnn-filehub' ); ?></h3>
                <p><?php echo esc_html( size_format( $local_used_bytes ) ); ?> <?php esc_html_e( 'kullanılıyor', 'gnn-filehub' ); ?></p>
            </div>

            <?php if ( $r2_configured ) : ?>
                <div class="filehub-card">
                    <h3>Cloudflare R2 (10 GB Free Tier Tracker)</h3>
                    <p><?php printf( esc_html__( '%s GB / 10 GB (%%%s)', 'gnn-filehub' ), $r2_used_gb, $r2_pct ); ?></p>
                    <div class="filehub-progress-bar">
                        <div class="filehub-progress-fill" style="width: <?php echo esc_attr( $r2_pct ); ?>%;"></div>
                    </div>
                </div>
            <?php else : ?>
                <div class="filehub-card">
                    <h3>Cloudflare R2</h3>
                    <p class="description"><?php esc_html_e( 'Henüz yapılandırılmadı. API bilgilerini "Depolama Sürücüleri" sekmesinden girin.', 'gnn-filehub' ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( $gdrive_configured ) : ?>
                <div class="filehub-card">
                    <h3>Google Drive (15 GB Free Tier Tracker)</h3>
                    <p><?php printf( esc_html__( '%s GB / 15 GB (%%%s)', 'gnn-filehub' ), $gdrive_used_gb, $gdrive_pct ); ?></p>
                    <div class="filehub-progress-bar">
                        <div class="filehub-progress-fill" style="width: <?php echo esc_attr( $gdrive_pct ); ?>%;"></div>
                    </div>
                </div>
            <?php else : ?>
                <div class="filehub-card">
                    <h3>Google Drive</h3>
                    <p class="description"><?php esc_html_e( 'Henüz yapılandırılmadı. API bilgilerini "Depolama Sürücüleri" sekmesinden girin.', 'gnn-filehub' ); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Settings Tab: General & Security
     */
    private function render_tab_general() {
        $guest_upload = get_option( 'filehub_guest_upload', '0' );
        $strict_mime  = get_option( 'filehub_strict_mime', '1' );
        $auto_rename  = get_option( 'filehub_auto_rename', '1' );
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'filehub_general_group' );
            ?>
            <div class="filehub-card">
                <h3><?php esc_html_e( 'Genel & Güvenlik Yapılandırması', 'gnn-filehub' ); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Misafir Yükleme İzni', 'gnn-filehub' ); ?></th>
                        <td>
                            <label class="filehub-switch">
                                <input type="checkbox" name="filehub_guest_upload" value="1" <?php checked( '1', $guest_upload ); ?>>
                                <span class="filehub-slider"></span>
                            </label>
                            <p class="description"><?php esc_html_e( 'Açık olduğunda üye olmayan misafirlerin dosya yüklemesine izin verilir.', 'gnn-filehub' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Katı MIME Doğrulaması', 'gnn-filehub' ); ?></th>
                        <td>
                            <label class="filehub-switch">
                                <input type="checkbox" name="filehub_strict_mime" value="1" <?php checked( '1', $strict_mime ); ?>>
                                <span class="filehub-slider"></span>
                            </label>
                            <p class="description"><?php esc_html_e( 'Finfo ile sunucu seviyesinde gerçek dosya içeriği doğrulaması yapılır.', 'gnn-filehub' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Otomatik İsim Çakışma Önleme', 'gnn-filehub' ); ?></th>
                        <td>
                            <label class="filehub-switch">
                                <input type="checkbox" name="filehub_auto_rename" value="1" <?php checked( '1', $auto_rename ); ?>>
                                <span class="filehub-slider"></span>
                            </label>
                            <p class="description"><?php esc_html_e( 'Aynı isimde dosya yüklenirse sonuna otomatik Sayaç (-1, -2) eklenir.', 'gnn-filehub' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'İzin Verilen Uzantı Listesi', 'gnn-filehub' ); ?></th>
                        <td>
                            <input type="text" name="filehub_allowed_extensions" class="large-text" value="<?php echo esc_attr( get_option( 'filehub_allowed_extensions', 'jpg,jpeg,png,gif,pdf,zip,doc,docx,xlsx' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'Virgülle ayrılmış dosya uzantıları listesi.', 'gnn-filehub' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
            <?php submit_button( __( 'Genel Ayarları Kaydet', 'gnn-filehub' ) ); ?>
        </form>
        <?php
    }

    /**
     * Required Page Assignments Configuration Map
     *
     * @return array
     */
    private function get_required_pages_config(): array {
        return array(
            'filehub_page_account'     => array(
                'title'     => __( 'Hesabım', 'gnn-filehub' ),
                'shortcode' => '[filehub_account]',
            ),
            'filehub_page_uploader'    => array(
                'title'     => __( 'Dosya Yükle', 'gnn-filehub' ),
                'shortcode' => '[filehub_uploader]',
            ),
            'filehub_page_manager'     => array(
                'title'     => __( 'Dosyalarım', 'gnn-filehub' ),
                'shortcode' => '[filehub_manager]',
            ),
            'filehub_page_admin_files' => array(
                'title'     => __( 'Tüm Dosyalar', 'gnn-filehub' ),
                'shortcode' => '[filehub_admin_files]',
            ),
        );
    }

    /**
     * Handle "Otomatik Sayfa Oluştur" Form Submission
     */
    public function maybe_create_missing_pages() {
        if ( ! isset( $_POST['filehub_create_pages_nonce'] ) || ! wp_verify_nonce( $_POST['filehub_create_pages_nonce'], 'filehub_create_pages' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $created = $this->create_missing_pages();

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'                  => 'filehub-settings',
                    'tab'                   => 'pages',
                    'filehub_pages_created' => $created,
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * Create any Missing/Unassigned Required Pages (WooCommerce-style Auto Setup)
     *
     * @return int Number of pages created.
     */
    private function create_missing_pages(): int {
        $created = 0;

        foreach ( $this->get_required_pages_config() as $option_name => $config ) {
            $page_id = (int) get_option( $option_name, 0 );
            $page    = $page_id ? get_post( $page_id ) : null;

            if ( $page && 'page' === $page->post_type && 'trash' !== $page->post_status ) {
                continue;
            }

            $new_page_id = wp_insert_post(
                array(
                    'post_title'   => $config['title'],
                    'post_content' => $config['shortcode'],
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                )
            );

            if ( $new_page_id && ! is_wp_error( $new_page_id ) ) {
                update_option( $option_name, $new_page_id );
                $created++;
            }
        }

        return $created;
    }

    /**
     * Settings Tab: Automatic Page Assignments
     */
    private function render_tab_pages() {
        ?>
        <?php if ( isset( $_GET['filehub_pages_created'] ) ) : ?>
            <?php $created_count = (int) $_GET['filehub_pages_created']; ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php
                    if ( $created_count > 0 ) {
                        printf(
                            /* translators: %d: number of pages created */
                            esc_html__( '%d eksik sayfa otomatik olarak oluşturuldu ve atandı.', 'gnn-filehub' ),
                            $created_count
                        );
                    } else {
                        esc_html_e( 'Tüm sayfalar zaten atanmış, yeni sayfa oluşturulmadı.', 'gnn-filehub' );
                    }
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field( 'filehub_create_pages', 'filehub_create_pages_nonce' ); ?>
            <div class="filehub-card" style="margin-bottom: 20px;">
                <h3><?php esc_html_e( 'Hızlı Kurulum', 'gnn-filehub' ); ?></h3>
                <p style="color: #646970; margin-bottom: 15px;">
                    <?php esc_html_e( 'Eksik veya atanmamış sayfaları tek tıkla otomatik olarak oluşturur ve ilgili kısa kodları atar.', 'gnn-filehub' ); ?>
                </p>
                <?php submit_button( __( 'Eksik Sayfaları Otomatik Oluştur', 'gnn-filehub' ), 'secondary', 'filehub_create_pages', false ); ?>
            </div>
        </form>

        <form method="post" action="options.php">
            <?php settings_fields( 'filehub_pages_group' ); ?>
            <div class="filehub-card">
                <h3><?php esc_html_e( 'Otomatik Sayfa Atamaları', 'gnn-filehub' ); ?></h3>
                <p style="color: #646970; margin-bottom: 20px;">
                    <?php esc_html_e( 'Aşağıdaki WordPress sayfalarını seçtiğinizde, kısa kodlar (shortcode) ilgili sayfalara otomatik olarak gömülür. Manuel kısa kod yazmak zorunda kalmazsınız.', 'gnn-filehub' ); ?>
                </p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="filehub_page_account"><?php esc_html_e( 'Hesap Sayfası (Giriş / Kayıt / Profil) [filehub_account]', 'gnn-filehub' ); ?></label></th>
                        <td>
                            <?php
                            wp_dropdown_pages( array(
                                'name'              => 'filehub_page_account',
                                'selected'          => get_option( 'filehub_page_account', 0 ),
                                'show_option_none'  => __( '-- Sayfa Seçin --', 'gnn-filehub' ),
                                'option_none_value' => '0',
                                'class'             => 'regular-text',
                            ) );
                            ?>
                            <p class="description"><?php esc_html_e( 'Tek sayfa: oturum kapalıysa giriş/kayıt sekmeleri, oturum açıksa profil ve şifre güncelleme gösterilir. Nav menüde bu sayfaya eklenen bağlantı, oturum durumuna göre otomatik olarak "Giriş Yap" veya "Profil" yazısına döner.', 'gnn-filehub' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="filehub_page_uploader"><?php esc_html_e( 'Dosya Yükleme Sayfası [filehub_uploader]', 'gnn-filehub' ); ?></label></th>
                        <td>
                            <?php
                            wp_dropdown_pages( array(
                                'name'              => 'filehub_page_uploader',
                                'selected'          => get_option( 'filehub_page_uploader', 0 ),
                                'show_option_none'  => __( '-- Sayfa Seçin --', 'gnn-filehub' ),
                                'option_none_value' => '0',
                                'class'             => 'regular-text',
                            ) );
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="filehub_page_manager"><?php esc_html_e( 'Dosyalarım Sayfası [filehub_manager]', 'gnn-filehub' ); ?></label></th>
                        <td>
                            <?php
                            wp_dropdown_pages( array(
                                'name'              => 'filehub_page_manager',
                                'selected'          => get_option( 'filehub_page_manager', 0 ),
                                'show_option_none'  => __( '-- Sayfa Seçin --', 'gnn-filehub' ),
                                'option_none_value' => '0',
                                'class'             => 'regular-text',
                            ) );
                            ?>
                            <p class="description"><?php esc_html_e( 'Üyeler bu sayfada yalnızca kendi yükledikleri dosyaları görür ve silebilir.', 'gnn-filehub' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="filehub_page_admin_files"><?php esc_html_e( 'Tüm Dosyalar Sayfası (Admin) [filehub_admin_files]', 'gnn-filehub' ); ?></label></th>
                        <td>
                            <?php
                            wp_dropdown_pages( array(
                                'name'              => 'filehub_page_admin_files',
                                'selected'          => get_option( 'filehub_page_admin_files', 0 ),
                                'show_option_none'  => __( '-- Sayfa Seçin --', 'gnn-filehub' ),
                                'option_none_value' => '0',
                                'class'             => 'regular-text',
                            ) );
                            ?>
                            <p class="description"><?php esc_html_e( 'Sadece yöneticilerin erişebildiği, tüm üyelerin yüklediği dosyaları listeleyen sayfa.', 'gnn-filehub' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
            <?php submit_button( __( 'Sayfa Atamalarını Kaydet', 'gnn-filehub' ) ); ?>
        </form>
        <?php
    }

    /**
     * Settings Tab: Storage Drivers & API Configurations
     */
    private function render_tab_storage() {
        $driver = get_option( 'filehub_storage_driver', 'local' );
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'filehub_storage_group' );
            ?>
            <div class="filehub-card">
                <h3><?php esc_html_e( 'Aktif Depolama Sürücü Seçimi', 'gnn-filehub' ); ?></h3>
                <div class="filehub-driver-grid">
                    <label class="filehub-driver-card <?php echo $driver === 'local' ? 'selected' : ''; ?>">
                        <input type="radio" name="filehub_storage_driver" value="local" <?php checked( 'local', $driver ); ?>>
                        <strong>Yerel Korumalı Depolama</strong>
                        <p style="margin: 5px 0 0 0; color: #646970; font-size: 0.9em;">.htaccess izolasyonlu güvenli sunucu depolaması.</p>
                    </label>

                    <label class="filehub-driver-card <?php echo $driver === 'r2' ? 'selected' : ''; ?>">
                        <input type="radio" name="filehub_storage_driver" value="r2" <?php checked( 'r2', $driver ); ?>>
                        <strong>Cloudflare R2 (S3)</strong>
                        <p style="margin: 5px 0 0 0; color: #646970; font-size: 0.9em;">10 GB Ücretsiz Kota, AWS SigV4 protokolü.</p>
                    </label>

                    <label class="filehub-driver-card <?php echo $driver === 'gdrive' ? 'selected' : ''; ?>">
                        <input type="radio" name="filehub_storage_driver" value="gdrive" <?php checked( 'gdrive', $driver ); ?>>
                        <strong>Google Drive API v3</strong>
                        <p style="margin: 5px 0 0 0; color: #646970; font-size: 0.9em;">15 GB Ücretsiz Kota, OAuth2 erişimi.</p>
                    </label>
                </div>
            </div>

            <div class="filehub-card filehub-storage-panel" data-driver="r2" style="margin-top: 20px; <?php echo $driver !== 'r2' ? 'display:none;' : ''; ?>">
                <h3>Cloudflare R2 API Bilgileri</h3>
                <p class="description" style="margin-bottom: 15px;">
                    <?php esc_html_e( 'Dosyalar tarayıcıdan doğrudan R2\'ye yüklenir (sunucunuzdan geçmez). Bunun çalışması için R2 bucket\'ınızın CORS ayarlarına sitenizin adresini eklemeniz gerekir:', 'gnn-filehub' ); ?>
                    <br>
                    <code style="user-select: all;">AllowedOrigins: <?php echo esc_html( home_url() ); ?></code>,
                    <code style="user-select: all;">AllowedMethods: PUT, GET, HEAD</code>
                </p>
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

            <div class="filehub-card filehub-storage-panel" data-driver="gdrive" style="margin-top: 20px; <?php echo $driver !== 'gdrive' ? 'display:none;' : ''; ?>">
                <h3>Google Drive API v3 Bilgileri</h3>

                <p class="description" style="margin-bottom: 15px;">
                    <?php esc_html_e( 'Google Cloud Console\'daki OAuth istemcinizin "Authorized redirect URIs" listesine şu adresi ekleyin:', 'gnn-filehub' ); ?>
                    <br>
                    <code style="user-select: all;"><?php echo esc_html( $this->get_gdrive_oauth_redirect_uri() ); ?></code>
                </p>

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
                        <th scope="row">Target Folder ID</th>
                        <td><input type="text" name="filehub_gdrive_folder_id" class="regular-text" value="<?php echo esc_attr( get_option( 'filehub_gdrive_folder_id' ) ); ?>"></td>
                    </tr>
                </table>

                <?php submit_button( __( 'Client ID / Secret / Klasör ID Kaydet', 'gnn-filehub' ), 'secondary', '', false ); ?>
            </div>

            <?php submit_button( __( 'Depolama Ayarlarını Kaydet', 'gnn-filehub' ) ); ?>
        </form>

        <?php
        $gdrive_refresh_token = get_option( 'filehub_gdrive_refresh_token', '' );
        $gdrive_client_id     = get_option( 'filehub_gdrive_client_id', '' );
        $gdrive_client_secret = get_option( 'filehub_gdrive_client_secret', '' );
        $gdrive_notice        = isset( $_GET['filehub_gdrive_oauth'] ) ? sanitize_text_field( $_GET['filehub_gdrive_oauth'] ) : '';
        ?>
        <div class="filehub-card filehub-storage-panel" data-driver="gdrive" style="margin-top: 20px; <?php echo $driver !== 'gdrive' ? 'display:none;' : ''; ?>">
            <h3><?php esc_html_e( 'Google Bağlantısı', 'gnn-filehub' ); ?></h3>

            <?php if ( $gdrive_notice ) : ?>
                <div class="notice notice-<?php echo 'connected' === $gdrive_notice ? 'success' : 'error'; ?> is-dismissible" style="margin: 0 0 15px;">
                    <p>
                        <?php
                        switch ( $gdrive_notice ) {
                            case 'connected':
                                esc_html_e( 'Google Drive hesabınız başarıyla bağlandı.', 'gnn-filehub' );
                                break;
                            case 'denied':
                                esc_html_e( 'İzin verilmedi, bağlantı iptal edildi.', 'gnn-filehub' );
                                break;
                            case 'missing_client':
                                esc_html_e( 'Önce Client ID ve Client Secret alanlarını doldurup kaydedin.', 'gnn-filehub' );
                                break;
                            case 'network_error':
                                esc_html_e( 'Google\'a bağlanılamadı, lütfen tekrar deneyin.', 'gnn-filehub' );
                                break;
                            case 'failed':
                                $reason = isset( $_GET['filehub_gdrive_oauth_reason'] ) ? sanitize_text_field( wp_unslash( $_GET['filehub_gdrive_oauth_reason'] ) ) : '';
                                printf(
                                    /* translators: %s: raw error reason returned by Google */
                                    esc_html__( 'Bağlantı başarısız: %s', 'gnn-filehub' ),
                                    esc_html( $reason )
                                );
                                break;
                        }
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <p>
                <?php if ( $gdrive_refresh_token ) : ?>
                    <span style="color: #00a32a; font-weight: 600;">✓ <?php esc_html_e( 'Google Drive hesabınız bağlı.', 'gnn-filehub' ); ?></span>
                <?php else : ?>
                    <span style="color: #646970;"><?php esc_html_e( 'Henüz bağlı değil.', 'gnn-filehub' ); ?></span>
                <?php endif; ?>
            </p>

            <?php if ( $gdrive_client_id && $gdrive_client_secret ) : ?>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=filehub-settings&tab=storage&filehub_gdrive_connect=1' ), 'filehub_gdrive_connect' ) ); ?>" class="button button-primary">
                    <?php echo $gdrive_refresh_token ? esc_html__( 'Yeniden Bağlan', 'gnn-filehub' ) : esc_html__( 'Google ile Bağlan', 'gnn-filehub' ); ?>
                </a>
            <?php else : ?>
                <p class="description"><?php esc_html_e( 'Bağlanmadan önce yukarıdan Client ID ve Client Secret\'ı kaydedin.', 'gnn-filehub' ); ?></p>
            <?php endif; ?>
        </div>
        <script>
        (function() {
            var radios = document.querySelectorAll('input[name="filehub_storage_driver"]');
            var panels = document.querySelectorAll('.filehub-storage-panel');
            function updatePanels() {
                var selected = document.querySelector('input[name="filehub_storage_driver"]:checked');
                var value = selected ? selected.value : 'local';
                panels.forEach(function( panel ) {
                    panel.style.display = panel.getAttribute('data-driver') === value ? '' : 'none';
                });
            }
            radios.forEach(function( radio ) {
                radio.addEventListener('change', updatePanels);
            });
            updatePanels();
        })();
        </script>
        <?php
    }

    /**
     * Google Drive OAuth2 Redirect URI
     * Must be registered verbatim (no extra query params) in the Google Cloud Console OAuth
     * client's "Authorized redirect URIs" list — Google matches it exactly against this value.
     */
    private function get_gdrive_oauth_redirect_uri(): string {
        return admin_url( 'admin.php?page=filehub-settings&tab=storage' );
    }

    /**
     * Start the Google Drive "Connect" OAuth2 Flow
     * Redirects the admin to Google's consent screen; the callback (handle_gdrive_oauth_callback)
     * exchanges the returned authorization code for a refresh token automatically.
     */
    public function handle_gdrive_oauth_start() {
        if ( ! isset( $_GET['filehub_gdrive_connect'] ) || '1' !== $_GET['filehub_gdrive_connect'] ) {
            return;
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'filehub_gdrive_connect' ) ) {
            wp_die( esc_html__( 'Güvenlik doğrulaması başarısız.', 'gnn-filehub' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'gnn-filehub' ) );
        }

        $client_id = get_option( 'filehub_gdrive_client_id', '' );
        if ( empty( $client_id ) ) {
            wp_safe_redirect( add_query_arg( array( 'page' => 'filehub-settings', 'tab' => 'storage', 'filehub_gdrive_oauth' => 'missing_client' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        $params = array(
            'client_id'     => $client_id,
            'redirect_uri'  => $this->get_gdrive_oauth_redirect_uri(),
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/drive',
            'access_type'   => 'offline',
            // Forces Google to re-issue a refresh_token even if this app was already
            // authorized before — without it, a repeat authorization returns none.
            'prompt'        => 'consent',
            'state'         => wp_create_nonce( 'filehub_gdrive_oauth_state' ),
        );

        wp_redirect( 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $params ) );
        exit;
    }

    /**
     * Handle the Google OAuth2 Redirect Back & Exchange the Code for a Refresh Token
     */
    public function handle_gdrive_oauth_callback() {
        if ( ! isset( $_GET['page'] ) || 'filehub-settings' !== $_GET['page'] || ! isset( $_GET['tab'] ) || 'storage' !== $_GET['tab'] ) {
            return;
        }

        if ( isset( $_GET['error'] ) ) {
            wp_safe_redirect( add_query_arg( array( 'page' => 'filehub-settings', 'tab' => 'storage', 'filehub_gdrive_oauth' => 'denied' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( ! isset( $_GET['code'] ) || ! isset( $_GET['state'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['state'] ) ), 'filehub_gdrive_oauth_state' ) ) {
            wp_die( esc_html__( 'Güvenlik doğrulaması başarısız (state).', 'gnn-filehub' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'gnn-filehub' ) );
        }

        $client_id     = get_option( 'filehub_gdrive_client_id', '' );
        $client_secret = get_option( 'filehub_gdrive_client_secret', '' );

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'body' => array(
                'code'          => sanitize_text_field( wp_unslash( $_GET['code'] ) ),
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $this->get_gdrive_oauth_redirect_uri(),
                'grant_type'    => 'authorization_code',
            ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            wp_safe_redirect( add_query_arg( array( 'page' => 'filehub-settings', 'tab' => 'storage', 'filehub_gdrive_oauth' => 'network_error' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $data['refresh_token'] ) ) {
            $reason = ! empty( $data['error_description'] ) ? $data['error_description'] : ( ! empty( $data['error'] ) ? $data['error'] : 'unknown' );
            wp_safe_redirect(
                add_query_arg(
                    array(
                        'page'                        => 'filehub-settings',
                        'tab'                         => 'storage',
                        'filehub_gdrive_oauth'        => 'failed',
                        'filehub_gdrive_oauth_reason' => rawurlencode( $reason ),
                    ),
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }

        update_option( 'filehub_gdrive_refresh_token', $data['refresh_token'] );
        delete_transient( 'filehub_gdrive_access_token' );

        wp_safe_redirect( add_query_arg( array( 'page' => 'filehub-settings', 'tab' => 'storage', 'filehub_gdrive_oauth' => 'connected' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * All Members' Files (Admin-Only Backend View) Content
     */
    private function render_tab_files() {
        ?>
        <div class="filehub-card filehub-manager" style="margin: 20px 0;">
            <div class="filehub-manager-toolbar">
                <h3 style="margin: 0;"><?php esc_html_e( 'Tüm Üye Dosyaları', 'gnn-filehub' ); ?></h3>
                <input type="text" class="filehub-search-input" placeholder="<?php esc_attr_e( 'Dosya veya yükleyen ara...', 'gnn-filehub' ); ?>">
            </div>
            <div class="filehub-file-list" data-scope="all">
                <p><?php esc_html_e( 'Yükleniyor...', 'gnn-filehub' ); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Whitelist of Exportable/Importable Option Keys & their Sanitization Type
     * This is the single source of truth for the Bakım (Maintenance) export/import feature —
     * only keys listed here are ever read from an uploaded file, so an import can never write
     * to arbitrary WordPress options.
     *
     * @return array<string,string>
     */
    private function get_settings_schema(): array {
        return array(
            'filehub_guest_upload'         => 'checkbox',
            'filehub_strict_mime'          => 'checkbox',
            'filehub_auto_rename'          => 'checkbox',
            'filehub_allowed_extensions'   => 'text',
            'filehub_page_account'         => 'page_id',
            'filehub_page_uploader'        => 'page_id',
            'filehub_page_manager'         => 'page_id',
            'filehub_page_admin_files'     => 'page_id',
            'filehub_storage_driver'       => 'driver',
            'filehub_r2_account_id'        => 'text',
            'filehub_r2_access_key'        => 'text',
            'filehub_r2_secret_key'        => 'text',
            'filehub_r2_bucket'            => 'text',
            'filehub_gdrive_client_id'     => 'text',
            'filehub_gdrive_client_secret' => 'text',
            'filehub_gdrive_refresh_token' => 'text',
            'filehub_gdrive_folder_id'     => 'text',
        );
    }

    /**
     * Sanitize a Single Imported Setting Value According to its Declared Type
     *
     * @param string $type  One of: checkbox, text, page_id, driver.
     * @param mixed  $value Raw value decoded from the uploaded JSON.
     * @return string|int
     */
    private function sanitize_imported_setting_value( string $type, $value ) {
        switch ( $type ) {
            case 'checkbox':
                return ( '1' === (string) $value ) ? '1' : '0';

            case 'page_id':
                $page_id = absint( $value );
                return ( $page_id && get_post( $page_id ) ) ? $page_id : 0;

            case 'driver':
                return in_array( $value, array( 'local', 'r2', 'gdrive' ), true ) ? $value : 'local';

            case 'text':
            default:
                return sanitize_text_field( (string) $value );
        }
    }

    /**
     * Settings Tab: Bakım (Maintenance) — Export / Import Settings as JSON
     */
    private function render_tab_maintenance() {
        $import_status = isset( $_GET['filehub_import'] ) ? sanitize_text_field( $_GET['filehub_import'] ) : '';
        ?>
        <?php if ( $import_status ) : ?>
            <div class="notice notice-<?php echo 'success' === $import_status ? 'success' : 'error'; ?> is-dismissible">
                <p>
                    <?php
                    switch ( $import_status ) {
                        case 'success':
                            $count = isset( $_GET['filehub_import_count'] ) ? (int) $_GET['filehub_import_count'] : 0;
                            printf(
                                /* translators: %d: number of settings updated */
                                esc_html__( 'Ayarlar başarıyla içe aktarıldı. %d ayar güncellendi.', 'gnn-filehub' ),
                                $count
                            );
                            break;
                        case 'invalid_type':
                            esc_html_e( 'Lütfen geçerli bir .json dosyası yükleyin.', 'gnn-filehub' );
                            break;
                        case 'too_large':
                            esc_html_e( 'Dosya çok büyük.', 'gnn-filehub' );
                            break;
                        case 'invalid_json':
                            esc_html_e( 'Dosya geçerli bir GNN Filehub ayar dosyası değil.', 'gnn-filehub' );
                            break;
                        default:
                            esc_html_e( 'İçe aktarma başarısız oldu.', 'gnn-filehub' );
                            break;
                    }
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="filehub-card" style="margin-bottom: 20px;">
            <h3><?php esc_html_e( 'Ayarları Dışa Aktar', 'gnn-filehub' ); ?></h3>
            <p class="description" style="margin-bottom: 15px;">
                <?php esc_html_e( 'Genel & güvenlik ayarlarınızı, sayfa atamalarınızı ve depolama sürücü yapılandırmanızı tek bir JSON dosyası olarak indirin. Eklentiyi yeniden kurduğunuzda bu dosyayı içe aktararak kaldığınız yerden devam edebilirsiniz.', 'gnn-filehub' ); ?>
            </p>
            <p style="color: #b32d2e; margin-bottom: 15px;">
                <strong><?php esc_html_e( 'Uyarı:', 'gnn-filehub' ); ?></strong>
                <?php esc_html_e( 'Depolama sürücüsü yapılandırdıysanız bu dosya Cloudflare R2 / Google Drive API anahtarlarınızı düz metin olarak içerir. Güvenli bir yerde saklayın ve kimseyle paylaşmayın.', 'gnn-filehub' ); ?>
            </p>
            <?php
            $export_url = wp_nonce_url(
                admin_url( 'admin.php?page=filehub-settings&tab=maintenance&filehub_export=1' ),
                'filehub_export_settings'
            );
            ?>
            <a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary"><?php esc_html_e( 'Ayarları Dışa Aktar (JSON)', 'gnn-filehub' ); ?></a>
        </div>

        <div class="filehub-card">
            <h3><?php esc_html_e( 'Ayarları İçe Aktar', 'gnn-filehub' ); ?></h3>
            <p class="description" style="margin-bottom: 15px;">
                <?php esc_html_e( 'Daha önce dışa aktardığınız bir JSON dosyasını yükleyerek eski ayarlarınızı geri yükleyin. Yalnızca GNN Filehub\'a ait bilinen ayarlar okunur; dosyadaki başka hiçbir veri işlenmez.', 'gnn-filehub' ); ?>
            </p>
            <form method="post" action="" enctype="multipart/form-data">
                <?php wp_nonce_field( 'filehub_import_settings', 'filehub_import_nonce' ); ?>
                <input type="file" name="filehub_import_file" accept=".json,application/json" required>
                <?php submit_button( __( 'Ayarları İçe Aktar', 'gnn-filehub' ), 'secondary', 'filehub_import_submit', false, array( 'style' => 'margin-left: 10px;' ) ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Handle "Ayarları Dışa Aktar" — Streams a JSON Download of Known FileHub Settings
     */
    public function handle_export_settings() {
        if ( ! isset( $_GET['filehub_export'] ) || '1' !== $_GET['filehub_export'] ) {
            return;
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'filehub_export_settings' ) ) {
            wp_die( esc_html__( 'Güvenlik doğrulaması başarısız.', 'gnn-filehub' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'gnn-filehub' ) );
        }

        $settings = array();
        foreach ( array_keys( $this->get_settings_schema() ) as $option_name ) {
            $settings[ $option_name ] = get_option( $option_name, '' );
        }

        $payload = array(
            'plugin'      => 'gnn-filehub',
            'version'     => defined( 'GNN_FILEHUB_VERSION' ) ? GNN_FILEHUB_VERSION : '',
            'exported_at' => gmdate( 'c' ),
            'settings'    => $settings,
        );

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="gnn-filehub-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT );
        exit;
    }

    /**
     * Handle "Ayarları İçe Aktar" — Validates & Applies an Uploaded Settings JSON File
     * Only ever writes to the whitelisted option keys from get_settings_schema(), each
     * re-sanitized by its declared type — the uploaded file can never set arbitrary options.
     */
    public function handle_import_settings() {
        if ( ! isset( $_POST['filehub_import_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['filehub_import_nonce'] ) ), 'filehub_import_settings' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $redirect_base = array(
            'page' => 'filehub-settings',
            'tab'  => 'maintenance',
        );

        if ( empty( $_FILES['filehub_import_file'] ) || UPLOAD_ERR_OK !== $_FILES['filehub_import_file']['error'] ) {
            wp_safe_redirect( add_query_arg( array_merge( $redirect_base, array( 'filehub_import' => 'error' ) ), admin_url( 'admin.php' ) ) );
            exit;
        }

        $file = $_FILES['filehub_import_file'];

        $filetype = wp_check_filetype( $file['name'], array( 'json' => 'application/json' ) );
        if ( empty( $filetype['ext'] ) || 'json' !== $filetype['ext'] ) {
            wp_safe_redirect( add_query_arg( array_merge( $redirect_base, array( 'filehub_import' => 'invalid_type' ) ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( $file['size'] > 1048576 ) { // 1MB is far more than a settings file could ever need
            wp_safe_redirect( add_query_arg( array_merge( $redirect_base, array( 'filehub_import' => 'too_large' ) ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
            wp_safe_redirect( add_query_arg( array_merge( $redirect_base, array( 'filehub_import' => 'error' ) ), admin_url( 'admin.php' ) ) );
            exit;
        }

        $contents = file_get_contents( $file['tmp_name'] );
        $decoded  = json_decode( (string) $contents, true );

        if ( ! is_array( $decoded ) || empty( $decoded['settings'] ) || ! is_array( $decoded['settings'] ) ) {
            wp_safe_redirect( add_query_arg( array_merge( $redirect_base, array( 'filehub_import' => 'invalid_json' ) ), admin_url( 'admin.php' ) ) );
            exit;
        }

        $schema  = $this->get_settings_schema();
        $applied = 0;

        foreach ( $decoded['settings'] as $option_name => $raw_value ) {
            if ( ! isset( $schema[ $option_name ] ) ) {
                continue; // Ignore anything outside our known settings — no arbitrary option writes.
            }

            $sanitized = $this->sanitize_imported_setting_value( $schema[ $option_name ], $raw_value );
            update_option( $option_name, $sanitized );
            $applied++;
        }

        wp_safe_redirect(
            add_query_arg(
                array_merge( $redirect_base, array( 'filehub_import' => 'success', 'filehub_import_count' => $applied ) ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * Render Custom Storage Quota Field on User Profile Screen
     *
     * @param WP_User $user
     */
    public function render_user_profile_fields( $user ) {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_user', $user->ID ) ) {
            return;
        }

        $custom_quota_mb = get_user_meta( $user->ID, '_filehub_custom_quota_mb', true );
        $user_stats      = FileHub_Attachment::get_user_stats( $user->ID );
        wp_nonce_field( 'filehub_save_user_quota', 'filehub_user_quota_nonce' );
        ?>
        <h3>GNN FileHub Depolama Ayarları</h3>
        <table class="form-table">
            <tr>
                <th><label for="filehub_custom_quota_mb">Özel Depolama Kotası (MB)</label></th>
                <td>
                    <input type="number" name="filehub_custom_quota_mb" id="filehub_custom_quota_mb" value="<?php echo esc_attr( $custom_quota_mb ); ?>" min="0" step="1" class="regular-text" />
                    <p class="description">
                        Bu kullanıcı için özel depolama limiti (MB). Boş bırakılırsa veya 0 yazılırsa varsayılan kota (500 MB) kullanılır.<br />
                        <strong>Mevcut Kullanım:</strong> <?php echo esc_html( $user_stats['used_formatted'] ); ?> / <?php echo esc_html( $user_stats['quota_formatted'] ); ?> (%<?php echo esc_html( $user_stats['percentage'] ); ?>)
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save Custom Storage Quota Field
     *
     * @param int $user_id
     */
    public function save_user_profile_fields( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }

        if ( ! isset( $_POST['filehub_user_quota_nonce'] ) || ! wp_verify_nonce( $_POST['filehub_user_quota_nonce'], 'filehub_save_user_quota' ) ) {
            return;
        }

        if ( isset( $_POST['filehub_custom_quota_mb'] ) ) {
            $val = sanitize_text_field( $_POST['filehub_custom_quota_mb'] );
            if ( $val === '' || (int) $val <= 0 ) {
                delete_user_meta( $user_id, '_filehub_custom_quota_mb' );
            } else {
                update_user_meta( $user_id, '_filehub_custom_quota_mb', absint( $val ) );
            }
        }
    }
}
