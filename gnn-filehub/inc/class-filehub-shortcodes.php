<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once GNN_FILEHUB_PATH . 'inc/class-filehub-attachment.php';

/**
 * Class FileHub_Shortcodes
 * Registers public shortcodes & automatic page content injection.
 */
class FileHub_Shortcodes {

    public function __construct() {
        add_shortcode( 'filehub_uploader', array( $this, 'render_uploader_shortcode' ) );
        add_shortcode( 'filehub_manager', array( $this, 'render_manager_shortcode' ) );
        add_shortcode( 'filehub_login', array( $this, 'render_login_shortcode' ) );
        add_shortcode( 'filehub_register', array( $this, 'render_register_shortcode' ) );
        add_shortcode( 'filehub_profile', array( $this, 'render_profile_shortcode' ) );
        add_shortcode( 'filehub_password_change', array( $this, 'render_password_change_shortcode' ) );
        add_shortcode( 'filehub_admin_files', array( $this, 'render_admin_files_shortcode' ) );
        add_shortcode( 'filehub_account', array( $this, 'render_account_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_public_scripts' ) );

        // Automatic Shortcode Injection on Assigned Pages
        add_filter( 'the_content', array( $this, 'auto_inject_shortcodes' ) );

        // WooCommerce-style Dynamic Nav Menu Label for the Account Page (Giriş Yap ⇄ Profil).
        // Two hooks are needed to cover both menu systems WordPress themes use today:
        // classic menus (wp_nav_menu()) and the block-theme Navigation block.
        add_filter( 'wp_nav_menu_objects', array( $this, 'filter_account_menu_label' ) );
        add_filter( 'render_block', array( $this, 'filter_account_menu_block_label' ), 10, 2 );
    }

    /**
     * Dynamically Relabel the Account Page's Nav Menu Item — Classic Menus
     * Shows "Giriş Yap" when logged out and "Profil" when logged in, WooCommerce "My Account"-style.
     */
    public function filter_account_menu_label( $items ) {
        $account_page_id = (int) get_option( 'filehub_page_account', 0 );
        if ( ! $account_page_id ) {
            return $items;
        }

        foreach ( $items as $item ) {
            if ( 'page' === $item->object && (int) $item->object_id === $account_page_id ) {
                $item->title = is_user_logged_in()
                    ? __( 'Profil', 'gnn-filehub' )
                    : __( 'Giriş Yap', 'gnn-filehub' );
            }
        }

        return $items;
    }

    /**
     * Dynamically Relabel the Account Page's Nav Menu Item — Block-Theme Navigation Block
     * core/navigation-link doesn't go through wp_nav_menu_objects, so it needs its own hook.
     * The label text is wrapped in a standard `.wp-block-navigation-item__label` span in every
     * WordPress core version that ships the Navigation block, which is what makes this safe.
     */
    public function filter_account_menu_block_label( $block_content, $block ) {
        if ( empty( $block['blockName'] ) || 'core/navigation-link' !== $block['blockName'] ) {
            return $block_content;
        }

        $account_page_id = (int) get_option( 'filehub_page_account', 0 );
        if ( ! $account_page_id ) {
            return $block_content;
        }

        $linked_type = isset( $block['attrs']['type'] ) ? $block['attrs']['type'] : '';
        $linked_id   = isset( $block['attrs']['id'] ) ? (int) $block['attrs']['id'] : 0;

        if ( 'page' !== $linked_type || $linked_id !== $account_page_id ) {
            return $block_content;
        }

        $label = is_user_logged_in() ? __( 'Profil', 'gnn-filehub' ) : __( 'Giriş Yap', 'gnn-filehub' );

        $updated = preg_replace(
            '/(<span class="wp-block-navigation-item__label">)(.*?)(<\/span>)/s',
            '$1' . esc_html( $label ) . '$3',
            $block_content,
            1
        );

        return null !== $updated ? $updated : $block_content;
    }

