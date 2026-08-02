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

        add_action( 'show_user_profile', array( $this, 'render_user_profile_fields' ) );
        add_action( 'edit_user_profile', array( $this, 'render_user_profile_fields' ) );
        add_action( 'personal_options_update', array( $this, 'save_user_profile_fields' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_user_profile_fields' ) );
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
            array( $this, 'render_admin_page' ),
            'dashicons-cloud-upload',
            '79.103'
        );

        add_submenu_page(
            'filehub',
            __( 'FileHub - Genel Bakış', 'gnn-filehub' ),
            __( 'Genel Bakış', 'gnn-filehub' ),
            'manage_options',
            'filehub',
            array( $this, 'render_admin_page' )
        );

        add_submenu_page(
            'filehub',
            __( 'FileHub - Ayarlar', 'gnn-filehub' ),
            __( 'Ayarlar', 'gnn-filehub' ),
            'manage_options',
            'filehub-settings',
            array( $this, 'render_admin_page' )
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

        if ( isset( $_GET['tab'] ) && 'files' === $_GET['tab'] ) {
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
                'rest_url' => esc_url_raw( rest_url() ),
                'nonce'    => wp_create_nonce( 'wp_rest' ),
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
        register_setting( 'filehub_pages_group', 'filehub_page_register' );
        register_setting( 'filehub_pages_group', 'filehub_page_login' );
        register_setting( 'filehub_pages_group', 'filehub_page_profile' );
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
        register_setting( 'filehub_storage_group', 'filehub_gdrive_refresh_token' );
        register_setting( 'filehub_storage_group', 'filehub_gdrive_folder_id' );
    }

    /**
     * Master Render Handler for Tabbed Interface
     */
    public function render_admin_page() {
        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'overview';
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'filehub-settings' && ! isset( $_GET['tab'] ) ) {
            $active_tab = 'general';
        }
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">GNN Filehub</h1>
            <hr class="wp-header-end">

            <nav class="nav-tab-wrapper filehub-nav-tab-wrapper">
                <a href="?page=filehub&tab=overview" class="nav-tab <?php echo $active_tab === 'overview' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-dashboard" style="vertical-align: text-bottom;"></span> <?php esc_html_e( 'Genel Bakış & Analiz', 'gnn-filehub' ); ?>
                </a>
                <a href="?page=filehub&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-settings" style="vertical-align: text-bottom;"></span> <?php esc_html_e( 'Genel & Güvenlik', 'gnn-filehub' ); ?>
                </a>
                <a href="?page=filehub&tab=pages" class="nav-tab <?php echo $active_tab === 'pages' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-page" style="vertical-align: text-bottom;"></span> <?php esc_html_e( 'Otomatik Sayfa Atamaları', 'gnn-filehub' ); ?>
                </a>
                <a href="?page=filehub&tab=storage" class="nav-tab <?php echo $active_tab === 'storage' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-cloud-upload" style="vertical-align: text-bottom;"></span> <?php esc_html_e( 'Depolama Sürücüleri', 'gnn-filehub' ); ?>
                </a>
                <a href="?page=filehub&tab=files" class="nav-tab <?php echo $active_tab === 'files' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-media-default" style="vertical-align: text-bottom;"></span> <?php esc_html_e( 'Tüm Dosyalar', 'gnn-filehub' ); ?>
                </a>
            </nav>

            <?php
            switch ( $active_tab ) {
                case 'general':
                    $this->render_tab_general();
                    break;
                case 'pages':
                    $this->render_tab_pages();
                    break;
                case 'storage':
                    $this->render_tab_storage();
                    break;
                case 'files':
                    $this->render_tab_files();
                    break;
                case 'overview':
                default:
                    $this->render_tab_overview();
                    break;
            }
            ?>
        </div>
        <?php
    }

    /**
     * Tab 1: Overview & Analytics
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
     * Tab 2: General & Security Settings
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
            'filehub_page_register'        => array(
                'title'     => __( 'Kayıt Ol', 'gnn-filehub' ),
                'shortcode' => '[filehub_register]',
            ),
            'filehub_page_login'           => array(
                'title'     => __( 'Giriş Yap', 'gnn-filehub' ),
                'shortcode' => '[filehub_login]',
            ),
            'filehub_page_profile'         => array(
                'title'     => __( 'Profilim', 'gnn-filehub' ),
                'shortcode' => '[filehub_profile]',
            ),
            'filehub_page_uploader'        => array(
                'title'     => __( 'Dosya Yükle', 'gnn-filehub' ),
                'shortcode' => '[filehub_uploader]',
            ),
            'filehub_page_manager'         => array(
                'title'     => __( 'Dosyalarım', 'gnn-filehub' ),
                'shortcode' => '[filehub_manager]',
            ),
            'filehub_page_admin_files'     => array(
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
                    'page'                    => 'filehub',
                    'tab'                     => 'pages',
                    'filehub_pages_created'   => $created,
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
     * Tab 3: Automatic Page Assignments
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
                    <?php esc_html_e( 'Aşağıdaki WordPress sayfalarını seçtiğinizde, kısa kodlar (shortcode) ilgili sayfalara otomatik olarak gömülür. Manuel kısa kod yazmak zorunda kalmazsınız. Şifre değiştirme formu artık ayrı bir sayfa gerektirmez; Profil sayfasının içinde otomatik olarak yer alır.', 'gnn-filehub' ); ?>
                </p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="filehub_page_register"><?php esc_html_e( 'Kayıt Ol Sayfası [filehub_register]', 'gnn-filehub' ); ?></label></th>
                        <td>
                            <?php
                            wp_dropdown_pages( array(
                                'name'              => 'filehub_page_register',
                                'selected'          => get_option( 'filehub_page_register', 0 ),
                                'show_option_none'  => __( '-- Sayfa Seçin --', 'gnn-filehub' ),
                                'option_none_value' => '0',
                                'class'             => 'regular-text',
                            ) );
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="filehub_page_login"><?php esc_html_e( 'Giriş Yap Sayfası [filehub_login]', 'gnn-filehub' ); ?></label></th>
                        <td>
                            <?php
                            wp_dropdown_pages( array(
                                'name'              => 'filehub_page_login',
                                'selected'          => get_option( 'filehub_page_login', 0 ),
                                'show_option_none'  => __( '-- Sayfa Seçin --', 'gnn-filehub' ),
                                'option_none_value' => '0',
                                'class'             => 'regular-text',
                            ) );
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="filehub_page_profile"><?php esc_html_e( 'Profil Sayfası [filehub_profile]', 'gnn-filehub' ); ?></label></th>
                        <td>
                            <?php
                            wp_dropdown_pages( array(
                                'name'              => 'filehub_page_profile',
                                'selected'          => get_option( 'filehub_page_profile', 0 ),
                                'show_option_none'  => __( '-- Sayfa Seçin --', 'gnn-filehub' ),
                                'option_none_value' => '0',
                                'class'             => 'regular-text',
                            ) );
                            ?>
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
     * Tab 4: Storage Drivers & API Configurations
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

            <?php submit_button( __( 'Depolama Ayarlarını Kaydet', 'gnn-filehub' ) ); ?>
        </form>
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
     * Tab 5: All Members' Files (Admin-Only Backend View)
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
