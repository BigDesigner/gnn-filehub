<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class FileHub_Shortcodes
 * Registers public shortcodes [filehub_uploader] and [filehub_manager].
 */
class FileHub_Shortcodes {

    public function __construct() {
        add_shortcode( 'filehub_uploader', array( $this, 'render_uploader_shortcode' ) );
        add_shortcode( 'filehub_manager', array( $this, 'render_manager_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_public_scripts' ) );
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
        wp_enqueue_style( 'filehub-admin-css' );
        wp_enqueue_script( 'filehub-public-js' );

        ob_start();
        ?>
        <div class="filehub-card" style="max-width: 600px; margin: 20px 0;">
            <h3><?php esc_html_e( 'Dosya Yükle', 'gnn-filehub' ); ?></h3>
            <div id="filehub-dropzone" style="border: 2px dashed var(--wp-admin-theme-color, #2271b1); padding: 40px; text-align: center; background: #fdfdfd; border-radius: 6px; cursor: pointer;">
                <p style="font-size: 1.1em; margin-bottom: 10px;"><?php esc_html_e( 'Dosyaları buraya sürükleyin veya dosya seçmek için tıklayın', 'gnn-filehub' ); ?></p>
                <input type="file" id="filehub-file-input" style="display: none;">
                <button type="button" class="button button-primary" onclick="document.getElementById('filehub-file-input').click();"><?php esc_html_e( 'Dosya Seç', 'gnn-filehub' ); ?></button>
            </div>

            <div id="filehub-progress-bar" class="filehub-progress-bar" style="display: none; margin-top: 15px;">
                <div id="filehub-progress-fill" class="filehub-progress-fill" style="width: 0%;"></div>
            </div>
            <p id="filehub-status-text" style="margin-top: 10px; font-weight: 600;"></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render [filehub_manager] Shortcode
     */
    public function render_manager_shortcode( $atts ) {
        wp_enqueue_style( 'filehub-admin-css' );
        wp_enqueue_script( 'filehub-public-js' );

        ob_start();
        ?>
        <div class="filehub-card" style="margin: 20px 0;">
            <h3><?php esc_html_e( 'Yüklenen Dosyalar', 'gnn-filehub' ); ?></h3>
            <div id="filehub-file-list">
                <p><?php esc_html_e( 'Yükleniyor...', 'gnn-filehub' ); ?></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
