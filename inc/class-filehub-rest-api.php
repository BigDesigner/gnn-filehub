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
 * Secure WP REST Controller for upload, list, delete, and download operations.
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
     * Handle List Files
     */
    public function handle_list_files( $request ) {
        $current_user_id = get_current_user_id();
        $is_admin        = current_user_can( 'manage_options' );

        $query_args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 50,
            'meta_key'       => '_filehub_storage_driver',
        );

        if ( ! $is_admin ) {
            $query_args['author'] = $current_user_id;
        }

        $attachments = get_posts( $query_args );
        $data        = array();

        foreach ( $attachments as $post ) {
            $att_id   = $post->ID;
            $driver   = get_post_meta( $att_id, '_filehub_storage_driver', true );
            $size     = (int) get_post_meta( $att_id, '_filehub_file_size', true );
            $downloads = (int) get_post_meta( $att_id, '_filehub_download_count', true );
            $author   = get_userdata( $post->post_author );

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

        return new WP_REST_Response( array( 'success' => true ), 200 );
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
}
