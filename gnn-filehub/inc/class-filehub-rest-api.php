<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once GNN_FILEHUB_PATH . 'inc/storage/class-storage-local.php';
require_once GNN_FILEHUB_PATH . 'inc/storage/class-storage-r2.php';
require_once GNN_FILEHUB_PATH . 'inc/storage/class-storage-gdrive.php';
require_once GNN_FILEHUB_PATH . 'inc/class-filehub-attachment.php';

/**
 * Class FileHub_REST_API
 * Secure WP REST Controller for upload, list, delete, download, password, and register operations.
 */
class FileHub_REST_API extends WP_REST_Controller {

    protected $namespace = 'filehub/v1';

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST API Routes
     */
    public function register_routes() {
        register_rest_route( $this->namespace, '/upload', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'handle_upload' ),
            'permission_callback' => array( $this, 'check_upload_permission' ),
        ) );

        register_rest_route( $this->namespace, '/upload-chunk', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'handle_upload_chunk' ),
            'permission_callback' => array( $this, 'check_upload_permission' ),
        ) );

        register_rest_route( $this->namespace, '/files', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'handle_list_files' ),
            'permission_callback' => array( $this, 'check_user_permission' ),
        ) );

        register_rest_route( $this->namespace, '/files/(?P<id>\d+)', array(
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => array( $this, 'handle_delete_file' ),
            'permission_callback' => array( $this, 'check_user_permission' ),
        ) );

        register_rest_route( $this->namespace, '/download/(?P<id>\d+)', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'handle_download_file' ),
            'permission_callback' => '__return_true', // Download URL checked inside handler
        ) );

        register_rest_route( $this->namespace, '/change-password', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'handle_change_password' ),
            'permission_callback' => array( $this, 'check_user_permission' ),
        ) );

        register_rest_route( $this->namespace, '/register', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'handle_register_user' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Check Upload Permission & Nonce Header
     */
    public function check_upload_permission( $request ) {
        if ( ! is_user_logged_in() && get_option( 'filehub_guest_upload', '0' ) !== '1' ) {
            return new WP_Error( 'rest_forbidden', __( 'Yükleme yapmak için giriş yapmalısınız.', 'gnn-filehub' ), array( 'status' => 401 ) );
        }
        return true;
    }

    /**
     * Check User Permission
     */
    public function check_user_permission( $request ) {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_forbidden', __( 'Bu işlem için giriş yapmalısınız.', 'gnn-filehub' ), array( 'status' => 401 ) );
        }
        return true;
    }

    /**
     * Handle File Upload
     */
    public function handle_upload( $request ) {
        $files = $request->get_file_params();
        if ( empty( $files['file'] ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Dosya bulunamadı.', 'gnn-filehub' ) ), 400 );
        }

        $file    = $files['file'];
        $user_id = get_current_user_id() ?: 0;

        // Extension Whitelist Validation
        $allowed_exts = array_map( 'trim', explode( ',', get_option( 'filehub_allowed_extensions', 'jpg,jpeg,png,gif,pdf,zip,doc,docx,xlsx' ) ) );
        $ext          = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, $allowed_exts, true ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Bu dosya uzantısına izin verilmiyor.', 'gnn-filehub' ) ), 400 );
        }

        // Selected Storage Driver
        $driver_name = get_option( 'filehub_storage_driver', 'local' );
        switch ( $driver_name ) {
            case 'r2':
                $driver = new FileHub_Storage_R2();
                break;
            case 'gdrive':
                $driver = new FileHub_Storage_GDrive();
                break;
            case 'local':
            default:
                $driver = new FileHub_Storage_Local();
                break;
        }

        $upload_result = $driver->upload_file( $file, $user_id );
        if ( is_wp_error( $upload_result ) ) {
            return new WP_REST_Response( array( 'error' => $upload_result->get_error_message() ), 500 );
        }

        $attachment_id = FileHub_Attachment::create_attachment( $upload_result, $user_id, $file['type'] );
        if ( is_wp_error( $attachment_id ) ) {
            return new WP_REST_Response( array( 'error' => $attachment_id->get_error_message() ), 500 );
        }

        return new WP_REST_Response( array(
            'success'       => true,
            'attachment_id' => $attachment_id,
            'file_name'     => $upload_result['file_name'],
            'download_url'  => rest_url( 'filehub/v1/download/' . $attachment_id ),
        ), 200 );
    }

    /**
     * Handle Chunked Resumable File Upload
     */
    public function handle_upload_chunk( $request ) {
        $user_id = get_current_user_id() ?: 0;

        // Sanitize parameters to block directory traversal & injection
        $file_id      = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $request->get_param( 'file_id' ) );
        $chunk_index  = absint( $request->get_param( 'chunk_index' ) );
        $total_chunks = absint( $request->get_param( 'total_chunks' ) );
        $filename     = sanitize_file_name( (string) $request->get_param( 'filename' ) );

        if ( empty( $file_id ) || empty( $filename ) || $total_chunks <= 0 ) {
            return new WP_REST_Response( array( 'error' => __( 'Görünüşe göre geçersiz parça bilgileri gönderildi.', 'gnn-filehub' ) ), 400 );
        }

        // Extension Whitelist Validation
        $allowed_exts = array_map( 'trim', explode( ',', get_option( 'filehub_allowed_extensions', 'jpg,jpeg,png,gif,pdf,zip,doc,docx,xlsx' ) ) );
        $ext          = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, $allowed_exts, true ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Bu dosya uzantısına izin verilmiyor.', 'gnn-filehub' ) ), 400 );
        }

        // User Quota Validation Check
        $user_stats = FileHub_Attachment::get_user_stats( $user_id );
        if ( $user_stats['quota_bytes'] > 0 && $user_stats['total_bytes'] >= $user_stats['quota_bytes'] ) {
            return new WP_REST_Response( array( 'error' => __( 'Depolama kotanızı aştınız.', 'gnn-filehub' ) ), 400 );
        }

        $files = $request->get_file_params();
        if ( empty( $files['chunk'] ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Parça verisi bulunamadı.', 'gnn-filehub' ) ), 400 );
        }

        $chunk_file = $files['chunk'];
        $upload_dir = wp_upload_dir();
        $chunk_dir  = $upload_dir['basedir'] . '/filehub-protected/chunks/' . $user_id . '_' . $file_id;

        if ( ! file_exists( $chunk_dir ) ) {
            wp_mkdir_p( $chunk_dir );
        }

        $part_file = $chunk_dir . '/part_' . $chunk_index;
        if ( ! @move_uploaded_file( $chunk_file['tmp_name'], $part_file ) ) {
            @copy( $chunk_file['tmp_name'], $part_file );
        }

        // Chunks can now arrive concurrently (the browser uploads several in parallel for
        // speed), so more than one request can see "all parts present" at nearly the same
        // moment. A blocking file lock, keyed by user+file_id and kept OUTSIDE the chunk
        // directory, guarantees only one of them actually performs the merge — everyone
        // else just reports their own chunk as saved.
        $locks_dir = $upload_dir['basedir'] . '/filehub-protected/chunks/.locks';
        if ( ! file_exists( $locks_dir ) ) {
            wp_mkdir_p( $locks_dir );
        }
        $lock_path = $locks_dir . '/' . $user_id . '_' . $file_id . '.lock';
        $lock_fp   = @fopen( $lock_path, 'c' );

        $merge_response = null;

        if ( $lock_fp && flock( $lock_fp, LOCK_EX ) ) {
            clearstatcache();
            $parts       = is_dir( $chunk_dir ) ? glob( $chunk_dir . '/part_*' ) : array();
            $parts_count = is_array( $parts ) ? count( $parts ) : 0;

            if ( $parts_count >= $total_chunks && is_dir( $chunk_dir ) ) {
                $merge_response = $this->merge_uploaded_chunks( $chunk_dir, $total_chunks, $user_id, $file_id, $filename, $ext, $chunk_file );
            }

            flock( $lock_fp, LOCK_UN );
        }
        if ( $lock_fp ) {
            fclose( $lock_fp );
        }
        @unlink( $lock_path );

        if ( null === $merge_response ) {
            return new WP_REST_Response( array(
                'success'     => true,
                'chunk_index' => $chunk_index,
                'status'      => 'chunk_saved',
            ), 200 );
        }

        return $merge_response;
    }

    /**
     * Merge all Received Chunks into the Final File & Persist via the Active Storage Driver
     * Only ever called from within the upload-chunk lock, so it runs at most once per upload.
     */
    private function merge_uploaded_chunks( $chunk_dir, $total_chunks, $user_id, $file_id, $filename, $ext, $chunk_file ) {
        $assembled_tmp = sys_get_temp_dir() . '/assembled_' . $user_id . '_' . $file_id . '.' . $ext;
        $out_stream    = fopen( $assembled_tmp, 'wb' );

        if ( ! $out_stream ) {
            return new WP_REST_Response( array( 'error' => __( 'Parçalar birleştirilirken sunucu hatası oluştu.', 'gnn-filehub' ) ), 500 );
        }

        for ( $i = 0; $i < $total_chunks; $i++ ) {
            $part_path = $chunk_dir . '/part_' . $i;
            if ( ! file_exists( $part_path ) ) {
                fclose( $out_stream );
                @unlink( $assembled_tmp );
                return new WP_REST_Response( array( 'error' => __( 'Eksik dosya parçası tespit edildi.', 'gnn-filehub' ) ), 400 );
            }
            $in_stream = fopen( $part_path, 'rb' );
            if ( $in_stream ) {
                stream_copy_to_stream( $in_stream, $out_stream );
                fclose( $in_stream );
            }
        }
        fclose( $out_stream );

        // Clean up temporary chunk files
        $part_files = glob( $chunk_dir . '/part_*' );
        if ( is_array( $part_files ) ) {
            array_map( 'unlink', $part_files );
        }
        @rmdir( $chunk_dir );

        // Build file array for Storage Driver
        $final_file_info = array(
            'name'     => $filename,
            'tmp_name' => $assembled_tmp,
            'type'     => ! empty( $chunk_file['type'] ) ? $chunk_file['type'] : 'application/octet-stream',
            'error'    => 0,
            'size'     => filesize( $assembled_tmp ),
        );

        // Upload to Selected Storage Driver
        $driver_name = get_option( 'filehub_storage_driver', 'local' );
        switch ( $driver_name ) {
            case 'r2':
                $driver = new FileHub_Storage_R2();
                break;
            case 'gdrive':
                $driver = new FileHub_Storage_GDrive();
                break;
            case 'local':
            default:
                $driver = new FileHub_Storage_Local();
                break;
        }

        $upload_result = $driver->upload_file( $final_file_info, $user_id );
        @unlink( $assembled_tmp );

        if ( is_wp_error( $upload_result ) ) {
            return new WP_REST_Response( array( 'error' => $upload_result->get_error_message() ), 500 );
        }

        $attachment_id = FileHub_Attachment::create_attachment( $upload_result, $user_id, $final_file_info['type'] );
        if ( is_wp_error( $attachment_id ) ) {
            return new WP_REST_Response( array( 'error' => $attachment_id->get_error_message() ), 500 );
        }

        return new WP_REST_Response( array(
            'success'       => true,
            'attachment_id' => $attachment_id,
            'file_name'     => $upload_result['file_name'],
            'download_url'  => rest_url( 'filehub/v1/download/' . $attachment_id ),
        ), 200 );
    }

    /**
     * Handle List Files
     */
    public function handle_list_files( $request ) {
        $current_user_id = get_current_user_id();
        $is_admin        = current_user_can( 'manage_options' );

        // Only admins may request the cross-user "all files" scope; everyone else always sees their own files only.
        $requested_scope = (string) $request->get_param( 'scope' );
        $scope           = ( 'all' === $requested_scope && $is_admin ) ? 'all' : 'own';

        $query_args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 100,
            'meta_key'       => '_filehub_storage_driver',
        );

        if ( 'own' === $scope ) {
            $query_args['author'] = $current_user_id;
        }

        $attachments = get_posts( $query_args );
        $data        = array();

        foreach ( $attachments as $post ) {
            $att_id    = $post->ID;
            $driver    = get_post_meta( $att_id, '_filehub_storage_driver', true );
            $size      = (int) get_post_meta( $att_id, '_filehub_file_size', true );
            $downloads = (int) get_post_meta( $att_id, '_filehub_download_count', true );
            $author    = get_userdata( $post->post_author );

            $can_delete = $is_admin || ( (int) $post->post_author === $current_user_id );

            $data[] = array(
                'id'             => $att_id,
                'title'          => get_the_title( $att_id ),
                'file_name'      => basename( get_attached_file( $att_id ) ?: get_the_title( $att_id ) ),
                'file_size'      => size_format( $size ),
                'driver'         => strtoupper( $driver ),
                'download_count' => $downloads,
                'author_name'    => $author ? $author->display_name : 'Guest',
                'created_at'     => get_the_date( 'Y-m-d H:i', $att_id ),
                'download_url'   => rest_url( 'filehub/v1/download/' . $att_id ),
                'can_delete'     => $can_delete,
            );
        }

        return new WP_REST_Response( $data, 200 );
    }

    /**
     * Handle Delete File
     */
    public function handle_delete_file( $request ) {
        $attachment_id = (int) $request['id'];
        $post          = get_post( $attachment_id );

        if ( ! $post || $post->post_type !== 'attachment' ) {
            return new WP_REST_Response( array( 'error' => __( 'Dosya bulunamadı.', 'gnn-filehub' ) ), 404 );
        }

        // BOLA Ownership Check
        if ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Bu dosyayı silme yetkiniz yok.', 'gnn-filehub' ) ), 403 );
        }

        $driver_name = get_post_meta( $attachment_id, '_filehub_storage_driver', true ) ?: 'local';
        $storage_key = get_post_meta( $attachment_id, '_filehub_storage_key', true );

        switch ( $driver_name ) {
            case 'r2':
                $driver = new FileHub_Storage_R2();
                break;
            case 'gdrive':
                $driver = new FileHub_Storage_GDrive();
                break;
            case 'local':
            default:
                $driver = new FileHub_Storage_Local();
                break;
        }

        if ( $storage_key ) {
            $driver->delete_file( $storage_key );
        }

        wp_delete_attachment( $attachment_id, true );

        return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Dosya başarıyla silindi.', 'gnn-filehub' ) ), 200 );
    }

    /**
     * Handle Download Stream
     */
    public function handle_download_file( $request ) {
        $attachment_id = (int) $request['id'];
        $post          = get_post( $attachment_id );

        if ( ! $post || $post->post_type !== 'attachment' ) {
            status_header( 404 );
            wp_die( esc_html__( 'Dosya bulunamadı.', 'gnn-filehub' ) );
        }

        $driver_name = get_post_meta( $attachment_id, '_filehub_storage_driver', true ) ?: 'local';
        $storage_key = get_post_meta( $attachment_id, '_filehub_storage_key', true );

        FileHub_Attachment::increment_download_count( $attachment_id );

        switch ( $driver_name ) {
            case 'r2':
                $driver = new FileHub_Storage_R2();
                break;
            case 'gdrive':
                $driver = new FileHub_Storage_GDrive();
                break;
            case 'local':
            default:
                $driver = new FileHub_Storage_Local();
                break;
        }

        $driver->get_download_stream( $storage_key, $post->post_mime_type, get_the_title( $post->ID ) );
    }

    /**
     * Handle Change Password via REST
     */
    public function handle_change_password( $request ) {
        $params           = $request->get_json_params();
        $current_password = isset( $params['current_password'] ) ? $params['current_password'] : '';
        $new_password     = isset( $params['new_password'] ) ? $params['new_password'] : '';
        $confirm_password = isset( $params['confirm_password'] ) ? $params['confirm_password'] : '';

        if ( empty( $current_password ) || empty( $new_password ) || empty( $confirm_password ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Lütfen tüm şifre alanlarını doldurun.', 'gnn-filehub' ) ), 400 );
        }

        if ( $new_password !== $confirm_password ) {
            return new WP_REST_Response( array( 'error' => __( 'Yeni şifreler birbiriyle eşleşmiyor.', 'gnn-filehub' ) ), 400 );
        }

        if ( strlen( $new_password ) < 6 ) {
            return new WP_REST_Response( array( 'error' => __( 'Şifre en az 6 karakter olmalıdır.', 'gnn-filehub' ) ), 400 );
        }

        $user = wp_get_current_user();
        if ( ! wp_check_password( $current_password, $user->user_pass, $user->ID ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Mevcut şifreniz yanlış.', 'gnn-filehub' ) ), 400 );
        }

        wp_set_password( $new_password, $user->ID );
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID );

        return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Şifreniz başarıyla güncellendi.', 'gnn-filehub' ) ), 200 );
    }

    /**
     * Handle Front-End User Registration via REST
     */
    public function handle_register_user( $request ) {
        if ( is_user_logged_in() ) {
            return new WP_REST_Response( array( 'error' => __( 'Zaten giriş yapmış durumdasınız.', 'gnn-filehub' ) ), 400 );
        }

        if ( ! get_option( 'users_can_register' ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Siteye yeni üye kaydı kapalıdır.', 'gnn-filehub' ) ), 403 );
        }

        $params           = $request->get_json_params();
        $username         = isset( $params['username'] ) ? sanitize_user( trim( $params['username'] ) ) : '';
        $email            = isset( $params['email'] ) ? sanitize_email( trim( $params['email'] ) ) : '';
        $first_name       = isset( $params['first_name'] ) ? sanitize_text_field( trim( $params['first_name'] ) ) : '';
        $last_name        = isset( $params['last_name'] ) ? sanitize_text_field( trim( $params['last_name'] ) ) : '';
        $password         = isset( $params['password'] ) ? $params['password'] : '';
        $confirm_password = isset( $params['confirm_password'] ) ? $params['confirm_password'] : '';

        if ( empty( $username ) || empty( $email ) || empty( $password ) || empty( $confirm_password ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Lütfen zorunlu tüm alanları doldurun.', 'gnn-filehub' ) ), 400 );
        }

        if ( ! is_email( $email ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Geçersiz e-posta adresi.', 'gnn-filehub' ) ), 400 );
        }

        if ( $password !== $confirm_password ) {
            return new WP_REST_Response( array( 'error' => __( 'Şifreler birbiriyle eşleşmiyor.', 'gnn-filehub' ) ), 400 );
        }

        if ( strlen( $password ) < 6 ) {
            return new WP_REST_Response( array( 'error' => __( 'Şifre en az 6 karakter olmalıdır.', 'gnn-filehub' ) ), 400 );
        }

        if ( username_exists( $username ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Bu kullanıcı adı zaten kullanılmaktadır.', 'gnn-filehub' ) ), 400 );
        }

        if ( email_exists( $email ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Bu e-posta adresi zaten kayıtlıdır.', 'gnn-filehub' ) ), 400 );
        }

        $user_id = wp_insert_user( array(
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => $password,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim( $first_name . ' ' . $last_name ) ?: $username,
            'role'         => get_option( 'default_role', 'subscriber' ),
        ) );

        if ( is_wp_error( $user_id ) ) {
            return new WP_REST_Response( array( 'error' => $user_id->get_error_message() ), 500 );
        }

        // Auto login user after registration
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id );

        return new WP_REST_Response( array(
            'success' => true,
            'message' => __( 'Tebrikler! Kaydınız başarıyla tamamlandı, yönlendiriliyorsunuz...', 'gnn-filehub' ),
        ), 200 );
    }
}