    /**
     * Automatic Shortcode Injection for Assigned Pages
     */
    public function auto_inject_shortcodes( $content ) {
        if ( ! is_singular( 'page' ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        $current_page_id = get_the_ID();

        $pages_map = array(
            'filehub_page_account'     => '[filehub_account]',
            'filehub_page_uploader'    => '[filehub_uploader]',
            'filehub_page_manager'     => '[filehub_manager]',
            'filehub_page_admin_files' => '[filehub_admin_files]',
        );

        foreach ( $pages_map as $option_name => $shortcode ) {
            $assigned_page_id = (int) get_option( $option_name, 0 );
            if ( $assigned_page_id > 0 && $assigned_page_id === $current_page_id ) {
                if ( false === strpos( $content, $shortcode ) ) {
                    $content .= "\n" . do_shortcode( $shortcode );
                }
                break;
            }
        }

        return $content;
    }

    /**
     * Register Public Scripts and Localize REST Nonce
     */
    public function register_public_scripts() {
        wp_register_style(
            'filehub-admin-css',
            GNN_FILEHUB_URL . 'assets/css/filehub-admin.css',
            array(),
            GNN_FILEHUB_VERSION
        );

        wp_register_style(
            'filehub-public-css',
            GNN_FILEHUB_URL . 'assets/css/filehub-public.css',
            array( 'filehub-admin-css' ),
            GNN_FILEHUB_VERSION
        );

        wp_register_script(
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

    /**
     * Render [filehub_uploader] Shortcode
     */
    public function render_uploader_shortcode( $atts ) {
        wp_enqueue_style( 'filehub-public-css' );
        wp_enqueue_script( 'filehub-public-js' );

        $show_own_files = is_user_logged_in();

        ob_start();
        ?>
        <div class="filehub-container">
            <div class="filehub-card filehub-uploader">
                <h3><?php esc_html_e( 'Dosya Yükle', 'gnn-filehub' ); ?></h3>
                <div id="filehub-dropzone" class="filehub-dropzone">
                    <svg class="filehub-dropzone-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 15V3M12 3L7.5 7.5M12 3l4.5 4.5"></path>
                        <path d="M3 15v3a3 3 0 0 0 3 3h12a3 3 0 0 0 3-3v-3"></path>
                    </svg>
                    <p class="filehub-dropzone-title"><?php esc_html_e( 'Dosyaları buraya sürükleyin', 'gnn-filehub' ); ?></p>
                    <p class="filehub-dropzone-subtitle"><?php esc_html_e( 'veya bilgisayarınızdan seçmek için tıklayın', 'gnn-filehub' ); ?></p>
                    <input type="file" id="filehub-file-input" multiple style="display: none;">
                    <form onsubmit="return false;">
                        <button type="button" class="button button-primary" onclick="document.getElementById('filehub-file-input').click();"><?php esc_html_e( 'Dosya Seç', 'gnn-filehub' ); ?></button>
                    </form>
                </div>

                <div id="filehub-progress-bar" class="filehub-progress-bar" style="display: none; margin-top: 15px;">
                    <div id="filehub-progress-fill" class="filehub-progress-fill" style="width: 0%;"></div>
                </div>
                <p id="filehub-status-text" style="margin-top: 10px; font-weight: 600;"></p>
            </div>

            <?php if ( $show_own_files ) : ?>
                <div class="filehub-card filehub-manager" style="margin-top: 20px;">
                    <div class="filehub-manager-toolbar">
                        <h3><?php esc_html_e( 'Yüklediğim Dosyalar', 'gnn-filehub' ); ?></h3>
                        <input type="text" class="filehub-search-input" placeholder="<?php esc_attr_e( 'Dosya ara...', 'gnn-filehub' ); ?>">
                    </div>
                    <div class="filehub-file-list" data-scope="own">
                        <p><?php esc_html_e( 'Yükleniyor...', 'gnn-filehub' ); ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render [filehub_manager] Shortcode with Live Search & AJAX Delete
     * Always scoped to the current logged-in user's own files.
     */
    public function render_manager_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<div class="filehub-card"><p>' . esc_html__( 'Dosyalarınızı görüntülemek için lütfen giriş yapın.', 'gnn-filehub' ) . '</p></div>';
        }

        wp_enqueue_style( 'filehub-public-css' );
        wp_enqueue_script( 'filehub-public-js' );

        ob_start();
        ?>
        <div class="filehub-container">
            <div class="filehub-card filehub-manager">
                <div class="filehub-manager-toolbar">
                    <h3><?php esc_html_e( 'Dosyalarım', 'gnn-filehub' ); ?></h3>
                    <input type="text" class="filehub-search-input" placeholder="<?php esc_attr_e( 'Dosya ara...', 'gnn-filehub' ); ?>">
                </div>
                <div class="filehub-file-list" data-scope="own">
                    <p><?php esc_html_e( 'Yükleniyor...', 'gnn-filehub' ); ?></p>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render [filehub_admin_files] Shortcode — Front-End "All Members' Files" View
     * Restricted to users with the `manage_options` capability.
     */
    public function render_admin_files_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<div class="filehub-card"><p>' . esc_html__( 'Bu sayfayı görüntülemek için lütfen giriş yapın.', 'gnn-filehub' ) . '</p></div>';
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return '<div class="filehub-card"><p>' . esc_html__( 'Bu sayfayı görüntüleme yetkiniz yok.', 'gnn-filehub' ) . '</p></div>';
        }

        wp_enqueue_style( 'filehub-public-css' );
        wp_enqueue_script( 'filehub-public-js' );

        ob_start();
        ?>
        <div class="filehub-container">
            <div class="filehub-card filehub-manager">
                <div class="filehub-manager-toolbar">
                    <h3><?php esc_html_e( 'Tüm Üye Dosyaları', 'gnn-filehub' ); ?></h3>
                    <input type="text" class="filehub-search-input" placeholder="<?php esc_attr_e( 'Dosya veya yükleyen ara...', 'gnn-filehub' ); ?>">
                </div>
                <div class="filehub-file-list" data-scope="all">
                    <p><?php esc_html_e( 'Yükleniyor...', 'gnn-filehub' ); ?></p>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render [filehub_login] Shortcode
     */
    public function render_login_shortcode( $atts ) {
        wp_enqueue_style( 'filehub-public-css' );

        ob_start();
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            ?>
            <div class="filehub-container">
                <div class="filehub-card filehub-auth-card" style="text-align: center;">
                    <div class="filehub-auth-form-inner">
                        <div style="margin-bottom: 15px;">
                            <?php echo get_avatar( $user->ID, 80, '', '', array( 'style' => 'border-radius: 50%;' ) ); ?>
                        </div>
                        <h3><?php printf( esc_html__( 'Hoş Geldiniz, %s', 'gnn-filehub' ), esc_html( $user->display_name ) ); ?></h3>
                        <p style="color: var(--filehub-text-muted);"><?php echo esc_html( $user->user_email ); ?></p>
                        <a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" class="button button-secondary" style="margin-top: 10px;"><?php esc_html_e( 'Çıkış Yap', 'gnn-filehub' ); ?></a>
                    </div>
                </div>
            </div>
            <?php
        } else {
            ?>
            <div class="filehub-container">
                <div class="filehub-card filehub-auth-card">
                    <h3><?php esc_html_e( 'Kullanıcı Girişi', 'gnn-filehub' ); ?></h3>
                    <?php echo $this->render_login_form_markup(); ?>
                </div>
            </div>
            <?php
        }
        return ob_get_clean();
    }

    /**
     * Shared Login Form Markup (used by [filehub_login] and the unified account tabs)
     */
    private function render_login_form_markup() {
        ob_start();
        ?>
        <div class="filehub-auth-form-inner">
            <?php
            wp_login_form( array(
                'echo'           => true,
                'redirect'       => get_permalink(),
                'form_id'        => 'filehub-login-form',
                'label_username' => __( 'Kullanıcı Adı veya E-posta', 'gnn-filehub' ),
                'label_password' => __( 'Şifre', 'gnn-filehub' ),
                'label_remember' => __( 'Beni Hatırla', 'gnn-filehub' ),
                'label_log_in'   => __( 'Giriş Yap', 'gnn-filehub' ),
                'remember'       => true,
            ) );
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render [filehub_register] Shortcode
     */
    public function render_register_shortcode( $atts ) {
        if ( is_user_logged_in() ) {
            return '<div class="filehub-container"><div class="filehub-card filehub-auth-card"><p>' . esc_html__( 'Zaten giriş yapmış durumdasınız.', 'gnn-filehub' ) . '</p></div></div>';
        }

        if ( ! get_option( 'users_can_register' ) ) {
            return '<div class="filehub-container"><div class="filehub-card filehub-auth-card"><p>' . esc_html__( 'Siteye yeni üye kaydı şu an kapalıdır.', 'gnn-filehub' ) . '</p></div></div>';
        }

        wp_enqueue_style( 'filehub-public-css' );
        wp_enqueue_script( 'filehub-public-js' );

        ob_start();
        ?>
        <div class="filehub-container">
            <div class="filehub-card filehub-auth-card">
                <h3><?php esc_html_e( 'Yeni Hesap Oluştur', 'gnn-filehub' ); ?></h3>
                <?php echo $this->render_register_form_markup(); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Shared Register Form Markup (used by [filehub_register] and the unified account tabs)
     */
    private function render_register_form_markup() {
        wp_enqueue_style( 'filehub-public-css' );
        wp_enqueue_script( 'filehub-public-js' );

        ob_start();
        ?>
        <div class="filehub-auth-form-inner">
            <form id="filehub-register-form">
                <div class="filehub-field">
                    <label for="filehub_reg_username"><?php esc_html_e( 'Kullanıcı Adı *', 'gnn-filehub' ); ?></label>
                    <input type="text" id="filehub_reg_username" required>
                </div>
                <div class="filehub-field">
                    <label for="filehub_reg_email"><?php esc_html_e( 'E-posta Adresi *', 'gnn-filehub' ); ?></label>
                    <input type="email" id="filehub_reg_email" required>
                </div>
                <div class="filehub-field-row">
                    <div class="filehub-field">
                        <label for="filehub_reg_first_name"><?php esc_html_e( 'Adı', 'gnn-filehub' ); ?></label>
                        <input type="text" id="filehub_reg_first_name">
                    </div>
                    <div class="filehub-field">
                        <label for="filehub_reg_last_name"><?php esc_html_e( 'Soyadı', 'gnn-filehub' ); ?></label>
                        <input type="text" id="filehub_reg_last_name">
                    </div>
                </div>
                <div class="filehub-field">
                    <label for="filehub_reg_password"><?php esc_html_e( 'Şifre *', 'gnn-filehub' ); ?></label>
                    <input type="password" id="filehub_reg_password" required minlength="6">
                </div>
                <div class="filehub-field">
                    <label for="filehub_reg_confirm_password"><?php esc_html_e( 'Şifre (Tekrar) *', 'gnn-filehub' ); ?></label>
                    <input type="password" id="filehub_reg_confirm_password" required minlength="6">
                </div>
                <button type="submit" class="button button-primary" style="width: 100%; padding: 8px; font-size: 1.05em;"><?php esc_html_e( 'Kayıt Ol', 'gnn-filehub' ); ?></button>
            </form>
            <p id="filehub-register-status" style="margin-top: 12px; font-weight: 600; text-align: center;"></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render [filehub_account] Shortcode — Unified Login / Register / Profile Page
     * WooCommerce "My Account"-style single page: shows a login/register tab switcher
     * when logged out, and the full profile (incl. password change) when logged in.
     */
    public function render_account_shortcode( $atts ) {
        if ( is_user_logged_in() ) {
            return $this->render_profile_shortcode( $atts );
        }

        wp_enqueue_style( 'filehub-public-css' );
        wp_enqueue_script( 'filehub-public-js' );

        $registration_open = (bool) get_option( 'users_can_register' );

        ob_start();
        ?>
        <div class="filehub-container">
            <div class="filehub-card filehub-auth-card">
                <?php if ( $registration_open ) : ?>
                    <div class="filehub-auth-tabs">
                        <button type="button" class="filehub-auth-tab is-active" data-target="login"><?php esc_html_e( 'Giriş Yap', 'gnn-filehub' ); ?></button>
                        <button type="button" class="filehub-auth-tab" data-target="register"><?php esc_html_e( 'Kayıt Ol', 'gnn-filehub' ); ?></button>
                    </div>
                <?php else : ?>
                    <h3><?php esc_html_e( 'Kullanıcı Girişi', 'gnn-filehub' ); ?></h3>
                <?php endif; ?>

                <div class="filehub-auth-tab-panel" data-panel="login">
                    <?php echo $this->render_login_form_markup(); ?>
                </div>

                <?php if ( $registration_open ) : ?>
                    <div class="filehub-auth-tab-panel" data-panel="register" hidden>
                        <?php echo $this->render_register_form_markup(); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ( $registration_open ) : ?>
        <script>
        (function() {
            var root = document.currentScript.previousElementSibling;
            if ( ! root ) return;
            var tabs   = root.querySelectorAll( '.filehub-auth-tab' );
            var panels = root.querySelectorAll( '.filehub-auth-tab-panel' );
            tabs.forEach( function( tab ) {
                tab.addEventListener( 'click', function() {
                    var target = tab.getAttribute( 'data-target' );
                    tabs.forEach( function( t ) { t.classList.toggle( 'is-active', t === tab ); } );
                    panels.forEach( function( p ) { p.hidden = p.getAttribute( 'data-panel' ) !== target; } );
                } );
            } );
        })();
        </script>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Render [filehub_profile] Shortcode
     */
    public function render_profile_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<div class="filehub-card"><p>' . esc_html__( 'Profilinizi görüntülemek için lütfen giriş yapın.', 'gnn-filehub' ) . '</p></div>';
        }

        wp_enqueue_style( 'filehub-public-css' );

        $user          = wp_get_current_user();
        $stats         = FileHub_Attachment::get_user_stats( $user->ID );
        $user_quota_mb = (int) get_user_meta( $user->ID, '_filehub_user_quota_mb', true ) ?: 500; // Default 500MB quota
        $quota_bytes   = $user_quota_mb * 1024 * 1024;
        $pct_used      = $quota_bytes > 0 ? min( 100, round( ( $stats['total_bytes'] / $quota_bytes ) * 100, 1 ) ) : 0;

        ob_start();
        ?>
        <div class="filehub-container">
            <div class="filehub-card filehub-profile-card">
                <div class="filehub-profile-header">
                    <div><?php echo get_avatar( $user->ID, 72, '', '', array( 'style' => 'border-radius: 50%;' ) ); ?></div>
                    <div>
                        <h2 style="margin: 0; font-size: 1.4em;"><?php echo esc_html( $user->display_name ); ?></h2>
                        <p style="margin: 4px 0 0 0; color: var(--filehub-text-muted);"><?php echo esc_html( $user->user_email ); ?></p>
                    </div>
                </div>

                <hr style="border: 0; border-top: 1px solid var(--filehub-border-soft); margin: 15px 0;">

                <h3><?php esc_html_e( 'Depolama Kotası ve Kullanım', 'gnn-filehub' ); ?></h3>
                <p style="margin-bottom: 8px;">
                    <strong><?php echo esc_html( size_format( $stats['total_bytes'] ) ); ?></strong> / <?php echo esc_html( $user_quota_mb ); ?> MB (%<?php echo esc_html( $pct_used ); ?> Dolu)
                </p>
                <div class="filehub-progress-bar">
                    <div class="filehub-progress-fill" style="width: <?php echo esc_attr( $pct_used ); ?>%;"></div>
                </div>

                <div class="filehub-profile-stats">
                    <div>
                        <span class="filehub-profile-stat-label"><?php esc_html_e( 'Yüklenen Dosya', 'gnn-filehub' ); ?></span>
                        <div class="filehub-profile-stat-value"><?php echo esc_html( number_format_i18n( $stats['file_count'] ) ); ?></div>
                    </div>
                    <div>
                        <span class="filehub-profile-stat-label"><?php esc_html_e( 'Kullanıcı Rolü', 'gnn-filehub' ); ?></span>
                        <div class="filehub-profile-stat-value" style="text-transform: capitalize;"><?php echo esc_html( implode( ', ', $user->roles ) ); ?></div>
                    </div>
                </div>

                <hr style="border: 0; border-top: 1px solid var(--filehub-border-soft); margin: 20px 0 15px;">

                <h3><?php esc_html_e( 'Şifre Güncelleme', 'gnn-filehub' ); ?></h3>
                <?php echo $this->render_password_change_form_markup(); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render [filehub_password_change] Shortcode (Standalone, kept for backward compatibility)
     */
    public function render_password_change_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<div class="filehub-card"><p>' . esc_html__( 'Şifrenizi değiştirmek için lütfen giriş yapın.', 'gnn-filehub' ) . '</p></div>';
        }

        wp_enqueue_style( 'filehub-public-css' );

        ob_start();
        ?>
        <div class="filehub-container">
            <div class="filehub-card filehub-auth-card">
                <h3><?php esc_html_e( 'Şifre Güncelleme', 'gnn-filehub' ); ?></h3>
                <?php echo $this->render_password_change_form_markup(); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Shared Password Change Form Markup (used by profile shortcode & standalone shortcode)
     */
    private function render_password_change_form_markup() {
        wp_enqueue_style( 'filehub-public-css' );
        wp_enqueue_script( 'filehub-public-js' );

        ob_start();
        ?>
        <div class="filehub-auth-form-inner">
            <form id="filehub-password-form">
                <div class="filehub-field">
                    <label for="filehub_current_password"><?php esc_html_e( 'Mevcut Şifre', 'gnn-filehub' ); ?></label>
                    <input type="password" id="filehub_current_password" required>
                </div>
                <div class="filehub-field">
                    <label for="filehub_new_password"><?php esc_html_e( 'Yeni Şifre', 'gnn-filehub' ); ?></label>
                    <input type="password" id="filehub_new_password" required minlength="6">
                </div>
                <div class="filehub-field">
                    <label for="filehub_confirm_password"><?php esc_html_e( 'Yeni Şifre (Tekrar)', 'gnn-filehub' ); ?></label>
                    <input type="password" id="filehub_confirm_password" required minlength="6">
                </div>
                <button type="submit" class="button button-primary" style="width: 100%; padding: 6px;"><?php esc_html_e( 'Şifreyi Güncelle', 'gnn-filehub' ); ?></button>
            </form>
            <p id="filehub-password-status" style="margin-top: 12px; font-weight: 600;"></p>
        </div>
        <?php
        return ob_get_clean();
    }
}
